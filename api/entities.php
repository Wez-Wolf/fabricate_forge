<?php
/**
 * fabricate_forge/api/entities.php
 *
 * ECS Entity operations.
 *
 * An entity is a typed container: part | assembly | fastener | quote.
 * Entities own components (via entity_id) and participate in links (from_id
 * / to_id). This endpoint handles the container CRUD plus the enriched
 * "get with components & links" read used by the quote/tree UI.
 */
namespace api;

include_once(__DIR__ . "/_base.php");

class entities extends Base
{
    /** Entity types (mirrors EntitySchema.allowedValues in the original app). */
    const TYPES = ['part', 'assembly', 'fastener', 'fitting', 'quote'];

    /**
     * Ensure the ECS core tables exist.
     */
    protected function buildTable()
    {
        $this->ensureEcsTables();
    }

    /**
     * List entities, scoped to the current user.
     * Filters: type, quote_id, search (name/description), limit.
     */
    public function handle_list($input = [])
    {
        $type = \getVal($input, 'type');
        $quoteId = \getVal($input, 'quote_id');
        $search = \getVal($input, 'search');
        $limit = (int)\getVal($input, 'limit', 50);
        // Raised ceiling: quote shells load ALL member rows (the tree walks
        // every node; a 200 cap silently zero-costs anything beyond it).
        $limit = min(max($limit, 1), 2000);

        $where = 'user_id_owner = $1 AND is_active = TRUE';
        $params = [$this->effOwnerId()];
        $idx = 2;

        if ($type) {
            if (!in_array($type, self::TYPES)) {
                return ['error' => "Invalid entity type: $type"];
            }
            $where .= " AND type = \${$idx}";
            $params[] = $type;
            $idx++;
        }
        if ($quoteId) {
            $where .= " AND quote_id = \${$idx}";
            $params[] = $quoteId;
            $idx++;
        }
        if ($search) {
            $where .= " AND (name ILIKE \${$idx} OR COALESCE(description,'') ILIKE \${$idx})";
            $params[] = "%{$search}%";
            $idx++;
        }

        $res = $this->pgCrud->read([
            'table' => 'entity',
            'where' => $where,
            'params' => $params,
            'order_fields' => ['created_at DESC'],
            'limit' => $limit,
        ]);

        $rows = $res['data'] ?? [];
        // Decode data blobs are handled by PgCrud; add component_count convenience
        foreach ($rows as &$row) {
            $row['component_count'] = $this->countComponents($row['id']);
        }
        unset($row);
        $this->attachParentInfo($rows, $quoteId);
        return $rows;
    }

    /**
     * Attach each entity's containment parent WITHIN this quote (from the
     * contains links) so flat lists can render "belongs to" + tree hybrids:
     *   parent_id, parent_name, parent_qty, top_level (no parent in this quote)
     */
    private function attachParentInfo(&$rows, $quoteId)
    {
        if (!$rows || !$quoteId) return;
        $ids = array_column($rows, 'id');
        $linkRes = $this->pgCrud->read([
            'table' => 'link',
            'where' => 'type = $1 AND user_id_owner = $2 AND to_id = ANY($3::uuid[])',
            'params' => ['contains', $this->effOwnerId(), '{' . implode(',', $ids) . '}'],
        ]);
        $parentIds = [];
        foreach (($linkRes['data'] ?? []) as $l) {
            $parentIds[$l['to_id']] = [
                'id' => $l['from_id'],
                'qty' => (float)($l['quantity'] ?? 1),
                'link_id' => $l['id'],
            ];
        }
        if (!$parentIds) {
            foreach ($rows as &$r) $r['top_level'] = true;
            return;
        }
        // Resolve parent names (parents may be outside the list — e.g. the quote root)
        $pIds = array_column($parentIds, 'id');
        $pRes = $this->pgCrud->read([
            'table' => 'entity',
            'fields' => ['id', 'name', 'type'],
            'where' => 'id = ANY($1::uuid[])',
            'params' => ['{' . implode(',', $pIds) . '}'],
        ]);
        $names = [];
        foreach (($pRes['data'] ?? []) as $p) $names[$p['id']] = $p['name'];
        foreach ($rows as &$r) {
            $p = $parentIds[$r['id']] ?? null;
            if ($p && isset($names[$p['id']])) {
                $r['parent_id'] = $p['id'];
                $r['parent_name'] = $names[$p['id']];
                $r['parent_qty'] = $p['qty'];
                $r['link_id'] = $p['link_id'];   // contains-link id → update its quantity
                $r['top_level'] = false;
            } else {
                $r['top_level'] = true;
            }
        }
    }

    /**
     * Get a single entity by id (owner-scoped).
     */
    public function handle_get($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'entity_id');
        if (!$id) return ['error' => 'Entity id is required.'];

        $entity = $this->getEntity($id);
        if (!$entity) return ['error' => 'Entity not found.', 'error_code' => 404];

        $includeComponents = \getVal($input, 'include_components', 0);
        $includeLinks = \getVal($input, 'include_links', 0);

        if ($includeComponents) {
            $entity['components'] = $this->getComponents($id);
        }
        if ($includeLinks) {
            $entity['links'] = $this->getLinks($id);
        }
        return $entity;
    }

    /**
     * Full read: entity + components + links in one call.
     * This is the ECS "getWithComponents" equivalent — what the quote detail
     * and tree tabs use instead of N round-trips.
     */
    public function handle_get_full($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'entity_id');
        if (!$id) return ['error' => 'Entity id is required.'];

        $entity = $this->getEntity($id);
        if (!$entity) return ['error' => 'Entity not found.', 'error_code' => 404];

        $entity['components'] = $this->getComponents($id);
        $entity['links'] = $this->getLinks($id);

        // BOM children (contains links) with their own component summaries —
        // used by the tree tab for a one-shot hierarchy load.
        $children = $this->getLinks($id, 'contains');
        $entity['children'] = [];
        foreach ($children['out'] as $link) {
            $child = $this->getEntity($link['to_id']);
            if (!$child) continue;
            $child['link_id'] = $link['id'];
            $child['link_quantity'] = $link['quantity'];
            $child['components'] = $this->getComponents($link['to_id']);
            $entity['children'][] = $child;
        }

        return $entity;
    }

    /**
     * Create an entity.
     * Input: { type, name, description?, quote_id?, quantity?, data? }
     */
    public function handle_create($input = [])
    {
        $type = \getVal($input, 'type');
        $name = \getVal($input, 'name');
        if (!$type || !in_array($type, self::TYPES)) {
            return ['error' => 'type is required and must be one of: ' . implode(', ', self::TYPES)];
        }
        if (!$name) return ['error' => 'name is required.'];

        $data = \getVal($input, 'data', []);
        $data = is_array($data) ? $data : [];
        // Empty data must store as JSON object {} — an empty PHP array would
        // json_encode to [] and poison every later `data || …` merge.
        // NOTE: pass the JSON string (PgCrud json_encodes arrays only; a
        // stdClass value crashes pg_query_params). '{}' is valid JSON for JSONB.
        if (empty($data)) $data = '{}';

        $res = $this->pgCrud->save([
            'table' => 'entity',
            'data' => [
                'type' => $type,
                'name' => $name,
                'description' => \getVal($input, 'description', ''),
                'quote_id' => \getVal($input, 'quote_id'),
                // D5: entities are SINGULAR — incoming quantity is ignored;
                // quantity belongs on the contains-link.
                'quantity' => 1,
                'data' => $data,
                'user_id_owner' => $this->effOwnerId(),
            ],
        ]);

        if (!empty($res['error'])) return $res;

        // G8 / structural-truth rule: membership is a contains-LINK from the
        // quote root, not just the quote_id column. The edge carries the
        // quantity (D5 — entity itself is singular).
        // Callers that attach the entity elsewhere (e.g. import decomposition
        // putting parts under an assembly) pass root_link => false.
        $quoteId = \getVal($input, 'quote_id');
        if ($quoteId && !\getVal($input, 'root_link', true)) {
            // skip — caller manages structure itself
        } elseif ($quoteId) {
            $this->ensureQuoteRootLink($quoteId, $res['data']['id'], (float)\getVal($input, 'link_quantity', 1));
        }

        return $this->getEntity($res['data']['id'] ?? null);
    }

    /**
     * Ensure exactly one contains-link quote_root → entity (qty 1 default).
     * Idempotent — safe to call on every create.
     */
    private function ensureQuoteRootLink($quoteId, $entityId, $qty = 1)
    {
        if (!$quoteId || !$entityId) return;
        $existing = $this->pgCrud->read([
            'table' => 'link',
            'where' => 'from_id = $1 AND to_id = $2 AND type = $3 AND user_id_owner = $4',
            'params' => [$quoteId, $entityId, 'contains', $this->effOwnerId()],
            'limit' => 1,
        ]);
        if (!empty($existing['data'])) {
            // Link exists but qty may differ — keep the caller's truth.
            $linkId = $existing['data'][0]['id'];
            if ((float)$existing['data'][0]['quantity'] !== (float)$qty) {
                $this->pgCrud->save(['table' => 'link', 'data' => [
                    'id' => $linkId, 'quantity' => $qty, 'user_id_owner' => $this->effOwnerId(),
                ]]);
            }
            return;
        }
        $this->pgCrud->save([
            'table' => 'link',
            'data' => [
                'from_id' => $quoteId,
                'to_id' => $entityId,
                'type' => 'contains',
                'quantity' => $qty,
                'user_id_owner' => $this->effOwnerId(),
            ],
        ]);
    }

    /**
     * Update an entity. Partial payloads allowed — only provided top-level
     * fields change; data (JSONB) is merged, not replaced.
     */
    public function handle_update($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'entity_id');
        if (!$id) return ['error' => 'Entity id is required.'];

        $current = $this->getEntity($id);
        if (!$current) {
            return ['error' => 'Entity not found.', 'error_code' => 404];
        }

        // Top-level columns we accept on update
        $sets = [];
        $params = [];
        $idx = 1;
        foreach (['type', 'name', 'description', 'quote_id', 'quantity'] as $col) {
            if (array_key_exists($col, $input)) {
                $sets[] = "$col = \${$idx}";
                $params[] = $input[$col];
                $idx++;
            }
        }
        // JSONB merge for the data blob — but if the stored data is a JSON
        // array (legacy rows created with empty data → '[]'), replace instead
        // of concatenating: '[]' || '{...}' yields a list, not a merge.
        if (isset($input['data']) && is_array($input['data'])) {
            $curData = $current['data'] ?? [];
            $isList = is_array($curData) && array_keys($curData) === range(0, count($curData) - 1);
            if ($isList || empty($curData)) {
                $sets[] = "data = \${$idx}::jsonb";
                $params[] = json_encode($input['data']);
            } else {
                $sets[] = "data = data || \${$idx}::jsonb";
                $params[] = json_encode($input['data']);
            }
            $idx++;
        }
        $sets[] = 'updated_at = NOW()';
        $params[] = $id;
        $params[] = $this->effOwnerId();

        if (count($sets) === 1) {
            return ['error' => 'Nothing to update.'];
        }

        $this->pgCrud->execute(
            "UPDATE entity SET " . implode(', ', $sets) .
            " WHERE id = \${$idx} AND user_id_owner = \$" . ($idx + 1),
            $params
        );

        // Watcher: entity-level changes (quantity, margin, type) affect cost
        $this->recalculateUpward($id);

        return $this->getEntity($id);
    }

    /**
     * Soft-delete an entity (is_active = FALSE). Components and links are
     * left in place for audit; they can be restored by setting is_active.
     */
    public function handle_delete($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'entity_id');
        if (!$id) return ['error' => 'Entity id is required.'];

        $res = $this->pgCrud->execute(
            "UPDATE entity SET is_active = FALSE, updated_at = NOW()
             WHERE id = \$1 AND user_id_owner = \$2",
            [$id, $this->effOwnerId()]
        );
        return ['success' => true, 'id' => $id];
    }

    /**
     * Search entities by name/description (shorthand for list with search).
     */
    public function handle_search($input = [])
    {
        $input['search'] = \getVal($input, 'query', \getVal($input, 'q'));
        return $this->handle_list($input);
    }

    // ── Internal helpers ────────────────────────────────

    private function countComponents($entityId)
    {
        $res = $this->pgCrud->read([
            'sql' => "SELECT COUNT(*)::int AS count FROM component WHERE entity_id = \$1 AND user_id_owner = \$2",
            'params' => [$entityId, $this->effOwnerId()],
        ]);
        return (int)($res['data'][0]['count'] ?? 0);
    }
}

\api\dispatchIfEntry(__FILE__);
