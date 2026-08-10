<?php
/**
 * fabricate_forge/scripts/seed-prefabs.php
 *
 * Seeds GLOBAL prefab templates from the original Fabricate app's
 * seed-prefabs.js (4 templates: pipe spool, equipment skid, storage tank,
 * platform walkway). Global rows have user_id_owner = NULL (visible to all
 * users, read-only via API) — same pattern as the material library.
 *
 * Usage:
 *   php scripts/seed-prefabs.php            # idempotent (skips existing names)
 *   php scripts/seed-prefabs.php --force    # re-inserts (updates by name)
 */

$forgeDir = dirname(__DIR__, 2) . '/forge';
require_once($forgeDir . '/php/util/helpers.php');
require_once($forgeDir . '/php/db/PgCrud.php');

\loadEnv(dirname(__DIR__) . '/.env');

$force = in_array('--force', $argv, true);
$pg = new \forge\db\PgCrud();
$conn = $pg->getConn();
if (!$conn) { fwrite(STDERR, "DB connection failed\n"); exit(1); }

// Same DDL as prefabs.php buildTable
$pg->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS prefab_template (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(200) NOT NULL,
    type VARCHAR(30) NOT NULL DEFAULT 'assembly',
    description TEXT,
    template_data JSONB DEFAULT '{}'::jsonb,
    version INT DEFAULT 1,
    data JSONB DEFAULT '{}'::jsonb,
    user_id_owner UUID,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    is_active BOOLEAN DEFAULT TRUE
)
SQL);

$PREFABS = [
    [
        'name' => 'Pipe Spool: Flanged Pipe Assembly',
        'type' => 'assembly',
        'description' => 'Pipe spool with two weld-neck flanges, suitable for 600mm NB piping',
        'version' => 2,
        'template_data' => [
            'root' => ['id' => 'root-assembly', 'type' => 'assembly', 'name' => 'Pipe Spool Assembly'],
            'items' => [
                ['id' => 'flange1', 'type' => 'part', 'name' => 'Flange WN 600#150', 'attributes' => ['diameter' => 600, 'rating' => 150]],
                ['id' => 'pipe', 'type' => 'part', 'name' => 'Pipe Section 600NB', 'attributes' => ['length' => 1500, 'diameter' => 600, 'schedule' => 'STD']],
                ['id' => 'flange2', 'type' => 'part', 'name' => 'Flange WN 600#150', 'attributes' => ['diameter' => 600, 'rating' => 150]],
            ],
            'processes' => [
                ['id' => 'weld1', 'name' => 'Tack Weld', 'durationHours' => 0.5, 'trade' => 'welding'],
                ['id' => 'weld2', 'name' => 'Root Weld', 'durationHours' => 1.0, 'trade' => 'welding'],
                ['id' => 'weld3', 'name' => 'Cap Weld', 'durationHours' => 1.5, 'trade' => 'welding'],
                ['id' => 'inspect', 'name' => 'Visual Inspection', 'durationHours' => 0.5, 'trade' => 'qualityControl'],
            ],
        ],
    ],
    [
        'name' => 'Equipment Skid: Pump Base Frame',
        'type' => 'assembly',
        'description' => 'Structural steel skid frame for pump mounting, with base plates and lifting lugs',
        'version' => 1,
        'template_data' => [
            'root' => ['id' => 'root-assembly', 'type' => 'assembly', 'name' => 'Pump Skid Frame'],
            'items' => [
                [
                    'id' => 'base-frame', 'type' => 'assembly', 'name' => 'Base Frame',
                    'attributes' => ['length' => 3000, 'width' => 1500],
                    'children' => [
                        ['id' => 'main-beam-l', 'type' => 'part', 'name' => 'Main Longitudinal Beam', 'attributes' => ['profile' => 'H-Beam 150x150', 'length' => 3000]],
                        ['id' => 'main-beam-r', 'type' => 'part', 'name' => 'Main Longitudinal Beam', 'attributes' => ['profile' => 'H-Beam 150x150', 'length' => 3000]],
                        ['id' => 'cross-beam-1', 'type' => 'part', 'name' => 'Cross Beam', 'attributes' => ['profile' => 'Channel 100x50', 'length' => 1500]],
                        ['id' => 'cross-beam-2', 'type' => 'part', 'name' => 'Cross Beam', 'attributes' => ['profile' => 'Channel 100x50', 'length' => 1500]],
                        ['id' => 'cross-beam-3', 'type' => 'part', 'name' => 'Cross Beam', 'attributes' => ['profile' => 'Channel 100x50', 'length' => 1500]],
                    ],
                ],
                [
                    'id' => 'base-plates', 'type' => 'assembly', 'name' => 'Base Plates',
                    'children' => [
                        ['id' => 'bp-1', 'type' => 'part', 'name' => 'Base Plate 300x300x20', 'attributes' => ['profile' => 'Plate 20mm', 'length' => 300, 'width' => 300]],
                        ['id' => 'bp-2', 'type' => 'part', 'name' => 'Base Plate 300x300x20', 'attributes' => ['profile' => 'Plate 20mm', 'length' => 300, 'width' => 300]],
                        ['id' => 'bp-3', 'type' => 'part', 'name' => 'Base Plate 300x300x20', 'attributes' => ['profile' => 'Plate 20mm', 'length' => 300, 'width' => 300]],
                        ['id' => 'bp-4', 'type' => 'part', 'name' => 'Base Plate 300x300x20', 'attributes' => ['profile' => 'Plate 20mm', 'length' => 300, 'width' => 300]],
                    ],
                ],
                [
                    'id' => 'lifting-lugs', 'type' => 'assembly', 'name' => 'Lifting Lugs',
                    'children' => [
                        ['id' => 'lug-1', 'type' => 'part', 'name' => 'Lifting Lug 50x200x25', 'attributes' => ['profile' => 'Plate 25mm', 'length' => 200, 'width' => 50]],
                        ['id' => 'lug-2', 'type' => 'part', 'name' => 'Lifting Lug 50x200x25', 'attributes' => ['profile' => 'Plate 25mm', 'length' => 200, 'width' => 50]],
                    ],
                ],
            ],
            'processes' => [
                ['id' => 'cut', 'name' => 'Plasma Cutting', 'durationHours' => 2.0, 'trade' => 'boilermaking'],
                ['id' => 'drill', 'name' => 'Drilling Bolt Holes', 'durationHours' => 1.0, 'trade' => 'machining'],
                ['id' => 'tack', 'name' => 'Tack Assembly', 'durationHours' => 1.5, 'trade' => 'boilermaking'],
                ['id' => 'weld', 'name' => 'Structural Welding', 'durationHours' => 4.0, 'trade' => 'welding'],
                ['id' => 'grind', 'name' => 'Grinding & Finishing', 'durationHours' => 1.5, 'trade' => 'boilermaking'],
                ['id' => 'paint', 'name' => 'Primer Application', 'durationHours' => 1.0, 'trade' => 'painting'],
            ],
        ],
    ],
    [
        'name' => 'Storage Tank: Vertical Cylindrical 5000L',
        'type' => 'assembly',
        'description' => 'Vertical cylindrical storage tank with dished ends, manhole, and nozzles',
        'version' => 1,
        'template_data' => [
            'root' => ['id' => 'root-assembly', 'type' => 'assembly', 'name' => 'Storage Tank 5000L'],
            'items' => [
                ['id' => 'shell', 'type' => 'part', 'name' => 'Tank Shell Course', 'attributes' => ['profile' => 'Plate 8mm', 'length' => 2000, 'width' => 5000, 'thickness' => 8]],
                ['id' => 'top-end', 'type' => 'part', 'name' => 'Dished Top End', 'attributes' => ['profile' => 'Plate 8mm', 'thickness' => 8, 'diameter' => 1500]],
                ['id' => 'bottom-end', 'type' => 'part', 'name' => 'Dished Bottom End', 'attributes' => ['profile' => 'Plate 10mm', 'thickness' => 10, 'diameter' => 1500]],
                [
                    'id' => 'nozzles', 'type' => 'assembly', 'name' => 'Nozzle Assembly',
                    'children' => [
                        ['id' => 'nozzle-inlet', 'type' => 'part', 'name' => 'Inlet Nozzle 100NB', 'attributes' => ['diameter' => 100, 'length' => 150]],
                        ['id' => 'nozzle-outlet', 'type' => 'part', 'name' => 'Outlet Nozzle 100NB', 'attributes' => ['diameter' => 100, 'length' => 150]],
                        ['id' => 'nozzle-vent', 'type' => 'part', 'name' => 'Vent Nozzle 50NB', 'attributes' => ['diameter' => 50, 'length' => 100]],
                    ],
                ],
                ['id' => 'manhole', 'type' => 'part', 'name' => 'Manhole 600mm', 'attributes' => ['diameter' => 600, 'rating' => 150]],
                [
                    'id' => 'supports', 'type' => 'assembly', 'name' => 'Support Legs',
                    'children' => [
                        ['id' => 'leg-1', 'type' => 'part', 'name' => 'Support Leg C-Channel', 'attributes' => ['profile' => 'Channel 150x75', 'length' => 800]],
                        ['id' => 'leg-2', 'type' => 'part', 'name' => 'Support Leg C-Channel', 'attributes' => ['profile' => 'Channel 150x75', 'length' => 800]],
                        ['id' => 'leg-3', 'type' => 'part', 'name' => 'Support Leg C-Channel', 'attributes' => ['profile' => 'Channel 150x75', 'length' => 800]],
                        ['id' => 'leg-4', 'type' => 'part', 'name' => 'Support Leg C-Channel', 'attributes' => ['profile' => 'Channel 150x75', 'length' => 800]],
                    ],
                ],
            ],
            'processes' => [
                ['id' => 'roll', 'name' => 'Plate Rolling', 'durationHours' => 3.0, 'trade' => 'boilermaking'],
                ['id' => 'weld-shell', 'name' => 'Shell Longitudinal Welding', 'durationHours' => 4.0, 'trade' => 'welding'],
                ['id' => 'weld-heads', 'name' => 'End Cap Welding', 'durationHours' => 3.0, 'trade' => 'welding'],
                ['id' => 'weld-nozzles', 'name' => 'Nozzle Welding', 'durationHours' => 2.0, 'trade' => 'welding'],
                ['id' => 'weld-supports', 'name' => 'Support Leg Welding', 'durationHours' => 2.0, 'trade' => 'welding'],
                ['id' => 'test', 'name' => 'Hydrostatic Test', 'durationHours' => 4.0, 'trade' => 'qualityControl'],
                ['id' => 'paint', 'name' => 'External Paint', 'durationHours' => 2.0, 'trade' => 'painting'],
            ],
        ],
    ],
    [
        'name' => 'Platform: Steel Grating Walkway 6000mm',
        'type' => 'assembly',
        'description' => 'Elevated steel walkway with grating, handrail, and toe plate',
        'version' => 1,
        'template_data' => [
            'root' => ['id' => 'root-assembly', 'type' => 'assembly', 'name' => 'Steel Walkway 6000mm'],
            'items' => [
                [
                    'id' => 'main-beams', 'type' => 'assembly', 'name' => 'Main Support Beams',
                    'children' => [
                        ['id' => 'beam-l', 'type' => 'part', 'name' => 'Main Beam Left', 'attributes' => ['profile' => 'I-Beam 200x100', 'length' => 6000]],
                        ['id' => 'beam-r', 'type' => 'part', 'name' => 'Main Beam Right', 'attributes' => ['profile' => 'I-Beam 200x100', 'length' => 6000]],
                    ],
                ],
                [
                    'id' => 'cross-members', 'type' => 'assembly', 'name' => 'Cross Members',
                    'children' => [
                        ['id' => 'cross-1', 'type' => 'part', 'name' => 'Cross Member', 'attributes' => ['profile' => 'Channel 100x50', 'length' => 1000, 'quantity' => 10]],
                    ],
                ],
                ['id' => 'grating', 'type' => 'part', 'name' => 'Steel Grating Panel 1000x6000', 'attributes' => ['profile' => 'Plate 5mm', 'length' => 6000, 'width' => 1000]],
                [
                    'id' => 'handrails', 'type' => 'assembly', 'name' => 'Handrail Assembly',
                    'children' => [
                        ['id' => 'handrail-top', 'type' => 'part', 'name' => 'Handrail Top Rail', 'attributes' => ['profile' => 'Round Bar 40mm', 'length' => 6000]],
                        ['id' => 'handrail-mid', 'type' => 'part', 'name' => 'Handrail Mid Rail', 'attributes' => ['profile' => 'Round Bar 20mm', 'length' => 6000]],
                        ['id' => 'posts', 'type' => 'part', 'name' => 'Handrail Posts', 'attributes' => ['profile' => 'Round Bar 20mm', 'length' => 1100, 'quantity' => 12]],
                    ],
                ],
                ['id' => 'toe-plate', 'type' => 'part', 'name' => 'Toe Plate 100x6000', 'attributes' => ['profile' => 'Plate 5mm', 'length' => 6000, 'width' => 100]],
            ],
            'processes' => [
                ['id' => 'cut-steel', 'name' => 'Cut Steel Members', 'durationHours' => 2.0, 'trade' => 'boilermaking'],
                ['id' => 'weld-frame', 'name' => 'Weld Frame Assembly', 'durationHours' => 4.0, 'trade' => 'welding'],
                ['id' => 'weld-handrail', 'name' => 'Weld Handrail', 'durationHours' => 3.0, 'trade' => 'welding'],
                ['id' => 'fit-grating', 'name' => 'Fit & Weld Grating', 'durationHours' => 2.0, 'trade' => 'boilermaking'],
                ['id' => 'paint', 'name' => 'Paint System', 'durationHours' => 3.0, 'trade' => 'painting'],
            ],
        ],
    ],
];

$inserted = 0;
$updated = 0;
foreach ($PREFABS as $p) {
    $existing = $pg->read([
        'table' => 'prefab_template',
        'where' => 'name = $1 AND user_id_owner IS NULL',
        'params' => [$p['name']],
        'limit' => 1,
    ]);

    $row = [
        'name' => $p['name'],
        'type' => $p['type'],
        'description' => $p['description'],
        'template_data' => $p['template_data'],
        'version' => $p['version'],
        'user_id_owner' => null,
    ];

    if (!empty($existing['data'])) {
        if ($force) {
            $pg->update('prefab_template', $row, 'id = $1 AND user_id_owner IS NULL', [$existing['data'][0]['id']]);
            $updated++;
            echo "[SEED] Updated prefab: {$p['name']}\n";
        } else {
            echo "[SEED] Prefab exists (skip): {$p['name']}\n";
        }
    } else {
        $pg->save(['table' => 'prefab_template', 'data' => $row]);
        $inserted++;
        echo "[SEED] Inserted prefab: {$p['name']}\n";
    }
}

echo "[SEED] Done: {$inserted} inserted, {$updated} updated (global prefab templates)\n";
