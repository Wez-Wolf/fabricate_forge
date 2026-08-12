<?php
/**
 * fabricate_forge/api/quotes.php
 *
 * Quote lifecycle — the workflow layer on top of the ECS core.
 *
 * In ECS, a quote IS an entity (type='quote'). This endpoint adds the
 * quote-specific behavior:
 *   - quote fields (customer, due date, validity, currency) in data JSONB
 *   - status lifecycle with VALID_TRANSITIONS enforcement + history
 *   - entity attachment (quote_id on member entities)
 *   - PDF export (server-generated HTML)
 *   - total persistence via systems.load_quote
 */
namespace api;

include_once(__DIR__ . "/_base.php");
include_once(__DIR__ . "/entities.php");
include_once(__DIR__ . "/systems.php");

class quotes extends Base
{
    /**
     * Valid status transitions (mirrors the original app's enforcement).
     */
    const VALID_TRANSITIONS = [
        'draft'     => ['submitted'],
        'submitted' => ['approved', 'rejected', 'draft'],
        'approved'  => ['invoiced', 'draft'],
        'invoiced'  => ['draft'],
        'rejected'  => ['draft'],
    ];

    const STATUSES = ['draft', 'submitted', 'approved', 'invoiced', 'rejected'];

    /** Quote field keys stored in entity.data (JSONB). */
    const QUOTE_FIELDS = [
        'quoteNumber', 'customerName', 'customerEmail', 'customerPhone',
        'customerAddress', 'dueDate', 'validityDays', 'validityDate',
        'currency', 'statusHistory', 'status', 'clientId', 'materialRates',
        'marginPercent',
    ];

    protected function buildTable()
    {
        $this->ensureEcsTables();
    }

    // ── Lifecycle ─────────────────────────────────────

    /**
     * Create a quote (entity type='quote', status draft, history seeded).
     * Input: { name?, customer_name?, currency?, due_date?, validity_days?,
     *          description?, quote_number? }
     */
    public function handle_create($input = [])
    {
        $name = \getVal($input, 'name') ?: \getVal($input, 'title')
            ?: ('Quote ' . substr(bin2hex(random_bytes(4)), 0, 8));

        // If a client was selected, auto-fill customer fields from it
        $clientId = \getVal($input, 'client_id');
        $client = null;
        if ($clientId) {
            $clientRes = $this->pgCrud->read([
                'table' => 'client',
                'where' => 'id = $1 AND user_id_owner = $2',
                'params' => [$clientId, $this->effOwnerId()],
                'limit' => 1,
            ]);
            $client = $clientRes['data'][0] ?? null;
        }

        $data = [
            'quoteNumber' => \getVal($input, 'quote_number') ?: 'Q-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'clientId' => $clientId,
            'customerName' => \getVal($input, 'customer_name', $client['company_name'] ?? ''),
            'customerEmail' => \getVal($input, 'customer_email', $client['email'] ?? ''),
            'customerPhone' => \getVal($input, 'customer_phone', $client['phone'] ?? ''),
            'dueDate' => \getVal($input, 'due_date'),
            'validityDays' => \getVal($input, 'validity_days', 30),
            'currency' => \getVal($input, 'currency', 'USD'),
            // Quote-global margin, defaulted from the user's settings
            // (defaultMarkupPercent) so quotes inherit the house margin
            // but can override per-quote (and per line item).
            'marginPercent' => \getVal($input, 'margin_percent', $this->getUserDefaultMargin()),
            'status' => 'draft',
            'statusHistory' => [[
                'status' => 'draft',
                'date' => date('c'),
                'note' => 'Quote created',
            ]],
        ];

        $res = $this->pgCrud->save([
            'table' => 'entity',
            'data' => [
                'type' => 'quote',
                'name' => $name,
                'description' => \getVal($input, 'description', ''),
                'data' => $data,
                'user_id_owner' => $this->effOwnerId(),
            ],
        ]);
        if (!empty($res['error'])) return $res;
        return $this->getEntity($res['data']['id']);
    }

    /**
     * Get a quote with its entities + cost (delegates to systems.load_quote).
     */
    public function handle_get($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'quote_id');
        if (!$id) return ['error' => 'quote_id is required.'];
        $systems = new \api\systems();
        $systems->user_id = $this->effOwnerId();
        return $systems->handle_load_quote(['quote_id' => $id]);
    }

    /**
     * List quotes (light — no full cost calc). Delegates to systems.list_quotes.
     */
    public function handle_list($input = [])
    {
        $systems = new \api\systems();
        $systems->user_id = $this->effOwnerId();
        return $systems->handle_list_quotes($input);
    }

    /**
     * Update quote fields (partial — data JSONB merge).
     * Allowed: name, description + QUOTE_FIELDS.
     */
    public function handle_update($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'quote_id');
        if (!$id) return ['error' => 'quote_id is required.'];

        $quote = $this->getEntity($id);
        if (!$quote || $quote['type'] !== 'quote') {
            return ['error' => 'Quote not found.', 'error_code' => 404];
        }

        $patch = [];
        foreach (self::QUOTE_FIELDS as $f) {
            // Accept both camelCase (API convention) and snake_case (form input)
            $snake = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $f));
            if (array_key_exists($f, $input)) $patch[$f] = $input[$f];
            elseif (array_key_exists($snake, $input)) $patch[$f] = $input[$snake];
        }
        // validityDate derived from dueDate + validityDays
        if (isset($patch['dueDate']) && isset($patch['validityDays'])) {
            $ts = strtotime($patch['dueDate']) + ((int)$patch['validityDays'] * 86400);
            $patch['validityDate'] = date('c', $ts);
        }

        $sets = [];
        $params = [];
        $idx = 1;
        if (isset($input['name'])) {
            $sets[] = "name = \${$idx}";
            $params[] = $input['name'];
            $idx++;
        }
        if (isset($input['description'])) {
            $sets[] = "description = \${$idx}";
            $params[] = $input['description'];
            $idx++;
        }
        if ($patch) {
            $curData = $quote['data'] ?? [];
            $isList = is_array($curData) && array_keys($curData) === range(0, count($curData) - 1);
            if ($isList || empty($curData)) {
                $sets[] = "data = \${$idx}::jsonb";
                $params[] = json_encode($patch);
            } else {
                $sets[] = "data = data || \${$idx}::jsonb";
                $params[] = json_encode($patch);
            }
            $idx++;
        }
        if (!$sets) return ['error' => 'Nothing to update.'];

        $sets[] = 'updated_at = NOW()';
        $params[] = $id;
        $params[] = $this->effOwnerId();

        $this->pgCrud->execute(
            "UPDATE entity SET " . implode(', ', $sets) .
            " WHERE id = \${$idx} AND user_id_owner = \$" . ($idx + 1),
            $params
        );
        return $this->getEntity($id);
    }

    /**
     * Status transition with VALID_TRANSITIONS enforcement + history append.
     * Input: { quote_id, status, note? }
     * Errors: quote-not-found (404), invalid-transition (409).
     */
    public function handle_update_status($input = [])
    {
        $id = \getVal($input, 'quote_id') ?: \getVal($input, 'id');
        $newStatus = \getVal($input, 'status');
        if (!$id) return ['error' => 'quote_id is required.'];
        if (!$newStatus || !in_array($newStatus, self::STATUSES)) {
            return ['error' => "Invalid status: $newStatus", 'error_code' => 400];
        }

        $quote = $this->getEntity($id);
        if (!$quote || $quote['type'] !== 'quote') {
            return ['error' => 'quote-not-found', 'error_code' => 404];
        }

        $data = $quote['data'] ?? [];
        $current = $data['status'] ?? 'draft';

        // Enforce the transition map
        $allowed = self::VALID_TRANSITIONS[$current] ?? [];
        if (!in_array($newStatus, $allowed)) {
            return [
                'error' => "Invalid transition: $current → $newStatus",
                'error_code' => 409,
                'allowed' => $allowed,
            ];
        }

        $history = $data['statusHistory'] ?? [];
        $history[] = [
            'status' => $newStatus,
            'date' => date('c'),
            'note' => \getVal($input, 'note', "Status changed to $newStatus"),
        ];

        $this->pgCrud->execute(
            "UPDATE entity SET data = data || \$2::jsonb, updated_at = NOW()
             WHERE id = \$1 AND user_id_owner = \$3",
            [$id, json_encode(['status' => $newStatus, 'statusHistory' => $history]), $this->effOwnerId()]
        );
        return ['id' => $id, 'status' => $newStatus, 'statusHistory' => $history];
    }

    /**
     * The user's default margin % from Settings (user_prefs.defaultMarkupPercent),
     * falling back to the app default (30) when unset.
     */
    private function getUserDefaultMargin()
    {
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
     * Soft-delete a quote (is_active = FALSE).
     */
    public function handle_delete($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'quote_id');
        if (!$id) return ['error' => 'quote_id is required.'];
        $this->pgCrud->execute(
            "UPDATE entity SET is_active = FALSE, updated_at = NOW()
             WHERE id = \$1 AND user_id_owner = \$2 AND type = 'quote'",
            [$id, $this->effOwnerId()]
        );
        return ['success' => true, 'id' => $id];
    }

    /**
     * Attach an entity to a quote (sets quote_id + recalculates total).
     * Input: { quote_id, entity_id } — entity must exist and be owned.
     */
    public function handle_add_entity($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        $entityId = \getVal($input, 'entity_id');
        if (!$quoteId || !$entityId) return ['error' => 'quote_id and entity_id are required.'];

        $quote = $this->getEntity($quoteId);
        if (!$quote || $quote['type'] !== 'quote') {
            return ['error' => 'Quote not found.', 'error_code' => 404];
        }
        $entity = $this->getEntity($entityId);
        if (!$entity) return ['error' => 'Entity not found.', 'error_code' => 404];

        $this->pgCrud->execute(
            "UPDATE entity SET quote_id = \$1, updated_at = NOW()
             WHERE id = \$2 AND user_id_owner = \$3",
            [$quoteId, $entityId, $this->effOwnerId()]
        );

        // Recalculate the quote total (ECS flow)
        $systems = new \api\systems();
        $systems->user_id = $this->effOwnerId();
        return $systems->handle_load_quote(['quote_id' => $quoteId]);
    }

    /**
     * Batch-add line items to a quote in one call (N entities + a single
     * recalc via load_quote — the batch cost pass is already single-roundtrip).
     * Input: { quote_id, items: [{ name, type?, quantity?, description?, data? }] }
     */
    public function handle_add_items($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        $items = \getVal($input, 'items', []);
        if (!$quoteId) return ['error' => 'quote_id is required.'];
        if (!is_array($items) || empty($items)) return ['error' => 'items (array) is required.'];

        $quote = $this->getEntity($quoteId);
        if (!$quote || $quote['type'] !== 'quote') {
            return ['error' => 'Quote not found.', 'error_code' => 404];
        }

        $allowed = ['part', 'assembly', 'fastener'];
        $created = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $name = trim((string)\getVal($it, 'name', ''));
            if ($name === '') continue;
            $type = \getVal($it, 'type', 'part');
            if (!in_array($type, $allowed)) $type = 'part';

            $row = [
                'type' => $type,
                'name' => $name,
                'description' => \getVal($it, 'description', ''),
                'quote_id' => $quoteId,
                'quantity' => max(1, (int)\getVal($it, 'quantity', 1)),
                'user_id_owner' => $this->effOwnerId(),
            ];
            $extra = \getVal($it, 'data', []);
            if (is_array($extra) && !empty($extra)) $row['data'] = $extra;

            $res = $this->pgCrud->save(['table' => 'entity', 'data' => $row]);
            if (empty($res['error'])) $created[] = $res['data']['id'] ?? null;
        }
        if (!$created) return ['error' => 'No valid items provided.'];

        // Single recalc + return the fresh quote (batch cost = one pass)
        $systems = new \api\systems();
        $systems->user_id = $this->effOwnerId();
        $loaded = $systems->handle_load_quote(['quote_id' => $quoteId]);
        if (!isset($loaded['error'])) $loaded['items_created'] = count($created);
        return $loaded;
    }

    /**
     * Remove an entity from a quote (clears quote_id + recalculates).
     */
    public function handle_remove_entity($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        $entityId = \getVal($input, 'entity_id');
        if (!$quoteId || !$entityId) return ['error' => 'quote_id and entity_id are required.'];

        $this->pgCrud->execute(
            "UPDATE entity SET quote_id = NULL, updated_at = NOW()
             WHERE id = \$1 AND user_id_owner = \$2",
            [$entityId, $this->effOwnerId()]
        );

        $systems = new \api\systems();
        $systems->user_id = $this->effOwnerId();
        return $systems->handle_load_quote(['quote_id' => $quoteId]);
    }

    /**
     * PDF export — server-generated styled HTML pricing schedule.
     * Opens in a new tab for print/save-as-PDF (same as the original).
     * Input: { quote_id }
     */
    public function handle_export_pdf($input = [])
    {
        $id = \getVal($input, 'quote_id') ?: \getVal($input, 'id');
        if (!$id) return ['error' => 'quote_id is required.'];

        $loaded = $this->handle_get(['quote_id' => $id]);
        if (isset($loaded['error'])) return $loaded;

        $quote = $loaded['quote'] ?? [];
        $entities = $loaded['entities'] ?? [];
        $data = $quote['data'] ?? [];
        $status = $data['status'] ?? 'draft';
        $currency = $data['currency'] ?? 'USD';

        $rows = '';
        foreach ($entities as $e) {
            $c = $e['cost'] ?? [];
            $rows .= '<tr>'
                . '<td>' . htmlspecialchars($e['name']) . '</td>'
                . '<td>' . htmlspecialchars($e['type']) . '</td>'
                . '<td>' . (float)($e['quantity'] ?? 1) . '</td>'
                . '<td>' . number_format((float)($c['material'] ?? 0), 2) . '</td>'
                . '<td>' . number_format((float)($c['boilerHrs'] ?? 0), 2) . '</td>'
                . '<td>' . number_format((float)($c['weldHrs'] ?? 0), 2) . '</td>'
                . '<td>' . number_format((float)($c['machHrs'] ?? 0), 2) . '</td>'
                . '<td>' . number_format((float)($c['labor'] ?? 0), 2) . '</td>'
                . '<td>' . number_format((float)($c['consumables'] ?? 0), 2) . '</td>'
                . '<td>' . number_format((float)($c['services'] ?? 0), 2) . '</td>'
                . '<td>' . number_format((float)($c['ndt'] ?? 0), 2) . '</td>'
                . '<td>' . number_format((float)($c['lining'] ?? 0), 2) . '</td>'
                . '<td>' . number_format((float)($c['paint'] ?? 0), 2) . '</td>'
                . '<td>' . number_format((float)($c['transport'] ?? 0), 2) . '</td>'
                . '<td>' . number_format((float)($c['total'] ?? 0), 2) . '</td>'
                . '</tr>';
        }

        $total = $loaded['total_cost'] ?? 0;
        $totals = $loaded['totals'] ?? [];
        $totalsRow = function ($col) use ($totals) {
            return isset($totals[$col]) ? number_format((float)$totals[$col], 2) : '0.00';
        };
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<title>' . htmlspecialchars($quote['name'] ?? 'Quote') . '</title>'
            . '<style>body{font-family:sans-serif;padding:2rem;color:#1e293b}'
            . 'h1{font-size:1.5rem;margin-bottom:.25rem}.meta{color:#64748b;font-size:.85rem;margin-bottom:1.5rem}'
            . 'table{width:100%;border-collapse:collapse}th,td{padding:.5rem;border-bottom:1px solid #cbd5e1;text-align:right}'
            . 'th:first-child,td:first-child{text-align:left}'
            . 'th{background:#f1f5f9;font-size:.75rem}'
            . 'tfoot td{font-weight:700;border-top:2px solid #1e293b}'
            . '.total{margin-top:1rem;font-size:1.25rem;font-weight:700;text-align:right}'
            . '</style></head><body>'
            . '<h1>' . htmlspecialchars($quote['name'] ?? 'Quote') . '</h1>'
            . '<div class="meta">Customer: ' . htmlspecialchars($data['customerName'] ?? '—')
            . ' &nbsp; Status: ' . htmlspecialchars($status)
            . ' &nbsp; Currency: ' . htmlspecialchars($currency) . '</div>'
            . '<table><thead><tr><th>Item</th><th>Type</th><th>Qty</th><th>Mat</th><th>Bm hrs</th><th>W hrs</th><th>M hrs</th><th>Lab</th><th>Cons</th><th>Serve</th><th>NDT</th><th>Lining</th><th>Paint</th><th>Transport</th><th>Total</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody>'
            . '<tfoot><tr><td colspan="3">Totals</td>'
            . '<td>' . $totalsRow('material') . '</td>'
            . '<td>' . $totalsRow('boilerHrs') . '</td>'
            . '<td>' . $totalsRow('weldHrs') . '</td>'
            . '<td>' . $totalsRow('machHrs') . '</td>'
            . '<td>' . $totalsRow('labor') . '</td>'
            . '<td>' . $totalsRow('consumables') . '</td>'
            . '<td>' . $totalsRow('services') . '</td>'
            . '<td>' . $totalsRow('ndt') . '</td>'
            . '<td>' . $totalsRow('lining') . '</td>'
            . '<td>' . $totalsRow('paint') . '</td>'
            . '<td>' . $totalsRow('transport') . '</td>'
            . '<td>' . $totalsRow('total') . '</td>'
            . '</tr></tfoot></table>'
            . '<div class="total">Grand Total: ' . number_format((float)$total, 2) . ' ' . htmlspecialchars($currency) . '</div>'
            . '</body></html>';

        return ['html' => $html, 'total_cost' => $total];
    }
}

\api\dispatchIfEntry(__FILE__);
