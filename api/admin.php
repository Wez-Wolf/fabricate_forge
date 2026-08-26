<?php
/**
 * fabricate_forge/api/admin.php
 *
 * Admin — company settings + user management.
 *   - company_settings: single row per user (defaultRates, company info)
 *   - user management: list users, set role
 */
namespace api;

include_once(__DIR__ . "/_base.php");
include_once(__DIR__ . "/rates.php");
include_once(__DIR__ . "/materials.php");

class admin extends Base
{
    protected function buildTable()
    {
        $this->ensureEcsTables();
    }

    /**
     * Get company settings (defaults merged in).
     */
    public function handle_get_settings($input = [])
    {
        $settings = $this->getSettings();
        $data = $settings['data'] ?? [];
        return array_merge([
            'companyName' => '',
            'vatNumber' => '',
            'defaultCurrency' => 'USD',
            'defaultTaxRate' => 0,
            'defaultValidityDays' => 30,
            'defaultMarkupPercent' => 30,
            'defaultOverheadPercent' => 15,
            'defaultRates' => \api\rates::GLOBAL_DEFAULT_RATES,
        ], $data);
    }

    /**
     * Update company settings (JSONB merge).
     * Input: { data: { companyName?, defaultRates?, defaultMarkupPercent?, ... } }
     */
    public function handle_update_settings($input = [])
    {
        $patch = \getVal($input, 'data', $input);
        unset($patch['action'], $patch['auth_id']);
        if (empty($patch)) return ['error' => 'data (object) is required.'];

        $settings = $this->getSettings();
        if ($settings) {
            $this->pgCrud->execute(
                "UPDATE company_settings SET data = data || \$2::jsonb, updated_at = NOW()
                 WHERE user_id_owner = \$1",
                [$this->effOwnerId(), json_encode($patch)]
            );
        } else {
            $this->pgCrud->save([
                'table' => 'company_settings',
                'data' => [
                    'user_id_owner' => $this->effOwnerId(),
                    'data' => $patch,
                ],
            ]);
        }
        return $this->handle_get_settings();
    }

    /**
     * List users (admin only — forge user_role INT: 1 = admin, 0 = user).
     */
    public function handle_list_users($input = [])
    {
        if (!$this->isAdmin()) {
            return ['error' => 'Admin access required.', 'error_code' => 403];
        }
        $res = $this->pgCrud->read([
            'table' => 'user',
            'fields' => ['id', 'email', 'user_data', 'user_role', 'created_date'],
            'order_fields' => ['created_date ASC'],
        ]);
        $users = $res['data'] ?? [];
        foreach ($users as &$u) {
            $ud = $u['user_data'] ?? [];
            $u['name'] = $ud['name'] ?? '';
            // forge-native role: user_role 1 = admin, 0 = user; user_data.role overrides
            $u['role'] = $ud['role'] ?? ((int)($u['user_role'] ?? 0) >= 1 ? 'admin' : 'viewer');
            unset($u['user_data']);
        }
        return $users;
    }

    /**
     * Set a user's role (admin only).
     * Input: { user_id, role } — role: admin | editor | viewer
     */
    public function handle_set_user_role($input = [])
    {
        if (!$this->isAdmin()) {
            return ['error' => 'Admin access required.', 'error_code' => 403];
        }
        $userId = \getVal($input, 'user_id');
        $role = \getVal($input, 'role');
        if (!$userId || !in_array($role, ['admin', 'editor', 'viewer'])) {
            return ['error' => 'user_id and role (admin|editor|viewer) are required.'];
        }
        // forge-native: user_role 1 = admin; 0 = non-admin. Also store string role.
        $userRoleInt = ($role === 'admin') ? 1 : 0;
        $this->pgCrud->execute(
            "UPDATE \"user\" SET user_role = \$2, user_data = jsonb_set(COALESCE(user_data,'{}'::jsonb), '{role}', \$3::jsonb)
             WHERE id = \$1",
            [$userId, $userRoleInt, json_encode($role)]
        );

        // Seed the shared material library ONCE when a new admin is created
        // (requirement: seeds fire only on a new admin account, never on load
        // and never re-fire). If the library is already populated this is a
        // no-op — the newly-created admin does NOT re-own or duplicate it.
        if ($role === 'admin') {
            try {
                $mat = new \api\materials();
                $mat->ensureSharedLibrary($userId);
            } catch (\Throwable $t) {
                // Seeding must never break admin role assignment.
                error_log('ensureSharedLibrary failed: ' . $t->getMessage());
            }
        }

        return ['success' => true, 'user_id' => $userId, 'role' => $role];
    }

    // ── Internal ───────────────────────────────────────

    private function getSettings()
    {
        $res = $this->pgCrud->read([
            'table' => 'company_settings',
            'where' => 'user_id_owner = $1',
            'params' => [$this->effOwnerId()],
            'limit' => 1,
        ]);
        return $res['data'][0] ?? null;
    }

    private function isAdmin()
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
        // forge-native: user_role 1 = admin (first user); user_data.role overrides
        if (isset($ud['role'])) return $ud['role'] === 'admin';
        return (int)($row['user_role'] ?? 0) >= 1;
    }
}

\api\dispatchIfEntry(__FILE__);
