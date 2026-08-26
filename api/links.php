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
                'params' => [$fromId, $toId, $type, $this->effOwnerId()],
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
                'user_id_owner' => $this->effOwnerId(),
            ],
        ]);

        if (!empty($res['error'])) return $res;
        $link = $this->pgCrud->read([
            'table' => 'link',
            'where' => 'id = $1 AND user_id_owner = $2',
            'params' => [$res['data']['id'], $this->effOwnerId()],
            'limit' => 1,
        ])['data'][0] ?? null;

        // Watcher: contains links change rollup structure → recalc parent upward
        if ($type === 'contains' && $fromId) {
            $this->recalculateUpward($fromId);
        }
        return $link;
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
        $params[] = $this->effOwnerId();

        $this->pgCrud->execute(
            "UPDATE link SET " . implode(', ', $sets) .
            " WHERE id = \${$idx} AND user_id_owner = \$" . ($idx + 1),
            $params
        );

        $link = $this->pgCrud->read([
            'table' => 'link',
            'where' => 'id = $1 AND user_id_owner = $2',
            'params' => [$id, $this->effOwnerId()],
            'limit' => 1,
        ])['data'][0] ?? null;

        // Watcher: contains link quantity change → recalc parent upward
        if (($link['type'] ?? null) === 'contains' && ($link['from_id'] ?? null)) {
            $this->recalculateUpward($link['from_id']);
        }
        return $link;
    }

    /**
     * Delete a link.
     */
    public function handle_delete($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'link_id');
        if (!$id) return ['error' => 'Link id is required.'];

        // Capture link before delete (for watcher)
        $before = $this->pgCrud->read([
            'table' => 'link',
            'where' => 'id = $1 AND user_id_owner = $2',
            'params' => [$id, $this->effOwnerId()],
            'limit' => 1,
        ])['data'][0] ?? null;

        $this->pgCrud->execute(
            "DELETE FROM link WHERE id = \$1 AND user_id_owner = \$2",
            [$id, $this->effOwnerId()]
        );

        // Watcher: contains link removal → recalc parent upward
        if (($before['type'] ?? null) === 'contains' && ($before['from_id'] ?? null)) {
            $this->recalculateUpward($before['from_id']);
        }
        return ['success' => true, 'id' => $id];
    }

    /**
     * BOM tree traversal — recursive contains-chain from a root entity.
     * Returns a nested tree: { id, name, type, quantity, children: [...] }.
     * depth_limit guards against runaway recursion (default 10, max 20).
     * @deprecated Use handle_tree_batched instead (2 queries vs N+1).
     */
    public function handle_tree($input = [])
    {
        // Backward-compat shim — delegates to the batched implementation.
        return $this->handle_tree_batched($input);
    }

    /**
     * BOM tree traversal — batched (2 queries total). Loads the whole quote's
     * entities (by quote_id) + all its 'contains' links in one round each,
     * then assembles the nested tree in PHP. ECS-consistent: structure comes
     * from links, and no entity is special-cased by type.
     *
     * Input: { entity_id, depth? }. Same shape as the legacy tree endpoint,
     * plus each node carries link_id and link_quantity so per-parent quantities
     * surface.
     */
    public function handle_tree_batched($input = []) {
        $rootId = \getVal($input, 'entity_id') ?: \getVal($input, 'root_id');
        if (!$rootId) return ['error' => 'entity_id is required.'];

        $maxDepth = (int)\getVal($input, 'depth', 10);
        $maxDepth = min(max($maxDepth, 1), 20);

        $root = $this->getEntity($rootId);
        if (!$root) return ['error' => 'Entity not found.', 'error_code' => 404];

        $owner = $this->effOwnerId();
        $quoteId = $root['quote_id'] ?? $rootId;

        // ── Scope: quote-based (normal) or orphan/standalone (no quote_id) ──
        // Quoted entities: one query gets the whole quote's entities.
        // Orphan entities (quote_id IS NULL): iterative BFS so we only ever
        // fetch the frontier's children — avoids the 1900-uuid array that
        // breaks pg_query_params with "Unexpected } character".
        $byId = [$root['id'] => $root];
        if ($quoteId) {
            $entRes = $this->pgCrud->read([
                'table' => 'entity',
                'where' => 'quote_id = $1 AND user_id_owner = $2 AND is_active = TRUE',
                'params' => [$quoteId, $owner],
            ]);
            foreach (($entRes['data'] ?? []) as $e) $byId[$e['id']] = $e;
        }

        // child→parent adjacency, built incrementally.
        $parents = [];
        $frontier = [$rootId];
        $bfsVisited = [$rootId => true];

        for ($depth = 0; $depth < $maxDepth && $frontier; $depth++) {
            // Batch-fetch links FROM this frontier in one query.
            // PostgreSQL array literals need double-quoted elements when
            // values contain hyphens (UUIDs).
            $uuidParam = '{' . implode(',', array_map(fn($id) => '"' . $id . '"', $frontier)) . '}';
            $linkRes = $this->pgCrud->read([
                'table' => 'link',
                'where' => 'from_id = ANY($1::uuid[]) AND type = $2 AND user_id_owner = $3',
                'params' => [$uuidParam, 'contains', $owner],
            ]);
            $links = $linkRes['data'] ?? [];

            // Collect child IDs that we haven't resolved yet.
            $nextFrontier = [];
            foreach ($links as $l) {
                $cId = $l['to_id'];
                if (isset($bfsVisited[$cId])) continue;
                $bfsVisited[$cId] = true;
                $parents[$l['from_id']][] = [$cId, (float)($l['quantity'] ?? 1), $l['id']];
                $nextFrontier[] = $cId;
            }
            if (!$nextFrontier) break;

            // Fetch child entities in one query (only the frontier size).
            $childUuidParam = '{' . implode(',', array_map(fn($id) => '"' . $id . '"', $nextFrontier)) . '}';
            $entRes = $this->pgCrud->read([
                'table' => 'entity',
                'where' => 'id = ANY($1::uuid[]) AND user_id_owner = $2 AND is_active = TRUE',
                'params' => [$childUuidParam, $owner],
            ]);
            foreach (($entRes['data'] ?? []) as $e) $byId[$e['id']] = $e;

            $frontier = $nextFrontier;
        }

        // In-memory DFS build from the fully-populated byId. Cycle-guarded.
        $visited = [];
        $build = function ($id, $depth) use (&$build, &$visited, $byId, $parents, $maxDepth) {
            if ($depth >= $maxDepth || isset($visited[$id])) return [];
            $visited[$id] = true;
            $out = [];
            foreach (($parents[$id] ?? []) as [$cId, $linkQty, $linkId]) {
                $e = $byId[$cId] ?? null;
                if (!$e || isset($visited[$e['id']])) continue;
                $out[] = [
                    'id' => $e['id'],
                    'link_id' => $linkId,
                    'link_quantity' => $linkQty,
                    'name' => $e['name'],
                    'description' => $e['description'] ?? '',
                    'type' => $e['type'],
                    'quantity' => $e['quantity'] ?? 1,
                    'children' => $build($e['id'], $depth + 1),
                ];
            }
            return $out;
        };

        return [
            'id' => $root['id'],
            'name' => $root['name'],
            'type' => $root['type'],
            'quantity' => $root['quantity'] ?? 1,
            'children' => $build($rootId, 0),
        ];
    }

    /**
     * Frontier children of one entity (no recursion) — the reusable primitive
     * for per-parent editing (Entities tab). Returns each child WITH its
     * link_id and link_quantity (the per-parent structural qty), so a shared
     * entity's different quantities across assemblies are visible/editable.
     * Input: { entity_id }. Output: { children: [...] }.
     */
    public function handle_children_of($input = []) {
        $id = \getVal($input, 'entity_id');
        if (!$id) return ['error' => 'entity_id is required.'];
        $owner = $this->effOwnerId();

        $linkRes = $this->pgCrud->read([
            'table' => 'link',
            'where' => 'from_id = $1 AND type = $2 AND user_id_owner = $3',
            'params' => [$id, 'contains', $owner],
        ]);
        $links = $linkRes['data'] ?? [];
        if (!$links) return ['children' => []];

        // Batch the child entities in one query.
        $cids = array_column($links, 'to_id');
        $entRes = $this->pgCrud->read([
            'table' => 'entity',
            'where' => 'id = ANY($1::uuid[]) AND user_id_owner = $2 AND is_active = TRUE',
            'params' => ['{' . implode(',', $cids) . '}', $owner],
        ]);
        $byId = [];
        foreach (($entRes['data'] ?? []) as $e) $byId[$e['id']] = $e;

        $children = [];
        foreach ($links as $l) {
            $e = $byId[$l['to_id']] ?? null;
            if (!$e) continue;
            $children[] = [
                'id' => $e['id'],
                'link_id' => $l['id'],
                'link_quantity' => (float)($l['quantity'] ?? 1),
                'name' => $e['name'],
                'type' => $e['type'],
                'quantity' => $e['quantity'] ?? 1,
            ];
        }
        return ['children' => $children];
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

    /**
     * Detect cycles in the contains graph (guards tree traversal + BOM edits).
     * Returns the cycle path if found, or null if no cycle.
     */
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
