<?php
/**
 * fabricate_forge/api/cost.php
 *
 * CostSystem — the 5-layer cost engine, implemented as a pure ECS system.
 *
 * ECS contract: READ components → COMPUTE → WRITE a 'cost' component.
 * The system never stores cost outside the entity's own component graph, and
 * the UI never re-computes — it reads the written 'cost' component.
 *
 * 5-layer model (mirrors cost-system.js in the original app):
 *   L1 material   = massKg × unitCostPerKg × quantity
 *   L2 process    = Σ(hours × effectiveRate) × quantity
 *   L3 on-costs   = consumables + services + paint (× quantity)
 *   L4 logistics  = transport (once per entity)
 *   L5 margin     = subtotal × marginPercent%
 *
 * Rates: entity rate component → company defaults → GLOBAL_DEFAULT_RATES.
 */
namespace api;

include_once(__DIR__ . "/_base.php");
include_once(__DIR__ . "/rates.php");
include_once(__DIR__ . "/process.php");

class cost extends Base
{
    const DEFAULT_MARGIN_PERCENT = 30;

    protected function buildTable()
    {
        $this->ensureEcsTables();
    }

    // ── ECS read helpers (component accessors) ─────────

    /**
     * Extract the material component data for an entity (first match).
     */
    private function getMaterialData($entityId)
    {
        $comps = $this->getComponents($entityId, 'material');
        return $comps[0]['data'] ?? [];
    }

    /**
     * Extract all process component data for an entity (merged hours).
     */
    private function getProcessHours($entityId)
    {
        $comps = $this->getComponents($entityId, 'process');
        $hours = [];
        foreach ($comps as $c) {
            $hours = \api\process::mergeHours($hours, $c['data'] ?? []);
        }
        return $hours;
    }

    /**
     * Look up a material library row (global or own).
     */
    private function getLibraryMaterial($materialId)
    {
        if (!$materialId) return null;
        $res = $this->pgCrud->read([
            'table' => 'material_library',
            'where' => 'id = $1 AND (user_id_owner IS NULL OR user_id_owner = $2)',
            'params' => [$materialId, $this->user_id],
            'limit' => 1,
        ]);
        return $res['data'][0] ?? null;
    }

    /**
     * Compute mass (kg) from a material component:
     *   - profile (mass_per_meter × length / 1000)
     *   - plate/sheet (L×W×T / 1e9 × density)
     *   - explicit mass field wins
     */
    private function calcMass($materialData, $libraryItem)
    {
        if (!empty($materialData['mass'])) return (float)$materialData['mass'];

        $density = (float)($libraryItem['density'] ?? $materialData['density'] ?? 0);
        $length = (float)($materialData['length'] ?? 0);
        $width = (float)($materialData['width'] ?? 0);
        $thickness = (float)($materialData['thickness'] ?? $libraryItem['thickness'] ?? 0);
        $category = $materialData['category'] ?? ($libraryItem['library_category'] ?? '');

        // Section/profile: mass_per_meter × length / 1000 (mm → m)
        $massPerMeter = (float)($libraryItem['mass_per_meter'] ?? 0);
        if ($massPerMeter > 0 && $length > 0) {
            return $massPerMeter * $length / 1000;
        }
        // Plate: volume (m³) × density
        if ($category === 'plate' && $length > 0 && $width > 0 && $thickness > 0 && $density > 0) {
            return $length * $width * $thickness / 1e9 * $density;
        }
        // Density-only fallback (approx cylinder/bar)
        if ($density > 0 && $length > 0 && $width > 0) {
            return $length * $width * $thickness / 1e9 * $density;
        }
        return 0.0;
    }

    // ── ECS compute + write ────────────────────────────

    /**
     * Calculate entity cost: READ components → COMPUTE 5 layers →
     * WRITE 'cost' component → return it.
     *
     * Input: { entity_id, options?: { consumables, services, paint,
     *                                  transport, margin_percent } }
     */
    public function handle_calculate_entity($input = [])
    {
        $entityId = \getVal($input, 'entity_id');
        if (!$entityId) return ['error' => 'entity_id is required.'];
        $entity = $this->getEntity($entityId);
        if (!$entity) return ['error' => 'Entity not found.', 'error_code' => 404];

        $options = \getVal($input, 'options', []);
        $quantity = (float)($entity['quantity'] ?? 1);
        $marginPercent = (float)\getVal($options, 'margin_percent', self::DEFAULT_MARGIN_PERCENT);

        // READ: material component → library → mass
        $matData = $this->getMaterialData($entityId);
        $libraryItem = $this->getLibraryMaterial($matData['materialLibraryId'] ?? null);
        $massKg = $this->calcMass($matData, $libraryItem);
        $unitCostPerKg = (float)($libraryItem['unit_cost'] ?? 0);
        $materialTotal = $massKg * $unitCostPerKg * $quantity;

        // READ: process hours → price via rate hierarchy
        $hours = $this->getProcessHours($entityId);
        $rates = $this->getAllEffectiveRates($entityId);
        $processItems = \api\process::extractItems($hours); // $hours is a named-field map {trade: hrs}

        $perTrade = []; $processTotal = 0.0;
        foreach ($processItems as $it) {
            $rate = $rates[$it['name']]['rate'] ?? 0;
            $cost = round($it['time'] * $rate * $quantity, 2);
            $perTrade[$it['name']] = $cost;
            $processTotal += $cost;
        }

        // L3 on-costs (per unit × quantity)
        $consumables = (float)\getVal($options, 'consumables', 0) * $quantity;
        $services = (float)\getVal($options, 'services', 0) * $quantity;
        $paint = (float)\getVal($options, 'paint', 0) * $quantity;

        // L4 transport (once)
        $transport = (float)\getVal($options, 'transport', 0);

        // Subtotal + L5 margin
        $subtotal = $materialTotal + $processTotal + $consumables + $services + $paint + $transport;
        $margin = $subtotal * ($marginPercent / 100);
        $total = $subtotal + $margin;

        // The full cost component payload
        $costData = [
            'material' => self::r2($materialTotal),
            'massKg' => self::r2($massKg),
            'processTotal' => self::r2($processTotal),
            'boilermaking' => self::r2($perTrade['boilermaking'] ?? 0),
            'welding' => self::r2($perTrade['welding'] ?? 0),
            'machining' => self::r2($perTrade['machining'] ?? 0),
            'cutting' => self::r2($perTrade['cutting'] ?? 0),
            'drilling' => self::r2($perTrade['drilling'] ?? 0),
            'grinding' => self::r2($perTrade['grinding'] ?? 0),
            'bending' => self::r2($perTrade['bending'] ?? 0),
            'assembly' => self::r2($perTrade['assembly'] ?? 0),
            'consumables' => self::r2($consumables),
            'services' => self::r2($services),
            'paint' => self::r2($paint),
            'transport' => self::r2($transport),
            'margin' => self::r2($margin),
            'marginPercent' => $marginPercent,
            'subtotal' => self::r2($subtotal),
            'total' => self::r2($total),
            'unitCost' => self::r2($quantity ? $total / $quantity : 0),
            'currency' => $entity['data']['currency'] ?? 'USD',
            'lastUpdated' => date('c'),
            'details' => [
                'quantity' => $quantity,
                'materialUnit' => self::r2($unitCostPerKg),
                'materialMass' => self::r2($massKg),
            ],
        ];

        // WRITE: upsert the 'cost' component on the entity
        $compId = $this->upsertCostComponent($entityId, $costData);

        return ['component_id' => $compId, 'entity_id' => $entityId, 'data' => $costData];
    }

    /**
     * Assembly cost: own cost + recursive BOM rollup (children × quantity).
     * WRITE: child costs are their own cost components (computed recursively);
     * the assembly's cost component includes the rolled-up totals.
     */
    public function handle_calculate_assembly($input = [])
    {
        $entityId = \getVal($input, 'entity_id');
        if (!$entityId) return ['error' => 'entity_id is required.'];
        $maxDepth = (int)\getVal($input, 'depth', 10);

        $tree = $this->buildCostTree($entityId, 0, $maxDepth);

        // Roll up: each child's total × link quantity, summed at each level
        $this->rollUp($tree);

        return $tree;
    }

    /**
     * Batch: calculate cost for many entities in one call (kills N+1).
     * Input: { entity_ids: [...] } — writes cost components on each.
     */
    public function handle_batch_calculate($input = [])
    {
        $ids = \getVal($input, 'entity_ids', []);
        if (!is_array($ids) || empty($ids)) return ['error' => 'entity_ids (array) is required.'];

        $results = [];
        foreach ($ids as $id) {
            $r = $this->handle_calculate_entity(['entity_id' => $id]);
            if (isset($r['error'])) {
                $results[$id] = $r;
            } else {
                $results[$id] = $r['data'];
            }
        }
        return $results;
    }

    /**
     * Read the written cost component for an entity (no recompute).
     * Input: { entity_id }
     */
    public function handle_get_cost($input = [])
    {
        $entityId = \getVal($input, 'entity_id');
        if (!$entityId) return ['error' => 'entity_id is required.'];

        $comps = $this->getComponents($entityId, 'cost');
        if (!$comps) return ['error' => 'No cost component yet — run calculate_entity first.', 'error_code' => 404];
        return $comps[0]['data'];
    }

    // ── Internal ───────────────────────────────────────

    /**
     * Upsert the cost component: update if one exists, else create.
     */
    private function upsertCostComponent($entityId, $costData)
    {
        $comps = $this->getComponents($entityId, 'cost');
        if ($comps) {
            $this->patchComponentData($comps[0]['id'], $costData);
            return $comps[0]['id'];
        }
        $entity = $this->getEntity($entityId);
        $res = $this->pgCrud->save([
            'table' => 'component',
            'data' => [
                'entity_id' => $entityId,
                'type' => 'cost',
                'data' => $costData,
                'quote_id' => $entity['quote_id'] ?? null,
                'user_id_owner' => $this->user_id,
            ],
        ]);
        return $res['data']['id'] ?? null;
    }

    /**
     * Build a nested cost tree: each node = entity snapshot + its own cost.
     */
    private function buildCostTree($entityId, $depth, $maxDepth, $visited = [])
    {
        if ($depth > $maxDepth || in_array($entityId, $visited)) return null;
        $visited[] = $entityId;

        $entity = $this->getEntity($entityId);
        if (!$entity) return null;

        $own = $this->handle_calculate_entity(['entity_id' => $entityId]);
        $node = [
            'id' => $entity['id'],
            'name' => $entity['name'],
            'type' => $entity['type'],
            'quantity' => (float)($entity['quantity'] ?? 1),
            'own_cost' => $own['data'] ?? null,
            'children' => [],
        ];

        $links = $this->getLinks($entityId, 'contains');
        foreach ($links['out'] as $link) {
            $child = $this->buildCostTree($link['to_id'], $depth + 1, $maxDepth, $visited);
            if ($child) {
                $child['link_quantity'] = (float)($link['quantity'] ?? 1);
                $node['children'][] = $child;
            }
        }
        return $node;
    }

    /**
     * Post-order rollup: child total × link quantity accumulates into parent.
     */
    private function rollUp(&$node)
    {
        $rollup = 0.0;
        foreach ($node['children'] as &$child) {
            $this->rollUp($child);
            $childTotal = (float)($child['rolled_total'] ?? $child['own_cost']['total'] ?? 0);
            $rollup += $childTotal * (float)($child['link_quantity'] ?? 1);
        }
        $ownTotal = (float)($node['own_cost']['total'] ?? 0);
        $node['rolled_total'] = self::r2($ownTotal + $rollup);
        return $node;
    }

    private function getAllEffectiveRates($entityId)
    {
        $ratesApi = new \api\rates();
        $ratesApi->user_id = $this->user_id;
        return $ratesApi->handle_get_all_effective(['entity_id' => $entityId]);
    }

    /** Round to 2 decimals (mirrors round2 util). */
    public static function r2($n)
    {
        return round((float)$n, 2);
    }
}

\api\dispatchIfEntry(__FILE__);
