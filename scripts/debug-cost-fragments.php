<?php
require_once '/var/www/html/forge/php/util/helpers.php';
require_once __DIR__ . '/../api/_base.php';
require_once __DIR__ . '/../api/entities.php';
require_once __DIR__ . '/../api/components.php';
require_once __DIR__ . '/../api/cost.php';
require_once __DIR__ . '/../api/systems.php';
require_once __DIR__ . '/../api/process.php';
require_once __DIR__ . '/../api/rates.php';
require_once __DIR__ . '/../api/quotes.php';

\loadEnv(__DIR__ . '/../.env');
$pg = new \forge\db\PgCrud();
$userRow = $pg->read(['table' => 'user', 'sql' => 'SELECT id FROM "user" ORDER BY created_date LIMIT 1']);
$userId = $userRow['data'][0]['id'];

function own($obj, $userId) {
    $ref = new ReflectionProperty(get_class($obj), 'user_id');
    $ref->setAccessible(true);
    $ref->setValue($obj, $userId);
}
function snap($pg, $pid, $label) {
    $rows = $pg->read(['table' => 'component', 'where' => 'entity_id = $1 AND type = $2', 'params' => [$pid, 'cost']]);
    $d = $rows['data'][0]['data'] ?? null;
    if (!$d) { echo "[$label] NO COMP\n"; return; }
    printf("[%s] material=%s weldHrs=%s welding=%s processTotal=%s subtotal=%s margin=%s total=%s unitCost=%s entCount=%s\n",
        $label, $d['material'] ?? '-', $d['weldHrs'] ?? '-', $d['welding'] ?? '-',
        $d['processTotal'] ?? '-', $d['subtotal'] ?? '-', $d['margin'] ?? '-',
        $d['total'] ?? '-', $d['unitCost'] ?? '-', $d['entity_count'] ?? '-');
}

$entities = new \api\entities();   own($entities, $userId);
$components = new \api\components(); own($components, $userId);
$cost = new \api\cost();           own($cost, $userId);

$quote = $entities->handle_create(['name' => 'DBG2 quote', 'type' => 'quote', 'quantity' => 1]);
$pipe  = $entities->handle_create(['name' => 'DBG2 pipe', 'type' => 'part', 'quantity' => 2]);
$qid = $quote['id']; $pid = $pipe['id'];
snap($pg, $pid, 'after pipe create');

$components->handle_create(['entity_id' => $pid, 'type' => 'material', 'data' => [
    'length' => 6000, 'length_secondary' => 1000, 'costPerM' => 350,
]]);
snap($pg, $pid, 'after material comp');

$components->handle_create(['entity_id' => $pid, 'type' => 'process', 'data' => [
    'weldType' => 'SO', 'unit' => 'hrs', 'description' => 'Butt weld', 'welding' => 2.5,
]]);
snap($pg, $pid, 'after process comp');

$rates = $cost->getAllEffectiveRates($pid);
echo "rates for pid: " . json_encode(array_map(fn($r) => $r['rate'] ?? null, $rates)) . "\n";

// cleanup
$pg->execute("DELETE FROM component WHERE entity_id IN (SELECT id FROM entity WHERE name LIKE 'DBG2 %')");
$pg->execute("DELETE FROM link WHERE from_id = \$1 OR to_id = \$1 OR from_id = \$2 OR to_id = \$2", [$qid, $pid]);
$pg->execute("DELETE FROM entity WHERE name LIKE 'DBG2 %'");
echo "cleaned\n";
