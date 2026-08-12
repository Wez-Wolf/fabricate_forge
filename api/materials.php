<?php
/**
 * fabricate_forge/api/materials.php
 *
 * Material library — the reference data the cost engine prices against.
 *
 * Mirrors the original Fabricate MaterialsCollection / MaterialLibrarySchema:
 *   name, profile, materialType, category, grade, density,
 *   thickness, massPerMeter, massPerArea, unitCost, aliases
 *
 * library_category ('material'|'fastener'|'fitting') maps to how the item is
 * priced: mass-based (material) vs quantity-based (fastener/fitting).
 */
namespace api;

include_once(__DIR__ . "/_base.php");

class materials extends Base
{
    const CATEGORIES = ['plate', 'section', 'pipe', 'tube', 'fitting', 'fastener', 'other'];
    const LIBRARY_CATEGORIES = ['material', 'fastener', 'fitting', 'flange'];
    const MATERIAL_TYPES = ['Carbon Steel', 'Stainless Steel', 'Aluminum', 'Copper', 'Brass', 'Titanium', 'Plastic', 'Other'];

    protected function buildTable()
    {
        $this->ensureEcsTables();
        // Material library is a plain table (not ECS) — it's reference data.
        $this->pgCrud->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS material_library (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(200) NOT NULL,
    profile VARCHAR(100),
    material_type VARCHAR(50),
    category VARCHAR(50) DEFAULT 'Carbon Steel',
    grade VARCHAR(100),
    density NUMERIC,
    thickness NUMERIC,
    mass_per_meter NUMERIC,
    mass_per_area NUMERIC,
    unit_cost NUMERIC DEFAULT 0,
    library_category VARCHAR(20) DEFAULT 'material',
    aliases JSONB DEFAULT '[]'::jsonb,
    data JSONB DEFAULT '{}'::jsonb,
    user_id_owner UUID,          -- NULL = global/system library
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
)
SQL);
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_mat_lib_cat ON material_library(library_category)');
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_mat_lib_owner ON material_library(user_id_owner)');
        // Pipe / fitting / flange attribute columns — surfaced from the piping
        // reference data (data/md) that previously lived only in the JSONB
        // `data` blob. Idempotent ALTERs: safe on every request, app-managed.
        //   od              outer diameter mm (pipe OD / fitting end-1 / flange pipe OD)
        //   wt              wall thickness mm (pipe WT / fitting end-1 WT)
        //   schedule        pipe schedule (#40/STD/XS/XXS) or fitting series
        //   nb              nominal bore DN
        //   nps             inch size (NPS)
        //   mass_kg         mass per item (fittings/flanges)
        //   paint_area_per_m  m² per metre external area (pipes)
        //   ext_area        m² per item external paint area (fittings/flanges)
        foreach ([
            'od'              => 'NUMERIC',
            'wt'              => 'NUMERIC',
            'schedule'        => 'VARCHAR(20)',
            'nb'              => 'NUMERIC',
            'nps'             => 'VARCHAR(20)',
            'mass_kg'         => 'NUMERIC',
            'paint_area_per_m'=> 'NUMERIC',
            'ext_area'        => 'NUMERIC',
            // Supplier pricing: which supplier prices this material + when the
            // buy price was last set (the SELL unit_cost is the same column;
            // the timestamp records the last price update).
            'supplier_id'     => 'UUID',
            'price_updated_at'=> 'TIMESTAMPTZ',
        ] as $col => $type) {
            $this->pgCrud->execute("ALTER TABLE material_library ADD COLUMN IF NOT EXISTS $col $type");
        }
    }

    /**
     * List materials. Global (owner NULL) + user's own, optional filters.
     * Filters: library_category, category, search (name/grade/profile), limit.
     */
    public function handle_list($input = [])
    {
        $where = '(user_id_owner IS NULL OR user_id_owner = $1)';
        $params = [$this->effOwnerId()];
        $idx = 2;

        $libCat = \getVal($input, 'library_category');
        if ($libCat) {
            if (!in_array($libCat, self::LIBRARY_CATEGORIES)) {
                return ['error' => "Invalid library_category: $libCat"];
            }
            $where .= " AND library_category = \${$idx}";
            $params[] = $libCat;
            $idx++;
        }

        $category = \getVal($input, 'category');
        if ($category) {
            $where .= " AND category = \${$idx}";
            $params[] = $category;
            $idx++;
        }

        $search = \getVal($input, 'search');
        if ($search) {
            $where .= " AND (name ILIKE \${$idx} OR COALESCE(grade,'') ILIKE \${$idx} OR COALESCE(profile,'') ILIKE \${$idx})";
            $params[] = "%{$search}%";
            $idx++;
        }

        $limit = (int)\getVal($input, 'limit', 100);
        $limit = min(max($limit, 1), 2000);

        $res = $this->pgCrud->read([
            'table' => 'material_library',
            'where' => $where,
            'params' => $params,
            'order_fields' => ['name ASC'],
            'limit' => $limit,
        ]);
        $rows = $res['data'] ?? [];
        // Normalize JSONB arrays that PgCrud returns as strings
        foreach ($rows as &$r) {
            $r['aliases'] = self::decodeJsonArray($r['aliases'] ?? []);
            $r['data'] = self::decodeJsonArray($r['data'] ?? []);
        }
        unset($r);
        $this->attachSupplierNames($rows);
        return $rows;
    }

    /**
     * Decode a JSONB value that may come back as a string (PgCrud only
     * auto-decodes {} objects, not [] arrays) or as an array already.
     */
    public static function decodeJsonArray($val)
    {
        if (is_array($val)) return $val;
        if (is_string($val)) {
            $decoded = json_decode($val, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * Attach supplier_name to material rows (from the supplier table) in one
     * batch query. Rows are passed by reference so callers keep their shape.
     */
    private function attachSupplierNames(&$rows)
    {
        $ids = [];
        foreach ($rows as $r) {
            if (!empty($r['supplier_id'])) $ids[$r['supplier_id']] = true;
        }
        if (!$ids) return;

        $res = $this->pgCrud->read([
            'table' => 'supplier',
            'fields' => ['id', 'company_name'],
            'where' => 'id = ANY($1::uuid[]) AND user_id_owner = $2 AND is_active = TRUE',
            'params' => ['{' . implode(',', array_keys($ids)) . '}', $this->effOwnerId()],
        ]);
        $names = [];
        foreach (($res['data'] ?? []) as $s) $names[$s['id']] = $s['company_name'];

        foreach ($rows as &$r) {
            $r['supplier_name'] = !empty($r['supplier_id']) ? ($names[$r['supplier_id']] ?? null) : null;
        }
        unset($r);
    }

    /**
     * Get one material by id (global or own).
     */
    public function handle_get($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'material_id');
        if (!$id) return ['error' => 'Material id is required.'];

        $res = $this->pgCrud->read([
            'table' => 'material_library',
            'where' => 'id = $1 AND (user_id_owner IS NULL OR user_id_owner = $2)',
            'params' => [$id, $this->effOwnerId()],
            'limit' => 1,
        ]);
        $row = $res['data'][0] ?? null;
        if (!$row) return ['error' => 'Material not found.', 'error_code' => 404];
        $row['aliases'] = self::decodeJsonArray($row['aliases'] ?? []);
        $row['data'] = self::decodeJsonArray($row['data'] ?? []);
        $rows = [$row];
        $this->attachSupplierNames($rows);
        return $rows[0];
    }

    /**
     * Create a user-owned material (global library is seeded by scripts, not API).
     */
    public function handle_create($input = [])
    {
        $name = \getVal($input, 'name');
        if (!$name) return ['error' => 'name is required.'];

        $data = \getVal($input, 'data', []);
        $data = is_array($data) ? $data : [];

        $res = $this->pgCrud->save([
            'table' => 'material_library',
            'data' => [
                'name' => $name,
                'profile' => \getVal($input, 'profile'),
                'material_type' => \getVal($input, 'material_type'),
                'category' => \getVal($input, 'category', 'Carbon Steel'),
                'grade' => \getVal($input, 'grade'),
                'density' => \getVal($input, 'density'),
                'thickness' => \getVal($input, 'thickness'),
                'mass_per_meter' => \getVal($input, 'mass_per_meter'),
                'mass_per_area' => \getVal($input, 'mass_per_area'),
                'unit_cost' => \getVal($input, 'unit_cost', 0),
                'library_category' => \getVal($input, 'library_category', 'material'),
                // Pipe/fitting/flange attribute columns (see buildTable)
                'od' => \getVal($input, 'od'),
                'wt' => \getVal($input, 'wt'),
                'schedule' => \getVal($input, 'schedule'),
                'nb' => \getVal($input, 'nb'),
                'nps' => \getVal($input, 'nps'),
                'mass_kg' => \getVal($input, 'mass_kg'),
                'paint_area_per_m' => \getVal($input, 'paint_area_per_m'),
                'ext_area' => \getVal($input, 'ext_area'),
                'aliases' => \getVal($input, 'aliases', []),
                'data' => $data,
                'user_id_owner' => $this->effOwnerId(),
            ],
        ]);
        if (!empty($res['error'])) return $res;
        return $this->handle_get(['id' => $res['data']['id']]);
    }

    /**
     * Update a material. Pricing fields (unit_cost, supplier_id) are editable
     * on ANY row — even global library items — because the buy price and which
     * supplier quotes it are the user's own commercial data. Spec fields
     * (name, profile, dims) stay read-only for global rows.
     */
    public function handle_update($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'material_id');
        if (!$id) return ['error' => 'Material id is required.'];

        $existing = $this->handle_get(['id' => $id]);
        if (isset($existing['error'])) return $existing;

        $sets = [];
        $params = [];
        $idx = 1;

        // Spec columns — editable on any row (global or user-owned). The
        // seeded library is a starting point; users fix names/dims as they
        // encounter real-world data. (Delete stays own-only.)
        $specCols = ['name','profile','material_type','category','grade','density',
                     'thickness','mass_per_meter','mass_per_area','library_category',
                     'od','wt','schedule','nb','nps','mass_kg','paint_area_per_m','ext_area'];
        foreach ($specCols as $col) {
            if (array_key_exists($col, $input)) {
                $sets[] = "$col = \${$idx}";
                $params[] = $input[$col];
                $idx++;
            }
        }

        // Pricing columns — editable on ANY material; stamp price_updated_at
        // whenever the buy price or supplier changes.
        $priceTouched = false;
        foreach (['unit_cost', 'supplier_id'] as $col) {
            if (array_key_exists($col, $input)) {
                $sets[] = "$col = \${$idx}";
                $params[] = $input[$col] === '' ? null : $input[$col];
                $idx++;
                $priceTouched = true;
            }
        }
        if ($priceTouched) {
            $sets[] = 'price_updated_at = NOW()';
        }

        if (isset($input['aliases']) && is_array($input['aliases'])) {
            $sets[] = "aliases = \${$idx}::jsonb";
            $params[] = json_encode($input['aliases']);
            $idx++;
        }
        // data JSONB merge (kind-specific variables: dn, type, rating, pipeOd,
        // massKg, paintAreaPerM, extArea) — preserves keys not in the payload.
        if (isset($input['data']) && is_array($input['data']) && !empty($input['data'])) {
            $sets[] = "data = COALESCE(data, '{}'::jsonb) || \${$idx}::jsonb";
            $params[] = json_encode($input['data']);
            $idx++;
        }
        if (!$sets) return ['error' => 'Nothing to update.'];

        $sets[] = 'updated_at = NOW()';
        $params[] = $id;
        $params[] = $this->effOwnerId();

        $this->pgCrud->execute(
            "UPDATE material_library SET " . implode(', ', $sets) .
            " WHERE id = \${$idx} AND (user_id_owner IS NULL OR user_id_owner = \$" . ($idx + 1) . ")",
            $params
        );
        return $this->handle_get(['id' => $id]);
    }

    /**
     * Delete own material.
     */
    public function handle_delete($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'material_id');
        if (!$id) return ['error' => 'Material id is required.'];

        $existing = $this->handle_get(['id' => $id]);
        if (isset($existing['error'])) return $existing;
        if (empty($existing['user_id_owner'])) {
            return ['error' => 'Global library materials are read-only.', 'error_code' => 403];
        }

        $this->pgCrud->execute(
            "DELETE FROM material_library WHERE id = \$1 AND user_id_owner = \$2",
            [$id, $this->effOwnerId()]
        );
        return ['success' => true, 'id' => $id];
    }

    /**
     * Density lookup — used by the physics layer (mass = L×W×T × density).
     */
    public function handle_get_density($input = [])
    {
        $id = \getVal($input, 'material_id') ?: \getVal($input, 'id');
        if (!$id) return ['error' => 'material_id is required.'];

        $mat = $this->handle_get(['id' => $id]);
        if (isset($mat['error'])) return $mat;
        return [
            'material_id' => $mat['id'],
            'density' => (float)($mat['density'] ?? 0),
            'mass_per_meter' => (float)($mat['mass_per_meter'] ?? 0),
            'mass_per_area' => (float)($mat['mass_per_area'] ?? 0),
            'unit_cost' => (float)($mat['unit_cost'] ?? 0),
        ];
    }

    /**
     * Material matching — grade (40%) + category (30%) + alias names (30%).
     * Returns ranked candidates so the BOM import can auto-link items.
     */
    public function handle_match($input = [])
    {
        $search = \getVal($input, 'search') ?: \getVal($input, 'q');
        if (!$search) return ['error' => 'search is required.'];

        $candidates = $this->handle_list([
            'limit' => 200,
            'search' => $search,
        ]);
        if (isset($candidates['error'])) return $candidates;

        $scored = [];
        $needle = strtolower($search);
        foreach ($candidates as $m) {
            $score = 0.0;
            $name = strtolower($m['name'] ?? '');
            $grade = strtolower($m['grade'] ?? '');
            $aliases = self::decodeJsonArray($m['aliases'] ?? []);

            // Grade match (40%)
            if ($grade && str_contains($needle, $grade)) $score += 0.4;
            // Category match (30%)
            if (in_array($needle, array_map('strtolower', self::CATEGORIES))) {
                $score += (strtolower($m['category'] ?? '') === $needle) ? 0.3 : 0;
            }
            // Alias/name match (30%)
            $aliasHit = false;
            foreach ($aliases as $a) {
                if (str_contains($needle, strtolower((string)$a))) { $aliasHit = true; break; }
            }
            if ($aliasHit) $score += 0.3;
            elseif (str_contains($name, $needle)) $score += 0.3;

            if ($score > 0) {
                $m['match_score'] = round($score, 2);
                $scored[] = $m;
            }
        }

        usort($scored, fn($a, $b) => $b['match_score'] <=> $a['match_score']);
        return array_slice($scored, 0, 10);
    }
}

\api\dispatchIfEntry(__FILE__);
