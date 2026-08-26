<?php
/**
 * fabricate_forge/api/process.php
 *
 * Process time tracking + pricing — the Layer-2 of the cost model.
 *
 * Process data lives in 'process' components:
 *   { data: { boilermaking: 4.5, welding: 2.0, ... } }  (named fields, hours)
 *   { data: { ops: [{ category, hours, summary }, ...], note } }  (buildable list)
 *
 * This endpoint:
 *   - exposes the trade registry (names + defaults)
 *   - extracts hours from process components (both modern shapes)
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

    // ── Process system (pure, entity-agnostic) ───────

    /**
     * Compute hours from a process component's data.
     * Returns [{ name: trade, time: hours }].
     */
    public static function computeHours($processData)
    {
        return self::extractItems($processData);
    }

    /**
     * Get merged process hours for an entity (reads DB).
     * Pass $ctx (the calling Base instance, e.g. cost.php) so component reads
     * run under the caller's authenticated owner scope — the legacy anonymous-
     * class path has no owner set and silently returns [] outside HTTP.
     */
    public static function hoursForEntity($entityId, $ctx = null)
    {
        if ($ctx !== null) {
            $hours = [];
            foreach ($ctx->getComponents($entityId, 'process') as $c) {
                $hours = self::mergeHours($hours, $c['data'] ?? []);
            }
            return $hours;
        }
        $base = new class extends \api\Base {
            public function get($entityId) {
                $comps = $this->getComponents($entityId, 'process');
                $hours = [];
                foreach ($comps as $c) {
                    $hours = \api\process::mergeHours($hours, $c['data'] ?? []);
                }
                return $hours;
            }
        };
        return $base->get($entityId);
    }

    /**
     * PRICED FRAGMENT PRODUCER — the process system's contribution to the
     * cost component (pure — no DB). Hours map + resolved rates in,
     * cost-comp-shaped fragment out: per-trade costs + processTotal + the
     * exposed hour fields (boilerHrs/weldHrs/machHrs). cost.php sums this
     * fragment with the material and on-cost fragments.
     *
     * @param array $hours   {trade: hours}
     * @param array $rates   {trade: {rate: R/h}}  (entity → company → global)
     * @param float $quantity entity quantity
     * @return array cost-comp fragment
     */
    public static function pricedFragment($hours, $rates, $quantity = 1.0)
    {
        $perTrade = []; $processTotal = 0.0;
        foreach ($hours as $trade => $hrs) {
            $hrs = (float)$hrs;
            if ($hrs <= 0) continue;
            $rate = (float)($rates[$trade]['rate'] ?? 0);
            $cost = round($hrs * $rate * (float)$quantity, 2);
            $perTrade[$trade] = $cost;
            $processTotal += $cost;
        }
        return [
            'boilermaking' => round($perTrade['boilermaking'] ?? 0, 2),
            'welding'      => round($perTrade['welding'] ?? 0, 2),
            'machining'    => round($perTrade['machining'] ?? 0, 2),
            'cutting'      => round($perTrade['cutting'] ?? 0, 2),
            'drilling'     => round($perTrade['drilling'] ?? 0, 2),
            'grinding'     => round($perTrade['grinding'] ?? 0, 2),
            'bending'      => round($perTrade['bending'] ?? 0, 2),
            'assembly'     => round($perTrade['assembly'] ?? 0, 2),
            'painting'     => round($perTrade['painting'] ?? 0, 2),
            'qualityControl' => round($perTrade['qualityControl'] ?? 0, 2),
            'surfaceTreatment' => round($perTrade['surfaceTreatment'] ?? 0, 2),
            'processTotal' => round($processTotal, 2),
            'labor'        => round($processTotal, 2),
            'boilerHrs'    => round((float)($hours['boilermaking'] ?? 0), 2),
            'weldHrs'      => round((float)($hours['welding'] ?? 0), 2),
            'machHrs'      => round((float)($hours['machining'] ?? 0), 2),
            'hours'        => $hours,
        ];
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

    // ── Pure extraction (entity-agnostic, no DB) ─────

    /**
     * Extract named-trade hours from a process component's data.
     * Accepted shapes (all collapse to [{ name: <trade>, time: <hrs> }]):
     *   - named-field map: { boilermaking: 4.5, welding: 2.0 }
     *   - operation list:  { ops: [{ category, hours, summary }, ...] }
     *   - legacy array:    { items: [{ name, time }, ...] }
     */
    public static function extractItems($data)
    {
        if (!is_array($data)) return [];
        $items = [];
        if (isset($data['items']) && is_array($data['items'])) {
            foreach ($data['items'] as $it) {
                $name = $it['name'] ?? $it['trade'] ?? null;
                if ($name) {
                    $items[] = ['name' => $name, 'time' => (float)($it['time'] ?? $it['hours'] ?? 0)];
                }
            }
            return $items;
        }
        if (isset($data['ops']) && is_array($data['ops'])) {
            foreach ($data['ops'] as $op) {
                $cat = $op['category'] ?? null;
                if ($cat) {
                    $items[] = ['name' => $cat, 'time' => (float)($op['hours'] ?? 0)];
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
     * Extract the operation list (with summaries) from a component's data.
     * Returns { category, hours, summary } rows for the buildable UI; reads
     * only the `ops` list (named-field maps carry no summaries).
     */
    public static function extractOps($data)
    {
        if (!is_array($data) || empty($data['ops']) || !is_array($data['ops'])) return [];
        $ops = [];
        foreach ($data['ops'] as $op) {
            if (isset($op['category'])) {
                $ops[] = [
                    'category' => $op['category'],
                    'hours' => (float)($op['hours'] ?? 0),
                    'summary' => (string)($op['summary'] ?? ''),
                ];
            }
        }
        return $ops;
    }

    /**
     * Sum hours from a process component's data (named-field map or ops list).
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

}

\api\dispatchIfEntry(__FILE__);
