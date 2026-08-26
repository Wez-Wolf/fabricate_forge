<?php
/**
 * fabricate_forge/api/orders.php
 *
 * Order management — orders track quote-to-delivery progress: draft → sent → won → order →
 * in-progress → complete (or lost). Owner-scoped.
 *
 * Actions:
 *   create / get / list / update / delete / set_status
 */
namespace api;

include_once(__DIR__ . "/_base.php");

class orders extends Base
{
    /** Valid order statuses (mirrors orders.js setStatus). */
    const STATUSES = ['draft', 'sent', 'won', 'order', 'in-progress', 'complete', 'lost'];

    protected function buildTable()
    {
        $this->ensureEcsTables();
        $this->pgCrud->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS "order" (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    quote_id UUID,
    entity_id UUID,
    client_id UUID,
    title VARCHAR(200) NOT NULL DEFAULT 'New Order',
    description TEXT,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    items JSONB DEFAULT '[]'::jsonb,
    total_value NUMERIC DEFAULT 0,
    delivery_date DATE,
    notes TEXT,
    data JSONB DEFAULT '{}'::jsonb,
    user_id_owner UUID NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
)
SQL);
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_order_owner ON "order"(user_id_owner)');
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_order_quote ON "order"(quote_id)');
    }

    /**
     * List orders (owner-scoped). Filters: status, search, limit.
     */
    public function handle_list($input = [])
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
        $search = \getVal($input, 'search');
        if ($search) {
            $where .= " AND (title ILIKE \${$idx} OR COALESCE(description,'') ILIKE \${$idx})";
            $params[] = "%{$search}%";
            $idx++;
        }
        $limit = (int)\getVal($input, 'limit', 50);

        $res = $this->pgCrud->read([
            'table' => 'order',
            'where' => $where,
            'params' => $params,
            'order_fields' => ['created_at DESC'],
            'limit' => min(max($limit, 1), 500),
        ]);
        return $res['data'] ?? [];
    }

    /**
     * Get one order by id.
     */
    public function handle_get($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'order_id');
        if (!$id) return ['error' => 'order_id is required.'];
        $res = $this->pgCrud->read([
            'table' => 'order',
            'where' => 'id = $1 AND user_id_owner = $2',
            'params' => [$id, $this->effOwnerId()],
            'limit' => 1,
        ]);
        $row = $res['data'][0] ?? null;
        if (!$row) return ['error' => 'Order not found.', 'error_code' => 404];
        return $row;
    }

    /**
     * Create an order.
     * Input: { title?, quote_id?, entity_id?, client_id?, description?, items?, total_value?, delivery_date?, notes? }
     */
    public function handle_create($input = [])
    {
        $res = $this->pgCrud->save([
            'table' => 'order',
            'data' => [
                'quote_id' => \getVal($input, 'quote_id'),
                'entity_id' => \getVal($input, 'entity_id'),
                'client_id' => \getVal($input, 'client_id'),
                'title' => \getVal($input, 'title', 'New Order'),
                'description' => \getVal($input, 'description', ''),
                'status' => 'draft',
                'items' => \getVal($input, 'items', []),
                'total_value' => \getVal($input, 'total_value', 0),
                'delivery_date' => \getVal($input, 'delivery_date'),
                'notes' => \getVal($input, 'notes', ''),
                'user_id_owner' => $this->effOwnerId(),
            ],
        ]);
        if (!empty($res['error'])) return $res;
        return $this->handle_get(['id' => $res['data']['id']]);
    }

    /**
     * Update an order (partial).
     */
    public function handle_update($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'order_id');
        if (!$id) return ['error' => 'order_id is required.'];
        if (!$this->handle_get(['id' => $id])) {
            return ['error' => 'Order not found.', 'error_code' => 404];
        }

        $sets = [];
        $params = [];
        $idx = 1;
        foreach (['quote_id','entity_id','client_id','title','description','items','total_value','delivery_date','notes','status'] as $col) {
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
            "UPDATE \"order\" SET " . implode(', ', $sets) .
            " WHERE id = \${$idx} AND user_id_owner = \$" . ($idx + 1),
            $params
        );
        return $this->handle_get(['id' => $id]);
    }

    /**
     * Set order status (validated against STATUSES).
     * Input: { id, status }
     */
    public function handle_set_status($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'order_id');
        $status = \getVal($input, 'status');
        if (!$id) return ['error' => 'order_id is required.'];
        if (!in_array($status, self::STATUSES)) {
            return ['error' => 'invalid-status', 'error_code' => 400];
        }
        $row = $this->handle_get(['id' => $id]);
        if (empty($row['id'])) return $row;

        $this->pgCrud->execute(
            "UPDATE \"order\" SET status = \$1, updated_at = NOW()
             WHERE id = \$2 AND user_id_owner = \$3",
            [$status, $id, $this->effOwnerId()]
        );
        return $this->handle_get(['id' => $id]);
    }

    /**
     * Hard-delete an order (mirrors original orders.delete).
     */
    public function handle_delete($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'order_id');
        if (!$id) return ['error' => 'order_id is required.'];
        $this->pgCrud->execute(
            "DELETE FROM \"order\" WHERE id = \$1 AND user_id_owner = \$2",
            [$id, $this->effOwnerId()]
        );
        return ['success' => true, 'id' => $id];
    }
}

\api\dispatchIfEntry(__FILE__);
