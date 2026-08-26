<?php
/**
 * fabricate_forge/scripts/migrate-material-library.php
 *
 * Materials-as-entities migration: material_library rows → entity(type='material')
 * + specification/dimensions/rate components. The quote's material components
 * keep the field name `materialLibraryId` but it now points at the material
 * ENTITY id (mapped below). Read paths reconstruct the legacy row shape from
 * the entity + components, so cost.php / boms.php / the UI read unchanged.
 *
 * Idempotent: rows already migrated (entity.data.legacy_library_id set) are
 * skipped; reference remapping only touches rows pointing at legacy ids.
 *
 * Usage: php scripts/migrate-material-library.php
 */
$forgeDir = '/var/www/html/forge';
$app = '/var/www/html/fabricate_forge';
require_once($forgeDir . '/php/util/helpers.php');
require_once($forgeDir . '/php/db/PgCrud.php');
require_once($app . '/api/_base.php');
\loadEnv($app . '/.env');

$pg = new \forge\db\PgCrud();

// The global (NULL-owner) library rows become material entities owned by the
// canonical library owner — the primary user. Materials are SHARED reference
// data: reads are by-id/global, edits are owner-scoped (library only).
$libOwner = $argv[1] ?? null;
if (!$libOwner) {
    $u = $pg->read(['table' => 'user', 'order_fields' => ['created_at ASC'], 'limit' => 1])['data'][0] ?? null;
    $libOwner = $u['id'] ?? null;
}
if (!$libOwner) { fwrite(STDERR, "no fallback user found — pass one: php migrate-material-library.php <user_id>\n"); exit(1); }
printf("library owner: %s\n", $libOwner);

// 1. Ensure the 'material' entity type exists (idempotent ALTER)
$pg->execute('ALTER TABLE entity DROP CONSTRAINT IF EXISTS entity_type_check');
$pg->execute("ALTER TABLE entity ADD CONSTRAINT entity_type_check
              CHECK (type IN ('part','assembly','fastener','fitting','material','quote'))");

// 2. Migrate library rows → material entities + components
$rows = $pg->read([
    'table' => 'material_library',
    'order_fields' => ['created_at ASC'],
])['data'] ?? [];
echo "library rows: " . count($rows) . "\n";

$migrated = 0; $skipped = 0; $remapped = 0;
foreach ($rows as $r) {
    // Already migrated? (entity.data.legacy_library_id = this id)
    $existing = $pg->read([
        'table' => 'entity',
        'where' => "type = 'material' AND data->>'legacy_library_id' = \$1",
        'params' => [$r['id']], 'limit' => 1,
    ])['data'][0] ?? null;
    if ($existing) { $skipped++; continue; }

    $data = is_array($r['data'] ?? null) ? $r['data'] : [];
    $name = (string)($r['name'] ?? 'Material');

    $spec = array_intersect_key($data, array_flip([
        'kind', 'standard', 'rating', 'type', 'dn', 'schedule', 'facing',
        'dims', 'dimensions', 'description', 'weldCirc',
    ]));
    $spec['library_category'] = $r['library_category'] ?? 'material';
    $spec['profile'] = $r['profile'] ?? '';
    $spec['grade'] = $r['grade'] ?? '';
    $spec['material_type'] = $r['material_type'] ?? '';
    $spec['category'] = $r['category'] ?? 'Carbon Steel';
    if (!empty($r['aliases'])) $spec['aliases'] = $r['aliases'];

    $dims = array_intersect_key($data, array_flip([
        'massKg', 'od', 'wt', 'pipeOd', 'flangeOd', 'nb', 'nps',
        'extArea', 'intArea', 'paintArea', 'paintAreaPerM',
    ]));
    $dims['density'] = (float)($r['density'] ?? 0);
    $dims['thickness'] = (float)($r['thickness'] ?? 0);
    $dims['mass_per_meter'] = (float)($r['mass_per_meter'] ?? 0);
    $dims['mass_per_area'] = (float)($r['mass_per_area'] ?? 0);

    $rate = ['unit_cost' => (float)($r['unit_cost'] ?? 0)];

    // Create the material entity
    $e = $pg->save([
        'table' => 'entity',
        'data' => [
            'type' => 'material',
            'name' => $name,
            'description' => $data['description'] ?? $name,
            'quantity' => 1,
            'data' => ['legacy_library_id' => $r['id']],
            'user_id_owner' => $r['user_id_owner'] ?? $libOwner,
        ],
    ]);
    $eid = $e['data']['id'] ?? null;
    if (!$eid) { echo "  !! failed to create entity for {$name}\n"; continue; }

    // Components: specification / dimensions / rate
    foreach ([
        ['specification', $spec],
        ['dimensions', $dims],
        ['rate', $rate],
    ] as [$ctype, $cdata]) {
        $pg->save([
            'table' => 'component',
            'data' => [
                'entity_id' => $eid,
                'type' => $ctype,
                'data' => $cdata,
                'user_id_owner' => $r['user_id_owner'] ?? $libOwner,
            ],
        ]);
    }
    $migrated++;
}

// 3. Remap quote material components: materialLibraryId (legacy) → entity id
$legacyRefs = $pg->read([
    'table' => 'component',
    'where' => "type = 'material' AND data->>'materialLibraryId' IS NOT NULL",
])['data'] ?? [];
foreach ($legacyRefs as $c) {
    $oldId = $c['data']['materialLibraryId'] ?? null;
    if (!$oldId) continue;
    $ent = $pg->read([
        'table' => 'entity',
        'where' => "type = 'material' AND data->>'legacy_library_id' = \$1",
        'params' => [$oldId], 'limit' => 1,
    ])['data'][0] ?? null;
    if (!$ent) continue;
    $pg->execute(
        "UPDATE component SET data = jsonb_set(data, '{materialLibraryId}', to_jsonb(\$1::text))
         WHERE id = \$2",
        [$ent['id'], $c['id']]
    );
    $remapped++;
}

printf("migrated: %d | already migrated: %d | remapped references: %d\n", $migrated, $skipped, $remapped);
