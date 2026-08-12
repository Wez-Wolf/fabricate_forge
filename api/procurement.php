<?php
/**
 * fabricate_forge/api/procurement.php
 *
 * Procurement — purchase orders, supplier quotes, received goods.
 * Ported from the original app's procurement.js method file.
 *
 * Actions (prefix = table):
 *   po_list / po_create / po_update / po_set_status   — purchase orders
 *   sq_list / sq_create                                — supplier quotes
 *   rg_list / rg_create                                — received goods
 */
namespace api;

include_once(__DIR__ . "/_base.php");

class procurement extends Base
{
    /** Valid PO statuses (mirrors procurement.js setStatus). */
    const PO_STATUSES = ['draft', 'quoted', 'ordered', 'received'];

    protected function buildTable()
    {
        $this->ensureEcsTables();
        $this->pgCrud->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS purchase_order (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    supplier_name VARCHAR(200) NOT NULL DEFAULT '',
    quote_id UUID,
    items JSONB DEFAULT '[]'::jsonb,
    total_value NUMERIC DEFAULT 0,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    order_date TIMESTAMPTZ DEFAULT NOW(),
    expected_date DATE,
    notes TEXT,
    data JSONB DEFAULT '{}'::jsonb,
    user_id_owner UUID NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
)
SQL);
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_po_owner ON purchase_order(user_id_owner)');

        $this->pgCrud->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS supplier_quote (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    supplier_name VARCHAR(200) NOT NULL DEFAULT '',
    material_id UUID,
    unit_price NUMERIC DEFAULT 0,
    min_order_qty NUMERIC DEFAULT 1,
    lead_time_days INT DEFAULT 14,
    valid_until DATE,
    notes TEXT,
    data JSONB DEFAULT '{}'::jsonb,
    user_id_owner UUID NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
)
SQL);
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_sq_owner ON supplier_quote(user_id_owner)');

        $this->pgCrud->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS received_goods (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    purchase_order_id UUID,
    items JSONB DEFAULT '[]'::jsonb,
    received_date TIMESTAMPTZ DEFAULT NOW(),
    notes TEXT,
    data JSONB DEFAULT '{}'::jsonb,
    user_id_owner UUID NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW()
)
SQL);
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_rg_owner ON received_goods(user_id_owner)');
    }

    // ── Purchase Orders ────────────────────────────────

    public function handle_po_list($input = [])
    {
        $where = 'user_id_owner = $1';
        $params = [$this->effOwnerId()];
        $idx = 2;
        $status = \getVal($input, 'status');
        if ($status) {
            $where .= " AND status = \${$idx}";
            $params[] = $status;
            $idx++;
        }
        $limit = (int)\getVal($input, 'limit', 50);
        $res = $this->pgCrud->read([
            'table' => 'purchase_order',
            'where' => $where,
            'params' => $params,
            'order_fields' => ['created_at DESC'],
            'limit' => min(max($limit, 1), 500),
        ]);
        return $res['data'] ?? [];
    }

    public function handle_po_create($input = [])
    {
        $res = $this->pgCrud->save([
            'table' => 'purchase_order',
            'data' => [
                'supplier_name' => \getVal($input, 'supplier_name', ''),
                'quote_id' => \getVal($input, 'quote_id'),
                'items' => \getVal($input, 'items', []),
                'total_value' => \getVal($input, 'total_value', 0),
                'status' => 'draft',
                'expected_date' => \getVal($input, 'expected_date'),
                'notes' => \getVal($input, 'notes', ''),
                'user_id_owner' => $this->effOwnerId(),
            ],
        ]);
        if (!empty($res['error'])) return $res;
        return $this->poGet($res['data']['id']);
    }

    public function handle_po_update($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'po_id');
        if (!$id) return ['error' => 'po_id is required.'];
        if (!$this->poGet($id)) return ['error' => 'PO not found.', 'error_code' => 404];

        $sets = [];
        $params = [];
        $idx = 1;
        foreach (['supplier_name','quote_id','items','total_value','expected_date','notes','status'] as $col) {
            if (array_key_exists($col, $input)) {
                $sets[] = "$col = \${$idx}";
                $params[] = $input[$col];
                $idx++;
            }
        }
        if (!$sets) return ['error' => 'Nothing to update.'];

        $sets[] = 'updated_at = NOW()';
        $params[] = $id;
        $params[] = $this->effOwnerId();

        $this->pgCrud->execute(
            "UPDATE purchase_order SET " . implode(', ', $sets) .
            " WHERE id = \${$idx} AND user_id_owner = \$" . ($idx + 1),
            $params
        );
        return $this->poGet($id);
    }

    public function handle_po_set_status($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'po_id');
        $status = \getVal($input, 'status');
        if (!$id) return ['error' => 'po_id is required.'];
        if (!in_array($status, self::PO_STATUSES)) {
            return ['error' => 'invalid-status', 'error_code' => 400];
        }
        if (!$this->poGet($id)) return ['error' => 'PO not found.', 'error_code' => 404];
        $this->pgCrud->execute(
            "UPDATE purchase_order SET status = \$1, updated_at = NOW()
             WHERE id = \$2 AND user_id_owner = \$3",
            [$status, $id, $this->effOwnerId()]
        );
        return $this->poGet($id);
    }

    // ── Supplier Quotes ────────────────────────────────

    public function handle_sq_list($input = [])
    {
        $limit = (int)\getVal($input, 'limit', 50);
        $res = $this->pgCrud->read([
            'table' => 'supplier_quote',
            'where' => 'user_id_owner = $1',
            'params' => [$this->effOwnerId()],
            'order_fields' => ['created_at DESC'],
            'limit' => min(max($limit, 1), 500),
        ]);
        return $res['data'] ?? [];
    }

    public function handle_sq_create($input = [])
    {
        $res = $this->pgCrud->save([
            'table' => 'supplier_quote',
            'data' => [
                'supplier_name' => \getVal($input, 'supplier_name', ''),
                'material_id' => \getVal($input, 'material_id'),
                'unit_price' => \getVal($input, 'unit_price', 0),
                'min_order_qty' => \getVal($input, 'min_order_qty', 1),
                'lead_time_days' => \getVal($input, 'lead_time_days', 14),
                'valid_until' => \getVal($input, 'valid_until'),
                'notes' => \getVal($input, 'notes', ''),
                'user_id_owner' => $this->effOwnerId(),
            ],
        ]);
        if (!empty($res['error'])) return $res;
        $id = $res['data']['id'];
        return $this->pgCrud->read([
            'table' => 'supplier_quote',
            'where' => 'id = $1 AND user_id_owner = $2',
            'params' => [$id, $this->effOwnerId()],
            'limit' => 1,
        ])['data'][0] ?? $id;
    }

    // ── Received Goods ─────────────────────────────────

    public function handle_rg_list($input = [])
    {
        $limit = (int)\getVal($input, 'limit', 50);
        $res = $this->pgCrud->read([
            'table' => 'received_goods',
            'where' => 'user_id_owner = $1',
            'params' => [$this->effOwnerId()],
            'order_fields' => ['received_date DESC'],
            'limit' => min(max($limit, 1), 500),
        ]);
        return $res['data'] ?? [];
    }

    public function handle_rg_create($input = [])
    {
        $res = $this->pgCrud->save([
            'table' => 'received_goods',
            'data' => [
                'purchase_order_id' => \getVal($input, 'purchase_order_id'),
                'items' => \getVal($input, 'items', []),
                'notes' => \getVal($input, 'notes', ''),
                'user_id_owner' => $this->effOwnerId(),
            ],
        ]);
        if (!empty($res['error'])) return $res;
        $id = $res['data']['id'];
        return $this->pgCrud->read([
            'table' => 'received_goods',
            'where' => 'id = $1 AND user_id_owner = $2',
            'params' => [$id, $this->effOwnerId()],
            'limit' => 1,
        ])['data'][0] ?? $id;
    }

    // ── shared ─────────────────────────────────────────

    private function poGet($id)
    {
        if (!$id) return null;
        return $this->pgCrud->read([
            'table' => 'purchase_order',
            'where' => 'id = $1 AND user_id_owner = $2',
            'params' => [$id, $this->effOwnerId()],
            'limit' => 1,
        ])['data'][0] ?? null;
    }
}

\api\dispatchIfEntry(__FILE__);
