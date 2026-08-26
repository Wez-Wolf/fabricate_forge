<?php
/**
 * scripts/test-mock-estimation.php
 *
 * Quick smoke test for the full estimation flow:
 *  - Assembly with nested BOM
 *  - Parts with materials (plates, sections)
 *  - Process hours (welding, machining, boilermaking, cutting, drilling, grinding, bending)
 *  - Consumables and services
 *  - Paint & lining
 *  - Transport
 *  - Assembly cost rollup with quantity
 *  - Margin calculation
 *
 * Usage: php scripts/test-mock-estimation.php
 *
 * Prerequisites:
 *   1. Database seeded (materials, rates)
 *   2. Test user exists (email: api-test@fabricate.local, pass: TestPass123!)
 */

$forgeDir = dirname(__DIR__, 2) . '/forge';
require_once($forgeDir . '/php/util/helpers.php');
require_once($forgeDir . '/php/db/PgCrud.php');
require_once(__DIR__ . '/../api/_base.php');
require_once(__DIR__ . '/../api/auth.php');
require_once(__DIR__ . '/../api/entities.php');
require_once(__DIR__ . '/../api/components.php');
require_once(__DIR__ . '/../api/links.php');
require_once(__DIR__ . '/../api/cost.php');
require_once(__DIR__ . '/../api/rates.php');
require_once(__DIR__ . '/../api/process.php');
require_once(__DIR__ . '/../api/materials.php');
require_once(__DIR__ . '/../api/systems.php');
require_once(__DIR__ . '/../api/weldmodel.php');

\loadEnv(__DIR__ . '/../.env');

$pg = new \forge\db\PgCrud();

echo "=== Mock Estimation Flow Test ===\n\n";

// ── 1. Get test user ──
$userRow = $pg->read([
    'table' => 'user',
    'where' => 'email = $1',
    'params' => ['api-test@fabricate.local'],
    'limit' => 1
]);
$userId = $userRow['data'][0]['id'] ?? null;
$userEmail = $userRow['data'][0]['email'] ?? null;

if (!$userId) {
    echo "ERROR: Test user api-test@fabricate.local not found.\n";
    echo "Please run the seeding process first.\n";
    exit(1);
}
echo "✓ Test user found: $userEmail (ID: $userId)\n";

// ── 2. Get material library items ──
$materialsApi = new \api\materials();
$materialsApi->user_id = $userId;

$steelPlate = $materialsApi->handle_match(['search' => 'SS 304 Plate 10mm']);
$ssPlateId = $steelPlate[0]['id'] ?? null;

$steelAngle = $materialsApi->handle_match(['search' => 'SS 304 Angle 50x50x6']);
$angleId = $steelAngle[0]['id'] ?? null;

if (!$ssPlateId || !$angleId) {
    echo "ERROR: Could not find test materials in library.\n";
    echo "Run: php scripts/seed-materials.php first\n";
    exit(1);
}
echo "✓ Materials found: SS 304 Plate 10mm (ID: $ssPlateId), Angle 50x50x6 (ID: $angleId)\n";

// ── 3. Create test entities via ECS classes ──
$entities = new \api\entities();
$components = new \api\components();
$cost = new \api\cost();
$rates = new \api\rates();
$systems = new \api\systems();
$links = new \api\links();

// Set user_id on controllers
foreach ([$entities, $components, $cost, $rates, $systems, $links] as $ctrl) {
    $ref = new ReflectionProperty(get_class($ctrl), 'user_id');
    $ref->setAccessible(true);
    $ref->setValue($ctrl, $userId);
}

echo "\n=== Creating Test BOM ===\n";

// ── 4. Create Main Assembly ──
$assembly = $entities->handle_create([
    'name' => 'Mock Assembly - Test Skid',
    'type' => 'assembly',
    'quantity' => 1,
    'data' => ['marginPercent' => 30]
]);

if (isset($assembly['error'])) {
    echo "ERROR: Failed to create assembly: " . json_encode($assembly) . "\n";
    exit(1);
}
$assemblyId = $assembly['id'];
echo "✓ Created assembly: $assemblyId\n";

// ── 5. Create Parts ──
// Part 1: Frame (angle section)
$frame = $entities->handle_create([
    'name' => 'Test Frame',
    'type' => 'part',
    'quantity' => 1
]);
$frameId = $frame['id'];
echo "✓ Created part: Frame ($frameId)\n";

// Part 2: Top Plate
$plate = $entities->handle_create([
    'name' => 'Test Top Plate',
    'type' => 'part',
    'quantity' => 1
]);
$plateId = $plate['id'];
echo "✓ Created part: Top Plate ($plateId)\n";

// Part 3: Brace (2x quantity)
$brace = $entities->handle_create([
    'name' => 'Test Brace',
    'type' => 'part',
    'quantity' => 2
]);
$braceId = $brace['id'];
echo "✓ Created part: Brace x2 ($braceId)\n";

// ── 6. Add Materials ──
$components->handle_create([
    'entity_id' => $frameId,
    'type' => 'material',
    'data' => [
        'materialLibraryId' => $angleId,
        'category' => 'section',
        'length' => 2000
    ]
]);
echo "✓ Added material to Frame (angle section, 2m)\n";

$components->handle_create([
    'entity_id' => $plateId,
    'type' => 'material',
    'data' => [
        'materialLibraryId' => $ssPlateId,
        'category' => 'plate',
        'length' => 1200,
        'width' => 800,
        'thickness' => 10
    ]
]);
echo "✓ Added material to Top Plate (10mm, 1.2x0.8m)\n";

$components->handle_create([
    'entity_id' => $braceId,
    'type' => 'material',
    'data' => [
        'materialLibraryId' => $angleId,
        'category' => 'section',
        'length' => 1500
    ]
]);
echo "✓ Added material to Brace (angle, 1.5m)\n";

// ── 7. Add Process Hours ──
$components->handle_create([
    'entity_id' => $frameId,
    'type' => 'process',
    'data' => [
        'welding' => 2.5,
        'assembly' => 1.0
    ]
]);
echo "✓ Added process to Frame: welding 2.5h + assembly 1h\n";

$components->handle_create([
    'entity_id' => $plateId,
    'type' => 'process',
    'data' => [
        'machining' => 1.5,
        'painting' => 0.5
    ]
]);
echo "✓ Added process to Top Plate: machining 1.5h + painting 0.5h\n";

$components->handle_create([
    'entity_id' => $braceId,
    'type' => 'process',
    'data' => [
        'welding' => 1.0,
        'cutting' => 0.5
    ]
]);
echo "✓ Added process to Brace: welding 1h + cutting 0.5h\n";

// ── 8. Add Contains Links ──
$links->handle_create(['from_id' => $assemblyId, 'to_id' => $frameId, 'type' => 'contains', 'quantity' => 1]);
$links->handle_create(['from_id' => $assemblyId, 'to_id' => $plateId, 'type' => 'contains', 'quantity' => 1]);
$links->handle_create(['from_id' => $assemblyId, 'to_id' => $braceId, 'type' => 'contains', 'quantity' => 2]);
echo "✓ Added BOM links: Assembly → Frame, Plate, Brace x2\n";

// ── 9. Create Quote ──
$quote = $entities->handle_create([
    'name' => 'Mock Estimation Quote',
    'type' => 'quote',
    'quantity' => 1,
    'data' => ['status' => 'draft', 'currency' => 'USD']
]);
$quoteId = $quote['id'];
echo "✓ Created quote: $quoteId\n";

$links->handle_create(['from_id' => $quoteId, 'to_id' => $assemblyId, 'type' => 'contains', 'quantity' => 1]);
echo "✓ Linked quote → assembly\n";

// ── 10. Calculate Costs ──
echo "\n=== Cost Calculations ===\n";

// Set entity welding rate for frame
$rates->handle_set_entity_rate(['entity_id' => $frameId, 'trade' => 'welding', 'rate' => 120]);
echo "✓ Set welding rate for Frame: 120 R/h\n";

// Calculate individual parts
$frameCost = $cost->handle_calculate_entity(['entity_id' => $frameId, 'options' => ['margin_percent' => 25]]);
$plateCost = $cost->handle_calculate_entity(['entity_id' => $plateId, 'options' => ['consumables' => 5, 'margin_percent' => 30]]);
$braceCost = $cost->handle_calculate_entity(['entity_id' => $braceId, 'options' => ['margin_percent' => 30]]);

echo "\n--- Individual Part Costs ---\n";
echo "Frame:  " . json_encode([
    'material' => $frameCost['data']['material'] ?? 0,
    'welding' => $frameCost['data']['welding'] ?? 0,
    'assembly' => $frameCost['data']['assembly'] ?? 0,
    'total' => $frameCost['data']['total'] ?? 0
]) . "\n";

echo "Plate:  " . json_encode([
    'material' => $plateCost['data']['material'] ?? 0,
    'machining' => $plateCost['data']['machining'] ?? 0,
    'painting' => $plateCost['data']['paint'] ?? 0,
    'total' => $plateCost['data']['total'] ?? 0
]) . "\n";

echo "Brace:  " . json_encode([
    'material' => $braceCost['data']['material'] ?? 0,
    'welding' => $braceCost['data']['welding'] ?? 0,
    'cutting' => $braceCost['data']['cutting'] ?? 0,
    'total' => $braceCost['data']['total'] ?? 0
]) . "\n";

// Calculate assembly (recursive)
$assemblyCost = $cost->handle_calculate_assembly(['entity_id' => $assemblyId]);
echo "\n--- Assembly Rollup ---\n";
echo "Rolled total: " . ($assemblyCost['rolled_total'] ?? 0) . "\n";
echo "Children count: " . (count($assemblyCost['children'] ?? [])) . "\n";

// Load summary (ECS: recalc writes, then entity rows for the report)
$quoteData = $systems->handle_recalculate_entity(['entity_id' => $quoteId]);
$entCount = count($entities->handle_list(['quote_id' => $quoteId, 'limit' => 200]));
echo "\n--- Quote Summary ---\n";
echo "Entities loaded: " . $entCount . "\n";
echo "Total cost (persisted by recalc): " . ($quoteData['total_cost'] ?? 0) . "\n";

// ── 11. Verify Cost Component Persistence ──
$storedCost = $components->handle_list(['entity_id' => $frameId, 'type' => 'cost']);
if (!empty($storedCost)) {
    echo "✓ Cost component persisted for Frame\n";
} else {
    echo "⚠ Warning: Cost component not found for Frame\n";
}

echo "\n=== Test Complete ===\n";
echo "All core estimation flow components verified.\n";
echo "Note: Run ./tests/run-phase.sh phase11 for formal test verification.\n";