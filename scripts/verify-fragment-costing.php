<?php
/**
 * scripts/verify-fragment-costing.php — throwaway E2E check of the
 * fragment-based cost engine. Creates a quote + part (material comp with
 * D1-green secondary length, weld process comp with weldType/unit/desc,
 * machining process comp), recalcs, prints the persisted cost comp, cleans up.
 */
require_once '/var/www/html/forge/php/util/helpers.php';
require_once __DIR__ . '/../api/_base.php';
require_once __DIR__ . '/../api/entities.php';
require_once __DIR__ . '/../api/components.php';
require_once __DIR__ . '/../api/cost.php';
require_once __DIR__ . '/../api/systems.php';
require_once __DIR__ . '/../api/quotes.php';
require_once __DIR__ . '/../api/process.php';
require_once __DIR__ . '/../api/rates.php';

\loadEnv(__DIR__ . '/../.env');

$pg = new \forge\db\PgCrud();
if (!$pg->getConn()) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

$userRow = $pg->read(['table' => 'user', 'sql' => 'SELECT id FROM "user" ORDER BY created_date LIMIT 1']);
$userId = $userRow['data'][0]['id'] ?? null;
if (!$userId) { fwrite(STDERR, "No user\n"); exit(1); }

function own($obj, $userId) {
    $ref = new ReflectionProperty(get_class($obj), 'user_id');
    $ref->setAccessible(true);
    $ref->setValue($obj, $userId);
}

$entities = new \api\entities();   own($entities, $userId);
$components = new \api\components(); own($components, $userId);
$cost = new \api\cost();           own($cost, $userId);
$systems = new \api\systems();     own($systems, $userId);
$quotes = new \api\quotes();       own($quotes, $userId);

$quote = $entities->handle_create(['name' => 'VERIFY-FRAG quote', 'type' => 'quote', 'quantity' => 1]);
$pipe  = $entities->handle_create(['name' => 'VERIFY-FRAG pipe', 'type' => 'part', 'quantity' => 2]);
$qid = $quote['id']; $pid = $pipe['id'];

// Material: 6 m primary + 1 m D1 green secondary, R350/m
$components->handle_create(['entity_id' => $pid, 'type' => 'material', 'data' => [
    'length' => 6000, 'length_secondary' => 1000,
    'costPerM' => 350, 'shopHrsPerKg' => 0,
]]);
// Weld process: estimator-selected type/unit/description (all required)
$components->handle_create(['entity_id' => $pid, 'type' => 'process', 'data' => [
    'weldType' => 'SO', 'unit' => 'hrs',
    'description' => 'Butt weld pipe to make up length',
    'welding' => 2.5,
]]);
// Machining process on the same entity (multiple processes)
$components->handle_create(['entity_id' => $pid, 'type' => 'process', 'data' => [
    'machining' => 1.5, 'description' => 'Machine ends',
]]);

// Attach the pipe to the quote (sets quote_id + recalcs)
$quotes->handle_add_entity(['quote_id' => $qid, 'entity_id' => $pid]);

$systems->handle_recalculate_entity(['entity_id' => $qid]);

$costData = $cost->handle_get_cost(['entity_id' => $pid]);
echo "── Part cost comp (qty=2) ──\n";
echo json_encode($costData, JSON_PRETTY_PRINT) . "\n";
foreach (['material','weldHrs','machHrs','boilerHrs','welding','machining','processTotal','labor','subtotal','margin','total','unitCost'] as $k) {
    printf("  %-13s %s\n", $k, $costData[$k] ?? '—');
}
echo "  details: " . json_encode($costData['details'] ?? []) . "\n";

$ov = $systems->handle_overview(['entity_id' => $qid]);
echo "\n── Quote overview ──\n";
echo "  total_cost: " . ($ov['total_cost'] ?? '—') . "\n";
echo "  entity_count: " . ($ov['entity_count'] ?? '—') . "\n";

// Expected math:
//   material = (6m + 1m green) × 350 × qty2 = 4900
//   welding  = 2.5h × rate × 2 ; machining = 1.5h × rate × 2
echo "\nExpected material: 4900 (7m × 350 × 2)\n";

// cleanup
$pg->execute("DELETE FROM component WHERE entity_id IN (SELECT id FROM entity WHERE name LIKE 'VERIFY-FRAG%')");
$pg->execute("DELETE FROM link WHERE from_id = \$1 OR to_id = \$1 OR from_id = \$2 OR to_id = \$2", [$qid, $pid]);
$pg->execute("DELETE FROM entity WHERE name LIKE 'VERIFY-FRAG%'");
echo "cleaned up\n";
