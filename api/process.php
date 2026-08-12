<?php
/**
 * fabricate_forge/api/process.php
 *
 * Process time tracking + pricing — the Layer-2 of the cost model.
 *
 * Process data lives in 'process' components:
 *   { data: { boilermaking: 4.5, welding: 2.0, ... } }  (named fields, hours)
 *   { data: { items: [{ name, time }, ...] } }          (legacy array format)
 *
 * This endpoint:
 *   - exposes the trade registry (names + defaults)
 *   - extracts hours from process components (both formats)
 *   - prices hours against the rate hierarchy → per-trade cost
 *   - aggregates hours across an entity's whole BOM (per quote)
 */
namespace api;

include_once(__DIR__ . "/_base.php");
include_once(__DIR__ . "/rates.php");
include_once(__DIR__ . "/components.php");

class process extends Base
{
    /** All process trades (mirrors TRADE_NAMES in the original app). */
    const TRADES = [
        'boilermaking','welding','machining','painting','assembly','qualityControl',
        'surfaceTreatment','cutting','drilling','grinding','bending',
    ];

    protected function buildTable()
    {
        $this->ensureEcsTables();
    }

    // ── Registry ───────────────────────────────────────

    /**
     * The process registry — trade names + global default rates, one call.
     */
    public function handle_get_registry($input = [])
    {
        return [
            'trades' => self::TRADES,
            'rates' => \api\rates::GLOBAL_DEFAULT_RATES,
        ];
    }

    // ── Extraction ─────────────────────────────────────

    /**
     * Extract hours from one process component (supports both formats).
     * Input: { component_id } or { data: {...} }
     */
    public function handle_extract($input = [])
    {
        $compId = \getVal($input, 'component_id') ?: \getVal($input, 'id');
        $data = \getVal($input, 'data');
        if ($compId) {
            $comp = (new \api\components())->handle_get(['id' => $compId]);
            // handle_get returns error array or component; guard array shape
            if (isset($comp['error'])) return $comp;
            $data = $comp['data'] ?? [];
        }
        if (!is_array($data)) return ['error' => 'component_id or data required.'];

        return ['items' => self::extractItems($data), 'total_hours' => self::sumHours($data)];
    }

    /**
     * Extract + price process hours for a single entity.
     * Prices each trade via the rate hierarchy.
     * Input: { entity_id, company_rates? }
     */
    public function handle_calculate_entity($input = [])
    {
        $entityId = \getVal($input, 'entity_id');
        if (!$entityId) return ['error' => 'entity_id is required.'];
        if (!$this->getEntity($entityId)) {
            return ['error' => 'Entity not found.', 'error_code' => 404];
        }

        $comps = $this->getComponents($entityId, 'process');
        $hours = [];
        foreach ($comps as $c) {
            $hours = self::mergeHours($hours, $c['data'] ?? []);
        }
        $items = self::extractItems($hours); // $hours is already a named-field map {trade: hrs}

        // Price against the rate hierarchy (rates.php API)
        $rates = $this->getAllEffectiveRates($entityId);
        $priced = [];
        $total = 0.0;
        foreach ($items as $it) {
            $rate = $rates[$it['name']]['rate'] ?? 0;
            $cost = round($it['time'] * $rate, 2);
            $priced[] = [
                'name' => $it['name'],
                'time' => $it['time'],
                'rate' => $rate,
                'cost' => $cost,
                'source' => $rates[$it['name']]['source'] ?? 'global',
            ];
            $total += $cost;
        }

        return [
            'entity_id' => $entityId,
            'items' => $priced,
            'total_hours' => round(self::sumHours($hours), 2),
            'total_cost' => round($total, 2),
        ];
    }

    /**
     * Aggregate process hours across an entity's whole BOM (children via
     * contains links, recursive). Used by the quote Process tab.
     * Input: { entity_id, max_depth? }
     */
    public function handle_aggregate($input = [])
    {
        $entityId = \getVal($input, 'entity_id');
        if (!$entityId) return ['error' => 'entity_id is required.'];
        $maxDepth = (int)\getVal($input, 'max_depth', 10);

        $allHours = [];
        $this->collectHours($entityId, 0, $maxDepth, $allHours);

        // Price the aggregate against the root entity's rate context
        $rates = $this->getAllEffectiveRates($entityId);
        $priced = [];
        $total = 0.0;
        foreach ($allHours as $trade => $hours) {
            $rate = $rates[$trade]['rate'] ?? 0;
            $cost = round($hours * $rate, 2);
            $priced[] = [
                'name' => $trade,
                'hours' => round($hours, 2),
                'rate' => $rate,
                'cost' => $cost,
            ];
            $total += $cost;
        }

        return [
            'entity_id' => $entityId,
            'trades' => $priced,
            'total_hours' => round(array_sum($allHours), 2),
            'total_cost' => round($total, 2),
        ];
    }

    // ── Internal ───────────────────────────────────────

    /**
     * Get all effective rates for an entity via rates.php API (no HTTP).
     */
    private function getAllEffectiveRates($entityId)
    {
        $ratesApi = new \api\rates();
        $ratesApi->user_id = $this->effOwnerId();
        return $ratesApi->handle_get_all_effective(['entity_id' => $entityId]);
    }

    /**
     * Extract named-trade items from a process component's data.
     * Handles both { boilermaking: 4.5 } and { items: [{name,time}] }.
     */
    public static function extractItems($data)
    {
        $items = [];
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $it) {
                if (isset($it['name'])) {
                    $items[] = ['name' => $it['name'], 'time' => (float)($it['time'] ?? 0)];
                }
            }
            return $items;
        }
        foreach (self::TRADES as $trade) {
            if (isset($data[$trade]) && $data[$trade] !== null) {
                $items[] = ['name' => $trade, 'time' => (float)$data[$trade]];
            }
        }
        return $items;
    }

    /**
     * Sum hours from a data blob (named-field or items format).
     */
    public static function sumHours($data)
    {
        $sum = 0.0;
        foreach (self::extractItems($data) as $it) $sum += $it['time'];
        return $sum;
    }

    /**
     * Merge two hour-maps (named-field style) without losing trades.
     */
    public static function mergeHours($a, $b)
    {
        foreach (self::extractItems($b) as $it) {
            $a[$it['name']] = ($a[$it['name']] ?? 0) + $it['time'];
        }
        return $a;
    }

    /**
     * Recursively collect process hours from an entity and its contains-children.
     */
    private function collectHours($entityId, $depth, $maxDepth, &$acc)
    {
        if ($depth > $maxDepth) return;
        $comps = $this->getComponents($entityId, 'process');
        foreach ($comps as $c) {
            $acc = self::mergeHours($acc, $c['data'] ?? []);
        }
        $links = $this->getLinks($entityId, 'contains');
        foreach ($links['out'] as $link) {
            $this->collectHours($link['to_id'], $depth + 1, $maxDepth, $acc);
        }
    }
}

\api\dispatchIfEntry(__FILE__);
