<?php
/**
 * fabricate_forge/api/reports.php
 *
 * Reports & analytics — ported from the original app's reports.js methods.
 * Owner-scoped aggregates over the quote book:
 *
 *   cost_by_client    — quote totals grouped by client
 *   quote_funnel      — status counts (draft/submitted/approved/invoiced/lost)
 *   monthly_summary   — totals + counts per calendar month
 *   cost_by_trade     — process hours + cost per trade (quote-scoped or all)
 *   margin_summary    — avg margin %, quote value, estimated margin, effective rate
 */
namespace api;

include_once(__DIR__ . "/_base.php");
include_once(__DIR__ . "/rates.php");

class reports extends Base
{
    /** Cost component per-trade keys that make up a quote's process cost. */
    const TRADES = ['boilermaking', 'welding', 'machining', 'cutting', 'drilling', 'grinding', 'bending', 'assembly', 'painting'];

    protected function buildTable()
    {
        $this->ensureEcsTables(); // reads only — all tables already exist
    }

    // ── shared: all quotes for the user (light) ─────────

    private function allQuotes()
    {
        $res = $this->pgCrud->read([
            'table' => 'entity',
            'where' => 'type = $1 AND user_id_owner = $2 AND is_active = TRUE',
            'params' => ['quote', $this->effOwnerId()],
            'order_fields' => ['created_at ASC'],
        ]);
        return $res['data'] ?? [];
    }

    private function quoteTotal($quote)
    {
        $comps = $this->getComponents($quote['id'], 'cost');
        return (float)($comps[0]['data']['total'] ?? 0);
    }

    private function quoteMargin($quote, $defaultMarkup)
    {
        // totalCost = subtotal × (1 + markup/100) → margin = totalCost × markup / (100 + markup)
        $tc = $this->quoteTotal($quote);
        return $tc * $defaultMarkup / (100 + $defaultMarkup);
    }

    // ── cost_by_client ─────────────────────────────────

    public function handle_cost_by_client($input = [])
    {
        $quotes = $this->allQuotes();
        $clientIds = [];
        foreach ($quotes as $q) {
            $cid = $q['data']['client_id'] ?? null;
            if ($cid) $clientIds[$cid] = true;
        }

        $clientMap = [];
        if ($clientIds) {
            $res = $this->pgCrud->read([
                'table' => 'client',
                // JSONB-array param — pg_query_params can't bind a PHP array directly
                'where' => 'id IN (SELECT value::uuid FROM jsonb_array_elements_text($1::jsonb) AS t(value)) AND user_id_owner = $2',
                'params' => [json_encode(array_keys($clientIds)), $this->effOwnerId()],
            ]);
            foreach (($res['data'] ?? []) as $c) {
                $clientMap[$c['id']] = $c['company_name'] ?? $c['primary_contact'] ?? 'Unknown';
            }
        }

        $map = [];
        foreach ($quotes as $q) {
            $cid = $q['data']['client_id'] ?? '__direct__';
            if (!isset($map[$cid])) {
                $map[$cid] = ['clientName' => $clientMap[$cid] ?? 'Direct', 'total' => 0, 'count' => 0];
            }
            $map[$cid]['total'] += $this->quoteTotal($q);
            $map[$cid]['count']++;
        }

        $rows = array_values($map);
        usort($rows, fn($a, $b) => $b['total'] <=> $a['total']);
        foreach ($rows as &$r) {
            $r['total'] = round($r['total'], 2);
        }
        unset($r);
        return $rows;
    }

    // ── quote_funnel ───────────────────────────────────

    public function handle_quote_funnel($input = [])
    {
        $totals = ['draft' => 0, 'submitted' => 0, 'approved' => 0, 'invoiced' => 0, 'lost' => 0];
        foreach ($this->allQuotes() as $q) {
            $s = $q['data']['status'] ?? 'draft';
            if (array_key_exists($s, $totals)) $totals[$s]++;
        }
        return $totals;
    }

    // ── monthly_summary ────────────────────────────────

    public function handle_monthly_summary($input = [])
    {
        $map = [];
        foreach ($this->allQuotes() as $q) {
            $created = $q['created_at'] ?? null;
            if (!$created) continue;
            $ts = is_numeric($created) ? (int)$created : strtotime((string)$created);
            if (!$ts) continue;
            $key = date('Y-m', $ts);
            if (!isset($map[$key])) $map[$key] = ['month' => $key, 'total' => 0, 'count' => 0];
            $map[$key]['total'] += $this->quoteTotal($q);
            $map[$key]['count']++;
        }
        $rows = array_values($map);
        usort($rows, fn($a, $b) => strcmp($a['month'], $b['month']));
        foreach ($rows as &$r) $r['total'] = round($r['total'], 2);
        unset($r);
        return $rows;
    }

    // ── cost_by_trade ──────────────────────────────────

    public function handle_cost_by_trade($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');

        $where = 'user_id_owner = $1 AND is_active = TRUE';
        $params = [$this->effOwnerId()];
        $idx = 2;
        if ($quoteId) {
            $where .= " AND quote_id = \${$idx}";
            $params[] = $quoteId;
        }
        $res = $this->pgCrud->read([
            'table' => 'entity',
            'where' => $where,
            'params' => $params,
        ]);
        $entities = $res['data'] ?? [];
        if (!$entities) return ['trades' => [], 'total' => 0];

        $entityIds = array_column($entities, 'id');
        $idParam = json_encode($entityIds);
        // Per-trade HOURS from process components
        $procRes = $this->pgCrud->read([
            'table' => 'component',
            'where' => 'type = $1 AND user_id_owner = $2 AND entity_id IN (SELECT value::uuid FROM jsonb_array_elements_text($3::jsonb) AS t(value))',
            'params' => ['process', $this->effOwnerId(), $idParam],
        ]);
        // Per-trade COST from cost components (already priced by the cost engine)
        $costRes = $this->pgCrud->read([
            'table' => 'component',
            'where' => 'type = $1 AND user_id_owner = $2 AND entity_id IN (SELECT value::uuid FROM jsonb_array_elements_text($3::jsonb) AS t(value))',
            'params' => ['cost', $this->effOwnerId(), $idParam],
        ]);

        $hoursByTrade = [];
        foreach (($procRes['data'] ?? []) as $comp) {
            $d = $comp['data'] ?? [];
            if (is_string($d)) $d = json_decode($d, true) ?: [];
            foreach (self::TRADES as $t) {
                if (isset($d[$t]) && (float)$d[$t] > 0) {
                    $hoursByTrade[$t] = ($hoursByTrade[$t] ?? 0) + (float)$d[$t];
                }
            }
        }
        $costByTrade = [];
        foreach (($costRes['data'] ?? []) as $comp) {
            $d = $comp['data'] ?? [];
            if (is_string($d)) $d = json_decode($d, true) ?: [];
            foreach (self::TRADES as $t) {
                if (isset($d[$t]) && (float)$d[$t] > 0) {
                    $costByTrade[$t] = ($costByTrade[$t] ?? 0) + (float)$d[$t];
                }
            }
        }

        $trades = [];
        foreach (self::TRADES as $t) {
            if (!isset($hoursByTrade[$t]) && !isset($costByTrade[$t])) continue;
            $trades[] = [
                'name' => $t,
                'hours' => round($hoursByTrade[$t] ?? 0, 2),
                'cost' => round($costByTrade[$t] ?? 0, 2),
            ];
        }
        $total = array_sum(array_column($trades, 'cost'));

        return ['trades' => $trades, 'total' => round($total, 2)];
    }

    // ── margin_summary ─────────────────────────────────

    public function handle_margin_summary($input = [])
    {
        $quotes = $this->allQuotes();

        // User's default markup (company settings → user prefs → 30)
        $defaultMarkup = 30.0;
        $settings = $this->pgCrud->read([
            'table' => 'company_settings',
            'where' => 'user_id_owner = $1',
            'params' => [$this->effOwnerId()],
            'limit' => 1,
        ])['data'][0] ?? null;
        if ($settings && isset($settings['data']['defaultMarkupPercent'])) {
            $defaultMarkup = (float)$settings['data']['defaultMarkupPercent'];
        }

        if (!$quotes) {
            return [
                'avgMarginPercent' => 0, 'totalQuoteValue' => 0, 'totalEstimatedMargin' => 0,
                'effectiveMarginRate' => 0, 'quoteCount' => 0,
            ];
        }

        $totalValue = 0.0;
        $marginSum = 0.0;
        foreach ($quotes as $q) {
            $totalValue += $this->quoteTotal($q);
            $marginSum += $this->quoteMargin($q, $defaultMarkup);
        }
        $effectiveRate = $totalValue > 0 ? ($marginSum / $totalValue) * 100 : 0;

        return [
            'avgMarginPercent' => round($defaultMarkup, 1),
            'totalQuoteValue' => round($totalValue, 2),
            'totalEstimatedMargin' => round($marginSum, 2),
            'effectiveMarginRate' => round($effectiveRate, 1),
            'quoteCount' => count($quotes),
        ];
    }
}

\api\dispatchIfEntry(__FILE__);
