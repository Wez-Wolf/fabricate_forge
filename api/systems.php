<?php
/**
 * fabricate_forge/api/systems.php
 *
 * Orchestration layer — quote summary + the explicit recalc entry point.
 * Mirrors the original app's recalculateQuote. No monolithic aggregate load:
 * reads are component-set queries (entities.php list, components.php
 * get_by_quote), the client composes. Systems run only via recalculate_entity.
 *
 * overview(quoteId) contract (pure read — never writes, never recalculates):
 *   {
 *     quote:    { id, name, status, data, ... },   // the quote entity
 *     total_cost, totals, margin_percent,          // persisted quote cost component
 *     entity_count                                 // member count
 *   }
 *
 * recalculate_entity(quoteId) — the ONLY system-invocation path: runs the cost
 * system over the quote's members (batch_calculate), rolls up assemblies
 * (money + mass), persists rolled values + the grand total, then returns the
 * fresh overview.
 */
namespace api;

include_once(__DIR__ . "/_base.php");
include_once(__DIR__ . "/entities.php");
include_once(__DIR__ . "/components.php");
include_once(__DIR__ . "/cost.php");
include_once(__DIR__ . "/links.php");

class systems extends Base
{
    protected function buildTable()
    {
        $this->ensureEcsTables();
    }

    /**
     * Generic graph read — direct contains-children of ANY entity (a quote is
     * just the usual root). Paged + searched, with total count. Structure
     * comes from the LINK table only (structural truth: quote_id column is
     * never used for parent resolution). Each row embeds the child's persisted
     * cost totals (read-only, via the cost comp) so grids need no second call.
     *
     * Three lenses (D5 — the singular entity vs how it's linked):
     *   lens='entity'  DEFAULT — one row per distinct entity (assemblies
     *                  included; shells/tabs aggregate from this)
     *   lens='catalog' like entity but containers excluded (procurement list)
     *   lens='usage'   one row per placement edge (entity × parent × qty)
     * Input: { entity_id, lens?, search?, page?, limit? }
     */
    public function handle_entity_items($input = [])
    {
        $entityId = \getVal($input, 'entity_id');
        if (!$entityId) return ['error' => 'entity_id is required.'];
        if (!$this->getEntity($entityId)) return ['error' => 'Entity not found.', 'error_code' => 404];

        $limit = min(max((int)\getVal($input, 'limit', 2000), 1), 4000);
        $page = max((int)\getVal($input, 'page', 1), 1);
        $offset = ($page - 1) * $limit;
        $scope = \getVal($input, 'lens');
        if (!in_array($scope, ['catalog', 'usage'], true)) $scope = 'entity';

        $params = [$entityId];
        $search = \getVal($input, 'search');
        $whereExtra = '';
        if ($search) {
            $params[] = '%' . $search . '%';
            $n = count($params);
            $whereExtra = " AND (e.name ILIKE \$$n OR COALESCE(e.description,'') ILIKE \$$n)";
        }

        // Structure from the link table only (structural truth). Depth guard
        // lvl<50 is belt-and-braces (cycles are prevented at write time by
        // links.validate_cycle).

        // Each CTE row IS an edge (placement): from_id = true parent,
        // quantity = true edge qty. No arbitrary parent picking.
        $edges = "WITH RECURSIVE edges AS (
                      SELECT l2.to_id AS id, l2.from_id AS parent_id,
                             l2.quantity AS edge_qty, 1 AS lvl
                      FROM link l2
                      WHERE l2.from_id = $1 AND l2.type = 'contains'
                      UNION ALL
                      SELECT l3.to_id, l3.from_id, l3.quantity, s.lvl + 1
                      FROM edges s
                      JOIN link l3 ON l3.from_id = s.id AND l3.type = 'contains'
                      WHERE s.lvl < 50
                    )";
        $cols = "e.id::text, e.type, e.name, e.description,
                 e.data->>'catalog_no' AS catalog_no,
                 mc.data->>'materialLibraryId' AS mat_lib_id,
                 cc.data AS cost_data,
                 count(*) OVER() AS total_rows";
        $joins = "JOIN entity e ON e.id = s.id AND e.is_active = TRUE
                LEFT JOIN component mc ON mc.entity_id = e.id AND mc.type = 'material'
                LEFT JOIN component cc ON cc.entity_id = e.id AND cc.type = 'cost'";
        if ($scope !== 'usage') {
            // Singular lens: one row per unique entity. catalog excludes
            // containers (assemblies are structure; they live in the tree).
            $typeFilter = $scope === 'catalog' ? "e.type <> 'assembly'" : 'TRUE';
            $sql = $edges . ",
                    nodes AS (SELECT DISTINCT id FROM edges)
             SELECT " . $cols . ",
                    COALESCE(SUM(x.edge_qty), 0)::text AS total_qty,
                    count(x.*)::int AS used_in
                FROM nodes s
                JOIN edges x ON x.id = s.id
                " . $joins . "
                WHERE {$typeFilter} {$whereExtra}
                GROUP BY e.id, e.type, e.name, e.description, mat_lib_id, cost_data
                ORDER BY e.name ASC
                LIMIT " . intval($limit) . " OFFSET " . intval($offset);
        } else {
            // Usage lens: one row per placement — real parent, real edge qty.
            $sql = $edges . "
             SELECT " . $cols . ",
                    s.edge_qty::text AS quantity,
                    s.edge_qty::text AS parent_qty,
                    pe.name AS parent_name,
                    s.parent_id::text AS parent_id
                FROM edges s
                " . $joins . "
                LEFT JOIN entity pe ON pe.id = s.parent_id
                WHERE 1=1 {$whereExtra}
                ORDER BY e.name ASC
                LIMIT " . intval($limit) . " OFFSET " . intval($offset);
        }
        $res = $this->pgCrud->execute($sql, $params);
        $res = $this->pgCrud->execute($sql, $params);
        if (!empty($res['error'])) return $res;
        $rows = $res['data'] ?? [];
        $total = $rows ? (int)$rows[0]['total_rows'] : 0;

        // Material labels via the shared Base helper — same seam the comps API
        // uses; never duplicated here (DRY).
        $libIds = [];
        foreach ($rows as $r) if ($r['mat_lib_id']) $libIds[$r['mat_lib_id']] = true;
        $shapes = $this->materialEntitiesByIds(array_keys($libIds));
        $labels = [];
        foreach ($shapes as $id => $m) {
            $label = $m['name'] ?? '';
            if (!empty($m['grade']) && strpos($label, $m['grade']) === false) $label .= ' ' . $m['grade'];
            if (!empty($m['profile']) && strpos($label, $m['profile']) === false) $label .= ' ' . $m['profile'];
            $labels[$id] = $label;
        }
        foreach ($rows as &$r) {
            $comps = [];
            if ($r['mat_lib_id']) {
                $comps[] = ['type' => 'material',
                            'data' => ['materialLibraryId' => $r['mat_lib_id']],
                            'material_label' => $labels[$r['mat_lib_id']] ?? null];
            }
            $r['components'] = $comps;
            // Full persisted cost comp — grids, process tab and rollup columns
            // all read from this one shape (same source as systems.overview).
            $r['cost'] = ($r['cost_data'] !== null)
                ? (json_decode($r['cost_data'], true) ?: []) : [];
            unset($r['total_rows'], $r['mat_lib_id'], $r['cost_data']);
        }
        unset($r);

        return ['items' => $rows, 'total' => $total,
                'page' => $page, 'limit' => $limit];
    }

    /**
     * Generic subtree read — full contains-tree below ANY entity. Delegates
     * to the links seam's batched walker (2 queries, no N+1). Pure read.
     *
     * Input: { entity_id, depth? }
     */
    public function handle_entity_tree($input = [])
    {
        $links = new \api\links();
        $links->user_id = $this->effOwnerId();
        return $links->handle_tree_batched($input);
    }

    /**
     * Entity summary — pure read. The entity + its persisted cost component
     * (totals, total_cost, margin). Zero writes, zero recalc: reads never
     * execute systems. Generic — any entity (a quote is just an entity with a
     * cost component; the rollup/persistence happens in recalc, not here).
     *
     * Input: { entity_id } (alias: quote_id)
     */
    public function handle_overview($input = [])
    {
        $entityId = \getVal($input, 'entity_id') ?: \getVal($input, 'quote_id');
        if (!$entityId) return ['error' => 'entity_id is required.'];

        $entity = $this->getEntity($entityId);
        if (!$entity) return ['error' => 'Entity not found.', 'error_code' => 404];

        // Persisted cost via the cost.php seam (read only — never recompute).
        $costApi = new \api\cost();
        $costApi->user_id = $this->effOwnerId();
        $cost = $costApi->handle_get_cost(['entity_id' => $entityId]);
        $costData = isset($cost['error']) ? null : $cost;

        return [
            'quote' => $entity,
            'total_cost' => $costData['total'] ?? null,
            'totals' => $costData['totals'] ?? [],
            'margin_percent' => isset($costData['marginPercent']) ? (float)$costData['marginPercent'] : $this->resolveRootMargin($entity),
            'entity_count' => $costData['entity_count'] ?? null,
            'last_updated' => $costData['lastUpdated'] ?? null,
        ];
    }

    /**
     * Recalculate an entity and all its members — the only system-invocation
     * path. Clears cached cost components, runs the cost system over its
     * members (batch), rolls up every entity that has children (money + mass),
     * persists everything, returns the fresh overview.
     * Input: { entity_id }
     */
    public function handle_recalculate_entity($input = [])
    {
        $rootId = \getVal($input, 'entity_id');
        if (!$rootId) return ['error' => 'entity_id is required.'];

        $root = $this->getEntity($rootId);
        if (!$root) return ['error' => 'Entity not found.', 'error_code' => 404];

        // NOTE: no clear_entity_costs() — incremental watchers (recalculateUpward)
        // keep cost components fresh on mutation. handle_calculate_entity skips
        // entities whose inputs are unchanged, so a full pass is cheap.
        // (A forced full recalc is still available via clear_entity_costs + this.)
        $res = $this->pgCrud->read([
            'table' => 'entity',
            'where' => 'quote_id = $1 AND user_id_owner = $2 AND is_active = TRUE',
            'params' => [$rootId, $this->effOwnerId()],
            'order_fields' => ['created_at ASC'],
        ]);
        $entities = $res['data'] ?? [];

        $entityIds = array_column($entities, 'id');
        $costs = [];

        // Effective root-global margin: root field → user settings → default.
        // Passed to the batch cost calc as options.margin_percent; line items
        // can still override per-entity via entity.data.marginPercent (cost.php).
        $marginPercent = $this->resolveRootMargin($root);

        if ($entityIds) {
            // 2. Cost system run — single pass over the member set (kills N+1)
            $costApi = new \api\cost();
            $costApi->user_id = $this->effOwnerId();
            $costRes = $costApi->handle_batch_calculate([
                'entity_ids' => $entityIds,
                'options' => ['margin_percent' => $marginPercent],
            ]);
            if (isset($costRes['error'])) {
                return $costRes;
            }
            $costs = $costRes;

            // 3. Attach freshly-written cost to each entity row
            foreach ($entities as &$e) {
                $e['cost'] = $costs[$e['id']] ?? null;
            }
            unset($e);

            // 3b. Assembly rollup — parent totals = own cost + Σ(child × qty).
            //    Persists rolled values so list views / exports / overview read
            //    them without recomputing (reads never execute systems).
            $rollup = $this->computeRollups($entities, $rootId);
            foreach ($entities as &$e) {
                if (isset($rollup[$e['id']])) {
                    $r = $rollup[$e['id']];
                    $e['cost']['total'] = $r['total'];
                    $e['cost']['rolled_total'] = $r['total'];
                    $e['cost']['children_total'] = $r['children_total'];
                    $e['cost']['child_count'] = $r['child_count'];
                    $e['cost']['massKg'] = $r['mass_kg'];
                    $e['cost']['rolled_mass_kg'] = $r['mass_kg'];
                    $rolledCols = [];
                    foreach (['material','boilerHrs','weldHrs','machHrs','labor','consumables',
                              'services','ndt','lining','paint','transport','processTotal','margin','subtotal'] as $cc) {
                        $rolledCols[$cc] = $r['col_' . $cc] ?? 0.0;
                    }
                    $e['cost']['rolled_columns'] = $rolledCols;
                    // Write rolled values via the cost.php seam (cost ADR).
                    $costApi->patch_entity_cost($e['id'], [
                        'rolled_total' => $r['total'],
                        'children_total' => $r['children_total'],
                        'rolled_mass_kg' => $r['mass_kg'],
                        'rolled_columns' => $rolledCols,
                    ]);
                }
            }
            unset($e);
        }

        // 4. Grand total + per-column totals = Σ(top-level entity cost)
        //    cost.php already multiplies by quantity inside calculate_entity
        //    (material×qty, process×qty, on-costs×qty).
        $COST_COLUMNS = [
            'material', 'boilerHrs', 'weldHrs', 'machHrs', 'labor',
            'consumables', 'services', 'ndt', 'lining', 'paint', 'transport',
            'processTotal', 'margin', 'subtotal', 'total',
        ];
        $totals = array_fill_keys($COST_COLUMNS, 0.0);
        $grandTotal = 0.0;
        $totalMassKg = 0.0;

        // Top-level entities = linked directly FROM the quote. Rolled-up
        // assembly totals already include all descendants × qty, so summing
        // ONLY top-level rolled totals gives the true grand total without
        // double-counting children.
        $topLevel = [];
        if ($entityIds) {
            $linkRes = $this->pgCrud->read([
                'table' => 'link',
                'where' => 'from_id = $1 AND type = $2 AND user_id_owner = $3',
                'params' => [$rootId, 'contains', $this->effOwnerId()],
            ]);
            foreach (($linkRes['data'] ?? []) as $l) {
                $topLevel[$l['to_id']] = (float)($l['quantity'] ?? 1);
            }
        }
        // No top-level links (items added without links) → treat every entity
        // as top-level.
        if (empty($topLevel)) {
            foreach ($entities as $e) {
                $topLevel[$e['id']] = 1;
            }
        }

        foreach ($entities as $e) {
            $c = $e['cost'] ?? null;
            if (!$c) continue;
            if (isset($topLevel[$e['id']])) {
                $use = $c['rolled_columns'] ?? null;
                foreach ($COST_COLUMNS as $col) {
                    $v = null;
                    if ($col === 'total') $v = $c[$col] ?? null;
                    elseif ($use) $v = $use[$col] ?? null;
                    else $v = $c[$col] ?? null;
                    if ($v !== null) $totals[$col] += (float)$v;
                }
                $totalMassKg += (float)($c['rolled_mass_kg'] ?? $c['massKg'] ?? 0);
            }
        }
        foreach ($totals as $col => $v) {
            $totals[$col] = \api\cost::r2($v);
        }
        $totals['massKg'] = \api\cost::r2($totalMassKg);
        $grandTotal = $totals['total'];

        // 5. Persist totalCost + column totals into the root's cost component
        $this->persistRootTotal($rootId, $grandTotal, count($entities), $totals, $marginPercent);

        // 6. Fresh summary (pure read of what was just written)
        return $this->handle_overview(['entity_id' => $rootId]);
    }

    /**
     * Resolve the root entity's effective margin %: explicit root field
     * (data.marginPercent) → user's defaultMarkupPercent from Settings → 30.
     */
    private function resolveRootMargin($entity)
    {
        $data = $quote['data'] ?? [];
        if (isset($data['marginPercent']) && $data['marginPercent'] !== null && $data['marginPercent'] !== '') {
            return (float)$data['marginPercent'];
        }
        $res = $this->pgCrud->read([
            'table' => 'user_prefs',
            'fields' => ['data'],
            'where' => 'user_id = $1',
            'params' => [$this->user_id],
            'limit' => 1,
        ]);
        $prefs = $res['data'][0]['data'] ?? [];
        $m = $prefs['defaultMarkupPercent'] ?? null;
        return $m !== null ? (float)$m : 30;
    }

    /**
     * Quote summary for dashboard/list — light read without full cost calc.
     * Returns quote entities with their persisted cost component only.
     * Input: { status?, search?, limit? }
     */
    public function handle_list_quotes($input = [])
    {
        $where = 'type = $1 AND user_id_owner = $2 AND is_active = TRUE';
        $params = ['quote', $this->effOwnerId()];
        $idx = 3;

        $status = \getVal($input, 'status');
        if ($status) {
            $where .= " AND data->>'status' = \${$idx}";
            $params[] = $status;
            $idx++;
        }
        $search = \getVal($input, 'search');
        if ($search) {
            $where .= " AND name ILIKE \${$idx}";
            $params[] = "%{$search}%";
            $idx++;
        }
        $limit = (int)\getVal($input, 'limit', 50);

        $res = $this->pgCrud->read([
            'table' => 'entity',
            'where' => $where,
            'params' => $params,
            'order_fields' => ['created_at DESC'],
            'limit' => min(max($limit, 1), 200),
        ]);
        $quotes = $res['data'] ?? [];

        // Attach persisted cost component (no recompute) — via the cost.php seam.
        $costApi = new \api\cost();
        $costApi->user_id = $this->effOwnerId();
        $costById = $costApi->get_costs_by_entities(array_column($quotes, 'id'));
        foreach ($quotes as &$q) {
            $q['total_cost'] = $costById[$q['id']]['total'] ?? null;
            $q['status'] = $q['data']['status'] ?? 'draft';
        }
        unset($q);

        return $quotes;
    }

    // ── Internal ───────────────────────────────────────

    /**
     * Post-order assembly rollup: for each entity in the quote, compute the
     * rolled-up total = own cost + Σ(child cost × link quantity). Uses the
     * contains-links graph rooted at the quote. Returns { entityId: { total,
     * children_total, child_count } }.
     *
     * Generic over entity type: ANY entity with children gets rolled up — the
     * decision is "does it have contains-links", not "is it an assembly".
     */
    private function computeRollups(&$entities, $rootId)
    {
        // Cost columns carried through the rollup (everything except `total`, which
        // is handled as rolled_total). Parents have 0 in these own columns, so
        // rolling them up is what lets the totals row show real material/hours.
        $COLUMNS = ['material','boilerHrs','weldHrs','machHrs','labor','consumables',
                    'services','ndt','lining','paint','transport','processTotal',
                    'margin','subtotal'];

        // id → own totals (already × quantity by cost.php) + own mass kg
        $own = [];
        $ownMass = [];
        foreach ($entities as $e) {
            $own[$e['id']] = (float)($e['cost']['total'] ?? 0);
            $ownMass[$e['id']] = (float)($e['cost']['massKg'] ?? 0);
        }

        // Load contains links for all quote entities in one query
        $ids = array_keys($own);
        $rollup = [];
        if (!$ids) return $rollup;

        $res = $this->pgCrud->read([
            'table' => 'link',
            'where' => 'from_id = ANY($1::uuid[]) AND type = $2 AND user_id_owner = $3',
            'params' => ['{' . implode(',', $ids) . '}', 'contains', $this->effOwnerId()],
        ]);
        $links = $res['data'] ?? [];

        // child → parent adjacency
        $parents = [];  // parentId => [ [childId, qty], ... ]
        $qtys = [];     // id => entity quantity
        foreach ($entities as $e) {
            $qtys[$e['id']] = (float)($e['quantity'] ?? 1);
        }
        foreach ($links as $l) {
            $parentId = $l['from_id'] ?? null;
            $childId = $l['to_id'] ?? null;
            if (!$parentId || !$childId) continue;
            if (!isset($parents[$parentId])) $parents[$parentId] = [];
            $parents[$parentId][] = [$childId, (float)($l['quantity'] ?? 1)];
        }

        // Post-order DFS: compute PER-UNIT cost, mass, AND each cost column
        // for a node, then the display total/per-unit × entity quantity. Per-unit
        // normalization handles the BOM import's redundant storage correctly:
        //   - leaf cost = unit × entity.qty (cost.php); leaf mass likewise
        //   - link qty (parent→child) = qty per ONE parent
        //   - per-unit avoids N² double counting.
        $memo = [];      // id → [costPerUnit, massPerUnit, colPerUnit: array]
        $visited = [];
        // e0: id → entity cost (with columns) for the walk to read.
        $e0 = [];
        foreach ($entities as $e) $e0[$e['id']] = $e['cost'] ?? null;

        $walk = function ($id) use (&$walk, &$memo, &$visited, $parents, $own, $ownMass, $qtys, $COLUMNS, &$e0) {
            if (isset($memo[$id])) return $memo[$id];
            if (in_array($id, $visited)) {  // cycle guard
                $z = array_fill_keys($COLUMNS, 0.0);
                return [0.0, 0.0, $z];
            }
            $visited[] = $id;

            $qty = max(($qtys[$id] ?? 1), 1);
            $ownPerUnit = ($own[$id] ?? 0.0) / $qty;
            $massPerUnit = ($ownMass[$id] ?? 0.0);   // already per unit
            // This entity's own columns, normalized to per-unit.
            $myCost = $e0[$id] ?? null;
            $cols = [];
            foreach ($COLUMNS as $c) {
                $cols[$c] = $myCost && isset($myCost[$c])
                    ? (float)$myCost[$c] / $qty
                    : 0.0;
            }

            $child = array_fill_keys($COLUMNS, 0.0);
            $childCost = 0.0;
            $childMass = 0.0;
            $childCount = 0;
            foreach (($parents[$id] ?? []) as [$childId, $qtyPerParent]) {
                list($cc, $cm, $ccols) = $walk($childId);
                // Child contribution = child per-unit × child qty × link qty.
                // Entity quantity is authoritative (the tree shows it, the BoQ
                // line carries it); link qty is structural (child lines per
                // parent unit). Matches cost.php rollUp (child rolled_total ×
                // link qty) — the two rollups were previously inconsistent.
                $cq = max(($qtys[$childId] ?? 1), 1);
                $childCost += $cc * $cq * $qtyPerParent;
                $childMass += $cm * $cq * $qtyPerParent;
                foreach ($COLUMNS as $c) $child[$c] += $ccols[$c] * $cq * $qtyPerParent;
                $childCount++;
            }

            $perUnitCols = [];
            foreach ($COLUMNS as $c) $perUnitCols[$c] = $cols[$c] + $child[$c];
            $perUnit = [$ownPerUnit + $childCost, $massPerUnit + $childMass, $perUnitCols];
            $memo[$id] = $perUnit;
            return $perUnit;
        };

        foreach ($entities as $e) {
            // No type names here: an entity with children gets rolled up; an
            // entity without children is already its own total (nothing to fold).
            if (!isset($parents[$e['id']])) continue;
            list($perUnit, $perMass, $perCols) = $walk($e['id']);
            $qty = max((float)($e['quantity'] ?? 1), 1);
            $displayTotal = $perUnit * $qty;
            $displayMass = $perMass * $qty;
            $rolled = ['total' => \api\cost::r2($displayTotal),
                       'mass_kg' => \api\cost::r2($displayMass),
                       'per_unit' => \api\cost::r2($perUnit),
                       'per_unit_mass' => \api\cost::r2($perMass),
                       'children_total' => \api\cost::r2($displayTotal - ($own[$e['id']] ?? 0.0)),
                       'child_count' => count($parents[$e['id']] ?? [])];
            foreach ($COLUMNS as $c) $rolled['col_' . $c] = \api\cost::r2($perCols[$c] * $qty);
            $rollup[$e['id']] = $rolled;
        }
        return $rollup;
    }
}

\api\dispatchIfEntry(__FILE__);
