<?php
/**
 * fabricate_forge/api/boms.php
 *
 * BOM import — turns rows into the ECS graph.
 *
 * A BOM row becomes:
 *   - an entity (type detected: assembly | part | fastener)
 *   - a 'contains' link to its parent (from item-number hierarchy)
 *   - a material component (materialLibraryId resolved via materials.match)
 *   - a quantity on the link
 *
 * This mirrors the original input-bom.js pipeline: detect format → resolve
 * items → link hierarchy → enrich materials.
 */
namespace api;

include_once(__DIR__ . "/_base.php");
include_once(__DIR__ . "/materials.php");
include_once(__DIR__ . "/systems.php");

class boms extends Base
{
    protected function buildTable()
    {
        $this->ensureEcsTables();
    }

    /**
     * Import a BOM from JSON rows.
     * Input: {
     *   quote_id: uuid,
     *   rows: [
     *     { item_number: "1.2", description: "Mounting Plate",
     *       material: "A36", quantity: 4, length?, width?, thickness? }
     *   ]
     * }
     * Returns: { imported: n, entities: [...], skipped: [...] }
     */
    public function handle_import($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        $rows = \getVal($input, 'rows', []);
        if (!$quoteId) return ['error' => 'quote_id is required.'];
        if (!is_array($rows) || empty($rows)) return ['error' => 'rows (array) is required.'];

        $quote = $this->getEntity($quoteId);
        if (!$quote || $quote['type'] !== 'quote') {
            return ['error' => 'Quote not found.', 'error_code' => 404];
        }

        $entities = [];
        $skipped = [];

        foreach ($rows as $row) {
            $itemNumber = \getVal($row, 'item_number', '');
            $description = \getVal($row, 'description', \getVal($row, 'name', 'Unnamed item'));
            $material = \getVal($row, 'material', '');
            $quantity = (float)\getVal($row, 'quantity', 1);

            // Detect entity type from description (mirrors input-bom.js)
            $type = $this->detectEntityType($description);

            // Resolve material library via match scoring
            $materialId = null;
            if ($material) {
                $match = $this->matchMaterial($material);
                if ($match) $materialId = $match;
            }

            // Create entity
            $entityRes = $this->pgCrud->save([
                'table' => 'entity',
                'data' => [
                    'type' => $type,
                    'name' => $description,
                    'description' => $description,
                    'quote_id' => $quoteId,
                    'quantity' => $quantity,
                    'user_id_owner' => $this->user_id,
                ],
            ]);
            if (!empty($entityRes['error'])) {
                $skipped[] = ['item_number' => $itemNumber, 'reason' => $entityRes['error']];
                continue;
            }
            $entityId = $entityRes['data']['id'];

            // Material component (if library item matched)
            if ($materialId) {
                $this->pgCrud->save([
                    'table' => 'component',
                    'data' => [
                        'entity_id' => $entityId,
                        'type' => 'material',
                        'data' => [
                            'materialLibraryId' => $materialId,
                            'category' => $this->detectCategory($description, $material),
                            'length' => \getVal($row, 'length'),
                            'width' => \getVal($row, 'width'),
                            'thickness' => \getVal($row, 'thickness'),
                            'quantity' => $quantity,
                        ],
                        'quote_id' => $quoteId,
                        'user_id_owner' => $this->user_id,
                    ],
                ]);
            }

            $entities[] = ['id' => $entityId, 'item_number' => $itemNumber, 'type' => $type, 'quantity' => $quantity];
        }

        // ── Link hierarchy from item numbers (1, 1.1, 1.1.2) ──
        $this->linkHierarchy($entities, $quoteId);

        return [
            'imported' => count($entities),
            'skipped_count' => count($skipped),
            'entities' => $entities,
            'skipped' => $skipped,
        ];
    }

    /**
     * Recalculate a BOM's total (delegates to systems.load_quote).
     */
    public function handle_calculate($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        if (!$quoteId) return ['error' => 'quote_id is required.'];
        $systems = new \api\systems();
        $systems->user_id = $this->user_id;
        return $systems->handle_load_quote(['quote_id' => $quoteId]);
    }

    // ── Internal ───────────────────────────────────────

    private function detectEntityType($description)
    {
        $d = strtolower($description ?? '');
        if (preg_match('/(header|skid|frame|assembly|unit|tank|vessel)/', $d)) return 'assembly';
        if (preg_match('/(bolt|nut|washer|screw|stud|fastener)/', $d)) return 'fastener';
        return 'part';
    }

    private function detectCategory($description, $material)
    {
        $p = strtolower(($material ?: '') . ' ' . ($description ?: ''));
        if (preg_match('/(plate|sheet)/', $p)) return 'plate';
        if (preg_match('/(angle|channel|i-beam|h-beam|flat bar|section|beam)/', $p)) return 'section';
        if (preg_match('/\bpipe\b/', $p)) return 'pipe';
        if (preg_match('/(chs|shs|rhs|tube)/', $p)) return 'tube';
        if (preg_match('/(elbow|tee|reducer|flange|cap|coupling|fitting)/', $p)) return 'fitting';
        if (preg_match('/(bolt|nut|washer|screw|stud|fastener)/', $p)) return 'fastener';
        return 'other';
    }

    /**
     * Resolve a material description to a library id via match scoring.
     */
    private function matchMaterial($search)
    {
        $materialsApi = new \api\materials();
        $materialsApi->user_id = $this->user_id;
        $res = $materialsApi->handle_match(['search' => $search]);
        if (isset($res['error']) || empty($res)) return null;
        $top = $res[0];
        return ($top['match_score'] ?? 0) >= 0.3 ? $top['id'] : null;
    }

    /**
     * Build contains-links from item-number hierarchy (1 ← 1.1 ← 1.1.2).
     */
    private function linkHierarchy($entities, $quoteId)
    {
        foreach ($entities as $e) {
            $item = $e['item_number'];
            if (!$item) continue;
            $parts = explode('.', (string)$item);
            if (count($parts) <= 1) continue;
            $parentItem = implode('.', array_slice($parts, 0, -1));

            // Find parent by item number
            $parentId = null;
            foreach ($entities as $other) {
                if ($other['item_number'] === $parentItem) { $parentId = $other['id']; break; }
            }
            if (!$parentId) continue;

            $this->pgCrud->save([
                'table' => 'link',
                'data' => [
                    'from_id' => $parentId,
                    'to_id' => $e['id'],
                    'type' => 'contains',
                    'quantity' => $e['quantity'],
                    'user_id_owner' => $this->user_id,
                ],
            ]);
        }
    }
}

\api\dispatchIfEntry(__FILE__);
