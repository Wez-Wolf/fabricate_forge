<?php
/**
 * fabricate_forge/api/suppliers.php
 *
 * Supplier management — the companies that price/supply materials.
 * Mirrors clients.php with supplier-specific fields:
 *   company_name, primary_contact, email, phone, city, country,
 *   materials_supplied (e.g. "Plates & Sheets, Fasteners"), lead_time_days,
 *   payment_terms, notes
 * Owner-scoped: each user sees only their own suppliers.
 */
namespace api;

include_once(__DIR__ . "/_base.php");

class suppliers extends Base
{
    protected function buildTable()
    {
        $this->ensureEcsTables();
        $this->pgCrud->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS supplier (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_name VARCHAR(200) NOT NULL,
    primary_contact VARCHAR(200),
    email VARCHAR(200),
    phone VARCHAR(100),
    city VARCHAR(100),
    country VARCHAR(100),
    materials_supplied TEXT,
    lead_time_days INT,
    payment_terms VARCHAR(100),
    notes TEXT,
    data JSONB DEFAULT '{}'::jsonb,
    user_id_owner UUID NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    is_active BOOLEAN DEFAULT TRUE
)
SQL);
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_supplier_owner ON supplier(user_id_owner)');
    }

    /**
     * List suppliers (owner-scoped). Filters: search, limit.
     */
    public function handle_list($input = [])
    {
        $where = 'user_id_owner = $1 AND is_active = TRUE';
        $params = [$this->effOwnerId()];
        $idx = 2;

        $search = \getVal($input, 'search');
        if ($search) {
            $where .= " AND (company_name ILIKE \${$idx} OR COALESCE(primary_contact,'') ILIKE \${$idx} OR COALESCE(email,'') ILIKE \${$idx} OR COALESCE(materials_supplied,'') ILIKE \${$idx})";
            $params[] = "%{$search}%";
            $idx++;
        }

        $limit = (int)\getVal($input, 'limit', 200);
        $res = $this->pgCrud->read([
            'table' => 'supplier',
            'where' => $where,
            'params' => $params,
            'order_fields' => ['company_name ASC'],
            'limit' => min(max($limit, 1), 500),
        ]);
        return $res['data'] ?? [];
    }

    /**
     * Get one supplier by id.
     */
    public function handle_get($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'supplier_id');
        if (!$id) return ['error' => 'supplier_id is required.'];
        $res = $this->pgCrud->read([
            'table' => 'supplier',
            'where' => 'id = $1 AND user_id_owner = $2',
            'params' => [$id, $this->effOwnerId()],
            'limit' => 1,
        ]);
        $row = $res['data'][0] ?? null;
        if (!$row) return ['error' => 'Supplier not found.', 'error_code' => 404];
        return $row;
    }

    /**
     * Create a supplier.
     */
    public function handle_create($input = [])
    {
        $companyName = \getVal($input, 'company_name');
        if (!$companyName) return ['error' => 'company_name is required.'];

        $res = $this->pgCrud->save([
            'table' => 'supplier',
            'data' => [
                'company_name' => $companyName,
                'primary_contact' => \getVal($input, 'primary_contact'),
                'email' => \getVal($input, 'email'),
                'phone' => \getVal($input, 'phone'),
                'city' => \getVal($input, 'city'),
                'country' => \getVal($input, 'country'),
                'materials_supplied' => \getVal($input, 'materials_supplied'),
                'lead_time_days' => \getVal($input, 'lead_time_days'),
                'payment_terms' => \getVal($input, 'payment_terms'),
                'notes' => \getVal($input, 'notes'),
                'user_id_owner' => $this->effOwnerId(),
            ],
        ]);
        if (!empty($res['error'])) return $res;
        return $this->handle_get(['id' => $res['data']['id']]);
    }

    /**
     * Update a supplier (partial).
     */
    public function handle_update($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'supplier_id');
        if (!$id) return ['error' => 'supplier_id is required.'];
        if (!$this->handle_get(['id' => $id])) {
            return ['error' => 'Supplier not found.', 'error_code' => 404];
        }

        $sets = [];
        $params = [];
        $idx = 1;
        foreach (['company_name','primary_contact','email','phone','city','country','materials_supplied','lead_time_days','payment_terms','notes'] as $col) {
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
            "UPDATE supplier SET " . implode(', ', $sets) .
            " WHERE id = \${$idx} AND user_id_owner = \$" . ($idx + 1),
            $params
        );
        return $this->handle_get(['id' => $id]);
    }

    /**
     * Soft-delete a supplier.
     */
    public function handle_delete($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'supplier_id');
        if (!$id) return ['error' => 'supplier_id is required.'];
        $this->pgCrud->execute(
            "UPDATE supplier SET is_active = FALSE, updated_at = NOW()
             WHERE id = \$1 AND user_id_owner = \$2",
            [$id, $this->effOwnerId()]
        );
        return ['success' => true, 'id' => $id];
    }
}

\api\dispatchIfEntry(__FILE__);
