<?php
/**
 * fabricate_forge/api/components.php
 *
 * ECS Component operations.
 *
 * A component is a typed data block attached to an entity:
 *   basic | dimensions | material | cost | process | rate |
 *   specification | notes | status | cadData
 *
 * Components carry their own quote_id (denormalized from the entity) so cost
 * queries can hit them directly without joining through entities — mirrors
 * the original app's CompSystem.
 */
namespace api;

include_once(__DIR__ . "/_base.php");

class components extends Base
{
    /** Component types (mirrors ComponentSchemas registry in the original app). */
    const TYPES = [
        'basic', 'dimensions', 'material', 'cost', 'process', 'rate',
        'specification', 'notes', 'status', 'cadData',
    ];

    protected function buildTable()
    {
        $this->ensureEcsTables();
    }

    /**
     * List components for an entity (optional type filter).
     */
    public function handle_list($input = [])
    {
        $entityId = \getVal($input, 'entity_id');
        if (!$entityId) return ['error' => 'entity_id is required.'];

        if (!$this->getEntity($entityId)) {
            return ['error' => 'Entity not found.', 'error_code' => 404];
        }

        return $this->getComponents($entityId, \getVal($input, 'type'));
    }

    /**
     * Get a single component by id.
     */
    public function handle_get($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'component_id');
        if (!$id) return ['error' => 'Component id is required.'];

        $res = $this->pgCrud->read([
            'table' => 'component',
            'where' => 'id = $1 AND user_id_owner = $2',
            'params' => [$id, $this->user_id],
            'limit' => 1,
        ]);
        $row = $res['data'][0] ?? null;
        if (!$row) return ['error' => 'Component not found.', 'error_code' => 404];
        return $row;
    }

    /**
     * Create a component on an entity.
     * Input: { entity_id, type, data?, quote_id? }
     * quote_id is auto-derived from the entity when not supplied.
     */
    public function handle_create($input = [])
    {
        $entityId = \getVal($input, 'entity_id');
        $type = \getVal($input, 'type');
        if (!$entityId) return ['error' => 'entity_id is required.'];
        if (!$type || !in_array($type, self::TYPES)) {
            return ['error' => 'type is required and must be one of: ' . implode(', ', self::TYPES)];
        }

        $entity = $this->getEntity($entityId);
        if (!$entity) return ['error' => 'Entity not found.', 'error_code' => 404];

        $data = \getVal($input, 'data', []);
        $data = is_array($data) ? $data : [];
        $quoteId = \getVal($input, 'quote_id', $entity['quote_id'] ?? null);

        $res = $this->pgCrud->save([
            'table' => 'component',
            'data' => [
                'entity_id' => $entityId,
                'type' => $type,
                'data' => $data,
                'quote_id' => $quoteId,
                'user_id_owner' => $this->user_id,
            ],
        ]);

        if (!empty($res['error'])) return $res;
        return $this->handle_get(['id' => $res['data']['id'] ?? null]);
    }

    /**
     * Update a component's data blob (JSONB merge — partial payloads don't
     * clobber unrelated keys). Type/entity_id are immutable.
     */
    public function handle_update($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'component_id');
        if (!$id) return ['error' => 'Component id is required.'];

        $existing = $this->handle_get(['id' => $id]);
        if (isset($existing['error'])) return $existing;

        $patch = \getVal($input, 'data', []);
        if (!is_array($patch) || empty($patch)) {
            return ['error' => 'data (object) is required for update.'];
        }
        if (array_key_exists('quote_id', $input)) {
            $patch = ['quote_id' => $input['quote_id']] + $patch;
        }

        $this->patchComponentData($id, $patch);
        return $this->handle_get(['id' => $id]);
    }

    /**
     * Replace the whole data blob (used by importers / calculators that want
     * to write a full component state, not a merge).
     */
    public function handle_replace($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'component_id');
        if (!$id) return ['error' => 'Component id is required.'];
        $data = \getVal($input, 'data', []);
        if (!is_array($data)) return ['error' => 'data must be an object.'];

        $this->pgCrud->execute(
            "UPDATE component SET data = \$2::jsonb, updated_at = NOW()
             WHERE id = \$1 AND user_id_owner = \$3",
            [$id, json_encode($data), $this->user_id]
        );
        return $this->handle_get(['id' => $id]);
    }

    /**
     * Delete a component.
     */
    public function handle_delete($input = [])
    {
        $id = \getVal($input, 'id') ?: \getVal($input, 'component_id');
        if (!$id) return ['error' => 'Component id is required.'];

        $this->pgCrud->execute(
            "DELETE FROM component WHERE id = \$1 AND user_id_owner = \$2",
            [$id, $this->user_id]
        );
        return ['success' => true, 'id' => $id];
    }

    /**
     * Batch: get all components across an entity's whole BOM (children via
     * contains links) in one call. Used by quote material/process tabs.
     */
    public function handle_get_by_quote($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        if (!$quoteId) return ['error' => 'quote_id is required.'];

        $res = $this->pgCrud->read([
            'table' => 'component',
            'where' => 'quote_id = $1 AND user_id_owner = $2',
            'params' => [$quoteId, $this->user_id],
            'order_fields' => ['created_at ASC'],
        ]);
        return $res['data'] ?? [];
    }
}

\api\dispatchIfEntry(__FILE__);
