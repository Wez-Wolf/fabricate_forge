<?php
/**
 * fabricate_forge/api/_base.php
 *
 * Project API base — extends forge\api\Base with Fabricate ECS (Entity
 * Component System) helpers.
 *
 * ECS model:
 *   entity      — container with type: part | assembly | fastener | quote
 *   component   — data block attached to an entity, typed:
 *                 basic | dimensions | material | cost | process | rate |
 *                 specification | notes | status | cadData
 *   link        — relationship between two entities, typed:
 *                 contains | references | suppliedBy | uses | dependsOn | relatedTo
 *
 * All three tables share the same shape: id, type, data (JSONB), owner,
 * timestamps. That uniformity is what makes the ECS handlers generic.
 */
namespace api;

$forgeDir = dirname(__DIR__, 2) . '/forge';
require_once($forgeDir . '/php/util/helpers.php');
require_once($forgeDir . '/php/api/Base.php');
require_once($forgeDir . '/php/api/Auth.php');

\loadEnv(dirname(__DIR__) . '/.env');

/**
 * Idempotent ECS table creation — shared by entities.php / components.php /
 * links.php (every endpoint includes _base.php, so these resolve everywhere).
 * Each endpoint file declares ITS OWN tables in buildTable(); the ECS core
 * tables are declared here once because all three endpoints touch them.
 */
function ensureEcs($pg, $table)
{
    static $tables = [
        'entity' => [
            'create' => <<<'SQL'
CREATE TABLE IF NOT EXISTS entity (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    type VARCHAR(30) NOT NULL CHECK (type IN ('part','assembly','fastener','fitting','material','quote')),
    name VARCHAR(200) NOT NULL,
    description TEXT,
    quote_id UUID,
    quantity NUMERIC DEFAULT 1,
    data JSONB DEFAULT '{}'::jsonb,
    user_id_owner UUID NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    is_active BOOLEAN DEFAULT TRUE
)
SQL,
            'columns' => [
                'CREATE INDEX IF NOT EXISTS idx_entity_owner ON entity(user_id_owner)',
                'CREATE INDEX IF NOT EXISTS idx_entity_quote ON entity(quote_id)',
                'CREATE INDEX IF NOT EXISTS idx_entity_type ON entity(type)',
            ],
        ],

        'component' => [
            'create' => <<<'SQL'
CREATE TABLE IF NOT EXISTS component (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    entity_id UUID NOT NULL REFERENCES entity(id) ON DELETE CASCADE,
    type VARCHAR(30) NOT NULL CHECK (type IN (
        'basic','dimensions','material','cost','process','rate',
        'specification','notes','status','cadData'
    )),
    data JSONB DEFAULT '{}'::jsonb,
    quote_id UUID,
    user_id_owner UUID NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
)
SQL,
            'columns' => [
                'CREATE INDEX IF NOT EXISTS idx_component_entity ON component(entity_id)',
                'CREATE INDEX IF NOT EXISTS idx_component_type ON component(type)',
                'CREATE INDEX IF NOT EXISTS idx_component_quote ON component(quote_id)',
            ],
        ],

        'link' => [
            'create' => <<<'SQL'
CREATE TABLE IF NOT EXISTS link (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    from_id UUID NOT NULL REFERENCES entity(id) ON DELETE CASCADE,
    to_id UUID NOT NULL REFERENCES entity(id) ON DELETE CASCADE,
    type VARCHAR(30) NOT NULL CHECK (type IN (
        'contains','references','suppliedBy','uses','dependsOn','relatedTo'
    )),
    quantity NUMERIC DEFAULT 1,
    data JSONB DEFAULT '{}'::jsonb,
    user_id_owner UUID NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
)
SQL,
            'columns' => [
                'CREATE INDEX IF NOT EXISTS idx_link_from ON link(from_id)',
                'CREATE INDEX IF NOT EXISTS idx_link_to ON link(to_id)',
                'CREATE INDEX IF NOT EXISTS idx_link_type ON link(type)',
                'CREATE INDEX IF NOT EXISTS idx_link_owner ON link(user_id_owner)',
            ],
        ],
    ];

    $t = $tables[$table] ?? null;
    if (!$t) return false;
    if (!empty($t['create'])) $pg->execute($t['create']);
    foreach ($t['columns'] ?? [] as $sql) $pg->execute($sql);
    return true;
}

/**
 * Dispatch guard — fires dispatch ONLY when this file is the HTTP entry point.
 * Prevents cross-endpoint includes (e.g. rates.php included by process.php)
 * from triggering dispatch on include. Mirrors progeny_forge's
 * realpath(SCRIPT_FILENAME) auto-run guard.
 */
function dispatchIfEntry($scriptFile)
{
    $entry = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if ($entry && realpath($scriptFile) === realpath($entry)) {
        \forge\api\dispatch($scriptFile);
    }
}

abstract class Base extends \forge\api\Base
{
    /** @var string|null The authenticated user's UUID (from forge auth). */
    protected $user_id = null;

    /** @var string|null Cached team-owner id ("data silo") for this user. */
    private $team_owner_id = null;

    protected $publicActions = [];

    /**
     * Resolve the authenticated user id once per request.
     */
    protected function checkAuth($input)
    {
        $auth = parent::checkAuth($input);
        if ($auth) {
            $this->user_id = $auth['user_id'] ?? $auth['auth_id'] ?? null;
        }
        return $auth;
    }

    // ── Team / data-silo resolution ──────────────────────

    /**
     * The user id whose data this request may touch.
     *
     * Solo users: their own id (unchanged behavior).
     * Team members: the team owner's id — everyone on a team works the
     * owner's data silo ("invitee sees my quotes"). No gates yet; this is
     * the single seam future per-team permissions hang on.
     *
     * One team per user (enforced on join). Resolved once per request.
     *
     * @return string|null
     */
    protected function effOwnerId()
    {
        if ($this->team_owner_id !== null) return $this->team_owner_id;
        $this->team_owner_id = $this->user_id;
        if (!$this->user_id) return $this->team_owner_id;

        $res = $this->pgCrud->read([
            'table' => 'team_member',
            'fields' => ['team_id'],
            'where' => 'user_id = $1',
            'params' => [$this->user_id],
            'limit' => 1,
        ]);
        $teamId = $res['data'][0]['team_id'] ?? null;
        if ($teamId) {
            $t = $this->pgCrud->read([
                'table' => 'team',
                'fields' => ['owner_id'],
                'where' => 'id = $1',
                'params' => [$teamId],
                'limit' => 1,
            ]);
            if (!empty($t['data'][0]['owner_id'])) {
                $this->team_owner_id = $t['data'][0]['owner_id'];
            }
        }
        return $this->team_owner_id;
    }

    // ── ECS shared helpers ──────────────────────────────

    /**
     * Ensure the ECS core tables exist (entity, component, link).
     */
    protected function ensureEcsTables()
    {
        foreach (['entity', 'component', 'link'] as $t) {
            ensureEcs($this->pgCrud, $t);
        }
        // Idempotent type-taxonomy upgrade: bought-in pipe hardware (flanges,
        // elbows, tees, valves, gaskets…) is 'fitting', not 'part'. The CREATE
        // above covers fresh installs; existing DBs need the CHECK rebuilt.
        // Guard on the current constraint: only DROP/re-ADD when a newer type
        // is missing — NEVER drop+re-add on every request (ACCESS EXCLUSIVE
        // lock + full re-check per call).
        $types = ['part', 'assembly', 'fastener', 'fitting', 'material', 'quote'];
        $rule = $this->pgCrud->read([
            'table' => 'pg_constraint',
            'fields' => ['pg_get_constraintdef(oid) AS def'],
            'where' => "conname = 'entity_type_check' AND conrelid = 'entity'::regclass",
            'limit' => 1,
        ]);
        $def = $rule['data'][0]['def'] ?? '';
        $missing = false;
        foreach ($types as $t) {
            if (stripos($def, "'" . $t . "'") === false) { $missing = true; break; }
        }
        if ($missing) {
            $this->pgCrud->execute('ALTER TABLE entity DROP CONSTRAINT IF EXISTS entity_type_check');
            $this->pgCrud->execute(
                'ALTER TABLE entity ADD CONSTRAINT entity_type_check
                 CHECK (type IN (\'part\',\'assembly\',\'fastener\',\'fitting\',\'material\',\'quote\'))'
            );
        }

        // Shared app tables (idempotent — created once, indexed once).
        $this->pgCrud->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS company_settings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id_owner UUID NOT NULL UNIQUE,
    data JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
)
SQL);
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_cs_owner ON company_settings(user_id_owner)');

        $this->pgCrud->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS user_prefs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL UNIQUE REFERENCES "user"(id) ON DELETE CASCADE,
    data JSONB DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
)
SQL);
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_up_user ON user_prefs(user_id)');
    }

    /** Round to 6 decimals — single source of truth for the codebase. */
    protected static function r6($n)
    {
        return round((float)$n, 6);
    }

    /** Round to 2 decimals — single source of truth for the codebase. */
    protected static function r2($n)
    {
        return round((float)$n, 2);
    }

    // ── Mass system (pure functions, no DB) ────────────
    // Computes physical mass from material component + library row.
    // Entity-agnostic: no mention of quote/part/assembly.

    /** @return array{massKg, lengthM, unit, od, wt, dn, schedule, grade} */
    protected static function massCompute($matData, $libraryItem, $lengthOverrideMm = null)
    {
        $libData = is_array($libraryItem['data'] ?? null) ? $libraryItem['data'] : [];
        $density = (float)($libraryItem['density'] ?? $matData['density'] ?? 0);
        // Optional length override (total cut length incl. D1 green secondary)
        // so mass-based pricing matches the costPerM / area paths.
        $length = $lengthOverrideMm !== null
            ? (float)$lengthOverrideMm
            : (float)($matData['length'] ?? 0);
        $width = (float)($matData['width'] ?? 0);
        $thickness = (float)($matData['thickness'] ?? $libraryItem['thickness'] ?? 0);
        $category = strtolower((string)($matData['category'] ?? $libraryItem['library_category'] ?? ''));
        $profile = strtolower((string)($libraryItem['profile'] ?? ''));
        $lengthM = $length / 1000;

        // Explicit mass field wins.
        if (!empty($matData['mass'])) {
            return [
                'massKg' => (float)$matData['mass'],
                'lengthM' => $lengthM,
                'unit' => 'kg',
                'od' => (float)($libData['od'] ?? 0),
                'wt' => (float)($libData['wt'][0] ?? $matData['thickness'] ?? 0),
                'dn' => $libData['dn'] ?? null,
                'schedule' => $libData['schedule'] ?? '',
                'grade' => $libraryItem['grade'] ?? '',
            ];
        }

        // Section/profile: mass_per_meter × length / 1000 (mm → m)
        $massPerMeter = (float)($libraryItem['mass_per_meter'] ?? 0);
        if ($massPerMeter > 0 && $length > 0) {
            return [
                'massKg' => $massPerMeter * $lengthM,
                'lengthM' => $lengthM,
                'unit' => 'm',
                'od' => (float)($libData['od'] ?? 0),
                'wt' => (float)($libData['wt'][0] ?? 0),
                'dn' => $libData['dn'] ?? null,
                'schedule' => $libData['schedule'] ?? '',
                'grade' => $libraryItem['grade'] ?? '',
            ];
        }

        // Plate/sheet: volume (m³) × density
        if ($category === 'plate' && $length > 0 && $width > 0 && $thickness > 0 && $density > 0) {
            return [
                'massKg' => $length * $width * $thickness / 1e9 * $density,
                'lengthM' => $lengthM,
                'unit' => 'kg',
                'od' => 0, 'wt' => $thickness,
                'dn' => null, 'schedule' => '', 'grade' => $libraryItem['grade'] ?? '',
            ];
        }

        // Density-only fallback (approx cylinder/bar)
        if ($density > 0 && $length > 0 && $width > 0) {
            return [
                'massKg' => $length * $width * $thickness / 1e9 * $density,
                'lengthM' => $lengthM,
                'unit' => 'kg',
                'od' => 0, 'wt' => $thickness,
                'dn' => null, 'schedule' => '', 'grade' => $libraryItem['grade'] ?? '',
            ];
        }

        return [
            'massKg' => 0.0, 'lengthM' => $lengthM, 'unit' => 'kg',
            'od' => 0, 'wt' => 0, 'dn' => null, 'schedule' => '', 'grade' => '',
        ];
    }

    /** Get all effective rates for an entity via rates.php API (no HTTP). */
    protected function getAllEffectiveRates($entityId)
    {
        $ratesApi = new \api\rates();
        $ratesApi->user_id = $this->effOwnerId();
        return $ratesApi->handle_get_all_effective(['entity_id' => $entityId]);
    }

    /**
     * Load an entity by id, scoped to the current user.
     *
     * @param string $id
     * @return array|null
     */
    protected function getEntity($id)
    {
        $res = $this->pgCrud->read([
            'table' => 'entity',
            'where' => 'id = $1 AND user_id_owner = $2 AND is_active = TRUE',
            'params' => [$id, $this->effOwnerId()],
            'limit' => 1,
        ]);
        return $res['data'][0] ?? null;
    }

    /**
     * Load components for an entity (optionally filtered by type).
     *
     * @param string $entityId
     * @param string|null $type
     * @return array
     */
    protected function getComponents($entityId, $type = null)
    {
        $where = 'entity_id = $1 AND user_id_owner = $2';
        $params = [$entityId, $this->effOwnerId()];
        if ($type) {
            $where .= ' AND type = $3';
            $params[] = $type;
        }
        $res = $this->pgCrud->read([
            'table' => 'component',
            'where' => $where,
            'params' => $params,
            'order_fields' => ['created_at ASC'],
        ]);
        return $res['data'] ?? [];
    }

    // ── Material entity helpers (materials-as-entities) ────────────────
    // Materials live in the entity table (type='material') with
    // specification/dimensions/rate components. These helpers reconstruct the
    // legacy material_library row shape so every read path (cost, takeoff,
    // compat, UI labels) sees the same fields it always did. The
    // material_library TABLE remains only as the legacy seed mirror.

    protected function getMaterialComps($entityId)
    {
        $res = $this->pgCrud->read([
            'table' => 'component',
            'where' => 'entity_id = $1 AND type = ANY($2::text[])',
            'params' => [$entityId, '{specification,dimensions,rate}'],
        ]);
        return $res['data'] ?? [];
    }

    /** Legacy material_library row shape reconstructed from entity + components. */
    protected function materialRowShape($entity, $comps)
    {
        $spec = []; $dims = []; $rate = [];
        foreach ($comps as $c) {
            $d = is_array($c['data'] ?? null) ? $c['data'] : [];
            if (($c['type'] ?? '') === 'specification') $spec = $d;
            elseif (($c['type'] ?? '') === 'dimensions') $dims = $d;
            elseif (($c['type'] ?? '') === 'rate') $rate = $d;
        }
        $data = array_merge($spec, $dims, $rate);
        unset($data['aliases']);
        return [
            'id' => $entity['id'],
            'name' => $entity['name'] ?? '',
            'description' => $entity['description'] ?? '',
            'profile' => $spec['profile'] ?? '',
            'material_type' => $spec['material_type'] ?? '',
            'category' => $spec['category'] ?? 'Carbon Steel',
            'grade' => $spec['grade'] ?? '',
            'density' => $dims['density'] ?? null,
            'thickness' => $dims['thickness'] ?? null,
            'mass_per_meter' => $dims['mass_per_meter'] ?? null,
            'mass_per_area' => $dims['mass_per_area'] ?? null,
            'unit_cost' => $rate['unit_cost'] ?? 0,
            'library_category' => $spec['library_category'] ?? 'material',
            'aliases' => $spec['aliases'] ?? [],
            'data' => $data,
            'user_id_owner' => $entity['user_id_owner'] ?? null,
            'od' => $dims['od'] ?? ($data['od'] ?? null),
            'wt' => $dims['wt'] ?? ($data['wt'] ?? null),
            'schedule' => $spec['schedule'] ?? ($data['schedule'] ?? null),
            'nb' => $dims['nb'] ?? ($data['nb'] ?? null),
            'nps' => $dims['nps'] ?? ($data['nps'] ?? null),
            'mass_kg' => $dims['massKg'] ?? null,
            'paint_area_per_m' => $dims['paintAreaPerM'] ?? null,
            'ext_area' => $dims['extArea'] ?? null,
            'supplier_id' => $rate['supplier_id'] ?? null,
            'price_updated_at' => $rate['price_updated_at'] ?? null,
        ];
    }

    /** Read one material entity (shared reference data — reads are global). */
    protected function getMaterialEntity($id)
    {
        if (!$id) return null;
        $e = $this->pgCrud->read([
            'table' => 'entity',
            'where' => "id = \$1 AND type = 'material' AND is_active = TRUE",
            'params' => [$id], 'limit' => 1,
        ])['data'][0] ?? null;
        if (!$e) return null;
        return ['entity' => $e, 'comps' => $this->getMaterialComps($id)];
    }

    /** Batch: { materialId: legacyShape } for a set of material entity ids. */
    protected function materialEntitiesByIds($ids)
    {
        $out = [];
        if (!$ids) return $out;
        $rows = $this->pgCrud->read([
            'table' => 'entity',
            'where' => "id = ANY(\$1::uuid[]) AND type = 'material' AND is_active = TRUE",
            'params' => ['{' . implode(',', $ids) . '}'],
        ])['data'] ?? [];
        if (!$rows) return $out;
        $comps = $this->pgCrud->read([
            'table' => 'component',
            'where' => "entity_id = ANY(\$1::uuid[]) AND type = ANY(\$2::text[])",
            'params' => ['{' . implode(',', array_column($rows, 'id')) . '}', '{specification,dimensions,rate}'],
        ])['data'] ?? [];
        $byEnt = [];
        foreach ($comps as $c) $byEnt[$c['entity_id']][] = $c;
        foreach ($rows as $e) $out[$e['id']] = $this->materialRowShape($e, $byEnt[$e['id']] ?? []);
        return $out;
    }

    /**
     * Load links for an entity (inbound + outbound, optional type filter).
     * Returns both directions so the caller can build BOM trees or trace
     * references without extra round-trips.
     *
     * @param string $entityId
     * @param string|null $type
     * @return array ['out' => [...], 'in' => [...]]
     */
    protected function getLinks($entityId, $type = null)
    {
        $whereType = $type ? " AND type = '$type'" : '';
        $out = $this->pgCrud->read([
            'table' => 'link',
            'where' => "from_id = \$1 AND user_id_owner = \$2$whereType",
            'params' => [$entityId, $this->effOwnerId()],
        ]);
        $in = $this->pgCrud->read([
            'table' => 'link',
            'where' => "to_id = \$1 AND user_id_owner = \$2$whereType",
            'params' => [$entityId, $this->effOwnerId()],
        ]);
        return [
            'out' => $out['data'] ?? [],
            'in'  => $in['data'] ?? [],
        ];
    }

    /**
     * Merge a component's data onto an existing row without clobbering
     * unrelated keys (partial payloads are the norm in ECS workflows).
     * Uses jsonb merging so one update doesn't wipe the whole component.
     *
     * @param string $id       Component id
     * @param array  $patch    Data to merge
     * @return array           PgCrud result
     */
    protected function patchComponentData($id, $patch)
    {
        return $this->pgCrud->execute(
            "UPDATE component
             SET data = component.data || \$2::jsonb,
                 updated_at = NOW()
             WHERE id = \$1 AND user_id_owner = \$3",
            [$id, json_encode($patch), $this->effOwnerId()]
        );
    }

    /**
     * Single source of truth for entity type detection from a description.
     * Static + public so import endpoints AND seed/reconcile scripts share one
     * classifier (no three drifting regexes). Order matters:
     *   1. assembly — only container/group words (a Skid/Header/Frame groups parts)
     *   2. fastener — ONLY when the item STARTS as a bolt/nut/washer set (a valve
     *      desc contains "ins screw rising stem" but is NOT a fastener)
     *   3. fitting  — bought-in pipe hardware (flanges, elbows, tees, valves,
     *      gaskets, couplings — incl. cplg/half-couplings), standard purchases
     *   4. otherwise part (fabricated pipe lengths, custom supports, etc.)
     */
    public static function classifyEntityType($description, $section = null)
    {
        $d = strtolower(trim((string)$description));
        // Assembly: PIPE SPOOL/CLOSURE sections from a BoQ sheet, or container words.
        if ($section && in_array(strtoupper((string)$section), ['PIPE SPOOL', 'PIPE CLOSURE'])) return 'assembly';
        if (preg_match('/(header|skid|frame|assembly|sub-assembly|subassembly|unit|tank|vessel|section|structure|system|module|platform|framework|cage)/', $d)) return 'assembly';
        // Fastener: only when the line STARTS as a fastener set, or with a size
        // token immediately followed by a fastener word ("M12 Bolt",
        // "M20 x 110 bolt", "8 of M20"). A valve desc ("ins screw rising stem")
        // matches neither a leading fastener word nor a leading size+word pair.
        if (preg_match('/^(?:stud\s+bolt|bolt|nut|washer|stud)\b/', $d)) return 'fastener';
        if (preg_match('/^m\d{1,4}(?:\s*[x×]\s*\d+)?\s+(?:bolt|nut|washer|stud)\b/', $d)) return 'fastener';
        if (preg_match('/^\d+\s*(?:of|off|x)\s*m\d+/', $d)) return 'fastener';
        // Bought-in pipe hardware -> fitting.
        if (preg_match('/(flange|flg|elbow|ell|tee|reducer|nipple|coupl|cplg|cpl|union|cap|valve|gasket|fitting|sockolet|weldolets|weldolet|bend)/', $d)) return 'fitting';
        return 'part';
    }

    // ── Incremental cost recalculation (watcher-driven) ────────────────
    //
    // When an entity's inputs change (material/process/link/entity data),
    // the mutation endpoint calls recalculateUpward(entityId). This walks
    // UP the tree: calculate the entity, roll up its children, move to the
    // parent, repeat until the root. No full-tree recalc, no nuke.
    //
    // Research basis: self-adjusting computation (Adapton) / reactive
    // dataflow — mark the change path dirty, recompute only that path in
    // topological (bottom-up) order, memoize unchanged subtrees. Industry
    // analog: SAP/Dynamics BOM cost rollup with explosion mode = targeted
    // multilevel (only the change path, not the whole BOM).

    /**
     * Lazy-load the cost API (cost.php defines class api\cost but isn't
     * auto-included by _base.php — only systems.php includes it). Returns a
     * configured instance with user_id set.
     */
    protected function costApi()
    {
        if (!class_exists('\api\cost')) {
            require_once(__DIR__ . '/cost.php');
        }
        $api = new \api\cost();
        $api->user_id = $this->effOwnerId();
        return $api;
    }

    /**
     * Is this entity's cost component still valid?
     * Fresh = cost component exists AND entity + driving components
     * (material, process) all updated at or before the cost was calculated.
     * A child's cost changing is handled by the parent walk (rollup), not here.
     */
    protected function isEntityCostFresh($entityId)
    {
        $cost = $this->getComponents($entityId, 'cost');
        if (!$cost) return false;
        $costUpdated = strtotime($cost[0]['updated_at'] ?? '1970-01-01');

        // Entity row itself
        $entity = $this->getEntity($entityId);
        if ($entity && strtotime($entity['updated_at'] ?? '1970-01-01') > $costUpdated) return false;

        // Driving components: material, process (these feed the cost calc)
        foreach (['material', 'process'] as $type) {
            $comps = $this->getComponents($entityId, $type);
            foreach ($comps as $c) {
                if (strtotime($c['updated_at'] ?? '1970-01-01') > $costUpdated) return false;
            }
        }
        return true;
    }

    /**
     * Find the parent entity for upward propagation.
     * STRUCTURAL TRUTH RULE (D5): contains links are the ONLY structure —
     * checked FIRST. quote_id is fallback metadata for legacy rows created
     * before quote-root links existed (never for linked items).
     * Returns parent entity id or null (root reached).
     */
    protected function findParentEntity($entityId)
    {
        $entity = $this->getEntity($entityId);
        if (!$entity) return null;

        // 1. contains link FROM parent TO this (assembly → child, or quote root → top-level)
        $res = $this->pgCrud->read([
            'table' => 'link',
            'fields' => ['from_id'],
            'where' => 'to_id = $1 AND type = $2 AND user_id_owner = $3',
            'params' => [$entityId, 'contains', $this->effOwnerId()],
            'limit' => 1,
        ]);
        if (!empty($res['data'][0]['from_id'])) {
            return $res['data'][0]['from_id'];
        }

        // 2. Legacy fallback: bare quote membership without a root link
        return !empty($entity['quote_id']) ? $entity['quote_id'] : null;
    }

    /**
     * Roll up an entity's children into its cost component.
     * Reads each child's existing cost (already calculated from prior runs),
     * sums rolled_total × link quantity, patches onto the parent.
     * Calculates any child missing a cost component first.
     */
    protected function rollupEntityChildren($entityId)
    {
        $links = $this->getLinks($entityId, 'contains');
        if (empty($links['out'])) return;

        $costApi = $this->costApi();

        $childIds = array_column($links['out'], 'to_id');
        $childCosts = $costApi->get_costs_by_entities($childIds);

        // Calculate any children missing a cost component
        $missing = [];
        foreach ($childIds as $cid) {
            if (!isset($childCosts[$cid])) $missing[] = $cid;
        }
        if ($missing) {
            $newCosts = $costApi->handle_batch_calculate(['entity_ids' => $missing]);
            foreach ($missing as $cid) {
                if (isset($newCosts[$cid]) && !isset($newCosts[$cid]['error'])) {
                    $childCosts[$cid] = $newCosts[$cid];
                }
            }
        }

        // Roll up: parent children_total = Σ(child rolled_total × link qty)
        $childrenTotal = 0.0;
        $childrenMass = 0.0;
        $rolledCols = [];
        foreach ($links['out'] as $link) {
            $cid = $link['to_id'];
            $qty = (float)($link['quantity'] ?? 1);
            $childCost = $childCosts[$cid] ?? null;
            if (!$childCost) continue;
            $childTotal = $childCost['rolled_total'] ?? $childCost['total'] ?? 0;
            $childMass = $childCost['rolled_mass_kg'] ?? $childCost['massKg'] ?? 0;
            $childrenTotal += $childTotal * $qty;
            $childrenMass += $childMass * $qty;
            $cols = $childCost['rolled_columns'] ?? [];
            foreach ($cols as $col => $val) {
                $rolledCols[$col] = ($rolledCols[$col] ?? 0) + (float)$val * $qty;
            }
        }

        // Get own cost to combine with children
        $ownCost = $costApi->get_costs_by_entities([$entityId]);
        $own = $ownCost[$entityId] ?? [];
        $ownTotal = $own['total'] ?? 0;
        $ownMass = $own['massKg'] ?? 0;

        $costApi->patch_entity_cost($entityId, [
            'rolled_total' => \api\cost::r2($childrenTotal + $ownTotal),
            'children_total' => \api\cost::r2($childrenTotal),
            'rolled_mass_kg' => \api\cost::r2($childrenMass + $ownMass),
            'rolled_columns' => $rolledCols,
        ]);
    }

    /**
     * Watcher entry point: recalculate an entity and propagate upward.
     * Called by mutation endpoints (components/links/entities) after a write.
     * Walks up: calculate own cost → roll up children → move to parent → repeat.
     */
    protected function recalculateUpward($entityId)
    {
        $current = $entityId;
        $rootId = null;
        $visited = [];
        while ($current && !isset($visited[$current])) {
            $visited[$current] = true;

            // 1. Calculate this entity's own cost (skips if fresh)
            $costApi = $this->costApi();
            $costApi->handle_calculate_entity(['entity_id' => $current]);

            // 2. Roll up children (children's costs already fresh from prior runs)
            $this->rollupEntityChildren($current);

            // 3. Track root for final total
            $rootId = $current;

            // 4. Walk to parent
            $current = $this->findParentEntity($current);
        }

        // 5. Persist root grand total (sum of top-level members' rolled totals)
        if ($rootId) {
            $this->persistRootTotalFromCost($rootId);
        }
    }

    /**
     * Compute and persist the root's grand total from its members' cost
     * components. Called after an upward walk reaches the root.
     */
    protected function persistRootTotalFromCost($rootId)
    {
        $costApi = $this->costApi();

        // Top-level members = linked directly FROM the root via contains
        $linkRes = $this->pgCrud->read([
            'table' => 'link',
            'where' => 'from_id = $1 AND type = $2 AND user_id_owner = $3',
            'params' => [$rootId, 'contains', $this->effOwnerId()],
        ]);
        $topLevel = [];
        foreach (($linkRes['data'] ?? []) as $l) {
            $topLevel[$l['to_id']] = (float)($l['quantity'] ?? 1);
        }
        // No top-level links → treat all quote members as top-level
        if (empty($topLevel)) {
            $res = $this->pgCrud->read([
                'table' => 'entity',
                'where' => 'quote_id = $1 AND user_id_owner = $2 AND is_active = TRUE',
                'params' => [$rootId, $this->effOwnerId()],
            ]);
            foreach (($res['data'] ?? []) as $e) {
                $topLevel[$e['id']] = 1;
            }
        }

        $COST_COLUMNS = [
            'material', 'boilerHrs', 'weldHrs', 'machHrs', 'labor',
            'consumables', 'services', 'ndt', 'lining', 'paint', 'transport',
            'processTotal', 'margin', 'subtotal', 'total',
        ];
        $totals = array_fill_keys($COST_COLUMNS, 0.0);
        $totalMassKg = 0.0;

        $costs = $costApi->get_costs_by_entities(array_keys($topLevel));
        foreach ($topLevel as $id => $qty) {
            $c = $costs[$id] ?? null;
            if (!$c) continue;
            foreach ($COST_COLUMNS as $col) {
                if ($col === 'total') {
                    $totals[$col] += (float)($c['rolled_total'] ?? $c['total'] ?? 0) * $qty;
                } else {
                    $use = $c['rolled_columns'] ?? null;
                    $v = $use ? (float)($use[$col] ?? 0) : (float)($c[$col] ?? 0);
                    $totals[$col] += $v * $qty;
                }
            }
            $totalMassKg += (float)($c['rolled_mass_kg'] ?? $c['massKg'] ?? 0) * $qty;
        }
        foreach ($totals as $col => $v) $totals[$col] = \api\cost::r2($v);
        $totals['massKg'] = \api\cost::r2($totalMassKg);

        $entity = $this->getEntity($rootId);
        $marginPercent = (float)($entity['data']['marginPercent'] ?? 30);
        $this->persistRootTotal($rootId, $totals['total'], count($topLevel), $totals, $marginPercent);
    }

    /**
     * Persist grand total + column totals into the root's cost component.
     * Generic — works on any entity that is a cost aggregate.
     */
    protected function persistRootTotal($rootId, $grandTotal, $entityCount, $totals = [], $marginPercent = null)
    {
        $rootCostData = [
            'total' => $grandTotal,
            'subtotal' => $grandTotal,
            'entity_count' => $entityCount,
            'totals' => $totals,
            'marginPercent' => $marginPercent !== null ? (float)$marginPercent : null,
            'lastUpdated' => date('c'),
        ];
        // Write via the cost.php seam (cost ADR owns all cost comp writes).
        $costApi = $this->costApi();
        $costApi->write_entity_cost($rootId, $rootCostData, $rootId);
    }
}
