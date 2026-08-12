<?php
/**
 * fabricate_forge/api/_base.php
 *
 * Project API base — extends forge\api\Base with Fabricate ECS (Entity
 * Component System) helpers.
 *
 * ECS model (mirrors the original Fabricate Meteor app's data model):
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
    type VARCHAR(30) NOT NULL CHECK (type IN ('part','assembly','fastener','quote')),
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
}
