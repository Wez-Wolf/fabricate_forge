<?php
/**
 * fabricate_forge/api/rates.php
 *
 * Process rate lookups with the authoritative hierarchy:
 *   1. Entity-specific rate component (highest priority)
 *   2. Company default rates (user prefs / company settings)
 *   3. Global fallback (GLOBAL_DEFAULT_RATES)
 *
 * Rates are stored as:
 *   - global:   in this file (GLOBAL_DEFAULT_RATES const)
 *   - company:  company_settings.data.defaultRates (JSONB)
 *   - entity:   a 'rate' component on the entity (data.rates.{trade})
 */
namespace api;

include_once(__DIR__ . "/_base.php");

class rates extends Base
{
    /** Global fallback rates — mirrors GLOBAL_DEFAULT_RATES in the original app. */
    const GLOBAL_DEFAULT_RATES = [
        'cutting' => 85, 'drilling' => 75, 'grinding' => 70, 'welding' => 90,
        'painting' => 75, 'assembly' => 65, 'qualityControl' => 60,
        'machining' => 95, 'punching' => 60, 'bending' => 65, 'shipping' => 50,
        'boilermaking' => 75, 'surfaceTreatment' => 80, 'other' => 65,
    ];

    const TRADES = [
        'boilermaking','welding','machining','cutting','drilling','grinding',
        'bending','assembly','painting','qualityControl','surfaceTreatment',
        'punching','shipping','other',
    ];

    protected function buildTable()
    {
        $this->ensureEcsTables();
    }

    // ── Reads ──────────────────────────────────────────

    /**
     * Get the global fallback rates (and the trade list).
     */
    public function handle_globals($input = [])
    {
        return [
            'rates' => self::GLOBAL_DEFAULT_RATES,
            'trades' => self::TRADES,
        ];
    }

    /**
     * Get the company (user) default rates from company_settings.
     * Returns ONLY user-set rates (empty array if none) so the hierarchy
     * can distinguish "no company override" from global defaults.
     */
    public function handle_company($input = [])
    {
        $settings = $this->getCompanySettings();
        return $settings['data']['defaultRates'] ?? [];
    }

    /**
     * Get entity-specific rates from the entity's 'rate' component.
     */
    public function handle_entity($input = [])
    {
        $entityId = \getVal($input, 'entity_id') ?: \getVal($input, 'id');
        if (!$entityId) return ['error' => 'entity_id is required.'];

        if (!$this->getEntity($entityId)) {
            return ['error' => 'Entity not found.', 'error_code' => 404];
        }

        $comps = $this->getComponents($entityId, 'rate');
        $rates = [];
        foreach ($comps as $c) {
            $data = $c['data'] ?? [];
            foreach (self::TRADES as $trade) {
                if (isset($data[$trade]) && $data[$trade] !== null) {
                    $rates[$trade] = (float)$data[$trade];
                }
            }
        }
        return $rates;
    }

    /**
     * THE hierarchy lookup: entity → company → global. First match wins.
     * Returns the effective rate for a single trade.
     */
    public function handle_get_effective($input = [])
    {
        $trade = \getVal($input, 'trade') ?: \getVal($input, 'process_name');
        if (!$trade) return ['error' => 'trade (process_name) is required.'];

        // 1. Entity rate (highest priority)
        $entityId = \getVal($input, 'entity_id');
        if ($entityId && $this->getEntity($entityId)) {
            $entityRates = $this->handle_entity(['entity_id' => $entityId]);
            if (isset($entityRates[$trade])) {
                return ['trade' => $trade, 'rate' => $entityRates[$trade], 'source' => 'entity'];
            }
        }

        // 2. Company rate
        $company = $this->handle_company();
        if (isset($company[$trade])) {
            return ['trade' => $trade, 'rate' => (float)$company[$trade], 'source' => 'company'];
        }

        // 3. Global fallback
        return [
            'trade' => $trade,
            'rate' => (float)(self::GLOBAL_DEFAULT_RATES[$trade] ?? 0),
            'source' => 'global',
        ];
    }

    /**
     * Batch hierarchy lookup — effective rates for ALL trades given an entity.
     * Used by the cost engine to price process hours in one call.
     */
    public function handle_get_all_effective($input = [])
    {
        $entityId = \getVal($input, 'entity_id');
        $entityRates = [];
        if ($entityId && $this->getEntity($entityId)) {
            $entityRates = $this->handle_entity(['entity_id' => $entityId]);
        }
        $company = $this->handle_company();

        $result = [];
        foreach (self::TRADES as $trade) {
            if (isset($entityRates[$trade])) {
                $result[$trade] = ['rate' => $entityRates[$trade], 'source' => 'entity'];
            } elseif (isset($company[$trade])) {
                $result[$trade] = ['rate' => (float)$company[$trade], 'source' => 'company'];
            } else {
                $result[$trade] = ['rate' => (float)(self::GLOBAL_DEFAULT_RATES[$trade] ?? 0), 'source' => 'global'];
            }
        }
        return $result;
    }

    // ── Writes ─────────────────────────────────────────

    /**
     * Set an entity-specific rate (creates/updates the entity's 'rate' component).
     * Input: { entity_id, trade, rate }
     */
    public function handle_set_entity_rate($input = [])
    {
        $entityId = \getVal($input, 'entity_id');
        $trade = \getVal($input, 'trade');
        $rate = \getVal($input, 'rate');
        if (!$entityId || !$trade) return ['error' => 'entity_id and trade are required.'];
        if (!in_array($trade, self::TRADES)) return ['error' => "Unknown trade: $trade"];
        if ($rate === null || $rate < 0) return ['error' => 'rate must be >= 0'];

        if (!$this->getEntity($entityId)) {
            return ['error' => 'Entity not found.', 'error_code' => 404];
        }

        // Find existing rate component, else create one
        $comps = $this->getComponents($entityId, 'rate');
        $compId = null;
        foreach ($comps as $c) {
            if (isset($c['data'][$trade])) { $compId = $c['id']; break; }
        }

        if ($compId) {
            $this->patchComponentData($compId, [$trade => (float)$rate]);
        } else {
            // Create or merge into first rate component
            if ($comps) {
                $this->patchComponentData($comps[0]['id'], [$trade => (float)$rate]);
            } else {
                $this->pgCrud->save([
                    'table' => 'component',
                    'data' => [
                        'entity_id' => $entityId,
                        'type' => 'rate',
                        'data' => [$trade => (float)$rate],
                        'quote_id' => $this->getEntity($entityId)['quote_id'] ?? null,
                        'user_id_owner' => $this->effOwnerId(),
                    ],
                ]);
            }
        }

        return $this->handle_get_effective(['entity_id' => $entityId, 'trade' => $trade]);
    }

    /**
     * Set company default rates (upsert into company_settings).
     * Input: { rates: { trade: rate, ... } } — merges, doesn't replace.
     */
    public function handle_set_company_rates($input = [])
    {
        $rates = \getVal($input, 'rates', []);
        if (!is_array($rates) || empty($rates)) {
            return ['error' => 'rates (object) is required.'];
        }

        $settings = $this->getCompanySettings();
        $current = $settings['data']['defaultRates'] ?? [];
        $merged = array_merge($current, $rates);

        if ($settings) {
            $this->pgCrud->execute(
                "UPDATE company_settings
                 SET data = jsonb_set(data, '{defaultRates}', \$2::jsonb), updated_at = NOW()
                 WHERE user_id_owner = \$1",
                [$this->effOwnerId(), json_encode($merged)]
            );
        } else {
            $this->pgCrud->save([
                'table' => 'company_settings',
                'data' => [
                    'user_id_owner' => $this->effOwnerId(),
                    'data' => ['defaultRates' => $merged],
                ],
            ]);
        }

        return ['success' => true, 'defaultRates' => $merged];
    }

    // ── Internal ───────────────────────────────────────

    private function getCompanySettings()
    {
        $res = $this->pgCrud->read([
            'table' => 'company_settings',
            'where' => 'user_id_owner = $1',
            'params' => [$this->effOwnerId()],
            'limit' => 1,
        ]);
        return $res['data'][0] ?? null;
    }
}

\api\dispatchIfEntry(__FILE__);
