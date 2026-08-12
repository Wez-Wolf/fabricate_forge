<?php
/**
 * scripts/setup-5-mock-quotes.php
 * Creates 5 comprehensive mock quotes with full BOMs for estimation testing.
 */

$forgeDir = dirname(__DIR__, 2) . '/forge';
require_once($forgeDir . '/php/util/helpers.php');
require_once($forgeDir . '/php/db/PgCrud.php');
require_once(__DIR__ . '/../api/_base.php');
require_once(__DIR__ . '/../api/entities.php');
require_once(__DIR__ . '/../api/components.php');
require_once(__DIR__ . '/../api/links.php');
require_once(__DIR__ . '/../api/cost.php');
require_once(__DIR__ . '/../api/rates.php');
require_once(__DIR__ . '/../api/process.php');
require_once(__DIR__ . '/../api/systems.php');

\loadEnv(__DIR__ . '/../.env');

$pg = new \forge\db\PgCrud();
echo "=== Setting Up 5 Mock Quotes ===\n\n";

// Get test user
$userRow = $pg->read(['table' => 'user', 'where' => 'email = $1', 'params' => ['api-test@fabricate.local'], 'limit' => 1]);
$userId = isset($userRow['data'][0]['id']) ? $userRow['data'][0]['id'] : null;
if (!$userId) {
    die("ERROR: Test user 'api-test@fabricate.local' not found.\n");
}
echo "User ID: " . $userId . "\n\n";

// Set up controllers
function setUser($obj, $uid) {
    $ref = new ReflectionProperty(get_class($obj), 'user_id');
    $ref->setAccessible(true);
    $ref->setValue($obj, $uid);
}

$entities = new \api\entities();
$components = new \api\components();
$cost = new \api\cost();
$systems = new \api\systems();
$links = new \api\links();

foreach ([$entities, $components, $cost, $systems, $links] as $ctrl) {
    setUser($ctrl, $userId);
}

// Find material - use simpler pattern matching
function findMaterial($pg, $namePattern) {
    $res = $pg->read([
        'table' => 'material_library',
        'where' => "name ILIKE '%{$namePattern}%'",
        'limit' => 1
    ]);
    $row = $res['data'][0] ?? null;
    return $row;
}

// Get key materials
echo "Finding materials...\n";
$plate6mm = findMaterial($pg, 'Plate 6mm');
$plate10mm = findMaterial($pg, 'Plate 10mm');
$plate12mm = findMaterial($pg, 'Plate 12mm');
$ssPlate = findMaterial($pg, 'SS 304 Plate 10mm');

echo "Materials:\n";
echo "  Plate 6mm: " . ($plate6mm['id'] ?? 'NOT FOUND') . " ($" . ($plate6mm['unit_cost'] ?? 0) . "/m)\n";
echo "  Plate 10mm: " . ($plate10mm['id'] ?? 'NOT FOUND') . " ($" . ($plate10mm['unit_cost'] ?? 0) . "/m)\n";
echo "  Plate 12mm: " . ($plate12mm['id'] ?? 'NOT FOUND') . " ($" . ($plate12mm['unit_cost'] ?? 0) . "/m)\n";
echo "  SS 304 Plate 10mm: " . ($ssPlate['id'] ?? 'NOT FOUND') . " ($" . ($ssPlate['unit_cost'] ?? 0) . "/m)\n\n";

if (!$plate6mm) {
    die("ERROR: Could not find plate materials!\n");
}

// Helper to create part with material and process
function createPart($entities, $components, $materialId, $name, $qty, $matDims, $processHours) {
    $part = $entities->handle_create(['type' => 'part', 'name' => $name, 'quantity' => $qty]);
    $partId = $part['id'];
    
    // Create material component with library reference
    $compData = $matDims;
    if ($materialId) {
        $compData['materialLibraryId'] = $materialId;
    }
    $components->handle_create([
        'entity_id' => $partId, 
        'type' => 'material', 
        'data' => $compData
    ]);
    
    // Create process component
    if (!empty($processHours)) {
        $components->handle_create([
            'entity_id' => $partId, 
            'type' => 'process', 
            'data' => $processHours
        ]);
    }
    
    return $partId;
}

// ── Quote 1: Tank Skid (Simple Assembly) ──
echo "=== Creating Q-001: Tank Skid Assembly ===\n";

$q1 = $entities->handle_create(['type' => 'quote', 'name' => 'Q-001: Tank Skid', 'data' => ['status' => 'draft', 'marginPercent' => 30]]);
$q1Id = $q1['id'];
echo "Created Quote: $q1Id\n";

$a1 = $entities->handle_create(['type' => 'assembly', 'name' => 'Skid Frame', 'quote_id' => $q1Id]);
$a1Id = $a1['id'];
echo "Created Assembly: $a1Id\n";

// Create parts with material references
$f1 = createPart($entities, $components, $plate10mm['id'], 'Frame Legs', 4, ['length' => 2000], ['welding' => 3.0, 'boilermaking' => 4.0]);
$p2 = createPart($entities, $components, $plate10mm['id'], 'Cover Plate', 1, ['length' => 1200, 'width' => 800], ['machining' => 1.5, 'painting' => 0.8]);
echo "Created Parts: $f1, $p2\n";

// Create links
$links->handle_create(['from_id' => $a1Id, 'to_id' => $f1, 'type' => 'contains', 'quantity' => 4]);
$links->handle_create(['from_id' => $a1Id, 'to_id' => $p2, 'type' => 'contains', 'quantity' => 1]);
$links->handle_create(['from_id' => $q1Id, 'to_id' => $a1Id, 'type' => 'contains', 'quantity' => 1]);
echo "Created Links\n";

$r1 = $systems->handle_load_quote(['quote_id' => $q1Id]);
$total1 = isset($r1['total_cost']) ? $r1['total_cost'] : 0;
echo "Q-001 Total: $" . number_format($total1, 2) . "\n\n";

// ── Quote 2: Pipe Spool ──
echo "=== Creating Q-002: Pipe Spool ===\n";

$q2 = $entities->handle_create(['type' => 'quote', 'name' => 'Q-002: Pipe Spool', 'data' => ['status' => 'submitted', 'marginPercent' => 25]]);
$q2Id = $q2['id'];

$a2 = $entities->handle_create(['type' => 'assembly', 'name' => 'Pipe Assembly', 'quote_id' => $q2Id]);
$a2Id = $a2['id'];

// Use mass-based costing with unit_cost
$pMain = createPart($entities, $components, null, 'Main Pipe', 1, ['mass' => 200], ['welding' => 2.5, 'assembly' => 1.0]);
$pElbows = createPart($entities, $components, null, 'Elbows', 6, ['mass' => 15], ['welding' => 1.5, 'cutting' => 0.5]);

$links->handle_create(['from_id' => $a2Id, 'to_id' => $pMain, 'type' => 'contains', 'quantity' => 1]);
$links->handle_create(['from_id' => $a2Id, 'to_id' => $pElbows, 'type' => 'contains', 'quantity' => 6]);
$links->handle_create(['from_id' => $q2Id, 'to_id' => $a2Id, 'type' => 'contains', 'quantity' => 1]);

$r2 = $systems->handle_load_quote(['quote_id' => $q2Id]);
$total2 = isset($r2['total_cost']) ? $r2['total_cost'] : 0;
echo "Q-002 Total: $" . number_format($total2, 2) . "\n\n";

// ── Quote 3: Machined Parts ──
echo "=== Creating Q-003: Machined Parts ===\n";

$q3 = $entities->handle_create(['type' => 'quote', 'name' => 'Q-003: Machined Parts', 'data' => ['status' => 'draft', 'marginPercent' => 35]]);
$q3Id = $q3['id'];

$a3 = $entities->handle_create(['type' => 'assembly', 'name' => 'Machined Assembly', 'quote_id' => $q3Id]);
$a3Id = $a3['id'];

$pShaft = createPart($entities, $components, null, 'Shafts', 4, ['mass' => 8, 'unitCost' => 50], ['turning' => 3.0, 'grinding' => 1.0]);
$pPlates = createPart($entities, $components, $plate6mm['id'], 'Machined Plates', 4, ['length' => 300, 'width' => 200, 'thickness' => 6], ['milling' => 2.0, 'drilling' => 1.0]);

$links->handle_create(['from_id' => $a3Id, 'to_id' => $pShaft, 'type' => 'contains', 'quantity' => 4]);
$links->handle_create(['from_id' => $a3Id, 'to_id' => $pPlates, 'type' => 'contains', 'quantity' => 4]);
$links->handle_create(['from_id' => $q3Id, 'to_id' => $a3Id, 'type' => 'contains', 'quantity' => 1]);

$r3 = $systems->handle_load_quote(['quote_id' => $q3Id]);
$total3 = isset($r3['total_cost']) ? $r3['total_cost'] : 0;
echo "Q-003 Total: $" . number_format($total3, 2) . "\n\n";

// ── Quote 4: Structural Frame (Nested) ──
echo "=== Creating Q-004: Structural Frame ===\n";

$q4 = $entities->handle_create(['type' => 'quote', 'name' => 'Q-004: Structural Frame', 'data' => ['status' => 'approved', 'marginPercent' => 28]]);
$q4Id = $q4['id'];

$a4 = $entities->handle_create(['type' => 'assembly', 'name' => 'Main Frame', 'quote_id' => $q4Id]);
$a4Id = $a4['id'];

$a4Sub = $entities->handle_create(['type' => 'assembly', 'name' => 'Brace Section', 'quantity' => 2, 'quote_id' => $q4Id]);
$a4SubId = $a4Sub['id'];

$pBase = createPart($entities, $components, $plate12mm['id'], 'Base Plates', 6, ['length' => 1200, 'width' => 1200, 'thickness' => 12], ['boilermaking' => 1.5, 'welding' => 1.0]);
$pStrut = createPart($entities, $components, $plate10mm['id'], 'Struts', 12, ['length' => 3000, 'width' => 100, 'thickness' => 10], ['welding' => 2.0, 'assembly' => 0.5]);
$pBrace = createPart($entities, $components, $plate6mm['id'], 'Brace Bars', 24, ['length' => 150, 'width' => 50, 'thickness' => 6], ['cutting' => 0.3]);

$links->handle_create(['from_id' => $a4SubId, 'to_id' => $pBrace, 'type' => 'contains', 'quantity' => 24]);
$links->handle_create(['from_id' => $a4Id, 'to_id' => $pBase, 'type' => 'contains', 'quantity' => 6]);
$links->handle_create(['from_id' => $a4Id, 'to_id' => $pStrut, 'type' => 'contains', 'quantity' => 12]);
$links->handle_create(['from_id' => $a4Id, 'to_id' => $a4SubId, 'type' => 'contains', 'quantity' => 2]);
$links->handle_create(['from_id' => $q4Id, 'to_id' => $a4Id, 'type' => 'contains', 'quantity' => 1]);

$r4 = $systems->handle_load_quote(['quote_id' => $q4Id]);
$total4 = isset($r4['total_cost']) ? $r4['total_cost'] : 0;
echo "Q-004 Total: $" . number_format($total4, 2) . "\n\n";

// ── Quote 5: Complete Project ──
echo "=== Creating Q-005: Process Unit ===\n";

$q5 = $entities->handle_create(['type' => 'quote', 'name' => 'Q-005: Process Unit', 'data' => ['status' => 'approved', 'marginPercent' => 32]]);
$q5Id = $q5['id'];

$a5 = $entities->handle_create(['type' => 'assembly', 'name' => 'Process Unit', 'quote_id' => $q5Id]);
$a5Id = $a5['id'];

$pShell = createPart($entities, $components, $plate12mm['id'], 'Vessel Shell', 1, ['length' => 2000, 'width' => 1200, 'thickness' => 12], ['boilermaking' => 4.0, 'welding' => 3.0]);
$pCovers = createPart($entities, $components, $ssPlate['id'], 'Manway Covers', 4, ['length' => 500, 'width' => 400, 'thickness' => 10], ['machining' => 1.5]);

$links->handle_create(['from_id' => $a5Id, 'to_id' => $pShell, 'type' => 'contains', 'quantity' => 1]);
$links->handle_create(['from_id' => $a5Id, 'to_id' => $pCovers, 'type' => 'contains', 'quantity' => 4]);
$links->handle_create(['from_id' => $q5Id, 'to_id' => $a5Id, 'type' => 'contains', 'quantity' => 1]);

$r5 = $systems->handle_load_quote(['quote_id' => $q5Id]);
$total5 = isset($r5['total_cost']) ? $r5['total_cost'] : 0;
echo "Q-005 Total: $" . number_format($total5, 2) . "\n\n";

// ── Summary ──
echo "=== Summary ===\n";
$grandTotal = $total1 + $total2 + $total3 + $total4 + $total5;
echo "Q-001 Tank Skid: $" . number_format($total1, 2) . "\n";
echo "Q-002 Pipe Spool: $" . number_format($total2, 2) . "\n";
echo "Q-003 Machined Parts: $" . number_format($total3, 2) . "\n";
echo "Q-004 Structural Frame: $" . number_format($total4, 2) . "\n";
echo "Q-005 Process Unit: $" . number_format($total5, 2) . "\n";
echo "----------------------------------------\n";
echo "GRAND TOTAL: $" . number_format($grandTotal, 2) . "\n\n";

echo "Setup complete! Verify via browser at /nav/quotes\n";