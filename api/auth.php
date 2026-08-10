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
    protected $publicActions = ['login', 'signup', 'logout', 'forgot_password', 'reset_password'];

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

        // Password reset tokens (single-use, expiring).
        $this->pgCrud->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS password_reset (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES "user"(id) ON DELETE CASCADE,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW()
)
SQL);
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_pr_token ON password_reset(token_hash)');
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

    /**
     * Forgot password (public): email → single-use reset token.
     *
     * Production would email the reset link; without a mailer the token is
     * returned in the response so the reset flow is usable/testable end-to-end.
     * Token hashes are stored SHA-256 (never plaintext). Expires in 1 hour.
     *
     * Input: { email }
     * Returns: { data: { sent: true, token, reset_url } } — token visible for dev.
     * Always answers "sent" for unknown emails too (no user enumeration).
     */
    public function handle_forgot_password($input = [])
    {
        $email = strtolower(trim((string)\getVal($input, 'email', '')));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'A valid email is required.', 'error_code' => 400];
        }

        $res = $this->pgCrud->read([
            'table' => 'user',
            'fields' => ['id'],
            'where' => 'lower(email) = $1',
            'params' => [$email],
            'limit' => 1,
        ]);
        $user = $res['data'][0] ?? null;

        $token = bin2hex(random_bytes(24));
        if ($user) {
            // NOTE: don't set 'used' explicitly — PgCrud converts PHP false to ''
            // which Postgres rejects for BOOLEAN; the column defaults to FALSE.
            $this->pgCrud->save([
                'table' => 'password_reset',
                'data' => [
                    'user_id' => $user['id'],
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => date('c', time() + 3600),
                ],
            ]);
        }

        return ['data' => [
            'sent' => true,
            'token' => $token,                       // dev-friendly; email in prod
            'reset_url' => '/reset-password/' . $token,
        ]];
    }

    /**
     * Reset password (public): { token, pass } → new password set.
     * Validates: token exists, not used, not expired. Single-use.
     */
    public function handle_reset_password($input = [])
    {
        $token = trim((string)\getVal($input, 'token', ''));
        $pass = (string)\getVal($input, 'pass', '');
        if (!$token || strlen($pass) < 6) {
            return ['error' => 'token and a password of at least 6 characters are required.', 'error_code' => 400];
        }

        $hash = hash('sha256', $token);
        $res = $this->pgCrud->read([
            'table' => 'password_reset',
            'fields' => ['id', 'user_id', 'expires_at', 'used'],
            'where' => 'token_hash = $1',
            'params' => [$hash],
            'limit' => 1,
        ]);
        $row = $res['data'][0] ?? null;
        if (!$row) {
            return ['error' => 'Invalid or expired reset token.', 'error_code' => 400];
        }
        if ($this->isTruthy($row['used'])) {
            return ['error' => 'This reset link has already been used.', 'error_code' => 400];
        }
        if (strtotime($row['expires_at']) < time()) {
            return ['error' => 'This reset link has expired.', 'error_code' => 400];
        }

        // Set the new password (bcrypt, same as forge Auth login expects)
        $this->pgCrud->execute(
            "UPDATE \"user\" SET password = \$1 WHERE id = \$2",
            [password_hash($pass, PASSWORD_BCRYPT), $row['user_id']]
        );
        // Single-use: mark consumed
        $this->pgCrud->execute(
            "UPDATE password_reset SET used = TRUE WHERE id = \$1",
            [$row['id']]
        );

        return ['data' => ['success' => true, 'message' => 'Password updated. You can now log in.']];
    }

    /**
     * Postgres BOOLEAN arrives as 't'/'f' strings via PgCrud — PHP's truthiness
     * would treat 'f' as true. Central truthy check for PgCrud booleans.
     */
    private function isTruthy($v)
    {
        if (is_bool($v)) return $v;
        if ($v === null || $v === '') return false;
        if (is_numeric($v)) return (float)$v !== 0.0;
        return !in_array(strtolower((string)$v), ['f', 'false', '0', 'no', 'off']);
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
