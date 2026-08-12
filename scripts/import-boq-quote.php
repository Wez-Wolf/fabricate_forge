<?php
/**
 * scripts/import-boq-quote.php
 *
 * Creates the Sandsloot Decline Piping mock quote and imports the deduped
 * BoQ line items (pipes, bends, tees, flanges, gaskets, fasteners, valves)
 * via the same boms.php import endpoint the UI uses.
 *
 * Usage: php scripts/import-boq-quote.php
 */
$forgeDir = '/var/www/html/forge';
require_once($forgeDir . '/php/util/helpers.php');
require_once($forgeDir . '/php/db/PgCrud.php');
require_once(__DIR__ . '/../api/_base.php');
require_once(__DIR__ . '/../api/entities.php');
require_once(__DIR__ . '/../api/boms.php');
require_once(__DIR__ . '/../api/systems.php');

\loadEnv(__DIR__ . '/../.env');
$pg = new \forge\db\PgCrud();

$userRow = $pg->read(['table' => 'user', 'where' => 'email = $1', 'params' => ['wesley.stuart@innofuse.xyz'], 'limit' => 1]);
$userId = $userRow['data'][0]['id'] ?? null;
if (!$userId) { echo "user not found\n"; exit; }

function setUser($o, $u) { $r = new ReflectionProperty(get_class($o), 'user_id'); $r->setAccessible(true); $r->setValue($o, $u); }
$entities = new \api\entities(); setUser($entities, $userId);
$boms = new \api\boms(); setUser($boms, $userId);
$systems = new \api\systems(); setUser($systems, $userId);

// ── 1. Create the quote ──
$q = $entities->handle_create([
    'type' => 'quote',
    'name' => 'PB23068 Sandsloot Decline Piping Tender (Mock)',
    'data' => [
        'status' => 'draft',
        'customerName' => 'Sandsloot Decline Project',
        'currency' => 'ZAR',
        'marginPercent' => 20,
        'quoteNumber' => 'PB23068-BOQ',
    ],
]);
$qid = $q['id'];
echo "Quote: " . $q['name'] . " (" . substr($qid, 0, 8) . ")\n\n";

// ── 2. Parse the import CSV ──
$rows = [];
if (($h = fopen('/tmp/boq_import.csv', 'r')) !== false) {
    $header = fgetcsv($h);
    while (($r = fgetcsv($h)) !== false) {
        if (count($r) < 8) continue;
        $rows[] = [
            'item_number' => $r[0],
            'description' => $r[1],
            'material' => $r[2],
            'quantity' => (float)$r[3],
            'length' => $r[4] !== '' ? (float)$r[4] : null,
            'width' => (isset($r[5]) && $r[5] !== '') ? (float)$r[5] : null,
            'thickness' => (isset($r[6]) && $r[6] !== '') ? (float)$r[6] : null,
            'type' => $r[7],
        ];
    }
    fclose($h);
}
echo "Parsed " . count($rows) . " line items from CSV\n\n";


// ── 3. Import via the API (same endpoint the browser BOM import uses) ──
$res = $boms->handle_import(['quote_id' => $qid, 'rows' => $rows]);
if (isset($res['error'])) { echo "Import error: " . json_encode($res) . "\n"; exit; }
echo "Imported: " . $res['imported'] . " entities, skipped: " . $res['skipped_count'] . "\n";

$unmatched = array_filter($res['matches'] ?? [], fn($m) => !$m['matched']);
$matched = array_filter($res['matches'] ?? [], fn($m) => $m['matched']);
echo "Material matched: " . count($matched) . " / " . count($res['matches'] ?? []) . "\n";
if ($unmatched) {
    echo "Unmatched materials:\n";
    $shown = 0;
    foreach ($unmatched as $u) {
        if ($shown++ >= 10) break;
        echo "  - [" . $u['item_number'] . "] " . substr($u['description'], 0, 60) . " (hint: " . $u['material'] . ")\n";
    }
}

// ── 4. Recalculate + takeoff ──
echo "\nRecalculating...\n";
$loaded = $systems->handle_load_quote(['quote_id' => $qid]);
if (isset($loaded['error'])) { echo "Calc error: " . json_encode($loaded) . "\n"; }
else {
    echo "Entities: " . count($loaded['entities']) . " | Grand total: ZAR " . number_format($loaded['total_cost'], 2) . "\n";
    $to = $boms->handle_takeoff(['quote_id' => $qid]);
    if (isset($to['materials'])) {
        echo "\nMaterial take-off (" . count($to['materials']) . " materials):\n";
        $cur = '';
        foreach ($to['materials'] as $m) {
            if ($m['group'] !== $cur) { $cur = $m['group']; echo "\n[" . $cur . "]\n"; }
            printf("  %-38s %-4s %9.2f @ %8.2f = %10.2f\n", $m['name'], $m['unit'], $m['qty'], $m['unit_cost'], $m['extended_cost']);
        }
        echo "\nTake-off total: ZAR " . number_format($to['totals']['total_cost'], 2) . " (" . $to['totals']['total_mass_kg'] . " kg)\n";
    }
}
echo "\nDone. Quote id: $qid\n";
