<?php
/**
 * fabricate_forge/api/production.php
 *
 * Production tracking — records actual vs estimated hours per entity,
 * auto-calculating variance. Ported from the original app's
 * production-tracking.js method file.
 *
 * Actions:
 *   record_create / record_list / record_variance / quote_summary
 */
namespace api;

include_once(__DIR__ . "/_base.php");

class production extends Base
{
    protected function buildTable()
    {
        $this->ensureEcsTables();
        $this->pgCrud->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS production_record (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    entity_id UUID,
    entity_name VARCHAR(200) NOT NULL DEFAULT '',
    quote_id UUID,
    trade VARCHAR(50) NOT NULL DEFAULT 'boilermaking',
    estimated_hours NUMERIC DEFAULT 0,
    actual_hours NUMERIC DEFAULT 0,
    date_completed TIMESTAMPTZ DEFAULT NOW(),
    notes TEXT,
    data JSONB DEFAULT '{}'::jsonb,
    user_id_owner UUID NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
)
SQL);
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_pr_owner ON production_record(user_id_owner)');
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_pr_entity ON production_record(entity_id)');
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_pr_quote ON production_record(quote_id)');

        $this->pgCrud->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS production_variance (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    record_id UUID,
    entity_id UUID,
    trade VARCHAR(50),
    estimated_hours NUMERIC DEFAULT 0,
    actual_hours NUMERIC DEFAULT 0,
    variance NUMERIC DEFAULT 0,
    variance_percent NUMERIC DEFAULT 0,
    data JSONB DEFAULT '{}'::jsonb,
    user_id_owner UUID NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW()
)
SQL);
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_pv_owner ON production_variance(user_id_owner)');
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_pv_entity ON production_variance(entity_id)');
    }

    /**
     * Create a production record; auto-computes variance (actual − estimated).
     * Input: { entity_id?, entity_name?, quote_id?, trade?, estimated_hours?, actual_hours?, date_completed?, notes? }
     */
    public function handle_record_create($input = [])
    {
        $estimated = (float)\getVal($input, 'estimated_hours', 0);
        $actual = (float)\getVal($input, 'actual_hours', 0);

        $res = $this->pgCrud->save([
            'table' => 'production_record',
            'data' => [
                'entity_id' => \getVal($input, 'entity_id'),
                'entity_name' => \getVal($input, 'entity_name', ''),
                'quote_id' => \getVal($input, 'quote_id'),
                'trade' => \getVal($input, 'trade', 'boilermaking'),
                'estimated_hours' => $estimated,
                'actual_hours' => $actual,
                'date_completed' => \getVal($input, 'date_completed') ?: date('c'),
                'notes' => \getVal($input, 'notes', ''),
                'user_id_owner' => $this->user_id,
            ],
        ]);
        if (!empty($res['error'])) return $res;
        $id = $res['data']['id'];

        // Auto-calculate variance (mirrors original production-tracking.js)
        $variance = null;
        if ($estimated > 0 && $actual > 0) {
            $diff = $actual - $estimated;
            $variance = [
                'record_id' => $id,
                'entity_id' => \getVal($input, 'entity_id'),
                'trade' => \getVal($input, 'trade', 'boilermaking'),
                'estimated_hours' => $estimated,
                'actual_hours' => $actual,
                'variance' => $diff,
                'variance_percent' => round(($diff / $estimated) * 100, 2),
                'user_id_owner' => $this->user_id,
            ];
            $this->pgCrud->save([
                'table' => 'production_variance',
                'data' => $variance,
            ]);
        }

        $record = $this->pgCrud->read([
            'table' => 'production_record',
            'where' => 'id = $1 AND user_id_owner = $2',
            'params' => [$id, $this->user_id],
            'limit' => 1,
        ])['data'][0] ?? null;

        return ['record' => $record, 'variance' => $variance];
    }

    /**
     * List records (owner-scoped). Filters: quote_id, entity_id, trade.
     */
    public function handle_record_list($input = [])
    {
        $where = 'user_id_owner = $1';
        $params = [$this->user_id];
        $idx = 2;
        foreach (['quote_id', 'entity_id', 'trade'] as $col) {
            $v = \getVal($input, $col);
            if ($v) {
                $where .= " AND $col = \${$idx}";
                $params[] = $v;
                $idx++;
            }
        }
        $limit = (int)\getVal($input, 'limit', 50);
        $res = $this->pgCrud->read([
            'table' => 'production_record',
            'where' => $where,
            'params' => $params,
            'order_fields' => ['date_completed DESC'],
            'limit' => min(max($limit, 1), 500),
        ]);
        return $res['data'] ?? [];
    }

    /**
     * Variance history for an entity.
     * Input: { entity_id }
     */
    public function handle_record_variance($input = [])
    {
        $entityId = \getVal($input, 'entity_id');
        if (!$entityId) return ['error' => 'entity_id is required.'];
        $res = $this->pgCrud->read([
            'table' => 'production_variance',
            'where' => 'entity_id = $1 AND user_id_owner = $2',
            'params' => [$entityId, $this->user_id],
            'order_fields' => ['created_at DESC'],
        ]);
        return $res['data'] ?? [];
    }

    /**
     * Summary for a quote: totals + variance across all its records.
     * Input: { quote_id }
     */
    public function handle_quote_summary($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        if (!$quoteId) return ['error' => 'quote_id is required.'];
        $res = $this->pgCrud->read([
            'table' => 'production_record',
            'where' => 'quote_id = $1 AND user_id_owner = $2',
            'params' => [$quoteId, $this->user_id],
        ]);
        $records = $res['data'] ?? [];
        $totalEst = 0.0;
        $totalAct = 0.0;
        foreach ($records as $r) {
            $totalEst += (float)$r['estimated_hours'];
            $totalAct += (float)$r['actual_hours'];
        }
        return [
            'records' => $records,
            'total_estimated' => self::r2($totalEst),
            'total_actual' => self::r2($totalAct),
            'variance' => self::r2($totalAct - $totalEst),
        ];
    }

    private static function r2($v) { return round((float)$v, 2); }
}

\api\dispatchIfEntry(__FILE__);
