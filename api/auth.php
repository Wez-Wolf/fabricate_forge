<?php
/**
 * fabricate_forge/api/auth.php
 *
 * Auth endpoints — wraps forge\api\Auth for the fabricate login flow.
 *
 * forge\api\Auth handles login/signup/logout/session natively; this endpoint
 * exposes the actions the fabricate UI needs and adds the profile default
 * (defaultMarkupPercent) handling the cost engine reads.
 */
namespace api;

include_once(__DIR__ . "/_base.php");

class auth extends \forge\api\Auth
{
    protected $publicActions = ['login', 'signup', 'logout', 'forgot_password'];

    protected function buildTable()
    {
        // Delegate to forge Auth's user/auth table creation
        parent::buildTable();
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

    /**
     * Login (public). forge Auth's handle_login is inherited; add nothing.
     */
    public function handle_login($input = [])
    {
        $res = parent::handle_login($input);
        // forge Auth returns {auth_id, user_id} flat; WEB.api unwraps {data:...}
        // and drops bare keys — so wrap the payload IN data for the client.
        if (!empty($res['auth_id'])) {
            $prefs = $this->getPrefs($res['user_id'] ?? null);
            return ['data' => [
                'auth_id' => $res['auth_id'],
                'user_id' => $res['user_id'],
                'preferences' => $prefs['data'] ?? [],
            ]];
        }
        return $res; // error passthrough
    }

    /**
     * Signup (public) — same {data:...} wrapper so WEB.api keeps auth_id.
     */
    public function handle_signup($input = [])
    {
        $res = parent::handle_signup($input);
        if (!empty($res['auth_id'])) {
            $prefs = $this->getPrefs($res['user_id'] ?? null);
            return ['data' => [
                'auth_id' => $res['auth_id'],
                'user_id' => $res['user_id'],
                'preferences' => $prefs['data'] ?? [],
            ]];
        }
        return $res; // error passthrough (e.g. Email already registered 409)
    }

    /**
     * Validate a session token (used by the SPA on boot).
     * Input: { auth_id }
     */
    public function handle_verify($input = [])
    {
        $authId = \getVal($input, 'auth_id');
        if (!$authId) return ['error' => 'auth_id is required.', 'error_code' => 401];

        $auth = \forge\api\Auth::validateAuth($authId);
        if (!$auth) return ['error' => 'Invalid or expired session.', 'error_code' => 401];
        return ['data' => $auth];
    }

    /**
     * Logout (public).
     */
    public function handle_logout($input = [])
    {
        return parent::handle_logout($input);
    }

    private function getPrefs($userId)
    {
        $res = $this->pgCrud->read([
            'table' => 'user_prefs',
            'where' => 'user_id = $1',
            'params' => [$userId],
            'limit' => 1,
        ]);
        return $res['data'][0] ?? null;
    }
}

\api\dispatchIfEntry(__FILE__);
