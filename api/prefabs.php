<?php
/**
 * fabricate_forge/api/prefabs.php
 *
 * Prefab templates — reusable assemblies that instantiate into quotes as an
 * ECS entity tree (root assembly + child parts with material components +
 * process component + contains links). Ported from the original app's
 * prefabs.js method file.
 *
 * Actions:
 *   list / create / update / delete / get       — template CRUD
 *   bake_from_quote                             — save a quote's assembly as a template
 *   instantiate                                 — materialize a template into a quote
 */
namespace api;

include_once(__DIR__ . "/_base.php");
include_once(__DIR__ . "/entities.php");
include_once(__DIR__ . "/components.php");
include_once(__DIR__ . "/links.php");
include_once(__DIR__ . "/materials.php");
include_once(__DIR__ . "/systems.php");

class prefabs extends Base
{
    protected function buildTable()
    {
        $this->ensureEcsTables();
        $this->pgCrud->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS prefab_template (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(200) NOT NULL,
    type VARCHAR(30) NOT NULL DEFAULT 'assembly',
    description TEXT,
    template_data JSONB DEFAULT '{}'::jsonb,
    version INT DEFAULT 1,
    data JSONB DEFAULT '{}'::jsonb,
    user_id_owner UUID NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    is_active BOOLEAN DEFAULT TRUE
)
SQL);
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_prefab_owner ON prefab_template(user_id_owner)');

        $this->pgCrud->execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS prefab_instance (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    prefab_id UUID,
    quote_id UUID,
    root_entity_id UUID,
    child_ids JSONB DEFAULT '[]'::jsonb,
    instance_data JSONB DEFAULT '{}'::jsonb,
    version_at_instantiation INT DEFAULT 1,
    user_id_owner UUID NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW()
)
SQL);
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_prefab_inst_owner ON prefab_instance(user_id_owner)');
        $this->pgCrud->execute('CREATE INDEX IF NOT EXISTS idx_prefab_inst_quote ON prefab_instance(quote_id)');
    }

    // ── Template CRUD ──────────────────────────────────

    public function handle_list($input = [])
    {
        $res = $this->pgCrud->read([
            'table' => 'prefab_template',
            'where' => 'user_id_owner = $1 AND is_active = TRUE',
            'params' => [$this->user_id],
            'order_fields' => ['name ASC'],
        ]);
        return $res['data'] ?? [];
    }

    public function handle_get($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'prefab_id');
        if (!$id) return ['error' => 'prefab_id is required.'];
        $res = $this->pgCrud->read([
            'table' => 'prefab_template',
            'where' => 'id = $1 AND user_id_owner = $2',
            'params' => [$id, $this->user_id],
            'limit' => 1,
        ]);
        $row = $res['data'][0] ?? null;
        if (!$row) return ['error' => 'Prefab not found.', 'error_code' => 404];
        return $row;
    }

    /**
     * Create a template.
     * Input: { name, type?, description?, template_data: { root, items[], processes[] }, version? }
     */
    public function handle_create($input = [])
    {
        $name = \getVal($input, 'name');
        if (!$name) return ['error' => 'name is required.'];
        $templateData = \getVal($input, 'template_data', []);
        if (!is_array($templateData)) $templateData = [];

        $res = $this->pgCrud->save([
            'table' => 'prefab_template',
            'data' => [
                'name' => $name,
                'type' => \getVal($input, 'type', 'assembly'),
                'description' => \getVal($input, 'description', ''),
                'template_data' => $templateData,
                'version' => \getVal($input, 'version', 1),
                'user_id_owner' => $this->user_id,
            ],
        ]);
        if (!empty($res['error'])) return $res;
        return $this->handle_get(['id' => $res['data']['id']]);
    }

    /**
     * Update a template (partial; template_data merged).
     */
    public function handle_update($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'prefab_id');
        if (!$id) return ['error' => 'prefab_id is required.'];
        if (!$this->handle_get(['id' => $id])) {
            return ['error' => 'Prefab not found.', 'error_code' => 404];
        }

        $sets = [];
        $params = [];
        $idx = 1;
        foreach (['name','type','description','version'] as $col) {
            if (array_key_exists($col, $input)) {
                $sets[] = "$col = \${$idx}";
                $params[] = $input[$col];
                $idx++;
            }
        }
        // template_data: JSONB merge into the stored blob
        if (array_key_exists('template_data', $input) && is_array($input['template_data'])) {
            $sets[] = "template_data = template_data || \${$idx}::jsonb";
            $params[] = json_encode($input['template_data']);
            $idx++;
        }
        if (!$sets) return ['error' => 'Nothing to update.'];

        $sets[] = 'updated_at = NOW()';
        $params[] = $id;
        $params[] = $this->user_id;

        $this->pgCrud->execute(
            "UPDATE prefab_template SET " . implode(', ', $sets) .
            " WHERE id = \${$idx} AND user_id_owner = \$" . ($idx + 1),
            $params
        );
        return $this->handle_get(['id' => $id]);
    }

    public function handle_delete($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'prefab_id');
        if (!$id) return ['error' => 'prefab_id is required.'];
        $this->pgCrud->execute(
            "UPDATE prefab_template SET is_active = FALSE, updated_at = NOW()
             WHERE id = \$1 AND user_id_owner = \$2",
            [$id, $this->user_id]
        );
        return ['success' => true, 'id' => $id];
    }

    /**
     * Bake a quote's assembly into a template (mirrors prefabs.bakeFromQuote).
     * Input: { quote_id, assembly_id, name? }
     */
    public function handle_bake_from_quote($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        $assemblyId = \getVal($input, 'assembly_id');
        if (!$quoteId || !$assemblyId) {
            return ['error' => 'quote_id and assembly_id are required.'];
        }

        // Ownership check on the assembly (must belong to the quote)
        $entities = new \api\entities();
        $entities->user_id = $this->user_id;
        $entity = $this->getEntity($assemblyId);
        if (!$entity || ($entity['quote_id'] ?? null) !== $quoteId) {
            return ['error' => 'assembly-not-found', 'error_code' => 404];
        }

        // Gather all quote entities into template items (id, type, name, quantity)
        $items = [];
        $res = $this->pgCrud->read([
            'table' => 'entity',
            'where' => 'quote_id = $1 AND user_id_owner = $2 AND is_active = TRUE',
            'params' => [$quoteId, $this->user_id],
        ]);
        foreach (($res['data'] ?? []) as $it) {
            $items[] = [
                'id' => $it['id'],
                'type' => $it['type'] ?? 'assembly',
                'name' => $it['name'] ?? 'Item',
                'quantity' => (float)($it['quantity'] ?? 1),
            ];
        }

        $name = \getVal($input, 'name', 'Prefab from Quote: ' . ($entity['name'] ?? 'Assembly'));
        $templateData = [
            'root' => ['id' => $assemblyId, 'type' => 'assembly', 'name' => $entity['name'] ?? 'Assembly'],
            'items' => $items,
            'processes' => [['id' => 'default', 'name' => 'Assemble', 'durationHours' => 1, 'consumables' => []]],
            'consumables' => [],
        ];

        return $this->handle_create([
            'name' => $name,
            'type' => 'assembly',
            'description' => 'Auto-baked prefab from quote assembly',
            'template_data' => $templateData,
            'version' => 1,
        ]);
    }

    /**
     * Instantiate a template into a quote (mirrors prefabs.instantiate).
     * Creates: root assembly + child entities (material comps) + process comp
     * on root + contains links + instance record, then recalculates quote cost.
     * Input: { prefab_id, quote_id }
     */
    public function handle_instantiate($input = [])
    {
        $prefabId = \getVal($input, 'prefab_id');
        $quoteId = \getVal($input, 'quote_id');
        if (!$prefabId || !$quoteId) {
            return ['error' => 'prefab_id and quote_id are required.'];
        }

        $prefab = $this->handle_get(['id' => $prefabId]);
        if (empty($prefab['id'])) return $prefab;

        // Quote must exist + be owned
        $quote = $this->getEntity($quoteId);
        if (!$quote || $quote['type'] !== 'quote') {
            return ['error' => 'Quote not found.', 'error_code' => 404];
        }

        $templateData = $this->decodeJson($prefab['template_data'] ?? []);
        $items = $templateData['items'] ?? [];
        $processes = $templateData['processes'] ?? [];
        $rootDef = $templateData['root'] ?? ['id' => 'root', 'type' => 'assembly', 'name' => $prefab['name']];

        // 1. Root assembly
        $rootEntityId = $this->insertEntity([
            'type' => $rootDef['type'] ?? 'assembly',
            'name' => $rootDef['name'] ?? $prefab['name'],
            'quote_id' => $quoteId,
            'quantity' => 1,
        ]);

        // 2. Child tree (recursive)
        $childIds = $this->createEntityTree($items, $rootEntityId, $quoteId);

        // 3. Process component on the root (trade → hours aggregation)
        if (count($processes) > 0) {
            $processData = [];
            foreach ($processes as $proc) {
                $trade = \getVal($proc, 'trade', 'assembly');
                $processData[$trade] = round(($processData[$trade] ?? 0) + (float)\getVal($proc, 'durationHours', 0), 2);
            }
            $this->pgCrud->save([
                'table' => 'component',
                'data' => [
                    'entity_id' => $rootEntityId,
                    'type' => 'process',
                    'data' => $processData,
                    'quote_id' => $quoteId,
                    'user_id_owner' => $this->user_id,
                ],
            ]);
        }

        // 4. Link root to the quote (contains)
        $this->insertLink($quoteId, $rootEntityId, 'contains', 1);

        // 5. Instance record
        $instRes = $this->pgCrud->save([
            'table' => 'prefab_instance',
            'data' => [
                'prefab_id' => $prefabId,
                'quote_id' => $quoteId,
                'root_entity_id' => $rootEntityId,
                'child_ids' => $childIds,
                'instance_data' => $templateData,
                'version_at_instantiation' => (int)\getVal($prefab, 'version', 1),
                'user_id_owner' => $this->user_id,
            ],
        ]);
        $instanceId = $instRes['data']['id'] ?? null;

        // 6. Recalculate quote costs (non-fatal)
        $recalc = null;
        try {
            $systems = new \api\systems();
            $systems->user_id = $this->user_id;
            $recalc = $systems->handle_recalculate_quote(['quote_id' => $quoteId]);
        } catch (\Throwable $e) {
            $recalc = ['error' => 'recalc failed (non-fatal): ' . $e->getMessage()];
        }

        return [
            'instance_id' => $instanceId,
            'root_entity_id' => $rootEntityId,
            'child_ids' => $childIds,
            'total_cost' => $recalc['total_cost'] ?? null,
        ];
    }

    // ── Internal ───────────────────────────────────────

    private function insertEntity($data)
    {
        $res = $this->pgCrud->save([
            'table' => 'entity',
            'data' => [
                'type' => $data['type'],
                'name' => $data['name'],
                'quote_id' => $data['quote_id'],
                'quantity' => $data['quantity'] ?? 1,
                'data' => $data['data'] ?? [],
                'user_id_owner' => $this->user_id,
            ],
        ]);
        return $res['data']['id'] ?? null;
    }

    private function insertLink($fromId, $toId, $type, $quantity = 1)
    {
        return $this->pgCrud->save([
            'table' => 'link',
            'data' => [
                'from_id' => $fromId,
                'to_id' => $toId,
                'type' => $type,
                'quantity' => $quantity,
                'user_id_owner' => $this->user_id,
            ],
        ]);
    }

    /**
     * Recursively create entities from template items. Children attach to the
     * parent via a contains link. Parts get a material component (with the
     * library item resolved by profile) + attributes stored in entity.data.
     */
    private function createEntityTree($items, $parentId, $quoteId)
    {
        $createdIds = [];
        foreach ($items as $item) {
            $entityId = $this->createEntityFromPrefab($item, $quoteId, $parentId);
            if (!$entityId) continue;
            $createdIds[] = $entityId;
            $children = $item['children'] ?? [];
            if (is_array($children) && count($children) > 0) {
                $grand = $this->createEntityTree($children, $entityId, $quoteId);
                foreach ($grand as $g) $createdIds[] = $g;
            }
        }
        return $createdIds;
    }

    /**
     * Create a single prefab entity: attributes → entity.data, material
     * component from attributes via the material library.
     */
    private function createEntityFromPrefab($item, $quoteId, $parentId)
    {
        $attrs = \getVal($item, 'attributes', []);
        if (!is_array($attrs)) $attrs = [];

        $entityId = $this->insertEntity([
            'type' => \getVal($item, 'type', 'part'),
            'name' => \getVal($item, 'name', 'Item'),
            'quote_id' => $quoteId,
            'quantity' => \getVal($item, 'quantity', 1),
            'data' => count($attrs) ? ['attributes' => $attrs] : [],
        ]);
        if (!$entityId) return null;

        if ($parentId) {
            $this->insertLink($parentId, $entityId, 'contains', \getVal($item, 'quantity', 1));
        }

        // Material component for parts (mirrors buildMaterialData in prefabs.js)
        if (($item['type'] ?? 'part') === 'part' && count($attrs) > 0) {
            $materialData = $this->buildMaterialData($attrs);
            if (count($materialData) > 0) {
                $this->pgCrud->save([
                    'table' => 'component',
                    'data' => [
                        'entity_id' => $entityId,
                        'type' => 'material',
                        'data' => $materialData,
                        'quote_id' => $quoteId,
                        'user_id_owner' => $this->user_id,
                    ],
                ]);
            }
        }

        return $entityId;
    }

    /**
     * Infer material category from a profile string or attribute hints.
     * (port of inferCategory from prefabs.js)
     */
    private function inferCategory($attrs)
    {
        if (!empty($attrs['category'])) return $attrs['category'];
        $profile = strtolower((string)\getVal($attrs, 'profile', ''));
        if ($profile && (str_contains($profile, 'plate') || !empty($attrs['thickness']))) return 'plate';
        if ($profile && (str_contains($profile, 'beam') || str_contains($profile, 'h-beam') || str_contains($profile, 'i-beam'))) return 'section';
        if ($profile && str_contains($profile, 'channel')) return 'section';
        if ($profile && str_contains($profile, 'angle')) return 'section';
        if ($profile && (str_contains($profile, 'pipe') || str_contains($profile, 'tube') || !empty($attrs['diameter']))) return 'pipe';
        if ($profile && str_contains($profile, 'flat')) return 'section';
        if ($profile && (str_contains($profile, 'round') || str_contains($profile, 'bar'))) return 'section';
        return 'general';
    }

    /**
     * Look up a material library entry by profile name ('Plate 20mm' → 'Plate').
     * Uses materials.match scoring; returns the best-scoring library item.
     */
    private function lookupMaterialByProfile($profile)
    {
        if (!$profile) return null;
        $profileType = trim(preg_split('/\s+/', $profile)[0]);
        if (!$profileType) return null;

        $materials = new \api\materials();
        $materials->user_id = $this->user_id;
        $matches = $materials->handle_match(['search' => $profileType]);
        if (isset($matches['error']) || !is_array($matches) || count($matches) === 0) return null;

        // Prefer an exact profile match; fall back to the best score.
        foreach ($matches as $m) {
            if (strtolower((string)$m['profile'] ?? '') === strtolower($profileType)) return $m;
        }
        return $matches[0];
    }

    /**
     * Map prefab item attributes to a material component payload.
     * (port of buildMaterialData from prefabs.js)
     */
    private function buildMaterialData($attrs)
    {
        $data = [];
        $category = $this->inferCategory($attrs);
        $data['category'] = $category;

        if (!empty($attrs['profile'])) {
            $data['profile'] = $attrs['profile'];
            $libMatch = $this->lookupMaterialByProfile($attrs['profile']);
            if ($libMatch) {
                $data['materialLibraryId'] = $libMatch['id'];
                if (!empty($libMatch['density'])) $data['density'] = $libMatch['density'];
                if (!empty($libMatch['unit_cost'])) $data['unitCost'] = $libMatch['unit_cost'];
            }
        }

        if ($category === 'plate') {
            foreach (['length', 'width', 'thickness'] as $k) {
                if (isset($attrs[$k]) && $attrs[$k] !== '') $data[$k] = $attrs[$k];
            }
            if (!empty($attrs['length']) && !empty($attrs['width'])) {
                $data['quantity'] = round(((float)$attrs['length'] / 1000) * ((float)$attrs['width'] / 1000), 4);
                $data['unit'] = 'm²';
            }
        } elseif ($category === 'pipe') {
            foreach (['diameter', 'length', 'thickness'] as $k) {
                if (isset($attrs[$k]) && $attrs[$k] !== '') $data[$k] = $attrs[$k];
            }
            $data['unit'] = 'm';
        } elseif ($category === 'section') {
            if (isset($attrs['length']) && $attrs['length'] !== '') $data['length'] = $attrs['length'];
            $data['unit'] = 'm';
        }

        if (isset($attrs['mass']) && $attrs['mass'] !== null) $data['mass'] = $attrs['mass'];
        if (!empty($attrs['density'])) $data['density'] = $attrs['density'];

        return $data;
    }

    private function decodeJson($v)
    {
        if (is_array($v)) return $v;
        $decoded = json_decode((string)$v, true);
        return is_array($decoded) ? $decoded : [];
    }
}

\api\dispatchIfEntry(__FILE__);
