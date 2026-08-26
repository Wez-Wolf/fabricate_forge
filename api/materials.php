<?php
/**
 * fabricate_forge/api/materials.php
 *
 * Material library — the reference data the cost engine prices against.
 *
 * Materials-as-entities: every material is an entity (type='material') with
 * specification / dimensions / rate components. The material_library TABLE
 * is the legacy seed mirror (seeders still write it; the migration
 * scripts/migrate-material-library.php lifts rows into entities).
 *
 * This API is the ONLY editing surface for materials (the "library"): quote
 * contexts pick a material via materialLibraryId, they never edit its base
 * data. Reads reconstruct the legacy material_library row shape so every
 * consumer (cost engine, takeoff, compat, UI labels) sees the same fields.
 *
 * library_category ('material'|'fastener'|'fitting'|'flange') maps to how
 * the item is priced: mass-based vs quantity-based.
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
        // Legacy seed mirror — seeders (build-*.js) still write here; the API
        // reads the material ENTITIES. Kept until seeders migrate.
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
    user_id_owner UUID,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
)
SQL);
    }

    /**
     * List materials. Scope = the shared library (owned by the canonical
     * library owner) + the caller's own materials — same semantics as the
     * legacy "global (NULL owner) OR mine". Optional filters:
     * library_category, category, search (name/grade/profile), limit.
     */
    public function handle_list($input = [])
    {
        $libCat = \getVal($input, 'library_category');
        if ($libCat && !in_array($libCat, self::LIBRARY_CATEGORIES)) {
            return ['error' => "Invalid library_category: $libCat"];
        }
        $category = \getVal($input, 'category');
        $search = \getVal($input, 'search');
        $limit = (int)\getVal($input, 'limit', 100);
        $limit = min(max($limit, 1), 2000);

        // Lazy seed guard: if an admin opens an EMPTY library, seed it once
        // (owned under them as the canonical library owner). Handles the
        // fresh-install case where the first user is auto-admin at signup and
        // never passes through set_user_role. Idempotent — no-ops if populated.
        if ($this->isAdminCaller() && !$this->libraryHasMaterials()) {
            $this->ensureSharedLibrary($this->effOwnerId());
        }

        // The canonical library owner = the owner of the first material entity
        // (the migration seeded the shared library under one owner).
        $libOwner = $this->pgCrud->read([
            'table' => 'entity',
            'fields' => ['user_id_owner'],
            'where' => "type = 'material' AND is_active = TRUE",
            'order_fields' => ['created_at ASC'],
            'limit' => 1,
        ])['data'][0]['user_id_owner'] ?? null;

        $rows = $this->pgCrud->read([
            'table' => 'entity',
            'where' => "type = 'material' AND is_active = TRUE
                         AND (user_id_owner = \$1 OR user_id_owner = \$2)",
            'params' => [$this->effOwnerId(), $libOwner],
            'order_fields' => ['name ASC'],
        ])['data'] ?? [];

        $shapes = $this->materialEntitiesByIds(array_column($rows, 'id'));

        $out = [];
        foreach ($rows as $e) {
            $m = $shapes[$e['id']] ?? null;
            if (!$m) continue;
            if ($libCat && ($m['library_category'] ?? '') !== $libCat) continue;
            if ($category && ($m['category'] ?? '') !== $category) continue;
            if ($search) {
                if (stripos($m['name'] ?? '', $search) === false
                    && stripos((string)($m['grade'] ?? ''), $search) === false
                    && stripos((string)($m['profile'] ?? ''), $search) === false) continue;
            }
            $out[] = $m;
            if (count($out) >= $limit) break;
        }
        $this->attachSupplierNames($out);
        return $out;
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
     * Get one material by id (shared reference data).
     */
    public function handle_get($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'material_id');
        if (!$id) return ['error' => 'Material id is required.'];

        $m = $this->getMaterialEntity($id);
        if (!$m) return ['error' => 'Material not found.', 'error_code' => 404];
        $row = $this->materialRowShape($m['entity'], $m['comps']);
        $rows = [$row];
        $this->attachSupplierNames($rows);
        return $rows[0];
    }

    /** True if ANY material entity exists (shared library already seeded). */
    private function libraryHasMaterials()
    {
        return (bool)$this->pgCrud->read([
            'table' => 'entity',
            'where' => "type = 'material' AND is_active = TRUE",
            'limit' => 1,
        ])['data'][0] ?? false;
    }

    /** True if the current caller is an admin (forge user_role 1). */
    private function isAdminCaller()
    {
        $res = $this->pgCrud->read([
            'table' => 'user',
            'fields' => ['user_role', 'user_data'],
            'where' => 'id = $1',
            'params' => [$this->effOwnerId()],
            'limit' => 1,
        ])['data'][0] ?? [];
        $ud = $res['user_data'] ?? [];
        if (isset($ud['role'])) return $ud['role'] === 'admin';
        return (int)($res['user_role'] ?? 0) >= 1;
    }

    /**
     * Seed the shared material library ONCE when the first admin is created.
     *
     * If no `type='material'` entity exists yet (fresh install / empty library),
     * reads the bundled seed-data/*.json and inserts each row as a material
     * entity + specification/dimensions/rate components, owned by the given
     * admin (making them the canonical library owner). If materials already
     * exist, this is a no-op — it never re-seeds or re-owns.
     *
     * This is the single entry point that satisfies "seeds fire only on a new
     * admin account creation" — invoked from api/admin.php when a user is
     * promoted to admin, and from handle_list as a lazy fallback for the
     * auto-admin first user. Idempotent across all callers.
     *
     * @param string $adminUserId The admin to own the seeded library.
     * @return array{seeded:int,skipped:int}
     */
    public function ensureSharedLibrary($adminUserId)
    {
        // Already seeded? Never re-fire (a second admin must not get the library
        // re-created or re-owned under them).
        if ($this->libraryHasMaterials()) {
            return ['seeded' => 0, 'skipped' => 1];
        }

        $adminUserId = $adminUserId ?: $this->effOwnerId();
        $seeded = 0;
        $files = [
            __DIR__ . '/../seed-data/materials.json',
            __DIR__ . '/../seed-data/fasteners.json',
            __DIR__ . '/../seed-data/fittings.json',
            __DIR__ . '/../seed-data/flanges.json',
            __DIR__ . '/../seed-data/pipes.json',
        ];
        foreach ($files as $file) {
            if (!is_file($file)) continue;
            $rows = json_decode(file_get_contents($file), true);
            if (!is_array($rows)) continue;
            foreach ($rows as $m) {
                if (!is_array($m) || empty($m['name'])) continue;
                if ($this->insertMaterialEntity($m, $adminUserId)) $seeded++;
            }
        }
        return ['seeded' => $seeded, 'skipped' => 0];
    }

    /** Insert one seed row (seed-data/*.json shape) as a material entity. */
    private function insertMaterialEntity($m, $owner)
    {
        $name = (string)$m['name'];
        $spec = [
            'library_category' => $this->categoryForProfile($m),
            'profile' => $m['profile'] ?? ($m['type'] ?? ''),
            'material_type' => $m['materialType'] ?? $m['material_type'] ?? $m['material'] ?? null,
            'category' => $m['category'] ?? 'Carbon Steel',
            'grade' => $m['grade'] ?? null,
            'schedule' => $m['schedule'] ?? null,
        ];
        if (!empty($m['data']) && is_array($m['data'])) {
            foreach (['kind','dn','type','rating','standard','pipeOd','flangeOd','facing','dims','description','weldCirc','massKg','weldType'] as $k) {
                if (array_key_exists($k, $m['data'])) $spec[$k] = $m['data'][$k];
            }
        }
        $dims = [
            'density' => $m['density'] ?? null,
            'thickness' => $m['thickness'] ?? null,
            'mass_per_meter' => $m['massPerMeter'] ?? $m['mass_per_meter'] ?? null,
            'mass_per_area' => $m['massPerArea'] ?? $m['mass_per_area'] ?? null,
            'od' => $m['od'] ?? null,
            'wt' => $m['wt'] ?? null,
            'nb' => $m['nb'] ?? null,
            'nps' => $m['nps'] ?? null,
            'massKg' => $m['massKg'] ?? null,
            'paintAreaPerM' => $m['paintAreaPerM'] ?? null,
            'extArea' => $m['extArea'] ?? null,
        ];
        $rate = ['unit_cost' => $m['unitCost'] ?? $m['unit_cost'] ?? 0];

        $e = $this->pgCrud->save([
            'table' => 'entity',
            'data' => [
                'type' => 'material',
                'name' => $name,
                'description' => $m['description'] ?? $name,
                'quantity' => 1,
                'data' => [],
                'user_id_owner' => $owner,
            ],
        ]);
        $eid = $e['data']['id'] ?? null;
        if (!$eid) return false;
        foreach ([['specification', $spec], ['dimensions', $dims], ['rate', $rate]] as [$t, $d]) {
            $this->pgCrud->save([
                'table' => 'component',
                'data' => ['entity_id' => $eid, 'type' => $t, 'data' => $d, 'user_id_owner' => $owner],
            ]);
        }
        return true;
    }

    /** Map a seed row to a library_category by profile/type. */
    private function categoryForProfile($m)
    {
        $p = strtolower($m['profile'] ?? $m['type'] ?? '');
        if (stripos($p, 'flange') !== false) return 'flange';
        if (in_array($p, ['fitting','bend','elbow','tee','coupling','reducer','valve','gasket','nipple','cap'], true)) return 'fitting';
        if (in_array($p, ['bolt','nut','washer','stud','fastener'], true)) return 'fastener';
        if (in_array($p, ['pipe','tube','plate','section','block','round','angle','channel','h-beams','sheet','bar'], true)) return 'material';
        return 'material';
    }

    /**
     * Create a material (entity + specification/dimensions/rate components).
     * The library API is the only create surface.
     */
    public function handle_create($input = [])
    {
        $name = \getVal($input, 'name');
        if (!$name) return ['error' => 'name is required.'];
        $owner = $this->effOwnerId();

        $data = \getVal($input, 'data', []);
        $data = is_array($data) ? $data : [];

        $spec = [
            'library_category' => \getVal($input, 'library_category', 'material'),
            'profile' => \getVal($input, 'profile'),
            'material_type' => \getVal($input, 'material_type') ?? \getVal($input, 'materialType'),
            'category' => \getVal($input, 'category', 'Carbon Steel'),
            'grade' => \getVal($input, 'grade'),
            'schedule' => \getVal($input, 'schedule'),
            'aliases' => \getVal($input, 'aliases', []),
        ];
        $dims = [
            'density' => \getVal($input, 'density'),
            'thickness' => \getVal($input, 'thickness'),
            'mass_per_meter' => \getVal($input, 'mass_per_meter') ?? \getVal($input, 'massPerMeter'),
            'mass_per_area' => \getVal($input, 'mass_per_area') ?? \getVal($input, 'massPerArea'),
            'od' => \getVal($input, 'od'),
            'wt' => \getVal($input, 'wt'),
            'nb' => \getVal($input, 'nb'),
            'nps' => \getVal($input, 'nps'),
            'massKg' => \getVal($input, 'mass_kg') ?? \getVal($input, 'massKg'),
            'paintAreaPerM' => \getVal($input, 'paint_area_per_m') ?? \getVal($input, 'paintAreaPerM'),
            'extArea' => \getVal($input, 'ext_area') ?? \getVal($input, 'extArea'),
        ];
        $rate = [
            'unit_cost' => \getVal($input, 'unit_cost') ?? \getVal($input, 'unitCost') ?? 0,
            'supplier_id' => \getVal($input, 'supplier_id'),
        ];
        // Kind-specific variables ride in via data (dn, type, rating, pipeOd…)
        foreach (['kind','dn','type','rating','standard','pipeOd','flangeOd','facing','dims','description','weldCirc','massKg'] as $k) {
            if (array_key_exists($k, $data)) $spec[$k] = $data[$k];
        }

        $e = $this->pgCrud->save([
            'table' => 'entity',
            'data' => [
                'type' => 'material',
                'name' => $name,
                'description' => $data['description'] ?? $name,
                'quantity' => 1,
                'data' => [],
                'user_id_owner' => $owner,
            ],
        ]);
        $eid = $e['data']['id'] ?? null;
        if (!$eid) return ['error' => 'Material create failed.'];
        foreach ([['specification', $spec], ['dimensions', $dims], ['rate', $rate]] as [$t, $d]) {
            $this->pgCrud->save([
                'table' => 'component',
                'data' => ['entity_id' => $eid, 'type' => $t, 'data' => $d, 'user_id_owner' => $owner],
            ]);
        }
        return $this->handle_get(['id' => $eid]);
    }

    /**
     * Update a material. Spec + pricing fields editable (the library is the
     * edit surface; quote contexts only pick/reference materials). Delete
     * stays owner-only.
     */
    public function handle_update($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'material_id');
        if (!$id) return ['error' => 'Material id is required.'];
        $m = $this->getMaterialEntity($id);
        if (!$m) return ['error' => 'Material not found.', 'error_code' => 404];

        if (array_key_exists('name', $input)) {
            $this->pgCrud->execute('UPDATE entity SET name = $1, updated_at = NOW() WHERE id = $2', [$input['name'], $id]);
        }
        // data JSONB merge (kind-specific variables) → specification comp
        if (isset($input['data']) && is_array($input['data']) && !empty($input['data'])) {
            $this->patchMaterialComp($m, 'specification', $input['data']);
        }
        $specPatch = [];
        foreach (['profile','material_type','category','grade','library_category','schedule'] as $k) {
            if (array_key_exists($k, $input)) $specPatch[$k] = $input[$k];
        }
        if (isset($input['aliases']) && is_array($input['aliases'])) $specPatch['aliases'] = $input['aliases'];
        if ($specPatch) $this->patchMaterialComp($m, 'specification', $specPatch);

        $dimsPatch = [];
        foreach (['density','thickness','mass_per_meter','mass_per_area','od','wt','nb','nps'] as $k) {
            if (array_key_exists($k, $input)) $dimsPatch[$k] = $input[$k];
        }
        if (array_key_exists('mass_kg', $input)) $dimsPatch['massKg'] = $input['mass_kg'];
        if (array_key_exists('paint_area_per_m', $input)) $dimsPatch['paintAreaPerM'] = $input['paint_area_per_m'];
        if (array_key_exists('ext_area', $input)) $dimsPatch['extArea'] = $input['ext_area'];
        if ($dimsPatch) $this->patchMaterialComp($m, 'dimensions', $dimsPatch);

        $ratePatch = [];
        foreach (['unit_cost','supplier_id'] as $k) {
            if (array_key_exists($k, $input)) $ratePatch[$k] = $input[$k] === '' ? null : $input[$k];
        }
        if ($ratePatch) {
            $ratePatch['price_updated_at'] = date('c');
            $this->patchMaterialComp($m, 'rate', $ratePatch);
        }

        return $this->handle_get(['id' => $id]);
    }

    /** Patch (merge) one of the material's components by type. */
    private function patchMaterialComp($m, $type, $patch)
    {
        foreach ($m['comps'] as $c) {
            if (($c['type'] ?? '') === $type) {
                $this->pgCrud->execute(
                    "UPDATE component SET data = component.data || \$2::jsonb, updated_at = NOW() WHERE id = \$1",
                    [$c['id'], json_encode($patch)]
                );
                return;
            }
        }
        $this->pgCrud->save([
            'table' => 'component',
            'data' => [
                'entity_id' => $m['entity']['id'],
                'type' => $type,
                'data' => $patch,
                'user_id_owner' => $m['entity']['user_id_owner'] ?? $this->effOwnerId(),
            ],
        ]);
    }

    /**
     * Delete a material — owner only (shared library = the library owner).
     */
    public function handle_delete($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'material_id');
        if (!$id) return ['error' => 'Material id is required.'];
        $m = $this->getMaterialEntity($id);
        if (!$m) return ['error' => 'Material not found.', 'error_code' => 404];
        $owner = $m['entity']['user_id_owner'] ?? null;
        if (!$owner || $owner !== $this->effOwnerId()) {
            return ['error' => 'Only the owner can delete this material.', 'error_code' => 403];
        }
        $this->pgCrud->execute('UPDATE entity SET is_active = FALSE, updated_at = NOW() WHERE id = $1', [$id]);
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

    /**
     * Structured spec matcher — for import-time auto-matching.
     * Input: { section, dn, kind, desc, cls }  (+ optional pre-fetched candidates)
     * Scores every library row: DN/NB size hit (40%) + category word (30%)
     * + wall/schedule tokens (20%) + class/grade hint (10%). Returns top 5.
     */
    public function handle_match_spec($input = [])
    {
        $candidates = $input['candidates'] ?? null;
        if (!is_array($candidates)) {
            $candidates = $this->handle_list(['limit' => 2000]);
            if (isset($candidates['error'])) return $candidates;
        }
        return self::scoreSpec($input, $candidates);
    }

    /** Score $input's spec against pre-fetched candidate rows (pure). */
    public static function scoreSpec(array $input, array $candidates): array
    {
        $section = strtoupper((string)\getVal($input, 'section'));
        $desc = strtolower((string)\getVal($input, 'desc'));
        $dn = preg_replace('/[^0-9]/', '', (string)\getVal($input, 'dn'));
        $cls = strtoupper((string)\getVal($input, 'cls'));

        // Controlled-vocab category words → library profile/name fragments.
        $cats = [
            'FLANGE' => 'Flange', 'TEE' => 'Tee', 'ELBOW' => 'Elbow', 'BEND' => 'Bend',
            'REDUCER' => 'Reducer', 'COUPLING' => 'Coupling', 'CAP' => 'Cap',
            'NIPPLE' => 'Nipple', 'UNION' => 'Union', 'GASKET' => 'Gasket',
            'VALVE' => 'Valve', 'STUB' => 'Stub', 'SPACER' => 'Spacer',
            'PIPE' => 'Pipe', 'PLATE' => 'Plate', 'SHEET' => 'Sheet',
        ];
        $catWord = '';
        foreach ($cats as $kw => $word) {
            if ($section && strpos($section, $kw) !== false) { $catWord = $word; break; }
            if (!$section && strpos($desc, strtolower($kw)) !== false) { $catWord = $word; break; }
        }

        // Wall / schedule tokens both sides can share.
        $walls = [];
        foreach (['MED','MEDIUM','STD','STANDARD','XS','SCH40','SCH80','SCH160','HEAVY','LIGHT'] as $w) {
            if (strpos($desc, strtolower($w)) !== false || strpos($section, $w) !== false) $walls[] = $w;
        }

        $dnNum = (string)(int)$dn;   // "080" -> "80"
        $sizePats = $dnNum !== '' ? ['DN'.$dnNum, 'DN '.$dnNum, $dnNum.'NB', $dnNum.' NB', 'OD'.$dnNum] : [];
        $scored = [];
        foreach ($candidates as $m) {
            $name = strtoupper((string)($m['name'] ?? ''));
            $profile = strtoupper((string)($m['profile'] ?? ''));
            $score = 0.0;
            foreach ($sizePats as $p) { if (strpos($name, $p) !== false) { $score += 0.4; break; } }
            if ($dnNum === '') $score += 0.05;
            // Category (30%) — match against profile OR the row name.
            if ($catWord && ($profile === strtoupper($catWord) || strpos($name, strtoupper($catWord)) !== false)) $score += 0.3;
            elseif (!$catWord) $score += 0.05;
            // Wall / schedule (20%); unspecified wall = neutral nudge toward MED-type defaults.
            if ($walls) {
                foreach ($walls as $w) { if (strpos($name, $w) !== false) { $score += 0.2; break; } }
            } else {
                $score += 0.08;
            }
            // Class / grade hint (10%) — CS excludes stainless names, HDPE needs HDPE.
            if ($cls === 'CS' && !preg_match('/316|304|SS |STAINLESS|HDPE/i', $name)) $score += 0.1;
            elseif ($cls === 'HDPE' && strpos($name, 'HDPE') !== false) $score += 0.1;
            if ($score >= 0.5) { $m['match_score'] = round(min($score, 1.0), 2); $scored[] = $m; }
        }
        usort($scored, fn($a, $b) => $b['match_score'] <=> $a['match_score']);
        return array_slice($scored, 0, 5);
    }
}

\api\dispatchIfEntry(__FILE__);
