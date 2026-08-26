<?php
/**
 * scripts/seed-spool-mock.php
 *
 * Seeds a realistic DN100 pipe-spool mock quote showing flanges welded ON a
 * pipe and a concentric reducer welded to the pipe (stepping DN100→DN50).
 * Each flange/fitting carries boilermaking fit-up + welding hours (from the
 * cost engine's weld model), so the Process / Overview tabs show real
 * Bm/W/M labour for a fabrication spool.
 *
 * Structure:
 *   Quote: "Mock Spool — DN100 Flanged + Reduced"
 *   └─ Spool Assembly "DN100 Pipe Spool"
 *       ├─ Pipe:   DN100 3 m #40              (pipeWt for flanges = 6.02)
 *       │    ├─ Flange WN DN100 150lb  (butt-welded ON the pipe, qty 1)
 *       │    └─ Flange WN DN100 150lb  (butt-welded ON the pipe, qty 1)
 *       ├─ Reducer: Concentric Reducer DN100×50 #40 (welded to the pipe)
 *       │    └─ Pipe: DN50 1 m #40             (pipeWt for flange = 5.54)
 *       │         └─ Flange WN DN50 150lb (butt-welded on the DN50 end)
 *
 * Usage:
 *   php scripts/seed-spool-mock.php [email]   # default api-test@fabricate.local
 */
$forgeDir = dirname(__DIR__, 2) . '/forge';
require_once($forgeDir . '/php/util/helpers.php');
require_once($forgeDir . '/php/db/PgCrud.php');
\loadEnv(dirname(__DIR__) . '/.env');

$email = $argv[1] ?? 'api-test@fabricate.local';
$pg = new \forge\db\PgCrud();
$conn = $pg->getConn();
if (!$conn) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

// Resolve user
$u = $pg->read(['table' => 'user', 'where' => 'email = $1', 'params' => [$email], 'limit' => 1]);
$user = $u['data'][0] ?? null;
if (!$user) { fwrite(STDERR, "User not found: $email\n"); exit(1); }
$userId = $user['id'];

// API classes (need setUser to reuse the same owner-scoping)
require_once(__DIR__ . '/../api/_base.php');
require_once(__DIR__ . '/../api/entities.php');
require_once(__DIR__ . '/../api/components.php');
require_once(__DIR__ . '/../api/links.php');
require_once(__DIR__ . '/../api/systems.php');

function setUser($o, $u) { $r = new ReflectionProperty(get_class($o), 'user_id'); $r->setAccessible(true); $r->setValue($o, $u); }

$entities = new \api\entities(); setUser($entities, $userId);
$components = new \api\components(); setUser($components, $userId);
$links = new \api\links(); setUser($links, $userId);
$systems = new \api\systems(); setUser($systems, $userId);

// ── Material lookups ──────────────────────────────────
function matByName($pg, $name) {
    $r = $pg->read(['table' => 'material_library', 'where' => 'name = $1', 'params' => [$name], 'limit' => 1]);
    $legacy = $r['data'][0] ?? null;
    if (!$legacy) return null;
    // Materials-as-entities: resolve to the ENTITY id (the API reads entities).
    $e = $pg->read(['table' => 'entity', 'where' => "type = 'material' AND data->>'legacy_library_id' = \$1", 'params' => [$legacy['id']], 'limit' => 1])['data'][0] ?? null;
    if (!$e) $e = $pg->read(['table' => 'entity', 'where' => "type = 'material' AND name = \$1", 'params' => [$name], 'limit' => 1])['data'][0] ?? null;
    if (!$e) return null;
    $e['data'] = $legacy['data'] ?? [];
    return $e;
}
$pipe100   = matByName($pg, 'PIPE DN 100 #40 A106B');              // DN100 pipe, WT 6.02
$flange100 = matByName($pg, 'Flange DN100 150 lb ANSI B 16.5 WN'); // welds onto DN100 pipe
$reducer   = matByName($pg, 'Concentric Reducer DN100×50 #40 B16.9');
$pipe50    = matByName($pg, 'PIPE DN 50 #40 A106B');               // DN50 pipe, WT 5.54
$flange50  = matByName($pg, 'Flange DN50 150 lb ANSI B 16.5 WN');   // welds onto DN50 pipe

foreach (['pipe100'=>$pipe100,'flange100'=>$flange100,'reducer'=>$reducer,'pipe50'=>$pipe50,'flange50'=>$flange50] as $k=>$m) {
    if (!$m) { fwrite(STDERR, "Missing library material: $k (run scripts/seed-materials.php first?)\n"); exit(1); }
}

// ── Quote ─────────────────────────────────────────────
$quoteName = 'Mock Spool — DN100 Flanged + Reduced';
$existing = $pg->read([
    'table' => 'entity',
    'where' => "type='quote' AND name = $1 AND user_id_owner = \$2",
    'params' => [$quoteName, $userId],
    'limit' => 1,
]);
if (!empty($existing['data'])) {
    echo "[SEED] Quote already exists — skipping (" . substr($existing['data'][0]['id'],0,8) . ")\n";
    exit(0);
}

$q = $entities->handle_create([
    'type' => 'quote',
    'name' => $quoteName,
    'data' => [
        'quoteNumber'   => 'Q-SPOOL-' . strtoupper(substr(bin2hex(random_bytes(3)),0,6)),
        'customerName'  => 'Mock Fabrication',
        'currency'      => 'ZAR',
        'status'        => 'draft',
        'marginPercent' => 25,
        'statusHistory' => [['status'=>'draft','date'=>date('c'),'note'=>'Quote created']],
    ],
]);
$quoteId = $q['id'];

// ── Helpers ───────────────────────────────────────────
function insEntity($entities, $type, $name, $qty, $quoteId, $qIdFor) {
    return $entities->handle_create(['type'=>$type,'name'=>$name,'quantity'=>$qty,'quote_id'=>$quoteId])['id'];
}
function insMaterial($components, $entityId, $quoteId, $data) {
    $components->handle_create(['entity_id'=>$entityId,'type'=>'material','data'=>$data]);
}
function insProcess($components, $entityId, $quoteId, $hours) {
    $components->handle_create(['entity_id'=>$entityId,'type'=>'process','data'=>$hours]);
}
function insLink($links, $fromId, $toId, $qty) {
    $links->handle_create(['from_id'=>$fromId,'to_id'=>$toId,'type'=>'contains','quantity'=>$qty]);
}

// ── Root assembly ─────────────────────────────────────
$spoolId = insEntity($entities, 'assembly', 'DN100 Pipe Spool', 1, $quoteId, null);
insLink($links, $quoteId, $spoolId, 1);

// ── Main DN100 pipe (3 m) — carries 2 butt welds for the flanges, plus BM handling ──
$pipe100Id = insEntity($entities, 'part', 'DN100 Pipe 3 m #40', 1, $quoteId, null);
insMaterial($components, $pipe100Id, $quoteId, [
    'materialLibraryId' => $pipe100['id'],
    'length'     => 3000,
    'buttWeldQty'=> 3,        // 2 flange joints + 1 reducer joint → butt welds
    'costPerM'   => 240,      // R/m variable (est.)
    'shopHrsPerKg'=> 0.06,    // BM shop handling hrs/kg
]);
insProcess($components, $pipe100Id, $quoteId, ['boilermaking'=>0]); // BM auto from shop handling
insLink($links, $spoolId, $pipe100Id, 1);

// ── Flanges ON the DN100 pipe (WN → butt-welded, pipeWt = DN100 #40 = 6.02) ──
$fl100a = insEntity($entities, 'fitting', 'Flange WN DN100 150 lb', 1, $quoteId, null);
insMaterial($components, $fl100a, $quoteId, [
    'materialLibraryId' => $flange100['id'],
    'pipeWt'   => (float)$pipe100['data']['wt'],   // 6.02 — WT of the pipe it welds onto
    'costPerEa'=> 380,
]);
insLink($links, $pipe100Id, $fl100a, 1);

$fl100b = insEntity($entities, 'fitting', 'Flange WN DN100 150 lb', 1, $quoteId, null);
insMaterial($components, $fl100b, $quoteId, [
    'materialLibraryId' => $flange100['id'],
    'pipeWt'   => (float)$pipe100['data']['wt'],
    'costPerEa'=> 380,
]);
insLink($links, $pipe100Id, $fl100b, 1);

// ── Concentric reducer DN100×50 welded to the DN100 pipe ──
$redId = insEntity($entities, 'fitting', 'Concentric Reducer DN100 × DN50 #40', 1, $quoteId, null);
insMaterial($components, $redId, $quoteId, [
    'materialLibraryId' => $reducer['id'],
    'costPerEa'=> 170,
]);
insLink($links, $spoolId, $redId, 1);

// ── DN50 pipe (1 m) off the reducer small end ──
$pipe50Id = insEntity($entities, 'part', 'DN50 Pipe 1 m #40', 1, $quoteId, null);
insMaterial($components, $pipe50Id, $quoteId, [
    'materialLibraryId' => $pipe50['id'],
    'length'      => 1000,
    'buttWeldQty' => 1,        // reducer→DN50 joint
    'costPerM'    => 120,
    'shopHrsPerKg'=> 0.06,
]);
insLink($links, $redId, $pipe50Id, 1);

// ── Flange on the DN50 end (pipeWt = DN50 #40 = 5.54) ──
$fl50 = insEntity($entities, 'fitting', 'Flange WN DN50 150 lb', 1, $quoteId, null);
insMaterial($components, $fl50, $quoteId, [
    'materialLibraryId' => $flange50['id'],
    'pipeWt'   => (float)$pipe50['data']['wt'],
    'costPerEa'=> 220,
]);
insLink($links, $pipe50Id, $fl50, 1);

// ── Recalculate + report ──────────────────────────────
$loaded = $systems->handle_recalculate_entity(['entity_id' => $quoteId]);
if (isset($loaded['error'])) { echo "Calc error: " . json_encode($loaded['error']) . "\n"; exit(1); }

// ECS compose: entity rows + components → per-entity costs for the report
$ents = $entities->handle_list(['quote_id' => $quoteId, 'limit' => 200]);
$comps = $components->handle_get_by_quote(['quote_id' => $quoteId]);
$costById = [];
foreach ($comps as $c) { if (($c['type'] ?? '') === 'cost' && !empty($c['entity_id'])) $costById[$c['entity_id']] = $c['data'] ?? []; }
foreach ($ents as &$e) $e['cost'] = $costById[$e['id']] ?? [];
unset($e);

echo "\n[SEED] Spool mock quote created for $email\n";
echo "  Quote:   $quoteName (" . substr($quoteId,0,8) . ")\n";
echo "  Entities:" . count($ents) . "\n";
foreach ($ents as $e) {
    $k = $e['cost']['kind'] ?? '';
    printf("   - %-34s %-9s mat=%-9s BM=%-6s W=%-6s tot=%.2f\n",
        (string)$e['name'], $e['type'], number_format($e['cost']['material'] ?? 0,2),
        $e['cost']['boilerHrs'] ?? 0, $e['cost']['weldHrs'] ?? 0, (float)($e['cost']['total'] ?? 0));
}
printf("  Quote grand total: R %.2f\n", (float)$loaded['total_cost']);
echo "  Boilermaking total: " . ($loaded['totals']['boilerHrs'] ?? 0) . " h\n";
echo "  Welding total:      " . ($loaded['totals']['weldHrs'] ?? 0) . " h\n";
