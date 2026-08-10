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
            'params' => [$quoteId, $this->user_id],
            'order_fields' => ['created_at ASC'],
        ]);
        $entities = $res['data'] ?? [];

        $entityIds = array_column($entities, 'id');
        $costs = [];

        if ($entityIds) {
            // 2. Batch cost calculation (single pass — kills N+1)
            $costApi = new \api\cost();
            $costApi->user_id = $this->user_id;
            $costRes = $costApi->handle_batch_calculate(['entity_ids' => $entityIds]);
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
        foreach ($entities as $e) {
            $c = $e['cost'] ?? null;
            if (!$c) continue;
            foreach ($COST_COLUMNS as $col) {
                if (isset($c[$col])) $totals[$col] += (float)$c[$col];
            }
        }
        foreach ($totals as $col => $v) {
            $totals[$col] = \api\cost::r2($v);
        }
        $grandTotal = $totals['total'];

        // 5. Auto-persist totalCost + column totals into the quote's cost component
        $this->persistQuoteTotal($quoteId, $grandTotal, count($entities), $totals);

        return [
            'quote' => $quote,
            'entities' => $entities,
            'costs' => $costs,
            'totals' => $totals,
            'total_cost' => $grandTotal,
        ];
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
            [$this->user_id, $quoteId]
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
        $params = ['quote', $this->user_id];
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
     * Persist grand total + entity count + column totals into the quote's cost
     * component. If no cost component exists yet, create one (holds quote-level
     * data).
     */
    private function persistQuoteTotal($quoteId, $grandTotal, $entityCount, $totals = [])
    {
        $quoteCostData = [
            'total' => $grandTotal,
            'subtotal' => $grandTotal,
            'entity_count' => $entityCount,
            'totals' => $totals,
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
                    'user_id_owner' => $this->user_id,
                ],
            ]);
        }
    }
}

\api\dispatchIfEntry(__FILE__);
