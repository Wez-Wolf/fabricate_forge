<?php
/**
 * fabricate_forge/api/boms.php
 *
 * BOM import — turns rows into the ECS graph.
 *
 * A BOM row becomes:
 *   - an entity (type detected: assembly | part | fastener)
 *   - a 'contains' link to its parent (from item-number hierarchy)
 *   - a material component (materialLibraryId resolved via materials.match)
 *   - a quantity on the link
 *
 * This mirrors the original input-bom.js pipeline: detect format → resolve
 * items → link hierarchy → enrich materials.
 */
namespace api;

include_once(__DIR__ . "/_base.php");
include_once(__DIR__ . "/materials.php");
include_once(__DIR__ . "/systems.php");

class boms extends Base
{
    /**
     * Display-name length cap for imported entities. BoQ row descriptions are
     * long (full size/schedule/grade strings); cramming all 200 chars into the
     * entity name looks terrible in the tree/entities list. Cap the `name` and
     * keep the full description in the `description` column instead.
     */
    const MAX_NAME_LENGTH = 80;

    protected function buildTable()
    {
        $this->ensureEcsTables();
    }

    /**
     * Import a BOM from JSON rows.
     * Input: {
     *   quote_id: uuid,
     *   rows: [
     *     { item_number: "1.2", description: "Mounting Plate",
     *       material: "A36", quantity: 4, length?, width?, thickness? }
     *   ]
     * }
     * Returns: { imported: n, entities: [...], skipped: [...] }
     */
    public function handle_import($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        $rows = \getVal($input, 'rows', []);
        if (!$quoteId) return ['error' => 'quote_id is required.'];
        if (!is_array($rows) || empty($rows)) return ['error' => 'rows (array) is required.'];

        $quote = $this->getEntity($quoteId);
        if (!$quote || $quote['type'] !== 'quote') {
            return ['error' => 'Quote not found.', 'error_code' => 404];
        }

        $entities = [];
        $skipped = [];
        $matches = [];   // material-match feedback per row

        foreach ($rows as $row) {
            $itemNumber = \getVal($row, 'item_number', '');
            $description = \getVal($row, 'description', \getVal($row, 'name', 'Unnamed item'));
            $material = \getVal($row, 'material', '');
            $quantity = (float)\getVal($row, 'quantity', 1);

            // Explicit type column wins; else detect from description
            $explicitType = strtolower((string)\getVal($row, 'type', ''));
            $type = in_array($explicitType, ['part', 'assembly', 'fastener'], true)
                ? $explicitType
                : $this->detectEntityType($description);

            // Resolve material library via match scoring
            $materialId = null;
            if ($material) {
                $match = $this->matchMaterial($material, $description);
                if ($match) $materialId = $match;
            } else {
                // No material column — try to infer from the description
                $match = $this->matchMaterial($description, $description);
                if ($match) $materialId = $match;
            }
            $matches[] = [
                'item_number' => $itemNumber,
                'description' => $description,
                'material' => $material,
                'matched' => $materialId ? true : false,
                'matched_id' => $materialId,
            ];

            // Create entity. `name` = short display label (capped so the
            // tree/entities list stays readable); `description` = full row text.
            $name = $this->shortName($description);
            $entityRes = $this->pgCrud->save([
                'table' => 'entity',
                'data' => [
                    'type' => $type,
                    'name' => $name,
                    'description' => mb_substr($description, 0, 2000),
                    'quote_id' => $quoteId,
                    // D5: entities are singular — row qty rides the contains-link
                    // (linkHierarchy puts it there).
                    'quantity' => 1,
                    'user_id_owner' => $this->effOwnerId(),
                ],
            ]);
            if (!empty($entityRes['error'])) {
                $skipped[] = ['item_number' => $itemNumber, 'reason' => $entityRes['error']];
                continue;
            }
            $entityId = $entityRes['data']['id'];

            // Material component (if library item matched) — includes the
            // item's own variables so cost.php can price it (costPerM for
            // pipe, costPerEa for fittings/flanges, mass, unitCost).
            if ($materialId) {
                $matData = [
                    'materialLibraryId' => $materialId,
                    'category' => $this->detectCategory($description, $material),
                ];
                foreach (['length', 'width', 'thickness', 'mass'] as $dim) {
                    $v = \getVal($row, $dim);
                    if ($v !== null && $v !== '') $matData[$dim] = (float)$v;
                }
                foreach (['costPerM', 'costPerEa', 'unitCost'] as $costKey) {
                    $v = \getVal($row, $costKey);
                    if ($v !== null && $v !== '') $matData[$costKey] = (float)$v;
                }
                $this->pgCrud->save([
                    'table' => 'component',
                    'data' => [
                        'entity_id' => $entityId,
                        'type' => 'material',
                        'data' => $matData,
                        'quote_id' => $quoteId,
                        'user_id_owner' => $this->effOwnerId(),
                    ],
                ]);
            }

            // Process component — per-trade hours from the CSV (welding,
            // machining, boilermaking, cutting, drilling, grinding, assembly,
            // qualityControl). Only created when at least one hour > 0.
            $processData = [];
            foreach (['boilermaking', 'welding', 'machining', 'cutting', 'drilling', 'grinding', 'bending', 'assembly', 'qualityControl', 'painting'] as $trade) {
                $v = \getVal($row, $trade);
                $n = is_numeric($v) ? (float)$v : 0;
                if ($n > 0) $processData[$trade] = $n;
            }
            if ($processData) {
                $this->pgCrud->save([
                    'table' => 'component',
                    'data' => [
                        'entity_id' => $entityId,
                        'type' => 'process',
                        'data' => $processData,
                        'quote_id' => $quoteId,
                        'user_id_owner' => $this->effOwnerId(),
                    ],
                ]);
            }

            $entities[] = ['id' => $entityId, 'item_number' => $itemNumber, 'type' => $type, 'quantity' => $quantity];
        }

        // ── Link hierarchy from item numbers (1, 1.1, 1.1.2) ──
        $this->linkHierarchy($entities, $quoteId);

        // ── Promote parents to assemblies ──
        // Any entity that ended up with contains-children (from the item-number
        // hierarchy) but was typed 'part' (e.g. "Inlet Duct Section") is really
        // a container → promote to assembly so the tree/UI treats it right.
        $childIds = [];
        $linkRes = $this->pgCrud->read([
            'table' => 'link',
            'where' => 'from_id = ANY($1::uuid[]) AND type = $2 AND user_id_owner = $3',
            'params' => ['{' . implode(',', array_column($entities, 'id')) . '}', 'contains', $this->effOwnerId()],
        ]);
        foreach (($linkRes['data'] ?? []) as $l) {
            $childIds[] = $l['from_id'];
        }
        $childIds = array_unique($childIds);
        if ($childIds) {
            $this->pgCrud->execute(
                "UPDATE entity SET type = 'assembly' WHERE id = ANY($1::uuid[]) AND type = 'part' AND user_id_owner = $2",
                ['{' . implode(',', $childIds) . '}', $this->effOwnerId()]
            );
        }

        return [
            'imported' => count($entities),
            'skipped_count' => count($skipped),
            'entities' => $entities,
            'skipped' => $skipped,
            'matches' => $matches,
        ];
    }

    /**
     * Recalculate a root's total (delegates to systems.recalculate_entity —
     * the explicit system-invocation path).
     */
    public function handle_calculate($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        if (!$quoteId) return ['error' => 'quote_id is required.'];
        $systems = new \api\systems();
        $systems->user_id = $this->effOwnerId();
        return $systems->handle_recalculate_entity(['entity_id' => $quoteId]);
    }

    /**
     * Pipe ↔ flange/fitting compatibility checks for the assembly tree.
     *
     * Walks the BOM; for every PIPE entity it checks the flanges/fittings
     * linked underneath it (same DN? same schedule family?) and reports:
     *   - mismatch:  a linked flange/fitting whose size ≠ the pipe's size
     *   - missing:   a pipe with no flange linked → suggests matching flanges
     *                from the library the user could add
     *
     * Returns: {
     *   issues:      [{ type: 'mismatch'|'missing', pipe, pipe_dn, child, child_dn, message }]
     *   suggestions: [{ pipe, pipe_dn, flanges: [{ id, name, dn, rating }] }]
     *   ok: bool
     * }
     */
    public function handle_compat($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        if (!$quoteId) return ['error' => 'quote_id is required.'];
        $quote = $this->getEntity($quoteId);
        if (!$quote || $quote['type'] !== 'quote') {
            return ['error' => 'Quote not found.', 'error_code' => 404];
        }

        // Entities + links (same loading pattern as handle_takeoff)
        $res = $this->pgCrud->read([
            'table' => 'entity',
            'where' => 'quote_id = $1 AND user_id_owner = $2 AND is_active = TRUE',
            'params' => [$quoteId, $this->effOwnerId()],
        ]);
        $entities = $res['data'] ?? [];
        $byId = [];
        foreach ($entities as $e) $byId[$e['id']] = $e;
        $ids = array_keys($byId);
        if (!$ids) return ['issues' => [], 'suggestions' => [], 'ok' => true];

        $matRes = $this->pgCrud->read([
            'table' => 'component',
            'where' => 'entity_id = ANY($1::uuid[]) AND type = $2 AND user_id_owner = $3',
            'params' => ['{' . implode(',', $ids) . '}', 'material', $this->effOwnerId()],
        ]);
        $mats = [];
        foreach (($matRes['data'] ?? []) as $c) $mats[$c['entity_id']] = $c['data'] ?? [];

        $libIds = [];
        foreach ($mats as $m) {
            if (!empty($m['materialLibraryId'])) $libIds[$m['materialLibraryId']] = true;
        }
        // Materials are entities — batch-read the referenced material entities
        // and reconstruct the legacy row shape for takeOffLine.
        $libRows = $this->materialEntitiesByIds(array_keys($libIds));

        $linkRes = $this->pgCrud->read([
            'table' => 'link',
            'where' => 'type = $1 AND user_id_owner = $2 AND (from_id = ANY($3::uuid[]) OR to_id = ANY($3::uuid[]))',
            'params' => ['contains', $this->effOwnerId(), '{' . implode(',', array_merge($ids, [$quoteId])) . '}'],
        ]);
        $children = [];   // parentId → [childIds]
        $parentOf = [];   // childId → parentId
        foreach (($linkRes['data'] ?? []) as $l) {
            $children[$l['from_id']][] = $l['to_id'];
            $parentOf[$l['to_id']] = $l['from_id'];
        }

        $issues = [];
        $suggestions = [];

        foreach ($entities as $e) {
            $mat = $mats[$e['id']] ?? null;
            if (!$mat) continue;
            $lib = $libRows[$mat['materialLibraryId'] ?? ''] ?? null;
            $profile = strtolower((string)($lib['profile'] ?? ''));
            $libData = is_array($lib['data'] ?? null) ? $lib['data'] : [];
            if ($profile !== 'pipe') continue;   // only inspect pipes

            $pipeDn = $libData['dn'] ?? $libData['nb'] ?? $lib['nb'] ?? null;
            if ($pipeDn === null) {
                // Some seeded pipes (e.g. "S235JR Pipe DN100") carry no data.dn
                // — parse the nominal size from the name.
                if (preg_match('/DN\s*(\d+)/i', $lib['name'] ?? $e['name'], $mm)) {
                    $pipeDn = (int)$mm[1];
                }
            }
            $pipeLabel = $lib['name'] ?? $e['name'];
            $pipeDnLabel = $pipeDn !== null ? 'DN' . $pipeDn : '?DN';

            $childFlanges = [];   // direct-child flange names (nested BOM)
            $childIssues = [];
            $siblingFlangeDns = [];  // flange DNs among siblings (flat BOM)
            $parentId = $parentOf[$e['id']] ?? null;

            // 1) DIRECT children: pipe → flange/fitting (nested spool). A
            //    mismatched direct child is a real error.
            foreach (($children[$e['id']] ?? []) as $childId) {
                $childMat = $mats[$childId] ?? null;
                if (!$childMat) continue;
                $childLib = $libRows[$childMat['materialLibraryId'] ?? ''] ?? null;
                if (!$childLib) continue;
                $childCat = strtolower((string)($childLib['library_category'] ?? ''));
                if ($childCat !== 'flange' && $childCat !== 'fitting') continue;
                $childData = is_array($childLib['data'] ?? null) ? $childLib['data'] : [];
                $childDn = $childData['dn'] ?? $childData['pipeOd'] ?? ($childData['endNb'][0] ?? null);
                if ($childDn === null && preg_match('/DN\s*(\d+)/i', $childLib['name'] ?? '', $mm)) {
                    $childDn = (int)$mm[1];
                }
                $childLabel = $childLib['name'] ?? $byId[$childId]['name'] ?? 'item';
                if ($childCat === 'flange') $childFlanges[] = $childLabel;

                if ($pipeDn !== null && $childDn !== null && (int)$pipeDn !== (int)$childDn) {
                    $childIssues[] = [
                        'type' => 'mismatch',
                        'pipe' => $e['name'],
                        'pipe_dn' => $pipeDnLabel,
                        'child' => $byId[$childId]['name'] ?? $childLabel,
                        'child_dn' => 'DN' . $childDn,
                        'child_cat' => $childCat,
                        'message' => $pipeLabel . ' (' . $pipeDnLabel . ') has a ' . $childCat . ' ' . ($byId[$childId]['name'] ?? '') . ' at DN' . $childDn . ' nested under it — sizes don\'t match.',
                    ];
                }
            }

            // 2) SIBLINGS (flat BOM: pipe, flange, tee all under one assembly).
            //    Don't flag every cross-size pairing — only check whether a
            //    same-size flange exists in the assembly, and report a MISSING
            //    flange suggestion when there is none.
            if ($parentId) {
                foreach (($children[$parentId] ?? []) as $sibId) {
                    if ($sibId === $e['id']) continue;
                    $sibMat = $mats[$sibId] ?? null;
                    if (!$sibMat) continue;
                    $sibLib = $libRows[$sibMat['materialLibraryId'] ?? ''] ?? null;
                    if (!$sibLib) continue;
                    $sibCat = strtolower((string)($sibLib['library_category'] ?? ''));
                    if ($sibCat !== 'flange') continue;
                    $sibData = is_array($sibLib['data'] ?? null) ? $sibLib['data'] : [];
                    $sibDn = $sibData['dn'] ?? $sibData['pipeOd'] ?? null;
                    if ($sibDn !== null) $siblingFlangeDns[] = (int)$sibDn;
                }
            }
            $issues = array_merge($issues, $childIssues);

            // Pipe with NO matching-size flange (direct child or sibling) →
            // suggest matching flanges from the library.
            $hasMatchingFlange = $pipeDn !== null
                && (in_array((int)$pipeDn, $siblingFlangeDns, true)
                    || !empty($childFlanges));
            if (!$hasMatchingFlange && $pipeDn !== null) {
                $flanges = $this->suggestFlanges($pipeDn);
                if ($flanges) {
                    $suggestions[] = [
                        'pipe' => $e['name'],
                        'pipe_dn' => $pipeDnLabel,
                        'flanges' => $flanges,
                        'message' => $pipeLabel . ' (' . $pipeDnLabel . ') has no matching-size flange in its assembly — add one to connect it.',
                    ];
                }
            }
        }

        // ── Orphan flanges: a flange with no same-size pipe in the quote ──
        // (e.g. DN500 blind flanges but no DN500 pipe). Helps catch spec'd
        // flanges that don't connect to anything.
        $pipeSizes = [];
        foreach ($entities as $e) {
            $m = $mats[$e['id']] ?? null;
            if (!$m) continue;
            $l = $libRows[$m['materialLibraryId'] ?? ''] ?? null;
            if (!$l) continue;
            if (strtolower((string)($l['profile'] ?? '')) !== 'pipe') continue;
            $ld = is_array($l['data'] ?? null) ? $l['data'] : [];
            $dn = $ld['dn'] ?? $ld['nb'] ?? $l['nb'] ?? null;
            if ($dn === null && preg_match('/DN\s*(\d+)/i', $l['name'] ?? '', $mm)) $dn = (int)$mm[1];
            if ($dn !== null) $pipeSizes[(int)$dn] = true;
        }
        $seenOrphan = [];
        foreach ($entities as $e) {
            $m = $mats[$e['id']] ?? null;
            if (!$m) continue;
            $l = $libRows[$m['materialLibraryId'] ?? ''] ?? null;
            if (!$l) continue;
            if (strtolower((string)($l['library_category'] ?? '')) !== 'flange') continue;
            $ld = is_array($l['data'] ?? null) ? $l['data'] : [];
            $dn = $ld['dn'] ?? $ld['pipeOd'] ?? null;
            if ($dn !== null && !isset($pipeSizes[(int)$dn])) {
                $key = (int)$dn . '|' . $l['name'];
                if (isset($seenOrphan[$key])) continue;
                $seenOrphan[$key] = true;
                $issues[] = [
                    'type' => 'orphan-flange',
                    'pipe' => $e['name'],
                    'pipe_dn' => 'DN' . $dn,
                    'child' => $e['name'],
                    'child_dn' => 'DN' . $dn,
                    'child_cat' => 'flange',
                    'message' => ($l['name'] ?? $e['name']) . ' (DN' . $dn . ') has no ' . 'DN' . $dn . ' pipe in this quote — check it connects to something.',
                ];
            }
        }

        return [
            'issues' => $issues,
            'suggestions' => $suggestions,
            'ok' => empty($issues),
        ];
    }

    /**
     * Library flanges matching a DN (limited suggestions for the UI).
     */
    private function suggestFlanges($dn)
    {
        // Materials are entities — flange specs live in the specification
        // component; batch-load the matched entities as legacy shapes.
        $res = $this->pgCrud->read([
            'table' => 'component',
            'where' => "type = 'specification' AND data->>'library_category' = \$1 AND data->>'dn' = \$2",
            'params' => ['flange', (string)$dn],
            'limit' => 4,
        ]);
        $out = [];
        if ($res['data'] ?? []) {
            $libRows = $this->materialEntitiesByIds(array_column($res['data'], 'entity_id'));
            foreach ($res['data'] as $c) {
                $f = $libRows[$c['entity_id']] ?? null;
                if (!$f) continue;
                $d = is_array($f['data'] ?? null) ? $f['data'] : [];
                $out[] = [
                    'id' => $f['id'],
                    'name' => $f['name'],
                    'dn' => $d['dn'] ?? null,
                    'rating' => $d['rating'] ?? $d['type'] ?? '',
                ];
            }
        }
        return $out;
    }

    /**
     * Material take-off for supplier costing.
     *
     * Walks the quote's BOM tree and aggregates every material into a flat,
     * consolidated list a supplier can price from: each unique material +
     * size, with the TOTAL quantity needed (kg / m / ea — multiplied through
     * all nested assembly quantities), unit cost, and extended cost.
     *
     * Uses the same per-unit × link-quantity model as systems.computeAssemblyRollups:
     *   leafPerUnit = leafOwn / leafEntityQty      (cost.php already × entity qty)
     *   takeOffQty  = leafPerUnit × ∏ link quantities from quote root to leaf
     * (the leaf's entity qty is redundant with its incoming link qty, so we
     *  use the link-product only — matches the cost rollup exactly.)
     *
     * Input: { quote_id }
     * Returns: { materials: [{ material_id, name, grade, profile, category,
     *   dims, unit, qty, unit_cost, extended_cost, qty_kg, qty_m, qty_ea,
     *   items: [{ item, qty, item_number }] }], totals: { total_mass_kg,
     *   total_cost, distinct } }
     */
    public function handle_takeoff($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        if (!$quoteId) return ['error' => 'quote_id is required.'];

        $quote = $this->getEntity($quoteId);
        if (!$quote || $quote['type'] !== 'quote') {
            return ['error' => 'Quote not found.', 'error_code' => 404];
        }

        // 1. All entities in the quote (id → row)
        $res = $this->pgCrud->read([
            'table' => 'entity',
            'where' => 'quote_id = $1 AND user_id_owner = $2 AND is_active = TRUE',
            'params' => [$quoteId, $this->effOwnerId()],
        ]);
        $entities = $res['data'] ?? [];
        $byId = [];
        foreach ($entities as $e) $byId[$e['id']] = $e;
        $ids = array_keys($byId);
        if (!$ids) {
            return ['materials' => [], 'totals' => ['total_mass_kg' => 0, 'total_cost' => 0, 'distinct' => 0], 'error' => 'Quote has no items.'];
        }

        // 2. Material + cost components for all entities (one query each)
        $matRes = $this->pgCrud->read([
            'table' => 'component',
            'where' => 'entity_id = ANY($1::uuid[]) AND type = $2 AND user_id_owner = $3',
            'params' => ['{' . implode(',', $ids) . '}', 'material', $this->effOwnerId()],
        ]);
        $mats = [];
        foreach (($matRes['data'] ?? []) as $c) $mats[$c['entity_id']] = $c['data'] ?? [];

        $costRes = $this->pgCrud->read([
            'table' => 'component',
            'where' => 'entity_id = ANY($1::uuid[]) AND type = $2 AND user_id_owner = $3',
            'params' => ['{' . implode(',', $ids) . '}', 'cost', $this->effOwnerId()],
        ]);
        $costs = [];
        foreach (($costRes['data'] ?? []) as $c) $costs[$c['entity_id']] = $c['data'] ?? [];

        // 3. contains links (from quote + among entities)
        $linkRes = $this->pgCrud->read([
            'table' => 'link',
            'where' => 'type = $1 AND user_id_owner = $2 AND (from_id = ANY($3::uuid[]) OR to_id = ANY($3::uuid[]))',
            'params' => ['contains', $this->effOwnerId(), '{' . implode(',', $ids) . ', ' . $quoteId . '}'],
        ]);
        $children = []; // parentId => [ [childId, qty] ]
        $childSet = [];
        foreach (($linkRes['data'] ?? []) as $l) {
            $children[$l['from_id']][] = [$l['to_id'], (float)($l['quantity'] ?? 1)];
            $childSet[$l['to_id']] = true;
        }

        // 4. Library lookup for material names/unit costs
        $libIds = [];
        foreach ($mats as $m) {
            if (!empty($m['materialLibraryId'])) $libIds[$m['materialLibraryId']] = true;
        }
        // Materials are entities — batch-read the referenced material entities
        // and reconstruct the legacy row shape for takeOffLine.
        $libRows = $this->materialEntitiesByIds(array_keys($libIds));

        // 5. DFS from the quote: multiplier = ∏ link quantities on path
        $lines = [];
        $visited = [];
        $walk = function ($nodeId, $multiplier) use (&$walk, &$visited, $children, $byId, $mats, $costs, $libRows, &$lines) {
            if (in_array($nodeId, $visited)) return;
            $visited[] = $nodeId;
            foreach (($children[$nodeId] ?? []) as [$childId, $qty]) {
                $child = $byId[$childId] ?? null;
                if (!$child) continue;
                // Multiplier = ∏(link qty × ENTITY qty) on the path — entity
                // quantity is the BoQ count (same semantics as the rollup);
                // links are structural.
                $childMultiplier = $multiplier * $qty * max((float)($child['quantity'] ?? 1), 1);
                // Emit a take-off line if this entity carries its own material
                if (!empty($mats[$childId])) {
                    $lines[] = $this->takeOffLine($child, $mats[$childId], $libRows, $childMultiplier);
                }
                $walk($childId, $childMultiplier);
            }
        };
        $walk($quoteId, 1.0);

        // 6. Group by material + size; sum quantities + costs
        $groups = [];
        foreach ($lines as $ln) {
            $key = $ln['group_key'];
            if (!isset($groups[$key])) {
                // seed the group with ZERO quantities — the first line's own
                // qty is added below like every subsequent line (avoids
                // double-counting the first occurrence).
                $g = [
                    'material_id' => $ln['material_id'],
                    'name' => $ln['name'],
                    'grade' => $ln['grade'],
                    'profile' => $ln['profile'],
                    'category' => $ln['category'],
                    'group' => $ln['group'],
                    'dims' => $ln['dims'],
                    'unit' => $ln['unit'],
                    'qty_kg' => 0.0,
                    'qty_m' => 0.0,
                    'qty_ea' => 0,
                    'unit_cost' => $ln['unit_cost'],
                    'extended_cost' => 0.0,
                    'items' => [],
                ];
                $groups[$key] = $g;
            }
            $g = &$groups[$key];
            $g['qty_kg'] = round($g['qty_kg'] + $ln['qty_kg'], 3);
            $g['qty_m'] = round($g['qty_m'] + $ln['qty_m'], 3);
            $g['qty_ea'] += $ln['qty_ea'];
            $g['extended_cost'] = round($g['extended_cost'] + $ln['extended_cost'], 2);
            $g['items'][] = ['item' => $ln['item_name'], 'qty' => $ln['qty_ea'] ?: ($ln['qty_m'] ?: $ln['qty_kg']), 'item_id' => $ln['item_id'] ?? null];
            unset($g);
        }

        // Restore display fields + sort by supplier GROUP then cost desc
        $materials = array_values($groups);
        foreach ($materials as &$m) {
            if ($m['unit'] === 'kg') $m['qty'] = $m['qty_kg'];
            elseif ($m['unit'] === 'm') $m['qty'] = $m['qty_m'];
            else $m['qty'] = $m['qty_ea'];
            $m['qty'] = round((float)$m['qty'], 3);
        }
        unset($m);
        // Canonical group order (matches supplier specialities)
        $GROUP_ORDER = [
            'Plates & Sheets' => 0, 'Sections & Bars' => 1, 'Pipe' => 2, 'Tube' => 3,
            'Fittings' => 4, 'Flanges' => 5, 'Fasteners' => 6, 'Other' => 7,
        ];
        usort($materials, function ($a, $b) use ($GROUP_ORDER) {
            $ga = $GROUP_ORDER[$a['group']] ?? 99;
            $gb = $GROUP_ORDER[$b['group']] ?? 99;
            if ($ga !== $gb) return $ga <=> $gb;
            $ca = strcmp($a['category'], $b['category']);
            return $ca !== 0 ? $ca : ($b['extended_cost'] <=> $a['extended_cost']);
        });

        // Per-group subtotals (count, mass, cost) — suppliers price a group at
        // a glance and quote only the materials they can supply.
        $groupTotals = [];
        foreach ($materials as $m) {
            $g = $m['group'];
            if (!isset($groupTotals[$g])) $groupTotals[$g] = ['count' => 0, 'qty_kg' => 0.0, 'qty_m' => 0.0, 'qty_ea' => 0, 'cost' => 0.0];
            $groupTotals[$g]['count']++;
            $groupTotals[$g]['qty_kg'] += $m['qty_kg'];
            $groupTotals[$g]['qty_m'] += $m['qty_m'];
            $groupTotals[$g]['qty_ea'] += $m['qty_ea'];
            $groupTotals[$g]['cost'] += $m['extended_cost'];
        }
        foreach ($groupTotals as &$gt) {
            $gt['qty_kg'] = round($gt['qty_kg'], 3);
            $gt['qty_m'] = round($gt['qty_m'], 3);
            $gt['cost'] = round($gt['cost'], 2);
        }
        unset($gt);
        // ordered list for the UI
        $groupsList = [];
        foreach ($GROUP_ORDER as $name => $_) {
            if (isset($groupTotals[$name])) $groupsList[$name] = $groupTotals[$name];
        }
        foreach ($groupTotals as $name => $gt) {
            if (!isset($groupsList[$name])) $groupsList[$name] = $gt;
        }

        $totals = [
            'total_mass_kg' => array_sum(array_column($materials, 'qty_kg')),
            'total_cost' => round(array_sum(array_column($materials, 'extended_cost')), 2),
            'distinct' => count($materials),
            'groups' => $groupsList,
        ];

        return ['materials' => $materials, 'totals' => $totals];
    }

    // ── Internal ───────────────────────────────────────

    /**
     * Supplier-facing group for a material — lets a supplier see at a glance
     * which materials they can/can't supply (fastener houses, pipe merchants,
     * plate suppliers, etc.). Derived from library_category + profile.
     */
    private function takeOffGroup($libCat, $profile)
    {
        $cat = strtolower((string)$libCat);
        $prof = strtolower((string)$profile);
        if ($cat === 'fastener') return 'Fasteners';
        if ($cat === 'flange') return 'Flanges';
        if ($cat === 'fitting') return 'Fittings';
        if ($cat === 'material' || $cat === '' ) {
            if (str_contains($prof, 'plate') || str_contains($prof, 'sheet')) return 'Plates & Sheets';
            if (str_contains($prof, 'pipe')) return 'Pipe';
            if (str_contains($prof, 'tube')) return 'Tube';
            if (in_array($prof, ['angle', 'channel', 'i-beam', 'h-beam', 'flat bar', 'round bar', 'square bar', 'section', 'bar'], true)) return 'Sections & Bars';
            return 'Other';
        }
        return 'Other';
    }

    /**
     * Build one take-off line for an entity that carries a material component.
     * Computed DETERMINISTICALLY from the material component + library row
     * (mirrors cost.php's L1 math) — NOT from the persisted cost component,
     * which can be stale/inconsistent across recomputes.
     *
     * qty_kg / qty_m / qty_ea are the TOTAL quantities × multiplier; the
     * multiplier is the ∏ of link quantities from the quote root to this leaf.
     */
    private function takeOffLine($entity, $matData, $libRows, $multiplier)
    {
        $lib = $libRows[$matData['materialLibraryId'] ?? ''] ?? null;
        $libData = is_array($lib['data'] ?? null) ? $lib['data'] : [];
        $cat = strtolower((string)($lib['library_category'] ?? $matData['category'] ?? 'material'));
        $profile = strtolower((string)($lib['profile'] ?? ''));
        $density = (float)($lib['density'] ?? $matData['density'] ?? 0);
        $len = (float)($matData['length'] ?? 0)
             + (float)($matData['length_secondary'] ?? 0); // D1 green — extra length must be procured too
        $wid = (float)($matData['width'] ?? 0);
        $thk = (float)($matData['thickness'] ?? $lib['thickness'] ?? 0);
        $lengthM = $len / 1000;

        // ── Per-unit mass (kg) — same rules as cost.php calcMass ──
        $massPerUnit = 0.0;
        if (!empty($matData['mass'])) {
            $massPerUnit = (float)$matData['mass'];
        } elseif ((float)($lib['mass_per_meter'] ?? 0) > 0 && $len > 0) {
            $massPerUnit = (float)$lib['mass_per_meter'] * $lengthM;   // sections/pipes: kg/m × m
        } elseif ($len > 0 && $wid > 0 && $thk > 0 && $density > 0) {
            $massPerUnit = $len * $wid * $thk / 1e9 * $density;        // plate/sheet: L×W×T × ρ
        } elseif (isset($libData['massKg'])) {
            $massPerUnit = (float)$libData['massKg'];                  // fittings/flanges: per item
        }

        // ── Library / override unit prices ──
        $libUnitCost = (float)($lib['unit_cost'] ?? 0);                // per kg (mass items) or per ea (fasteners)
        $costPerM = (float)($matData['costPerM'] ?? 0);                // pipe: R/m override
        $costPerEa = (float)($matData['costPerEa'] ?? 0);              // fitting/flange/fastener: R/ea override
        $unitCostOverride = (float)($matData['unitCost'] ?? 0);        // generic per-kg override

        // ── Unit + per-unit price by category ──
        $qtyM = 0.0;
        $perUnitCost = 0.0;   // price per display unit (kg / m / ea)
        if ($cat === 'fastener') {
            $unit = 'ea';
            $perUnitCost = $costPerEa > 0 ? $costPerEa : $libUnitCost;
        } elseif ($cat === 'fitting' || $cat === 'flange') {
            $unit = 'ea';
            $perUnitCost = $costPerEa > 0 ? $costPerEa : ($libUnitCost > 0 ? $libUnitCost : $massPerUnit * 3.2);
        } elseif ($cat === 'material' && ($profile === 'pipe' || $profile === 'tube')) {
            $unit = 'm';
            $qtyM = $lengthM;
            $perUnitCost = $costPerM > 0 ? $costPerM : ($massPerUnit > 0 ? $massPerUnit / $lengthM * ($unitCostOverride > 0 ? $unitCostOverride : $libUnitCost) : 0);
        } else {
            $unit = 'kg';
            $perUnitCost = $unitCostOverride > 0 ? $unitCostOverride : $libUnitCost;
        }

        // ── Total quantities × multiplier ──
        $qtyKg = $massPerUnit * $multiplier;
        $qtyM = $qtyM * $multiplier;
        $qtyEa = ($unit === 'ea') ? round($multiplier, 0) : 0;
        $extended = $perUnitCost * ($unit === 'kg' ? $qtyKg : ($unit === 'm' ? $qtyM : $qtyEa));

        // Size key, not cut-length key: the takeoff is a supplier RFQ — one
        // line per material+size with summed quantities.
        //   pipes:     DN + schedule (the length is a cut length, not a size)
        //   flanges:   DN + type (SO/LOOSE/WN — loose + welded share a library
        //              row, so the material comp's weldType disambiguates)
        //   others:    length×width×thickness as before (real size variants)
        $dims = [];
        if ($profile === 'pipe' || $profile === 'tube') {
            if (isset($libData['dn']) && $libData['dn'] !== '') $dims[] = 'DN' . $libData['dn'];
            $sched = $libData['schedule'] ?? '';
            if ($sched !== '') $dims[] = (string)$sched;
        } elseif ($cat === 'fitting' || $cat === 'flange') {
            if (isset($libData['dn']) && $libData['dn'] !== '') $dims[] = 'DN' . $libData['dn'];
            $ftype = (string)($matData['weldType'] ?? $libData['type'] ?? '');
            if ($ftype !== '') $dims[] = strtoupper($ftype);
        } else {
            foreach (['length', 'width', 'thickness'] as $d) {
                $v = $matData[$d] ?? null;
                if ($v !== null && $v !== '') $dims[] = (string)$v;
            }
        }
        // Group key: material id + size (same material same size = one line)
        $groupKey = ($matData['materialLibraryId'] ?? 'none') . '|' . implode('x', $dims);

        return [
            'group_key' => $groupKey,
            'material_id' => $matData['materialLibraryId'] ?? null,
            'name' => $lib['name'] ?? ($matData['category'] ?? 'Material'),
            'grade' => $lib['grade'] ?? '',
            'profile' => $lib['profile'] ?? '',
            'category' => $lib['library_category'] ?? ($matData['category'] ?? ''),
            'group' => $this->takeOffGroup($lib['library_category'] ?? null, $lib['profile'] ?? ''),
            'dims' => implode('×', $dims),
            'unit' => $unit,
            'qty_kg' => round($qtyKg, 3),
            'qty_m' => round($qtyM, 3),
            'qty_ea' => $qtyEa,
            'unit_cost' => round($perUnitCost, 2),
            'extended_cost' => round($extended, 2),
            'item_id' => $entity['id'],
            'item_name' => $entity['name'],
        ];
    }
    private function detectEntityType($description)
    {
        // Single source of truth in _base.php (assembly words + full bought-in
        // hardware / fastener set, incl. cplg half-couplings).
        return $this->classifyEntityType($description);
    }

    private function detectCategory($description, $material)
    {
        $p = strtolower(($material ?: '') . ' ' . ($description ?: ''));
        if (preg_match('/(plate|sheet)/', $p)) return 'plate';
        if (preg_match('/(angle|channel|i-beam|h-beam|flat bar|section|beam)/', $p)) return 'section';
        if (preg_match('/\bpipe\b/', $p)) return 'pipe';
        if (preg_match('/(chs|shs|rhs|tube)/', $p)) return 'tube';
        if (preg_match('/(elbow|tee|reducer|flange|cap|coupling|fitting)/', $p)) return 'fitting';
        if (preg_match('/(bolt|nut|washer|screw|stud|fastener)/', $p)) return 'fastener';
        return 'other';
    }

    /**
     * Resolve a material description to a library id via match scoring.
     *
     * Smarter matching:
     *  1. If the description contains a fitting/flange keyword (elbow, tee,
     *     reducer, flange, cap, coupling) but the raw material column is
     *     generic or a pipe/plate, search the library by the ITEM keyword +
     *     size (e.g. "Elbow DN100") so we land on a FITTING/FLANGE row, not
     *     a pipe.
     *  2. Otherwise fall back to the plain match scoring.
     */
    private function matchMaterial($search, $description = '')
    {
        $materialsApi = new \api\materials();
        $materialsApi->user_id = $this->effOwnerId();

        $desc = strtolower(trim((string)$description));
        $mat = strtolower(trim((string)$search));

        // 0.5) Exact hint match — if the material column names a library row
        //      exactly (valves, gaskets, weldolets, u-bolts, couplings added by
        //      the BoQ importer), use it directly. Runs BEFORE the smart
        //      branches so a valve desc containing "flanged" doesn't land on
        //      an ANSI flange.
        if ($mat) {
            $res = $materialsApi->handle_list(['search' => $mat, 'limit' => 10]);
            if (!isset($res['error']) && !empty($res)) {
                foreach ($res as $r) {
                    if (strcasecmp(trim((string)($r['name'] ?? '')), trim((string)$search)) === 0) {
                        return $r['id'];
                    }
                }
            }
        }

        // Fitting / flange keyword in the description?
        $isFitting = (bool)preg_match('/(elbow|tee|reducer|cap|coupling|union|bushing|nipple)/', $desc);
        $isFlange = (bool)preg_match('/(flange|blind)/', $desc);
        // Segmented bends are FABRICATED FROM PIPE — price them as the pipe
        $isPipe = (bool)preg_match('/(pipe|spool|tube|run|bend)/', $desc);
        $isFastener = (bool)preg_match('/(bolt|nut|washer|screw|stud|fastener|rivet)/', $desc);

        // Pull a size token — prefer explicit DN (desc or material col), then a
        // bare size in the material column (avoids grabbing spool lengths like
        // 6000 from the description).
        $size = null;
        $haystack = $desc . ' ' . $mat;
        if (preg_match('/DN\s*(\d{2,4})/i', $haystack, $m)) {
            $size = $m[1];
        } elseif (preg_match('/(?:^|\s)(\d{2,4})(?:\s*(?:mm|\"))?(?:\s|$)/', $mat, $m)) {
            $size = $m[1];
        }

        // 0) Fasteners: search by size + type (M12 + bolt/nut/washer).
        //    The library stores e.g. "M12 x 40 Hex Bolt"; a client CSV often
        //    says just "M12 bolt" — so search by the SIZE token, then filter
        //    the candidates by the TYPE keyword.
        if ($isFastener) {
            // Detect the PRIMARY fastener type — check stud/bolt FIRST (a stud
            // bolt desc also mentions "A 194 Gr 2H NUTS").
            $d2 = strtolower($desc);
            $typeWord = null;
            if (preg_match('/stud/i', $d2)) $typeWord = 'stud';
            elseif (preg_match('/bolt/i', $d2)) $typeWord = 'bolt';
            elseif (preg_match('/screw/i', $d2)) $typeWord = 'screw';
            elseif (preg_match('/nut/i', $d2)) $typeWord = 'nut';
            elseif (preg_match('/washer/i', $d2)) $typeWord = 'washer';
            else $typeWord = 'bolt';
            $sizeToken = null;
            if (preg_match('/\bM(\d+)\b/i', $mat . ' ' . $desc, $m)) {
                $sizeToken = 'M' . $m[1];
            } elseif (preg_match('/(\d{2,4})\s*(?:mm)?/i', $desc, $m)) {
                $sizeToken = 'M' . $m[1];
            }
            $res = $sizeToken
                ? $materialsApi->handle_list(['search' => $sizeToken, 'library_category' => 'fastener', 'limit' => 25])
                : $materialsApi->handle_list(['search' => $mat, 'library_category' => 'fastener', 'limit' => 25]);
            if (!isset($res['error']) && !empty($res)) {
                foreach ($res as $r) {
                    if (stripos($r['name'] ?? '', $typeWord) !== false) return $r['id'];
                }
                // no stud rows in library → any bolt of that size
                foreach ($res as $r) {
                    if (stripos($r['name'] ?? '', 'bolt') !== false) return $r['id'];
                }
                return $res[0]['id'];
            }
        }

        // 1) Fitting/flange items: search by keyword + size against the
        //    library's fitting/flange rows (library_category filters).
        if (($isFitting || $isFlange) && $size) {
            $keyword = $isFlange ? 'flange' : (preg_match('/tee/', $desc) ? 'tee' : (preg_match('/reducer/', $desc) ? 'reducer' : (preg_match('/cap/', $desc) ? 'cap' : 'elbow')));
            $needle = ucfirst($keyword) . ' DN' . $size;
            $libCat = $isFlange ? 'flange' : 'fitting';

            $res = $materialsApi->handle_list(['search' => $needle, 'library_category' => $libCat, 'limit' => 15]);
            if (!isset($res['error']) && !empty($res)) {
                // Prefer an exact NB size match (DN100 → nb=100) over a
                // name-prefix match that could land on DN1000.
                $exact = null;
                foreach ($res as $r) {
                    if (isset($r['nb']) && (string)$r['nb'] === (string)$size) { $exact = $r; break; }
                }
                // Flange style/rating preference from the description
                $wantWN = (bool)preg_match('/\bWN\b|WELD NECK/i', $desc . ' ' . $mat);
                $wantBlind = (bool)preg_match('/BLIND/i', $desc);
                $want600 = (bool)preg_match('/CL\s*600|CLASS\s*600|600\s*lb/i', $desc . ' ' . $mat);
                if ($exact) {
                    // among same-nb rows, prefer the style/rating that matches
                    $cands = [];
                    foreach ($res as $r) {
                        if (isset($r['nb']) && (string)$r['nb'] === (string)$size) $cands[] = $r;
                    }
                    foreach ($cands as $r) {
                        $n = $r['name'] ?? '';
                        if ($wantWN && $want600 && stripos($n, 'WN') !== false && stripos($n, '600') !== false) return $r['id'];
                    }
                    foreach ($cands as $r) {
                        if ($want600 && stripos($r['name'] ?? '', '600') !== false) return $r['id'];
                    }
                    foreach ($cands as $r) {
                        if ($wantWN && stripos($r['name'] ?? '', 'WN') !== false) return $r['id'];
                    }
                    foreach ($cands as $r) {
                        if ($wantBlind && stripos($r['name'] ?? '', 'BLIND') !== false) return $r['id'];
                    }
                    return $exact['id'];
                }
                // Fall back: exact DN<size> in name (space or end boundary)
                foreach ($res as $r) {
                    if (preg_match('/DN' . preg_quote($size) . '(\s|$)/i', $r['name'] ?? '')) return $r['id'];
                }
                return $res[0]['id'];
            }
        }

        // 2) Pipe items: prefer a pipe row when the description says pipe/spool
        if ($isPipe && $size) {
            // Library names use "DN 100" (with space) — search with the space
            // and filter to the exact size (avoid DN1000 matching DN100).
            $res = $materialsApi->handle_list(['search' => 'DN ' . $size, 'library_category' => 'material', 'limit' => 20]);
            if (isset($res['error'])) $res = [];
            $cands = [];
            foreach ($res as $r) {
                if ((isset($r['nb']) && (string)$r['nb'] === (string)$size)
                    || preg_match('/DN\s*' . preg_quote($size) . '(\s|$)/i', $r['name'] ?? '')) {
                    $cands[] = $r;
                }
            }
            if (!empty($cands)) {
                // Prefer exact matches from the material hint: schedule (#80),
                // wall (MED/HEAVY), then grade (A106 / SANS 62 / SANS 719).
                $schedHint = preg_match('/#(\d+)/i', $mat, $m) ? '#' . $m[1]
                    : (preg_match('/\bSTD\b/i', $mat) ? 'STD'
                    : (preg_match('/\bXS\b/i', $mat) ? 'XS' : ''));
                $wallHint = (preg_match('/SANS\s*62/i', $mat) && preg_match('/MED/i', $mat)) ? 'MED' : '';
                $gradeHint = preg_match('/A106B/i', $mat) ? 'A106'
                    : (preg_match('/SANS 62/i', $mat) ? 'SANS 62'
                    : (preg_match('/SANS 719/i', $mat) ? 'SANS 719' : ''));
                $prefs = [$schedHint, $wallHint, $gradeHint];
                foreach ($prefs as $pref) {
                    if ($pref === '') continue;
                    foreach ($cands as $r) {
                        if (stripos($r['name'] ?? '', $pref) !== false
                            && str_contains(strtolower($r['profile'] ?? ''), 'pipe')) return $r['id'];
                    }
                }
                foreach ($cands as $r) {
                    if (str_contains(strtolower($r['profile'] ?? ''), 'pipe')) return $r['id'];
                }
                return $cands[0]['id'];
            }
        }

        // 3) Fall back to plain match scoring
        $res = $materialsApi->handle_match(['search' => $search]);
        if (isset($res['error']) || empty($res)) return null;
        $top = $res[0];
        return ($top['match_score'] ?? 0) >= 0.3 ? $top['id'] : null;
    }

    /**
     * Build contains-links from item-number hierarchy (1 ← 1.1 ← 1.1.2).
     */
    private function linkHierarchy($entities, $quoteId)
    {
        foreach ($entities as $e) {
            $item = $e['item_number'];
            if (!$item) continue;
            $parts = explode('.', (string)$item);
            // Top-level items ("1", "2") attach to the QUOTE itself — this is
            // what makes links.tree from the quote return the full hierarchy.
            if (count($parts) <= 1) {
                $this->pgCrud->save([
                    'table' => 'link',
                    'data' => [
                        'from_id' => $quoteId,
                        'to_id' => $e['id'],
                        'type' => 'contains',
                        'quantity' => $e['quantity'],
                        'user_id_owner' => $this->effOwnerId(),
                    ],
                ]);
                continue;
            }
            $parentItem = implode('.', array_slice($parts, 0, -1));

            // Find parent by item number
            $parentId = null;
            foreach ($entities as $other) {
                if ($other['item_number'] === $parentItem) { $parentId = $other['id']; break; }
            }
            if (!$parentId) continue;

            $this->pgCrud->save([
                'table' => 'link',
                'data' => [
                    'from_id' => $parentId,
                    'to_id' => $e['id'],
                    'type' => 'contains',
                    'quantity' => $e['quantity'],
                    'user_id_owner' => $this->effOwnerId(),
                ],
            ]);
        }
    }

    /**
     * Build a short, readable display name from a (possibly long) BoQ row
     * description. Trims whitespace/trailing commas and caps at MAX_NAME_LENGTH,
     * breaking on a sentence boundary when possible so we don't chop mid-word.
     */
    private function shortName($text)
    {
        $text = trim((string)$text);
        // Normalize repeated spaces and trailing punctuation
        $text = preg_replace('/\s+/', ' ', $text);
        $text = rtrim($text, " \t\n\r\0\x0B,");

        if (mb_strlen($text) <= self::MAX_NAME_LENGTH) {
            return $text ?: 'Item';
        }

        // Prefer cutting at a comma (BoQ descriptions are comma-separated
        // attributes) so the name keeps whole attributes, not a chopped word.
        $cut = mb_substr($text, 0, self::MAX_NAME_LENGTH);
        $lastComma = mb_strrpos($cut, ',');
        if ($lastComma && $lastComma > (self::MAX_NAME_LENGTH / 2)) {
            $cut = mb_substr($cut, 0, $lastComma);
        } else {
            $space = mb_strrpos($cut, ' ');
            if ($space && $space > (self::MAX_NAME_LENGTH / 2)) $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut ?: mb_substr($text, 0, self::MAX_NAME_LENGTH), " \t,") ;
    }
}

\api\dispatchIfEntry(__FILE__);
