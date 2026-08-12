<?php
/**
 * fabricate_forge/api/team.php
 *
 * Team membership + invites. No gates — every team works the owner's data
 * silo (see Base::effOwnerId() in _base.php). This endpoint manages:
 *   - team            — groups the owner (admin) creates, each with an id
 *   - team_member     — user → team (one team per user, enforced on join)
 *   - pending_invite  — email → team; matching signup auto-joins the team
 *
 * Actions:
 *   create         {name}                     → new team (owner = self)
 *   list           {}                         → teams owned by caller
 *   invite         {team_id, email}           → pending invite / direct join
 *   revoke_invite  {invite_id}                → cancel a pending invite
 *   join           {invite_code}              → manual join (logged-in user)
 *   members        {team_id}                  → member list + pending invites
 *   remove_member  {team_id, user_id}         → owner removes a member
 *   my_team        {}                         → caller's team (settings UI)
 */
namespace api;

include_once(__DIR__ . "/_base.php");

class team extends Base
{
    /** preview_invite is public — used by the landing page to name the team. */
    protected $publicActions = ['preview_invite'];

    protected function buildTable()
    {
        $this->ensureEcsTables();
        $this->pgCrud->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS team (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    owner_id UUID NOT NULL,
    name VARCHAR(120) NOT NULL,
    invite_code VARCHAR(16) UNIQUE,
    created_at TIMESTAMPTZ DEFAULT NOW()
)
SQL);
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_team_owner ON team(owner_id)');

        $this->pgCrud->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS team_member (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    team_id UUID NOT NULL REFERENCES team(id) ON DELETE CASCADE,
    user_id UUID NOT NULL UNIQUE,
    joined_at TIMESTAMPTZ DEFAULT NOW()
)
SQL);
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_tm_team ON team_member(team_id)');

        $this->pgCrud->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS pending_invite (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    team_id UUID NOT NULL REFERENCES team(id) ON DELETE CASCADE,
    email VARCHAR(255) NOT NULL,
    invite_code VARCHAR(16) UNIQUE,
    created_at TIMESTAMPTZ DEFAULT NOW()
)
SQL);
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_pi_team ON pending_invite(team_id)');
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_pi_email ON pending_invite(lower(email))');
    }

    // ── helpers ─────────────────────────────────────────

    private function genCode()
    {
        return strtoupper(bin2hex(random_bytes(5))); // 10 chars, URL-safe
    }

    private function getTeam($id)
    {
        $res = $this->pgCrud->read([
            'table' => 'team',
            'where' => 'id = $1',
            'params' => [$id],
            'limit' => 1,
        ]);
        return $res['data'][0] ?? null;
    }

    /** Email → existing user id, or null. */
    private function findUserByEmail($email)
    {
        $res = $this->pgCrud->read([
            'table' => 'user',
            'fields' => ['id'],
            'where' => 'lower(email) = $1',
            'params' => [strtolower($email)],
            'limit' => 1,
        ]);
        return $res['data'][0]['id'] ?? null;
    }

    /** Resolve a team's invite_code for display, generating one if missing. */
    private function ensureCode($teamId)
    {
        $team = $this->getTeam($teamId);
        if (!$team) return null;
        if (!empty($team['invite_code'])) return $team['invite_code'];
        $code = $this->genCode();
        $this->pgCrud->execute(
            "UPDATE team SET invite_code = \$1 WHERE id = \$2",
            [$code, $teamId]
        );
        return $code;
    }

    // ── actions ─────────────────────────────────────────

    /**
     * Public: resolve an invite code to team name + owner email — powers the
     * invite-link onboarding on the landing page. No auth required; reveals
     * only what the invitee already knows from the link.
     * Input: { invite_code }
     */
    public function handle_preview_invite($input = [])
    {
        $code = strtoupper(trim((string)($input['invite_code'] ?? ($_COOKIE['fab_invite'] ?? ''))));
        if ($code === '') return ['data' => ['team' => null]];

        $t = $this->pgCrud->read([
            'table' => 'team',
            'fields' => ['id', 'name', 'owner_id'],
            'where' => 'invite_code = $1',
            'params' => [$code],
            'limit' => 1,
        ]);
        $team = $t['data'][0] ?? null;
        if (!$team) return ['data' => ['team' => null]];

        $u = $this->pgCrud->read([
            'table' => 'user',
            'fields' => ['email', 'user_data'],
            'where' => 'id = $1',
            'params' => [$team['owner_id']],
            'limit' => 1,
        ]);
        $owner = $u['data'][0] ?? [];
        $ud = $owner['user_data'] ?? [];
        $ownerName = is_array($ud) ? ($ud['name'] ?? '') : '';

        return ['data' => ['team' => [
            'id' => $team['id'],
            'name' => $team['name'],
            'owner_email' => $owner['email'] ?? '',
            'owner_name' => $ownerName ?: ($owner['email'] ?? ''),
        ]]];
    }

    /**
     * Create a team. Owner = the caller (admin creates teams; invitees are
     * added via invite).
     * Input: { name }
     */
    public function handle_create($input = [])
    {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') return ['error' => 'Team name is required.', 'error_code' => 400];

        // Guard against duplicate team names for the same owner (double-click /
        // race protection — two rapid creates used to make two "test" teams).
        $existing = $this->pgCrud->read([
            'table' => 'team',
            'where' => 'owner_id = $1 AND LOWER(name) = LOWER($2)',
            'params' => [$this->user_id, $name],
            'limit' => 1,
        ])['data'] ?? [];
        if (!empty($existing)) {
            // NOTE: no error_code here — forge's WEB.api clears auth_id on any
            // error_code, which would log the user out for a duplicate-name 409.
            return ['error' => 'You already have a team named "' . $name . '".'];
        }

        $res = $this->pgCrud->save([
            'table' => 'team',
            'data' => [
                'owner_id' => $this->user_id,
                'name' => $name,
                'invite_code' => $this->genCode(),
            ],
        ]);
        $teamId = $res['data']['id'] ?? null;
        if (!$teamId) return ['error' => 'Failed to create team.', 'error_code' => 500];

        $team = $this->getTeam($teamId);
        if (!$team) return ['error' => 'Failed to create team.', 'error_code' => 500];

        return ['data' => [
            'id' => $team['id'],
            'name' => $team['name'],
            'invite_code' => $team['invite_code'] ?? null,
        ]];
    }

    /**
     * Teams owned by the caller.
     */
    public function handle_list($input = [])
    {
        $res = $this->pgCrud->read([
            'table' => 'team',
            'fields' => ['id', 'name', 'invite_code', 'created_at'],
            'where' => 'owner_id = $1',
            'params' => [$this->user_id],
            'order_fields' => ['created_at ASC'],
        ]);
        return ['data' => $res['data'] ?? []];
    }

    /**
     * Invite by email.
     *  - Email belongs to an existing user → add them directly.
     *  - New email → create a pending invite; they auto-join on signup.
     * Input: { team_id, email }
     */
    public function handle_invite($input = [])
    {
        $teamId = (string)($input['team_id'] ?? '');
        $email = strtolower(trim((string)($input['email'] ?? '')));
        if (!$teamId || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'A valid team and email are required.', 'error_code' => 400];
        }

        $team = $this->getTeam($teamId);
        if (!$team || $team['owner_id'] !== $this->user_id) {
            return ['error' => 'Team not found.', 'error_code' => 404];
        }

        $existing = $this->findUserByEmail($email);
        if ($existing) {
            // Already a member of this team? Report back as already-joined.
            $mem = $this->pgCrud->read([
                'table' => 'team_member',
                'fields' => ['id'],
                'where' => 'team_id = $1 AND user_id = $2',
                'params' => [$teamId, $existing],
                'limit' => 1,
            ]);
            if (!empty($mem['data'])) {
                return ['error' => 'That person is already on this team.', 'error_code' => 409];
            }
            // Joined to a different team → swap them (one team per user).
            $this->pgCrud->execute(
                "DELETE FROM team_member WHERE user_id = \$1",
                [$existing]
            );
            $this->pgCrud->save([
                'table' => 'team_member',
                'data' => ['team_id' => $teamId, 'user_id' => $existing],
            ]);
            return ['data' => ['status' => 'added', 'email' => $email]];
        }

        // New user → pending invite keyed by email.
        $dup = $this->pgCrud->read([
            'table' => 'pending_invite',
            'fields' => ['id'],
            'where' => 'team_id = $1 AND lower(email) = $2',
            'params' => [$teamId, $email],
            'limit' => 1,
        ]);
        if (empty($dup['data'])) {
            $this->pgCrud->save([
                'table' => 'pending_invite',
                'data' => [
                    'team_id' => $teamId,
                    'email' => $email,
                    'invite_code' => $this->genCode(),
                ],
            ]);
        }
        return ['data' => ['status' => 'invited', 'email' => $email]];
    }

    /**
     * Cancel a pending invite (owner only).
     * Input: { invite_id }
     */
    public function handle_revoke_invite($input = [])
    {
        $inviteId = (string)($input['invite_id'] ?? '');
        if (!$inviteId) return ['error' => 'invite_id is required.', 'error_code' => 400];

        $inv = $this->pgCrud->read([
            'table' => 'pending_invite',
            'where' => 'id = $1',
            'params' => [$inviteId],
            'limit' => 1,
        ]);
        $invite = $inv['data'][0] ?? null;
        if (!$invite) return ['error' => 'Invite not found.', 'error_code' => 404];

        $team = $this->getTeam($invite['team_id']);
        if (!$team || $team['owner_id'] !== $this->user_id) {
            return ['error' => 'Not allowed.', 'error_code' => 403];
        }

        $this->pgCrud->execute('DELETE FROM pending_invite WHERE id = $1', [$inviteId]);
        return ['data' => ['revoked' => true]];
    }

    /**
     * Manual join by invite code (for a logged-in user given a link/code).
     * Also used by the nav boot when an authed user opens an invite link
     * (fab_invite cookie).
     * Input: { invite_code }
     */
    public function handle_join($input = [])
    {
        $code = strtoupper(trim((string)($input['invite_code'] ?? '')));
        if ($code === '') return ['error' => 'invite_code is required.', 'error_code' => 400];

        // Existing member? Idempotent success.
        $mine = $this->pgCrud->read([
            'table' => 'team_member',
            'fields' => ['team_id'],
            'where' => 'user_id = $1',
            'params' => [$this->user_id],
            'limit' => 1,
        ]);
        if (!empty($mine['data'])) {
            setcookie('fab_invite', '', time() - 3600, '/');
            return ['data' => ['status' => 'already_joined']];
        }

        $t = $this->pgCrud->read([
            'table' => 'team',
            'fields' => ['id', 'name', 'owner_id'],
            'where' => 'invite_code = $1',
            'params' => [$code],
            'limit' => 1,
        ]);
        $team = $t['data'][0] ?? null;
        if (!$team) return ['error' => 'Invalid invite code.', 'error_code' => 404];

        // The owner can't join their own team (they own it — membership is
        // for invited colleagues). A stray fab_invite cookie in the owner's
        // browser must not create a member row.
        if ((string)($team['owner_id'] ?? '') === (string)$this->user_id) {
            setcookie('fab_invite', '', time() - 3600, '/');
            return ['data' => ['status' => 'already_joined']];
        }

        $this->pgCrud->save([
            'table' => 'team_member',
            'data' => ['team_id' => $team['id'], 'user_id' => $this->user_id],
        ]);
        setcookie('fab_invite', '', time() - 3600, '/');
        return ['data' => ['status' => 'joined', 'team_name' => $team['name']]];
    }

    /**
     * Members + pending invites for a team (owner only).
     * Input: { team_id }
     */
    public function handle_members($input = [])
    {
        $teamId = (string)($input['team_id'] ?? '');
        if (!$teamId) return ['error' => 'team_id is required.', 'error_code' => 400];

        $team = $this->getTeam($teamId);
        if (!$team || $team['owner_id'] !== $this->user_id) {
            return ['error' => 'Team not found.', 'error_code' => 404];
        }

        $members = $this->pgCrud->read([
            'table' => 'team_member',
            'fields' => ['user_id', 'joined_at'],
            'where' => 'team_id = $1',
            'params' => [$teamId],
            'order_fields' => ['joined_at ASC'],
        ]);
        $rows = [];
        foreach ($members['data'] ?? [] as $m) {
            $u = $this->pgCrud->read([
                'table' => 'user',
                'fields' => ['email'],
                'where' => 'id = $1',
                'params' => [$m['user_id']],
                'limit' => 1,
            ]);
            $rows[] = [
                'user_id' => $m['user_id'],
                'email' => $u['data'][0]['email'] ?? '(unknown)',
                'joined_at' => $m['joined_at'],
            ];
        }

        $invites = $this->pgCrud->read([
            'table' => 'pending_invite',
            'fields' => ['id', 'email', 'created_at'],
            'where' => 'team_id = $1',
            'params' => [$teamId],
            'order_fields' => ['created_at ASC'],
        ]);

        return ['data' => [
            'members' => $rows,
            'pending' => $invites['data'] ?? [],
        ]];
    }

    /**
     * Remove a member (owner only).
     * Input: { team_id, user_id }
     */
    public function handle_remove_member($input = [])
    {
        $teamId = (string)($input['team_id'] ?? '');
        $userId = (string)($input['user_id'] ?? '');
        if (!$teamId || !$userId) {
            return ['error' => 'team_id and user_id are required.', 'error_code' => 400];
        }

        $team = $this->getTeam($teamId);
        if (!$team || $team['owner_id'] !== $this->user_id) {
            return ['error' => 'Team not found.', 'error_code' => 404];
        }

        $this->pgCrud->execute(
            'DELETE FROM team_member WHERE team_id = $1 AND user_id = $2',
            [$teamId, $userId]
        );
        return ['data' => ['removed' => true]];
    }

    /**
     * Caller's team (for the Settings UI — members see which team they're on,
     * owners see their own team). An owner isn't a team_member row — ownership
     * is via team.owner_id — so fall back to the first owned team.
     */
    public function handle_my_team($input = [])
    {
        $teamId = null;
        $isOwner = false;

        $res = $this->pgCrud->read([
            'table' => 'team_member',
            'fields' => ['team_id'],
            'where' => 'user_id = $1',
            'params' => [$this->user_id],
            'limit' => 1,
        ]);
        $teamId = $res['data'][0]['team_id'] ?? null;
        if (!$teamId) {
            $own = $this->pgCrud->read([
                'table' => 'team',
                'fields' => ['id'],
                'where' => 'owner_id = $1',
                'params' => [$this->user_id],
                'order_fields' => ['created_at ASC'],
                'limit' => 1,
            ]);
            $teamId = $own['data'][0]['id'] ?? null;
            $isOwner = (bool)$teamId;
        }
        if (!$teamId) return ['data' => ['team' => null]];

        $t = $this->pgCrud->read([
            'table' => 'team',
            'fields' => ['id', 'name', 'owner_id'],
            'where' => 'id = $1',
            'params' => [$teamId],
            'limit' => 1,
        ]);
        $team = $t['data'][0] ?? null;
        $ownerEmail = '';
        if ($team) {
            $u = $this->pgCrud->read([
                'table' => 'user',
                'fields' => ['email'],
                'where' => 'id = $1',
                'params' => [$team['owner_id']],
                'limit' => 1,
            ]);
            $ownerEmail = $u['data'][0]['email'] ?? '';
        }

        return ['data' => [
            'team' => $team ? [
                'id' => $team['id'],
                'name' => $team['name'],
                'owner_email' => $ownerEmail,
                'is_owner' => $isOwner || $team['owner_id'] === $this->user_id,
            ] : null,
        ]];
    }
}

\api\dispatchIfEntry(__FILE__);
