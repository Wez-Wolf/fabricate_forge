<?php
/**
 * fabricate_forge/api/user.php
 *
 * User preferences — the profile defaults the cost engine and quote UI read:
 *   - defaultMarkupPercent (margin % applied when options don't override)
 *   - defaultCurrency
 *   - companyRates (rate overrides at company level)
 */
namespace api;

include_once(__DIR__ . "/_base.php");
include_once(__DIR__ . "/auth.php");

class user extends Base
{
    /**
     * login/signup are public (no auth_id yet) — forge-login / forge-signup
     * post here (./api/user.php), so delegate to auth.php's handlers which
     * wrap the response in {data:...} for WEB.api.
     */
    protected $publicActions = ['login', 'signup'];

    /**
     * Login — delegate to auth.php (forge Auth + {data:} wrapper).
     */
    public function handle_login($input = [])
    {
        $auth = new \api\auth();
        return $auth->handle_login($input);
    }

    /**
     * Signup — delegate to auth.php.
     */
    public function handle_signup($input = [])
    {
        $auth = new \api\auth();
        return $auth->handle_signup($input);
    }

    protected function buildTable()
    {
        $this->ensureEcsTables();
    }

    /**
     * Get preferences for the current user (defaults merged in).
     * Also returns the user's role (forge user_role: 1 = admin) so the
     * nav shell can gate the Admin tab.
     */
    public function handle_get_preferences($input = [])
    {
        $prefs = $this->getPrefs();
        $data = $prefs['data'] ?? [];
        $role = $this->getRole();
        return array_merge([
            'defaultMarkupPercent' => 30,
            'defaultCurrency' => 'USD',
            'companyRates' => [],
            'role' => $role,
        ], $data);
    }

    /**
     * Resolve the user's role from forge's user table.
     */
    private function getRole()
    {
        $res = $this->pgCrud->read([
            'table' => 'user',
            'fields' => ['user_role', 'user_data'],
            'where' => 'id = $1',
            'params' => [$this->user_id],
            'limit' => 1,
        ]);
        $row = $res['data'][0] ?? [];
        $ud = $row['user_data'] ?? [];
        if (isset($ud['role'])) return $ud['role'];
        return (int)($row['user_role'] ?? 0) >= 1 ? 'admin' : 'viewer';
    }

    /**
     * Update preferences (JSONB merge — partial payloads don't clobber).
     * Input: { data: { defaultMarkupPercent?, defaultCurrency?, companyRates? } }
     */
    public function handle_update_preferences($input = [])
    {
        $patch = \getVal($input, 'data', $input);
        unset($patch['action'], $patch['auth_id']);
        if (empty($patch)) return ['error' => 'data (object) is required.'];

        $prefs = $this->getPrefs();
        if ($prefs) {
            $this->pgCrud->execute(
                "UPDATE user_prefs SET data = data || \$2::jsonb, updated_at = NOW()
                 WHERE user_id = \$1",
                [$this->user_id, json_encode($patch)]
            );
        } else {
            $this->pgCrud->save([
                'table' => 'user_prefs',
                'data' => [
                    'user_id' => $this->user_id,
                    'data' => $patch,
                ],
            ]);
        }
        return $this->handle_get_preferences();
    }

    private function getPrefs()
    {
        $res = $this->pgCrud->read([
            'table' => 'user_prefs',
            'where' => 'user_id = $1',
            'params' => [$this->user_id],
            'limit' => 1,
        ]);
        return $res['data'][0] ?? null;
    }
}

\api\dispatchIfEntry(__FILE__);
