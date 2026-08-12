<?php
/**
 * fabricate_forge/api/systems.php
 *
 * Orchestration layer — the single-call entry points the quote UI uses.
 * Mirrors EntitySystem.loadQuote / recalculateQuote in the original app.
 *
 * loadQuote(quoteId) contract (one call, no N+1):
 *   {
 *     quote:    { id, name, status, data, ... }        // the quote entity
 *     entities: [ entity + components + own cost ],    // all entities in the quote
 *     costs:    { entityId: costComponentData },       // map for UI lookups
 *     totals:   { col: Σ across entities },            // per-column sums (material, boilerHrs, ndt, …)
 *     total_cost: number                               // grand total (auto-persisted)
 *   }
 *
 * In ECS, a quote IS an entity (type='quote') with a quote_id column on its
 * member entities. loadQuote reads the quote entity, batches cost
 * calculation over its members, and persists the grand total back into the
 * quote entity's 'cost' component.
 */
namespace api;

include_once(__DIR__ . "/_base.php");
include_once(__DIR__ . "/entities.php");
include_once(__DIR__ . "/components.php");
include_once(__DIR__ . "/cost.php");

class systems extends Base
{
    protected function buildTable()
    {
        $this->ensureEcsTables();
    }

    /**
     * Load a quote with all its entities, components, and calculated costs.
     * One round-trip — the quote UI's primary data call.
     *
     * Input: { quote_id }
     */
    public function handle_load_quote($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        if (!$quoteId) return ['error' => 'quote_id is required.'];

        $quote = $this->getEntity($quoteId);
        if (!$quote || $quote['type'] !== 'quote') {
            return ['error' => 'Quote not found.', 'error_code' => 404];
        }

        // 1. All entities belonging to this quote
        $res = $this->pgCrud->read([
            'table' => 'entity',
            'where' => 'quote_id = $1 AND user_id_owner = $2 AND is_active = TRUE',
            'params' => [$quoteId, $this->effOwnerId()],
            'order_fields' => ['created_at ASC'],
        ]);
        $entities = $res['data'] ?? [];

        $entityIds = array_column($entities, 'id');
        $costs = [];

        // Effective quote-global margin: quote field → user settings → default.
        // Passed to the batch cost calc as options.margin_percent; line items
        // can still override per-entity via entity.data.marginPercent (cost.php).
        $marginPercent = $this->resolveQuoteMargin($quote);

        if ($entityIds) {
            // 2. Batch cost calculation (single pass — kills N+1)
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

            // 3. Attach components + cost to each entity
            foreach ($entities as &$e) {
                $e['components'] = $this->getComponents($e['id']);
                $e['cost'] = $costs[$e['id']] ?? null;
            }
            unset($e);

            // 3b. Assembly rollup — parent totals = own cost + Σ(child × qty).
            //    This lets the tree/overview show a real assembly total (not 0)
            //    without double-counting (children stay in the totals row as
            //    their own lines; the rollup is only for display + parent cost).
            $rollup = $this->computeAssemblyRollups($entities, $quoteId);
            foreach ($entities as &$e) {
                if (isset($rollup[$e['id']])) {
                    $e['cost']['total'] = $rollup[$e['id']]['total'];
                    $e['cost']['rolled_total'] = $rollup[$e['id']]['total'];
                    $e['cost']['children_total'] = $rollup[$e['id']]['children_total'];
                    $e['cost']['child_count'] = $rollup[$e['id']]['child_count'];
                    // Rolled-up mass = Σ linked entities (per-unit × qty)
                    $e['cost']['massKg'] = $rollup[$e['id']]['mass_kg'];
                    $e['cost']['rolled_mass_kg'] = $rollup[$e['id']]['mass_kg'];
                    // Persist the rolled-up totals back to the cost component
                    // so list views / exports read the right numbers.
                    $this->patchComponentData($e['cost']['component_id'] ?? null, [
                        'rolled_total' => $rollup[$e['id']]['total'],
                        'children_total' => $rollup[$e['id']]['children_total'],
                        'rolled_mass_kg' => $rollup[$e['id']]['mass_kg'],
                    ]);
                }
            }
            unset($e);
        }

        // 4. Grand total + per-column totals = Σ(entity cost element)
        //    cost.php already multiplies by quantity inside calculate_entity
        //    (material×qty, process×qty, on-costs×qty).
        $COST_COLUMNS = [
            'material', 'boilerHrs', 'weldHrs', 'machHrs', 'labor',
            'consumables', 'services', 'ndt', 'lining', 'paint', 'transport',
            'processTotal', 'margin', 'subtotal', 'total',
        ];
        $totals = array_fill_keys($COST_COLUMNS, 0.0);
        $grandTotal = 0.0;

        // Determine top-level entities (linked directly FROM the quote). The
        // rolled-up assembly totals already include all descendants × qty, so
        // summing ONLY top-level rolled totals gives the true grand total
        // without double-counting children.
        $topLevel = [];
        if ($entityIds) {
            $linkRes = $this->pgCrud->read([
                'table' => 'link',
                'where' => 'from_id = $1 AND type = $2 AND user_id_owner = $3',
                'params' => [$quoteId, 'contains', $this->effOwnerId()],
            ]);
            foreach (($linkRes['data'] ?? []) as $l) {
                $topLevel[$l['to_id']] = (float)($l['quantity'] ?? 1);
            }
        }
        // If no top-level links exist (e.g. items added without links), fall
        // back to every entity that is not referenced as a child.
        if (empty($topLevel)) {
            foreach ($entities as $e) {
                $topLevel[$e['id']] = 1;
            }
        }

        foreach ($entities as $e) {
            $c = $e['cost'] ?? null;
            if (!$c) continue;
            // For top-level entities use the rolled-up total (assemblies) or
            // own total (leaves). Never sum both parent and children.
            if (isset($topLevel[$e['id']])) {
                foreach ($COST_COLUMNS as $col) {
                    if (isset($c[$col])) $totals[$col] += (float)$c[$col];
                }
            }
        }
        foreach ($totals as $col => $v) {
            $totals[$col] = \api\cost::r2($v);
        }
        $grandTotal = $totals['total'];

        // 5. Auto-persist totalCost + column totals into the quote's cost component
        $this->persistQuoteTotal($quoteId, $grandTotal, count($entities), $totals, $marginPercent);

        return [
            'quote' => $quote,
            'entities' => $entities,
            'costs' => $costs,
            'totals' => $totals,
            'margin_percent' => $marginPercent,
            'total_cost' => $grandTotal,
        ];
    }

    /**
     * Resolve the quote's effective margin %: explicit quote field
     * (data.marginPercent) → user's defaultMarkupPercent from Settings → 30.
     */
    private function resolveQuoteMargin($quote)
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
     * Recalculate a quote (clear cached cost components, reload).
     * Input: { quote_id }
     */
    public function handle_recalculate_quote($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        if (!$quoteId) return ['error' => 'quote_id is required.'];

        $quote = $this->getEntity($quoteId);
        if (!$quote || $quote['type'] !== 'quote') {
            return ['error' => 'Quote not found.', 'error_code' => 404];
        }

        // Delete all cost components for this quote's entities, forcing fresh calc
        $this->pgCrud->execute(
            "DELETE FROM component
             WHERE type = 'cost'
               AND user_id_owner = \$1
               AND (quote_id = \$2 OR entity_id IN (
                   SELECT id FROM entity WHERE quote_id = \$2 AND user_id_owner = \$1
               ))",
            [$this->effOwnerId(), $quoteId]
        );

        return $this->handle_load_quote(['quote_id' => $quoteId]);
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

        // Attach persisted cost component (no recompute)
        foreach ($quotes as &$q) {
            $comps = $this->getComponents($q['id'], 'cost');
            $q['total_cost'] = $comps[0]['data']['total'] ?? null;
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
     */
    private function computeAssemblyRollups(&$entities, $quoteId)
    {
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

        // Post-order DFS: compute PER-UNIT cost AND mass for each node, then
        // the display total = perUnit × entity quantity. This handles the BOM
        // import's redundant storage correctly:
        //   - leaf cost = unit × entity.qty (cost.php); leaf mass likewise
        //   - link qty (parent→child) = qty per ONE parent
        //   - per-unit normalization avoids N² double counting.
        $memo = [];      // id → [costPerUnit, massPerUnit]
        $visited = [];

        $walk = function ($id) use (&$walk, &$memo, &$visited, $parents, $own, $ownMass, $qtys) {
            if (isset($memo[$id])) return $memo[$id];
            if (in_array($id, $visited)) return [0.0, 0.0]; // cycle guard
            $visited[] = $id;

            // Own cost per single unit of this entity. NOTE: cost.php multiplies
            // COST by quantity but stores massKg PER UNIT — so they divide
            // differently here.
            $qty = max(($qtys[$id] ?? 1), 1);
            $ownPerUnit = ($own[$id] ?? 0.0) / $qty;
            $massPerUnit = ($ownMass[$id] ?? 0.0);   // already per unit

            $childCost = 0.0;
            $childMass = 0.0;
            $childCount = 0;
            foreach (($parents[$id] ?? []) as [$childId, $qtyPerParent]) {
                list($cc, $cm) = $walk($childId);
                $childCost += $cc * $qtyPerParent;
                $childMass += $cm * $qtyPerParent;
                $childCount++;
            }
            $perUnit = [$ownPerUnit + $childCost, $massPerUnit + $childMass];
            $memo[$id] = $perUnit;
            return $perUnit;
        };

        foreach ($entities as $e) {
            if ($e['type'] === 'assembly' || $e['type'] === 'part') {
                $perUnit = $walk($e['id']);
                $qty = max((float)($e['quantity'] ?? 1), 1);
                $displayTotal = $perUnit[0] * $qty;
                $displayMass = $perUnit[1] * $qty;
                $rollup[$e['id']] = [
                    'total' => \api\cost::r2($displayTotal),
                    'mass_kg' => \api\cost::r2($displayMass),
                    'per_unit' => \api\cost::r2($perUnit[0]),
                    'per_unit_mass' => \api\cost::r2($perUnit[1]),
                    'children_total' => \api\cost::r2($displayTotal - ($own[$e['id']] ?? 0.0)),
                    'child_count' => count($parents[$e['id']] ?? []),
                ];
            }
        }
        return $rollup;
    }

    /**
     * Persist grand total + entity count + column totals into the quote's cost
     * component. If no cost component exists yet, create one (holds quote-level
     * data).
     */
    private function persistQuoteTotal($quoteId, $grandTotal, $entityCount, $totals = [], $marginPercent = null)
    {
        $quoteCostData = [
            'total' => $grandTotal,
            'subtotal' => $grandTotal,
            'entity_count' => $entityCount,
            'totals' => $totals,
            'marginPercent' => $marginPercent !== null ? (float)$marginPercent : null,
            'lastUpdated' => date('c'),
        ];
        $comps = $this->getComponents($quoteId, 'cost');
        if ($comps) {
            $this->patchComponentData($comps[0]['id'], $quoteCostData);
        } else {
            $this->pgCrud->save([
                'table' => 'component',
                'data' => [
                    'entity_id' => $quoteId,
                    'type' => 'cost',
                    'data' => $quoteCostData,
                    'quote_id' => $quoteId,
                    'user_id_owner' => $this->effOwnerId(),
                ],
            ]);
        }
    }
}

\api\dispatchIfEntry(__FILE__);
