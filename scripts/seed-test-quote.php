<?php
/**
 * fabricate_forge/scripts/seed-test-quote.php
 *
 * Seeds a demo "Tank Skid" quote for a user, ported from the original
 * Fabricate app's imports/api/methods/test-data.js:
 *
 *   Tank Skid Assembly (assembly, SS304 skid frame)
 *   ├─ Main Frame        (part, SS304, welding process)
 *   ├─ Support Bracket   (part ×2, SS304, machining process)
 *   └─ (contains links between the assembly and its parts)
 *
 * Uses the seeded global material library (SS 304) so the cost engine
 * prices the quote for real.
 *
 * Usage:
 *   php scripts/seed-test-quote.php [email]      # default: api-test@fabricate.local
 *   php scripts/seed-test-quote.php wesley.stuart@innofuse.xyz
 */

$forgeDir = dirname(__DIR__, 2) . '/forge';
require_once($forgeDir . '/php/util/helpers.php');
require_once($forgeDir . '/php/db/PgCrud.php');

\loadEnv(dirname(__DIR__) . '/.env');

$email = $argv[1] ?? 'api-test@fabricate.local';
$pg = new \forge\db\PgCrud();
$conn = $pg->getConn();
if (!$conn) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

// Resolve user by email → user_id_owner
$u = $pg->read([
    'table' => 'user',
    'where' => 'email = $1',
    'params' => [$email],
    'limit' => 1,
]);
$user = $u['data'][0] ?? null;
if (!$user) { fwrite(STDERR, "User not found: $email\n"); exit(1); }
$userId = $user['id'];

// Ensure ECS tables exist (reuse API build paths)
require_once(__DIR__ . '/../api/_base.php');
require_once(__DIR__ . '/../api/entities.php');
require_once(__DIR__ . '/../api/components.php');
require_once(__DIR__ . '/../api/links.php');

// ── Material lookups (global library) ──────────────────
function matByName($pg, $name) {
    $r = $pg->read(['table' => 'material_library', 'where' => 'name = $1', 'params' => [$name], 'limit' => 1]);
    return $r['data'][0] ?? null;
}

$skidMat  = matByName($pg, 'SS 304 Plate 6mm');     // assembly skin
$frameMat = matByName($pg, 'SS 304 Angle 75x75x6');  // main frame legs
$bracketMat = matByName($pg, 'SS 304 Plate 10mm');   // brackets

if (!$skidMat || !$frameMat || !$bracketMat) {
    fwrite(STDERR, "Missing SS 304 materials in library — run php scripts/seed-materials.php first\n");
    exit(1);
}
echo "Materials: {$skidMat['name']}, {$frameMat['name']}, {$bracketMat['name']}\n";

// ── Quote ─────────────────────────────────────────────
// A quote is an entity with type='quote'; data carries quote fields.
$qres = $pg->save([
    'table' => 'entity',
    'data' => [
        'type' => 'quote',
        'name' => 'Test Project - Tank Skid',
        'description' => 'End-to-end test with assemblies, parts, materials and processes',
        'quantity' => 1,
        'data' => [
            'quoteNumber' => 'Q-TEST-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'customerName' => 'Test Customer',
            'customerEmail' => 'test@example.com',
            'currency' => 'ZAR',
            'status' => 'draft',
            'statusHistory' => [['status' => 'draft', 'date' => date('c'), 'note' => 'Quote created']],
        ],
        'user_id_owner' => $userId,
    ],
]);
if (!empty($qres['error'])) { fwrite(STDERR, "Quote create failed: " . json_encode($qres) . "\n"); exit(1); }
$quoteId = $qres['data']['id'];
echo "Quote: $quoteId\n";

// ── Assembly ──────────────────────────────────────────
function insEntity($pg, $userId, $type, $name, $desc, $qty, $quoteId) {
    $r = $pg->save([
        'table' => 'entity',
        'data' => [
            'type' => $type,
            'name' => $name,
            'description' => $desc,
            'quote_id' => $quoteId,
            'quantity' => $qty,
            'user_id_owner' => $userId,
        ],
    ]);
    if (!empty($r['error'])) { fwrite(STDERR, "Entity $name failed: " . json_encode($r) . "\n"); exit(1); }
    return $r['data']['id'];
}
function insComponent($pg, $userId, $entityId, $type, $data, $quoteId) {
    $r = $pg->save([
        'table' => 'component',
        'data' => [
            'entity_id' => $entityId,
            'type' => $type,
            'data' => $data,
            'quote_id' => $quoteId,
            'user_id_owner' => $userId,
        ],
    ]);
    if (!empty($r['error'])) { fwrite(STDERR, "Component $type failed: " . json_encode($r) . "\n"); exit(1); }
    return $r['data']['id'];
}
function insLink($pg, $userId, $fromId, $toId, $type, $qty) {
    $pg->save([
        'table' => 'link',
        'data' => [
            'from_id' => $fromId,
            'to_id' => $toId,
            'type' => $type,
            'quantity' => $qty,
            'user_id_owner' => $userId,
        ],
    ]);
}

$assemblyId = insEntity($pg, $userId, 'assembly', 'Tank Skid Assembly',
    'Main assembly for tank skid project', 1, $quoteId);

// Basic component
insComponent($pg, $userId, $assemblyId, 'basic',
    ['name' => 'Tank Skid Assembly', 'description' => 'Main assembly'], $quoteId);
// Material: skid skin — 6m plate
insComponent($pg, $userId, $assemblyId, 'material',
    [
        'materialLibraryId' => $skidMat['id'],
        'category' => 'plate',
        'type' => 'Stainless Steel',
        'grade' => '304',
        'density' => 7900,
        'length' => 6000,
    ], $quoteId);
// Process: boilermaking 4h + welding 2h
insComponent($pg, $userId, $assemblyId, 'process',
    ['boilermaking' => 4, 'welding' => 2], $quoteId);

// ── Main Frame (part) ─────────────────────────────────
$frameId = insEntity($pg, $userId, 'part', 'Main Frame',
    'Primary structural frame', 1, $quoteId);
insComponent($pg, $userId, $frameId, 'material',
    [
        'materialLibraryId' => $frameMat['id'],
        'category' => 'section',
        'length' => 2000,
        'type' => 'Stainless Steel',
        'grade' => '304',
        'density' => 7900,
    ], $quoteId);
insComponent($pg, $userId, $frameId, 'process', ['welding' => 1.5], $quoteId);

// ── Support Bracket (part ×2) ─────────────────────────
$bracketId = insEntity($pg, $userId, 'part', 'Support Bracket',
    'Mounting bracket', 2, $quoteId);
insComponent($pg, $userId, $bracketId, 'material',
    [
        'materialLibraryId' => $bracketMat['id'],
        'type' => 'Stainless Steel',
        'grade' => '304',
        'density' => 7900,
    ], $quoteId);
insComponent($pg, $userId, $bracketId, 'process', ['machining' => 0.5], $quoteId);

// ── Contains links (assembly → parts) ─────────────────
insLink($pg, $userId, $assemblyId, $frameId, 'contains', 1);
insLink($pg, $userId, $assemblyId, $bracketId, 'contains', 2);

echo "Seeded test quote for $email\n";
echo "  Quote:     Test Project - Tank Skid ($quoteId)\n";
echo "  Assembly:  Tank Skid Assembly ($assemblyId)\n";
echo "  Parts:     Main Frame ($frameId), Support Bracket x2 ($bracketId)\n";
echo "  Cost:      run the cost engine via systems.php load_quote\n";
