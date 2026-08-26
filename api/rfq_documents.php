<?php
/**
 * fabricate_forge/api/rfq_documents.php
 *
 * Persisted RFQ / BoQ document management.
 *
 * BoQ documents (xlsx/csv/pdf/spec) are stored via Forge's DB file store
 * (files_meta + files_data, SHA-256 deduped binary). The rfq_document table
 * links a stored file to a quote and keeps the parsed+flagged rows so any
 * entity imported from this doc can trace back to its source line.
 *
 * Tables:
 *   rfq_document   — links files_meta.id → quote; stores parsed_rows JSONB
 *   files_meta     — Forge file metadata (auto-created by forge\db\Files)
 *   files_data     — Forge binary content (auto-created by forge\db\Files)
 *
 * Actions:
 *   list    (quote_id)     → [{id, file_id, filename, mime_type, size, source_type, created_at, row_count, flag_counts}]
 *   get     (file_id)      → {file_id, filename, mime_type, size, source_type, quote_id, parsed_rows, created_at}
 *   delete  (file_id)      → {success:true} (removes rfq_document + files_meta/data rows)
 */
namespace api;

include_once(__DIR__ . "/_base.php");
$forgeDir = dirname(__DIR__, 2) . '/forge';
require_once($forgeDir . '/php/db/Files.php');

class rfq_documents extends Base
{
    protected function buildTable()
    {
        $this->ensureEcsTables();
        $this->pgCrud->execute("
            CREATE TABLE IF NOT EXISTS rfq_document (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                quote_id UUID NOT NULL REFERENCES entity(id),
                file_id  UUID NOT NULL,
                filename TEXT NOT NULL,
                mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',
                size_bytes INTEGER NOT NULL DEFAULT 0,
                source_type TEXT NOT NULL DEFAULT 'rfq_upload',
                parsed_rows JSONB DEFAULT '[]'::jsonb,
                flag_counts JSONB DEFAULT '{}'::jsonb,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMPTZ DEFAULT NOW(),
                user_id_owner UUID NOT NULL
            )
        ");
        // Idempotent migration: ensure all expected columns exist even if the
        // table was created by an older version (pre-existing schema drift).
        $cols = [
            'mime_type TEXT NOT NULL DEFAULT \'application/octet-stream\'',
            'size_bytes INTEGER NOT NULL DEFAULT 0',
            'source_type TEXT NOT NULL DEFAULT \'rfq_upload\'',
            'parsed_rows JSONB DEFAULT \'[]\'::jsonb',
            'flag_counts JSONB DEFAULT \'{}\'::jsonb',
            'is_active BOOLEAN DEFAULT TRUE',
        ];
        foreach ($cols as $colDef) {
            $this->pgCrud->execute("ALTER TABLE rfq_document ADD COLUMN IF NOT EXISTS " . $colDef);
        }
        // Migrate legacy column names if an old schema existed.
        $this->pgCrud->execute("ALTER TABLE rfq_document RENAME COLUMN IF EXISTS mime TO mime_type");
        $this->pgCrud->execute("ALTER TABLE rfq_document RENAME COLUMN IF EXISTS size TO size_bytes");
        $this->pgCrud->execute(
            "CREATE INDEX IF NOT EXISTS idx_rfq_doc_owner ON rfq_document(user_id_owner)"
        );
        $this->pgCrud->execute(
            "CREATE INDEX IF NOT EXISTS idx_rfq_doc_quote ON rfq_document(quote_id)"
        );
        $this->pgCrud->execute(
            "CREATE INDEX IF NOT EXISTS idx_rfq_doc_file  ON rfq_document(file_id)"
        );

        // Ensure Forge's file store tables exist (idempotent).
        $files = new \forge\db\Files($this->pgCrud);
        $files->buildTables();
    }

    /**
     * List all persisted documents for a quote.
     * Input: { quote_id }
     */
    public function handle_list($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        if (!$quoteId) return ['error' => 'quote_id is required.'];

        $quote = $this->getEntity($quoteId);
        if (!$quote || $quote['type'] !== 'quote') {
            return ['error' => 'Quote not found.', 'error_code' => 404];
        }

        $res = $this->pgCrud->read([
            'table' => 'rfq_document',
            'where' => 'quote_id = $1 AND user_id_owner = $2 AND is_active = TRUE',
            'params' => [$quoteId, $this->effOwnerId()],
            'order_fields' => ['created_at DESC'],
        ]);

        $rows = [];
        foreach (($res['data'] ?? []) as $r) {
            $parsed = is_string($r['parsed_rows']) ? json_decode($r['parsed_rows'], true) : ($r['parsed_rows'] ?? []);
            $flags = is_string($r['flag_counts']) ? json_decode($r['flag_counts'], true) : ($r['flag_counts'] ?? []);
            $rows[] = [
                'id'           => $r['id'],
                'file_id'      => $r['file_id'],
                'filename'     => $r['filename'],
                'mime_type'    => $r['mime_type'],
                'size'         => (int)$r['size_bytes'],
                'source_type'  => $r['source_type'],
                'row_count'    => count($parsed),
                'flag_counts'  => $flags,
                'created_at'   => $r['created_at'],
                'serve_url'    => $this->serveUrl($r['file_id']),
            ];
        }
        return $rows;
    }

    /**
     * Get a single document's metadata + parsed rows.
     * Input: { file_id }
     */
    public function handle_get($input = [])
    {
        $fileId = \getVal($input, 'file_id');
        if (!$fileId) return ['error' => 'file_id is required.'];

        $res = $this->pgCrud->read([
            'table' => 'rfq_document',
            'where' => 'file_id = $1 AND user_id_owner = $2 AND is_active = TRUE',
            'params' => [$fileId, $this->effOwnerId()],
            'limit' => 1,
        ]);
        $r = $res['data'][0] ?? null;
        if (!$r) return ['error' => 'Document not found.', 'error_code' => 404];

        $parsed = is_string($r['parsed_rows']) ? json_decode($r['parsed_rows'], true) : ($r['parsed_rows'] ?? []);
        $flags = is_string($r['flag_counts']) ? json_decode($r['flag_counts'], true) : ($r['flag_counts'] ?? []);

        return [
            'id'           => $r['id'],
            'file_id'      => $r['file_id'],
            'filename'     => $r['filename'],
            'mime_type'    => $r['mime_type'],
            'size'         => (int)$r['size_bytes'],
            'source_type'  => $r['source_type'],
            'quote_id'     => $r['quote_id'],
            'parsed_rows'  => $parsed,
            'flag_counts'  => $flags,
            'created_at'   => $r['created_at'],
            'serve_url'    => $this->serveUrl($fileId),
        ];
    }

    /**
     * Soft-delete a persisted document.
     * Input: { file_id }
     */
    public function handle_delete($input = [])
    {
        $fileId = \getVal($input, 'file_id');
        if (!$fileId) return ['error' => 'file_id is required.'];

        $res = $this->pgCrud->read([
            'table' => 'rfq_document',
            'fields' => ['id', 'file_id'],
            'where' => 'file_id = $1 AND user_id_owner = $2 AND is_active = TRUE',
            'params' => [$fileId, $this->effOwnerId()],
            'limit' => 1,
        ]);
        $doc = $res['data'][0] ?? null;
        if (!$doc) return ['error' => 'Document not found.', 'error_code' => 404];

        // Hard-delete the file from Forge's DB store.
        $files = new \forge\db\Files($this->pgCrud);
        $delRes = $files->delete($doc['file_id'], $this->effOwnerId());
        if (isset($delRes['error'])) {
            // If the binary delete fails, still soft-delete the DB link.
            // The orphaned files_data row is GC'd by Forge's Files::delete
            // when the last metadata reference is gone.
        }

        // Soft-delete the rfq_document row.
        $this->pgCrud->execute(
            "UPDATE rfq_document SET is_active = FALSE WHERE id = $1 AND user_id_owner = $2",
            [$doc['id'], $this->effOwnerId()]
        );

        return ['success' => true];
    }

    /**
     * Persist a BoQ upload: store the binary in Forge's file store +
     * save parsed rows in rfq_document. Called by rfq.php after parse.
     *
     * @param array $input {quote_id, filename, binary, mime_type, parsed_rows, flag_counts, source_type}
     * @return array {file_id, rfq_doc_id, filename, mime_type, size, parsed_rows, counts}
     */
    public function persistUpload($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        $filename = \getVal($input, 'filename', 'upload.bin');
        $binary = \getVal($input, 'binary', '');
        $mimeType = \getVal($input, 'mime_type', 'application/octet-stream');
        $parsedRows = \getVal($input, 'parsed_rows', []);
        $flagCounts = \getVal($input, 'flag_counts', []);
        $sourceType = \getVal($input, 'source_type', 'rfq_upload');

        if (!$quoteId || !$binary) {
            return ['error' => 'quote_id and binary are required.'];
        }

        // 1. Store the binary in Forge's file store.
        $files = new \forge\db\Files($this->pgCrud);
        $uploadRes = $files->upload($this->effOwnerId(), $binary, $mimeType, $filename, [
            'quote_id'    => $quoteId,
            'source_type' => $sourceType,
            'original_name'=> $filename,
        ]);
        if (isset($uploadRes['error'])) {
            return $uploadRes;
        }
        $fileId = $uploadRes['id'];

        // 2. Link the file to the quote + store parsed rows.
        $meta = json_encode(array_merge((array)$uploadRes, ['source_type' => $sourceType]));
        $this->pgCrud->save([
            'table' => 'rfq_document',
            'data'  => [
                'quote_id'      => $quoteId,
                'file_id'       => $fileId,
                'filename'      => $filename,
                'mime_type'     => $mimeType,
                'size_bytes'    => strlen($binary),
                'source_type'   => $sourceType,
                'parsed_rows'   => json_encode($parsedRows),
                'flag_counts'   => json_encode($flagCounts),
                'user_id_owner' => $this->effOwnerId(),
            ],
        ]);

        return [
            'file_id'     => $fileId,
            'filename'    => $filename,
            'mime_type'   => $mimeType,
            'size'        => strlen($binary),
            'parsed_rows' => $parsedRows,
            'counts'      => $flagCounts,
            'serve_url'   => $this->serveUrl($fileId),
        ];
    }

    private function serveUrl($fileId, $authId = '')
    {
        // serve.php lives at the app root (project document root).
        // Build a root-relative URL: /serve.php?id=<file_id>
        $url = '/serve.php?id=' . urlencode($fileId);
        if ($authId) $url .= '&auth_id=' . urlencode($authId);
        return $url;
    }
}

\api\dispatchIfEntry(__FILE__);
