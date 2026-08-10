<?php
/**
 * fabricate_forge/scripts/seed-materials.php
 *
 * Seeds the GLOBAL material library from seed-data/*.json.
 * Global rows have user_id_owner = NULL (visible to all users, read-only via API).
 *
 * Ported from the original Fabricate app's material-catalog.js (83 steel
 * materials, 13 fasteners, 6 fittings) + private/seed-data/*.json (SS 304/316,
 * fasteners, fittings).
 *
 * Usage:
 *   php scripts/seed-materials.php            # idempotent (skips existing names)
 *   php scripts/seed-materials.php --force    # re-inserts (updates by name)
 */

$forgeDir = dirname(__DIR__, 2) . '/forge';
require_once($forgeDir . '/php/util/helpers.php');
require_once($forgeDir . '/php/db/PgCrud.php');

\loadEnv(dirname(__DIR__) . '/.env');

$force = in_array('--force', $argv, true);
$pg = new \forge\db\PgCrud();
$conn = $pg->getConn();
if (!$conn) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

// Ensure the table exists (same DDL as materials.php buildTable)
$pg->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS material_library (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(200) NOT NULL,
    profile VARCHAR(100),
    material_type VARCHAR(50),
    category VARCHAR(50) DEFAULT 'Carbon Steel',
    grade VARCHAR(100),
    density NUMERIC,
    thickness NUMERIC,
    mass_per_meter NUMERIC,
    mass_per_area NUMERIC,
    unit_cost NUMERIC DEFAULT 0,
    library_category VARCHAR(20) DEFAULT 'material',
    aliases JSONB DEFAULT '[]'::jsonb,
    data JSONB DEFAULT '{}'::jsonb,
    user_id_owner UUID,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
)
SQL);
$pg->execute('CREATE INDEX IF NOT EXISTS idx_mat_lib_cat ON material_library(library_category)');
$pg->execute('CREATE INDEX IF NOT EXISTS idx_mat_lib_owner ON material_library(user_id_owner)');

/** Map profile → library_category (pricing mode). */
function profileToCategory($profile) {
    $p = strtolower($profile ?? '');
    if (str_contains($p, 'plate') || str_contains($p, 'sheet')) return 'material';
    if (str_contains($p, 'angle') || str_contains($p, 'channel') || str_contains($p, 'beam')
        || str_contains($p, 'bar') || str_contains($p, 'pipe') || str_contains($p, 'tube')) return 'material';
    if (str_contains($p, 'fitting')) return 'fitting';
    if (str_contains($p, 'bolt') || str_contains($p, 'nut') || str_contains($p, 'washer')
        || str_contains($p, 'screw') || str_contains($p, 'stud')) return 'fastener';
    return 'material';
}

/** Map materialType → category (the original's category field). */
function materialTypeToCategory($t) {
    $t = $t ?? 'Carbon Steel';
    if (in_array($t, ['Carbon Steel', 'Stainless Steel', 'Aluminum', 'Copper', 'Brass', 'Titanium', 'Plastic'])) return $t;
    return 'Other';
}

$inserted = 0; $updated = 0; $skipped = 0;

foreach (['materials', 'fasteners', 'fittings'] as $file) {
    $path = __DIR__ . '/../seed-data/' . $file . '.json';
    if (!file_exists($path)) { fwrite(STDERR, "missing $path\n"); continue; }
    $rows = json_decode(file_get_contents($path), true);
    if (!is_array($rows)) { fwrite(STDERR, "invalid json in $path\n"); continue; }

    foreach ($rows as $m) {
        $name = $m['name'] ?? '';
        if (!$name) continue;

        $profile = $m['profile'] ?? null;
        $libCat = profileToCategory($profile ?: $m['type'] ?? ($file === 'fasteners' ? 'fastener' : ''));
        if ($file === 'fasteners' || $libCat === 'fastener') $libCat = 'fastener';
        if ($file === 'fittings') $libCat = 'fitting';

        $aliases = [];
        foreach (['grade', 'profile'] as $k) {
            if (!empty($m[$k])) $aliases[] = (string)$m[$k];
        }

        $data = (object)[];
        foreach (['type', 'dimensions', 'thread', 'finish', 'standard', 'supplier', 'diameter', 'currency', 'available'] as $k) {
            if (isset($m[$k])) $data->{$k} = $m[$k];
        }
        $dataJson = json_encode($data) ?: '{}';

        $row = [
            'name' => $name,
            'profile' => $profile,
            'material_type' => $m['materialType'] ?? $m['material'] ?? null,
            'category' => materialTypeToCategory($m['materialType'] ?? $m['material'] ?? null),
            'grade' => $m['grade'] ?? null,
            'density' => $m['density'] ?? null,
            'thickness' => $m['thickness'] ?? null,
            'mass_per_meter' => $m['massPerMeter'] ?? null,
            'mass_per_area' => $m['massPerArea'] ?? null,
            'unit_cost' => $m['unitCost'] ?? 0,
            'library_category' => $libCat,
            'aliases' => $aliases,
            'data' => $dataJson,
            'user_id_owner' => null,
        ];

        // Idempotent: skip/update by exact name for global rows
        $existing = $pg->read([
            'table' => 'material_library',
            'where' => 'name = $1 AND user_id_owner IS NULL',
            'params' => [$name],
            'limit' => 1,
        ]);

        if (!empty($existing['data'])) {
            if ($force) {
                $row['id'] = $existing['data'][0]['id'];
                $pg->save(['table' => 'material_library', 'data' => $row]);
                $updated++;
            } else {
                $skipped++;
            }
        } else {
            $pg->save(['table' => 'material_library', 'data' => $row]);
            $inserted++;
        }
    }
}

$count = $pg->read(['table' => 'material_library', 'sql' => 'SELECT COUNT(*)::int as c FROM material_library WHERE user_id_owner IS NULL']);
echo "Seed complete: $inserted inserted, $updated updated, $skipped skipped (existing)\n";
echo "Total global materials: " . ($count['data'][0]['c'] ?? '?') . "\n";
