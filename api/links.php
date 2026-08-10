<?php
/**
 * fabricate_forge/api/links.php
 *
 * ECS Link operations.
 *
 * A link is a typed relationship between two entities:
 *   contains   — parent contains child (BOM structure)
 *   references — entity references another (drawings, specs)
 *   suppliedBy — entity is supplied by a vendor/part
 *   uses       — entity uses another
 *   dependsOn  — entity depends on another
 *   relatedTo  — generic relationship
 *
 * Links are what make the BOM tree: an assembly entity with 'contains' links
 * to part entities. This endpoint handles link CRUD + tree traversal.
 */
namespace api;

include_once(__DIR__ . "/_base.php");

class links extends Base
{
    /** Link types (mirrors LinkSchema.allowedValues in the original app). */
    const TYPES = ['contains', 'references', 'suppliedBy', 'uses', 'dependsOn', 'relatedTo'];

    protected function buildTable()
    {
        $this->ensureEcsTables();
    }

    /**
     * List links for an entity (outbound by default; both directions available).
     */
    public function handle_list($input = [])
    {
        $entityId = \getVal($input, 'entity_id');
        if (!$entityId) return ['error' => 'entity_id is required.'];

        $type = \getVal($input, 'type');
        $direction = \getVal($input, 'direction', 'out');

        if ($direction === 'both') {
            return $this->getLinks($entityId, $type);
        }

        $links = $this->getLinks($entityId, $type);
        return $direction === 'in' ? $links['in'] : $links['out'];
    }

    /**
     * Create a link between two entities.
     * Input: { from_id, to_id, type, quantity?, data? }
     */
    public function handle_create($input = [])
    {
        $fromId = \getVal($input, 'from_id');
        $toId = \getVal($input, 'to_id');
        $type = \getVal($input, 'type');
        if (!$fromId || !$toId) return ['error' => 'from_id and to_id are required.'];
        if (!$type || !in_array($type, self::TYPES)) {
            return ['error' => 'type is required and must be one of: ' . implode(', ', self::TYPES)];
        }

        // Ownership check on both endpoints
        if (!$this->getEntity($fromId) || !$this->getEntity($toId)) {
            return ['error' => 'One or both entities not found.', 'error_code' => 404];
        }

        // Prevent duplicate links (same from/to/type) unless explicitly allowed
        if (!\getVal($input, 'allow_duplicate', 0)) {
            $dup = $this->pgCrud->read([
                'table' => 'link',
                'where' => 'from_id = $1 AND to_id = $2 AND type = $3 AND user_id_owner = $4',
                'params' => [$fromId, $toId, $type, $this->user_id],
                'limit' => 1,
            ]);
            if (!empty($dup['data'])) {
                return ['error' => 'Link already exists between these entities.', 'error_code' => 409];
            }
        }

        $data = \getVal($input, 'data', []);
        $data = is_array($data) ? $data : [];

        $res = $this->pgCrud->save([
            'table' => 'link',
            'data' => [
                'from_id' => $fromId,
                'to_id' => $toId,
                'type' => $type,
                'quantity' => \getVal($input, 'quantity', 1),
                'data' => $data,
                'user_id_owner' => $this->user_id,
            ],
        ]);

        if (!empty($res['error'])) return $res;
        return $this->pgCrud->read([
            'table' => 'link',
            'where' => 'id = $1 AND user_id_owner = $2',
            'params' => [$res['data']['id'], $this->user_id],
            'limit' => 1,
        ])['data'][0] ?? null;
    }

    /**
     * Update a link (quantity + data merge). from/to/type immutable.
     */
    public function handle_update($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'link_id');
        if (!$id) return ['error' => 'Link id is required.'];

        $sets = [];
        $params = [];
        $idx = 1;
        if (array_key_exists('quantity', $input)) {
            $sets[] = "quantity = \${$idx}";
            $params[] = $input['quantity'];
            $idx++;
        }
        if (isset($input['data']) && is_array($input['data'])) {
            $sets[] = "data = data || \${$idx}::jsonb";
            $params[] = json_encode($input['data']);
            $idx++;
        }
        if (!$sets) return ['error' => 'Nothing to update.'];

        $sets[] = 'updated_at = NOW()';
        $params[] = $id;
        $params[] = $this->user_id;

        $this->pgCrud->execute(
            "UPDATE link SET " . implode(', ', $sets) .
            " WHERE id = \${$idx} AND user_id_owner = \$" . ($idx + 1),
            $params
        );

        return $this->pgCrud->read([
            'table' => 'link',
            'where' => 'id = $1 AND user_id_owner = $2',
            'params' => [$id, $this->user_id],
            'limit' => 1,
        ])['data'][0] ?? null;
    }

    /**
     * Delete a link.
     */
    public function handle_delete($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'link_id');
        if (!$id) return ['error' => 'Link id is required.'];

        $this->pgCrud->execute(
            "DELETE FROM link WHERE id = \$1 AND user_id_owner = \$2",
            [$id, $this->user_id]
        );
        return ['success' => true, 'id' => $id];
    }

    /**
     * BOM tree traversal — recursive contains-chain from a root entity.
     * Returns a nested tree: { id, name, type, quantity, children: [...] }.
     * depth_limit guards against runaway recursion (default 10, max 20).
     */
    public function handle_tree($input = [])
    {
        $rootId = \getVal($input, 'entity_id') ?: \getVal($input, 'root_id');
        if (!$rootId) return ['error' => 'entity_id is required.'];

        $maxDepth = (int)\getVal($input, 'depth', 10);
        $maxDepth = min(max($maxDepth, 1), 20);

        $root = $this->getEntity($rootId);
        if (!$root) return ['error' => 'Entity not found.', 'error_code' => 404];

        $tree = [
            'id' => $root['id'],
            'name' => $root['name'],
            'type' => $root['type'],
            'quantity' => $root['quantity'] ?? 1,
        ];
        $tree['children'] = $this->buildTree($rootId, 0, $maxDepth, []);

        return $tree;
    }

    /**
     * Detect cycles in the contains graph (guards tree traversal + BOM edits).
     * Returns the cycle path if found, or success.
     */
    public function handle_validate_cycle($input = [])
    {
        $entityId = \getVal($input, 'entity_id');
        if (!$entityId) return ['error' => 'entity_id is required.'];

        $path = $this->findCycle($entityId, [], []);
        if ($path) {
            return ['error' => 'Cycle detected: ' . implode(' → ', $path), 'cycle' => $path, 'error_code' => 409];
        }
        return ['success' => true, 'no_cycle' => true];
    }

    // ── Internal helpers ────────────────────────────────

    private function buildTree($entityId, $depth, $maxDepth, $visited)
    {
        if ($depth >= $maxDepth || in_array($entityId, $visited)) {
            return [];
        }
        $visited[] = $entityId;

        $links = $this->getLinks($entityId, 'contains');
        $children = [];
        foreach ($links['out'] as $link) {
            $child = $this->getEntity($link['to_id']);
            if (!$child) continue;
            $children[] = [
                'id' => $child['id'],
                'link_id' => $link['id'],
                'name' => $child['name'],
                'type' => $child['type'],
                'quantity' => $link['quantity'] ?? 1,
                'children' => $this->buildTree($link['to_id'], $depth + 1, $maxDepth, $visited),
            ];
        }
        return $children;
    }

    private function findCycle($entityId, $visited, $path)
    {
        if (in_array($entityId, $visited)) {
            return array_merge($path, [$entityId]);
        }
        $visited[] = $entityId;
        $path[] = $this->getEntity($entityId)['name'] ?? $entityId;

        $links = $this->getLinks($entityId, 'contains');
        foreach ($links['out'] as $link) {
            $result = $this->findCycle($link['to_id'], $visited, $path);
            if ($result) return $result;
        }
        return null;
    }
}

\api\dispatchIfEntry(__FILE__);
