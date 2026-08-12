<?php
/**
 * scripts/test-cost-engine.php — end-to-end cost engine smoke test.
 * Creates throwaway quote entities (pipe / flange / fitting) and runs
 * handle_calculate_entity against real library rows, printing the
 * returnable cost elements.
 *
 * Usage: php scripts/test-cost-engine.php
 */
$forgeDir = dirname(__DIR__, 2) . '/forge';
require_once($forgeDir . '/php/util/helpers.php');
require_once($forgeDir . '/php/db/PgCrud.php');
require_once(__DIR__ . '/../api/_base.php');
require_once(__DIR__ . '/../api/cost.php');
require_once(__DIR__ . '/../api/components.php');
require_once(__DIR__ . '/../api/entities.php');
require_once(__DIR__ . '/../api/process.php');
require_once(__DIR__ . '/../api/rates.php');
require_once(__DIR__ . '/../api/weldmodel.php');

\loadEnv(__DIR__ . '/../.env');

$pg = new \forge\db\PgCrud();
$conn = $pg->getConn();
if (!$conn) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

$userRow = $pg->read(['table' => 'user', 'sql' => 'SELECT id FROM "user" ORDER BY created_date LIMIT 1']);
$userId = $userRow['data'][0]['id'] ?? null;
if (!$userId) { fwrite(STDERR, "No user found\n"); exit(1); }

function libId($pg, $where, $params)
{
    $r = $pg->read(['table' => 'material_library', 'where' => $where, 'params' => $params, 'limit' => 1]);
    return $r['data'][0]['id'] ?? null;
}

$pipeId   = libId($pg, "data->>'kind'='pipe' AND data->>'dn'='100' AND data->>'schedule'='#40'", []);
$flangeId = libId($pg, "data->>'kind'='flange' AND data->>'dn'='100' AND data->>'type'='WN' AND data->>'rating' ILIKE '%150%'", []);
$fitId    = libId($pg, "data->>'kind'='fitting' AND data->>'type'='90° LRE' AND data->>'catalogueSize'='100' AND data->>'series'='#40'", []);

printf("pipe id: %s\nflange id: %s\nfitting id: %s\n\n", $pipeId, $flangeId, $fitId);

function setUserId($obj, $userId)
{
    $ref = new ReflectionProperty(get_class($obj), 'user_id');
    $ref->setAccessible(true);
    $ref->setValue($obj, $userId);
}

$entities = new \api\entities();
$components = new \api\components();
$cost = new \api\cost();
setUserId($entities, $userId);
setUserId($components, $userId);
setUserId($cost, $userId);

function makeEntity($entities, $name, $type, $qty)
{
    return $entities->handle_create(['name' => $name, 'type' => $type, 'quantity' => $qty]);
}

function setMaterial($components, $entityId, $data)
{
    $components->handle_create(['entity_id' => $entityId, 'type' => 'material', 'data' => $data]);
}

function setOnCosts($entities, $entityId, $onCosts)
{
    $entities->handle_update(['id' => $entityId, 'data' => ['onCosts' => $onCosts]]);
}

$results = [];

// ── 1. Pipe: 6 m of DN100 #40, 4 butt welds, shop handling, in-house paint ──
$e = makeEntity($entities, 'TEST Pipe DN100 #40 6m', 'part', 1);
setMaterial($components, $e['data']['id'] ?? $e['id'], [
    'materialLibraryId' => $pipeId,
    'length' => 6000,
    'buttWeldQty' => 4,
    'costPerM' => 350,          // R/m variable
    'shopHrsPerKg' => 0.06,     // BM hrs/kg
]);
setOnCosts($entities, $e['data']['id'] ?? $e['id'], [
    'painting' => ['mode' => 'inhouse', 'extPaint' => 45, 'intPaint' => 35, 'coating1' => 25, 'coating2' => 18],
    'transportPerTon' => 850,
]);
$r = $cost->handle_calculate_entity(['entity_id' => $e['data']['id'] ?? $e['id']]);
$results['PIPE'] = $r['data'] ?? $r;
echo "── PIPE (6m DN100 #40, 4 butt welds) ──\n";
foreach (['matCost','bmHrs','wHrs','mHrs','labCost','cons','serve','ndt','lining','painting','transport','total'] as $k) {
    echo str_pad($k, 11) . $results['PIPE'][$k] . "\n";
}
echo "  details: " . json_encode($results['PIPE']['details'] ?? []) . "\n\n";

// ── 2. Flange: WN DN100 150lb (butt weld, pipe WT 6.02) ──
$e = makeEntity($entities, 'TEST Flange WN DN100', 'part', 2);
setMaterial($components, $e['data']['id'] ?? $e['id'], [
    'materialLibraryId' => $flangeId,
    'pipeWt' => 6.02,
    'costPerEa' => 1200,
]);
setOnCosts($entities, $e['data']['id'] ?? $e['id'], [
    'painting' => ['mode' => 'subcontract', 'extPaint' => 65, 'intPaint' => 55],
]);
$r = $cost->handle_calculate_entity(['entity_id' => $e['data']['id'] ?? $e['id']]);
$results['FLANGE'] = $r['data'] ?? $r;
echo "── FLANGE (WN DN100 x2, subcontract paint) ──\n";
foreach (['matCost','bmHrs','wHrs','mHrs','labCost','cons','serve','ndt','lining','painting','transport','total'] as $k) {
    echo str_pad($k, 11) . $results['FLANGE'][$k] . "\n";
}
echo "  details: " . json_encode($results['FLANGE']['details'] ?? []) . "\n\n";

// ── 3. Fitting: 90° LRE DN100 #40 ──
$e = makeEntity($entities, 'TEST 90 LRE DN100 #40', 'part', 4);
setMaterial($components, $e['data']['id'] ?? $e['id'], [
    'materialLibraryId' => $fitId,
    'costPerEa' => 850,
]);
$r = $cost->handle_calculate_entity(['entity_id' => $e['data']['id'] ?? $e['id']]);
$results['FITTING'] = $r['data'] ?? $r;
echo "── FITTING (90° LRE DN100 #40 x4) ──\n";
foreach (['matCost','bmHrs','wHrs','mHrs','labCost','cons','serve','ndt','lining','painting','transport','total'] as $k) {
    echo str_pad($k, 11) . $results['FITTING'][$k] . "\n";
}
echo "  details: " . json_encode($results['FITTING']['details'] ?? []) . "\n\n";

// ── 4. Plate (generic material — legacy mass-based path must be unchanged) ──
$plateId = libId($pg, "profile='Plate' AND name LIKE 'S235JR Plate 10mm'", []);
$e = makeEntity($entities, 'TEST Plate 1x2m x10mm', 'part', 1);
setMaterial($components, $e['data']['id'] ?? $e['id'], [
    'materialLibraryId' => $plateId,
    'length' => 2000,
    'width' => 1000,
    'thickness' => 10,
]);
$r = $cost->handle_calculate_entity(['entity_id' => $e['data']['id'] ?? $e['id']]);
$results['PLATE'] = $r['data'] ?? $r;
echo "── PLATE (2x1m x10mm, no paint rates) ──\n";
foreach (['matCost','bmHrs','wHrs','mHrs','labCost','cons','serve','ndt','lining','painting','transport','total'] as $k) {
    echo str_pad($k, 11) . $results['PLATE'][$k] . "\n";
}
echo "  details: " . json_encode($results['PLATE']['details'] ?? []) . "\n\n";

// cleanup test entities
$pg->execute('DELETE FROM entity WHERE name LIKE \'TEST %\'');
echo "cleaned up test entities\n";
