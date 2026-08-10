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

        $data = [
            'quoteNumber' => \getVal($input, 'quote_number') ?: 'Q-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
            'customerName' => \getVal($input, 'customer_name', ''),
            'customerEmail' => \getVal($input, 'customer_email', ''),
            'customerPhone' => \getVal($input, 'customer_phone', ''),
            'dueDate' => \getVal($input, 'due_date'),
            'validityDays' => \getVal($input, 'validity_days', 30),
            'currency' => \getVal($input, 'currency', 'USD'),
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
                'user_id_owner' => $this->user_id,
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
        $systems->user_id = $this->user_id;
        return $systems->handle_load_quote(['quote_id' => $id]);
    }

    /**
     * List quotes (light — no full cost calc). Delegates to systems.list_quotes.
     */
    public function handle_list($input = [])
    {
        $systems = new \api\systems();
        $systems->user_id = $this->user_id;
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
            $sets[] = "data = data || \${$idx}::jsonb";
            $params[] = json_encode($patch);
            $idx++;
        }
        if (!$sets) return ['error' => 'Nothing to update.'];

        $sets[] = 'updated_at = NOW()';
        $params[] = $id;
        $params[] = $this->user_id;

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
            [$id, json_encode(['status' => $newStatus, 'statusHistory' => $history]), $this->user_id]
        );
        return ['id' => $id, 'status' => $newStatus, 'statusHistory' => $history];
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
            [$id, $this->user_id]
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
            [$quoteId, $entityId, $this->user_id]
        );

        // Recalculate the quote total (ECS flow)
        $systems = new \api\systems();
        $systems->user_id = $this->user_id;
        return $systems->handle_load_quote(['quote_id' => $quoteId]);
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
            [$entityId, $this->user_id]
        );

        $systems = new \api\systems();
        $systems->user_id = $this->user_id;
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
                . '<td>' . number_format((float)($c['processTotal'] ?? 0), 2) . '</td>'
                . '<td>' . number_format((float)($c['total'] ?? 0), 2) . '</td>'
                . '</tr>';
        }

        $total = $loaded['total_cost'] ?? 0;
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<title>' . htmlspecialchars($quote['name'] ?? 'Quote') . '</title>'
            . '<style>body{font-family:sans-serif;padding:2rem;color:#1e293b}'
            . 'h1{font-size:1.5rem;margin-bottom:.25rem}.meta{color:#64748b;font-size:.85rem;margin-bottom:1.5rem}'
            . 'table{width:100%;border-collapse:collapse}th,td{padding:.5rem;border-bottom:1px solid #cbd5e1;text-align:left}'
            . 'th{background:#f1f5f9}.total{margin-top:1rem;font-size:1.25rem;font-weight:700;text-align:right}'
            . '</style></head><body>'
            . '<h1>' . htmlspecialchars($quote['name'] ?? 'Quote') . '</h1>'
            . '<div class="meta">Customer: ' . htmlspecialchars($data['customerName'] ?? '—')
            . ' &nbsp; Status: ' . htmlspecialchars($status)
            . ' &nbsp; Currency: ' . htmlspecialchars($currency) . '</div>'
            . '<table><thead><tr><th>Item</th><th>Type</th><th>Qty</th><th>Material</th><th>Process</th><th>Total</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table>'
            . '<div class="total">Grand Total: ' . number_format((float)$total, 2) . ' ' . htmlspecialchars($currency) . '</div>'
            . '</body></html>';

        return ['html' => $html, 'total_cost' => $total];
    }
}

\api\dispatchIfEntry(__FILE__);
