<?php
require_once __DIR__ . '/api/_base.php';
require_once __DIR__ . '/api/entities.php';
require_once __DIR__ . '/api/components.php';
require_once __DIR__ . '/api/links.php';
require_once __DIR__ . '/api/cost.php';
require_once __DIR__ . '/api/rates.php';
require_once __DIR__ . '/api/process.php';
require_once __DIR__ . '/api/systems.php';
require_once __DIR__ . '/api/quotes.php';

\loadEnv(__DIR__ . '/.env');

// Set up fake authentication for wesley.stuart@innofuse.xyz
$userId = 'd2b7b80a-95ed-4016-9966-d178589b5f37';

// Mock the auth
class MockAuth extends \forge\api\Auth {
    public function checkAuth($input = []) {
        return ['user_id' => $GLOBALS['testUserId'] ?? null, 'auth_id' => 'mock'];
    }
}

// Temporarily replace the auth singleton
$forgeDir = dirname(__DIR__, 2) . '/forge';
if (file_exists($forgeDir . '/php/util/helpers.php')) {
    require_once($forgeDir . '/php/util/helpers.php');
}
if (file_exists($forgeDir . '/php/db/PgCrud.php')) {
    require_once($forgeDir . '/php/db/PgCrud.php');
}

$testUserId = $userId;
$api = new api\quotes();
$api->user_id = $userId; // Override the user_id

$input = [
    'name' => 'Quote for T26-132',
    'currency' => 'USD',
    'description' => 'Quote generated from T26-132- KTZEBR9643-PIPING BOQ -TSV1.xlsx'
];

$result = $api->handle_create($input);
echo json_encode($result, JSON_PRETTY_PRINT);
?>