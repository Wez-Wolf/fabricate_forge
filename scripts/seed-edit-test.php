<?php
/**
 * scripts/seed-edit-test.php — dev fixture for the quoteview edit dialog.
 * Seeds: assembly+child, material-only part, process-only part, plain part
 * (both) — used to verify type-aware editing (assemblies / parts / material /
 * processes). Run: php scripts/seed-edit-test.php [email]
 */
$forgeDir = dirname(__DIR__, 2) . '/forge';
require_once($forgeDir . '/php/util/helpers.php');
require_once($forgeDir . '/php/db/PgCrud.php');

\loadEnv(dirname(__DIR__) . '/.env');

$email = $argv[1] ?? 'api-test@fabricate.local';
$pg = new \forge\db\PgCrud();
$conn = $pg->getConn();
if (!$conn) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

$u = $pg->read(['table' => 'user', 'where' => 'email = $1', 'params' => [$email], 'limit' => 1]);
$user = $u['data'][0] ?? null;
if (!$user) { fwrite(STDERR, "User not found: $email\n"); exit(1); }
$userId = $user['id'];

require_once(__DIR__ . '/../api/_base.php');
require_once(__DIR__ . '/../api/entities.php');
require_once(__DIR__ . '/../api/components.php');
require_once(__DIR__ . '/../api/links.php');

// Pick the most recent quote for this user
$q = $pg->read([
    'table' => 'entity',
    'where' => "type = 'quote' AND user_id_owner = \$1 AND is_active = TRUE",
    'params' => [$userId],
    'order_fields' => ['created_at DESC'],
    'limit' => 1,
]);
$quote = $q['data'][0] ?? null;
if (!$quote) { fwrite(STDERR, "No quote found for $email — run seed-test-quote.php first\n"); exit(1); }
$quoteId = $quote['id'];

function se_ent($pg, $userId, $quoteId, $type, $name, $qty) {
    $r = $pg->save(['table' => 'entity', 'data' => [
        'type' => $type, 'name' => $name, 'quote_id' => $quoteId, 'quantity' => $qty,
        'user_id_owner' => $userId, 'data' => '{}']]);
    return $r['data']['id'] ?? null;
}
function se_comp($pg, $userId, $eid, $quoteId, $type, $data) {
    $pg->save(['table' => 'component', 'data' => [
        'entity_id' => $eid, 'type' => $type, 'data' => $data, 'quote_id' => $quoteId,
        'user_id_owner' => $userId]]);
}

// 1. Assembly with a child part (BOM structure)
$assy = se_ent($pg, $userId, $quoteId, 'assembly', 'Pipe Spool Assembly DN100', 1);
$child = se_ent($pg, $userId, $quoteId, 'part', 'Spool Piece 6m', 1);
se_comp($pg, $userId, $child, $quoteId, 'material', ['materialLibraryId' => '', 'category' => 'pipe', 'length' => 6000, 'costPerM' => 245]);
se_comp($pg, $userId, $child, $quoteId, 'process', ['welding' => 2.5, 'boilermaking' => 1.0]);
$pg->save(['table' => 'link', 'data' => ['from_id' => $assy, 'to_id' => $child, 'type' => 'contains', 'quantity' => 1, 'data' => '{}', 'user_id_owner' => $userId]]);

// 2. Material-only part (material comp, no process)
$mOnly = se_ent($pg, $userId, $quoteId, 'part', 'Plate Material A36 1200x400x10', 2);
se_comp($pg, $userId, $mOnly, $quoteId, 'material', ['materialLibraryId' => '', 'category' => 'plate', 'length' => 1200, 'width' => 400, 'thickness' => 10]);

// 3. Process-only part (process comp, no material)
$pOnly = se_ent($pg, $userId, $quoteId, 'part', 'Subcontract Machining', 1);
se_comp($pg, $userId, $pOnly, $quoteId, 'process', ['machining' => 6.0, 'grinding' => 1.5]);

// 4. Plain part with both (pipe kind)
$both = se_ent($pg, $userId, $quoteId, 'part', 'Pipe Spool 6m DN100', 1);
se_comp($pg, $userId, $both, $quoteId, 'material', ['materialLibraryId' => '', 'category' => 'pipe', 'length' => 6000, 'costPerM' => 245, 'buttWeldQty' => 2, 'weldSize' => 6]);
se_comp($pg, $userId, $both, $quoteId, 'process', ['welding' => 3.0]);

echo "Seeded edit fixtures on quote $quoteId\n";
echo "  assembly:  $assy (child: $child)\n";
echo "  matOnly:   $mOnly\n";
echo "  procOnly:  $pOnly\n";
echo "  both:      $both\n";
