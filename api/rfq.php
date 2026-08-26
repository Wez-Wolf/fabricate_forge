<?php
/**
 * fabricate_forge/api/rfq.php
 *
 * RFQ intake — upload a client BoQ, parse it into reviewable rows, import.
 *
 * Uploaded documents are PERSISTED in the Forge DB file store (files_meta +
 * files_data) and linked to the quote via rfq_document. Every entity imported
 * from a document carries boq_source_file provenance in its data JSONB so
 * the tree/entities/takeoff views can trace it back to the source row.
 *
 * Actions:
 *   upload  (quote_id, filename, file_base64) → {file_id, filename, rows, counts, serve_url}
 *           (base64 → DB file store → parse → return rows + file_id)
 *   import  (quote_id, file_id, rows) → rows → quote entities (per type, with lineage)
 */
namespace api;

include_once(__DIR__ . "/_base.php");
include_once(__DIR__ . "/import.php");
include_once(__DIR__ . "/entities.php");
include_once(__DIR__ . "/components.php");
include_once(__DIR__ . "/links.php");
include_once(__DIR__ . "/systems.php");
include_once(__DIR__ . "/cost.php");
include_once(__DIR__ . "/rfq_documents.php");
include_once(__DIR__ . "/materials.php");

class rfq extends Base
{
    /**
     * Upload + parse in one call — the document is persisted to the Forge DB
     * file store and the parsed rows are returned for review in the grid.
     * Input: { quote_id, filename, file_base64 }
     * Returns: { file_id, filename, mime_type, size, rows, counts, serve_url }
     */
    public function handle_upload($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        if (!$quoteId) return ['error' => 'quote_id is required.'];

        $quote = $this->getEntity($quoteId);
        if (!$quote || $quote['type'] !== 'quote') {
            return ['error' => 'Quote not found.', 'error_code' => 404];
        }

        $b64 = \getVal($input, 'file_base64');
        $filename = \getVal($input, 'filename', 'upload.bin');
        if (!$b64) return ['error' => 'file_base64 is required.'];

        // data URI: "data:application/vnd...;base64,..." or raw base64
        if (preg_match('/^data:([a-zA-Z0-9\/.+-]+);base64,(.*)$/s', $b64, $m)) {
            $mimeType = $m[1];
            $binary = base64_decode($m[2], true);
        } else {
            $mimeType = $this->mimeTypeFor($filename);
            $binary = base64_decode($b64, true);
        }
        if ($binary === false || $binary === '') return ['error' => 'file_base64 decode failed.'];

        // tempnam gives no extension — openpyxl validates the file name, so
        // name the the temp file with the document's real extension.
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $tmp = tempnam(sys_get_temp_dir(), 'rfq');
        if ($ext) $tmp .= '.' . $ext;
        file_put_contents($tmp, $binary);

        // Always persist the document, regardless of type (supports PDF, CAD, etc.).
        // Parsing only applies to spreadsheets (xlsx/xlsm) and CSV.
        $docs = new \api\rfq_documents();
        $docs->user_id = $this->effOwnerId();

        $parsedRows = [];
        $counts = [];
        $shouldParse = in_array($ext, ['xlsx', 'xlsm', 'csv']);

        if ($shouldParse) {
            if ($ext === 'xlsx' || $ext === 'xlsm') {
                $sheet = \getVal($input, 'sheet');
                $cmd = escapeshellcmd('python3 ' . dirname(__DIR__) . '/scripts/xlsx-to-rows.py') . ' ' . escapeshellarg($tmp) . ($sheet ? ' ' . escapeshellarg($sheet) : '');
                $raw = shell_exec($cmd . ' 2>/dev/null');
                $rows = json_decode((string)$raw, true);
            } else { // csv
                $fh = fopen($tmp, 'r');
                $rows = [];
                while (($line = fgetcsv($fh)) !== false) {
                    if (count($line) < 2) continue;
                    $rows[] = ['item_no' => $line[0] ?? '', 'desc' => $line[1] ?? '', 'size' => $line[2] ?? '', 'unit' => $line[3] ?? '', 'qty' => $line[4] ?? ''];
                }
                fclose($fh);
            }

            if (!is_array($rows)) {
                @unlink($tmp);
                return ['error' => 'Could not extract rows from the spreadsheet.'];
            }

            $importer = new \api\import();
            $importer->user_id = $this->effOwnerId();
            $parsed = $importer->handle_parse_boq(['rows' => $rows]);
            if (isset($parsed['error'])) {
                @unlink($tmp);
                return $parsed;
            }
            $parsedRows = $parsed['rows'] ?? [];
            $counts = $parsed['counts'] ?? [];
        }
        @unlink($tmp);

        // Persist the document (all types).
        $persisted = $docs->persistUpload([
            'quote_id'     => $quoteId,
            'filename'     => $filename,
            'binary'       => $binary,
            'mime_type'    => $mimeType,
            'source_type'  => 'rfq_upload',
            'parsed_rows'  => $parsedRows,
            'flag_counts'  => $counts,
        ]);
        if (isset($persisted['error'])) {
            // Non-fatal: return rows so the user can still review/import.
            return array_merge(
                ['rows' => $parsedRows, 'counts' => $counts],
                ['file_persisted' => false, 'file_error' => $persisted['error']]
            );
        }

        $result = [
            'file_id'      => $persisted['file_id'],
            'serve_url'    => $persisted['serve_url'],
            'file_persisted' => true,
        ];
        if ($shouldParse) {
            $result['rows'] = $parsedRows;
            $result['counts'] = $counts;
        } else {
            // Non-spreadsheet doc (PDF, CAD, etc.) — persisted but not parsed.
            $result['rows'] = [];
            $result['counts'] = ['ok' => 0, 'unclear' => 0, 'error' => 0, 'skip' => 0, 'duplicate' => 0];
            $result['info'] = 'File stored. This is not a spreadsheet — no rows to parse.';
        }
        return $result;
    }

    /**
     * cells — return the raw cell grid of a persisted spreadsheet document,
     * so the UI can show the source as cells and let the user map columns.
     * Input: { file_id, sheet?, max_rows? }
     * Output: { sheets, active, headerRow, headerLabels, cells:[{r,c,coord,v}] }
     */
    public function handle_cells($input = [])
    {
        $fileId = \getVal($input, 'file_id');
        if (!$fileId) return ['error' => 'file_id is required.'];

        // Extraction is slow (~13s on a 3MB multi-sheet xlsx) — cache the
        // python output per (file_id, sheet, max_rows). Re-uploads get new
        // file_ids so stale entries are never served.
        $cacheDir = sys_get_temp_dir() . '/fab_cells_cache';
        $cacheFile = $cacheDir . '/' . md5($fileId . '|' . (\getVal($input, 'sheet') ?: '') . '|' . ((int)(\getVal($input, 'max_rows') ?: 0))) . '.json';
        if (is_file($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }

        $files = new \forge\db\Files($this->pgCrud);
        $binary = $files->read($fileId);
        if (!$binary) return ['error' => 'File not found.', 'error_code' => 404];

        $tmp = tempnam(sys_get_temp_dir(), 'cells');
        $tmp .= '.xlsx';
        file_put_contents($tmp, $binary);

        $sheet = \getVal($input, 'sheet');
        $maxRows = (int)(\getVal($input, 'max_rows') ?: 0);
        $cmd = 'python3 ' . escapeshellarg(dirname(__DIR__) . '/scripts/xlsx-cells.py')
            . ' ' . escapeshellarg($tmp);
        if ($sheet)    $cmd .= ' ' . escapeshellarg($sheet);
        if ($maxRows)  $cmd .= ' ' . escapeshellarg((string)$maxRows);
        $raw = shell_exec(escapeshellcmd($cmd) . ' 2>/dev/null');
        @unlink($tmp);

        $json = json_decode((string)$raw, true);
        if (!is_array($json)) return ['error' => 'Could not extract cells from the spreadsheet.'];
        @mkdir($cacheDir, 0700, true);
        file_put_contents($cacheFile, json_encode($json));
        return $json;
    }

    /**
     * Import validated rows into the quote as entities.
     *   - PIPE SPOOL / PIPE CLOSURE (length unit) → assembly + pipe child
     *   - fittings / fasteners / parts → leaf entities (qty from the row)
     * Input: { quote_id, file_id?, rows: [normalized rows from parse_boq] }
     *   file_id — the rfq_document file id to link as boq_source_file on entities
     */
    public function handle_import($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        $rows = \getVal($input, 'rows', []);
        $boqFileId = \getVal($input, 'file_id');
        if (!$quoteId) return ['error' => 'quote_id is required.'];
        if (!is_array($rows) || empty($rows)) return ['error' => 'rows (array) is required.'];

        $quote = $this->getEntity($quoteId);
        if (!$quote || $quote['type'] !== 'quote') {
            return ['error' => 'Quote not found.', 'error_code' => 404];
        }
        $owner = $this->effOwnerId();
        $entities = new \api\entities();
        $entities->user_id = $owner;
        $components = new \api\components();
        $components->user_id = $owner;
        $links = new \api\links();
        $links->user_id = $owner;

        $imported = 0; $skipped = 0; $errors = 0;
        foreach ($rows as $r) {
            $flags = $r['flags'] ?? [];
            $levels = array_column($flags, 'level');
            if (in_array('skip', $levels)) { $skipped++; continue; }
            if (in_array('error', $levels) && $r['type'] !== 'assembly') { $errors++; continue; }

            $type = $r['type'] ?? 'part';
            $size = trim((string)($r['size'] ?? ''));
            $desc = trim((string)($r['desc'] ?? ''));
            $name = $this->entityName($desc, $size, $type, $r['spec'] ?? []);
            $qty = max((float)($r['qty'] ?? 1), 1);
            $section = $r['section'] ?? '';

            if (($section === 'PIPE SPOOL' || $section === 'PIPE CLOSURE') && ($r['unit'] ?? '') === 'm') {
                // BoQ lineage: every entity created from this row carries
                // its source file + original BoQ line data in entity.data
                $boqData = $this->boqLineageData($r, $boqFileId, $desc);

                $name = $this->entityName($desc, $size, $type, $r['spec'] ?? []);
                $asm = $entities->handle_create([
                    'type' => 'assembly', 'name' => $name, 'description' => $desc,
                    'quantity' => 1, 'quote_id' => $quoteId,
                    'data' => $boqData,
                ]);
                if (isset($asm['error'])) { $errors++; continue; }
                $asmId = $asm['id'];
                // Link the quote root → assembly so the Tree/BOM (which walk
                // contains-links) show the spool.
                // (G8: entities.create already made this link — nothing to do here.)
                $lenMm = (int)round($qty * 1000);
                $pipeName = 'Pipe ' . ($size ? $size . ' ' : '') . number_format($qty, 1) . 'm';
                $pipe = $entities->handle_create([
                    'type' => 'part', 'name' => $pipeName, 'description' => $desc,
                    'quantity' => 1, 'quote_id' => $quoteId,
                    'root_link' => false, // lives under the spool assembly, not the quote
                    'data' => $boqData,
                ]);
                if (!isset($pipe['error'])) {
                    $pipeMatData = $this->buildPipeMaterialData($size, $desc, $lenMm, $boqData);
                    $components->handle_create([
                        'entity_id' => $pipe['id'], 'type' => 'material',
                        'data' => $pipeMatData,
                    ]);
                    $links->handle_create(['from_id' => $asmId, 'to_id' => $pipe['id'], 'type' => 'contains', 'quantity' => 1]);
                }
                // Decompose: create flange children on the assembly as bought-out
                // line items (costPerEa). Welding is NOT auto-derived — the
                // estimator adds weld process components manually (weld type +
                // unit + description). Flange type parsed from the description
                // ("FLG TO SLIP ON" → SO, etc.). A missing/no-flange description
                // yields one SO + one blind (closure default).
                $flanges = $this->decomposeSpoolFlanges($size, $desc, $section);
                foreach ($flanges as $fl) {
                    $flName = $fl['name'];
                    $flEntity = $entities->handle_create([
                        'type' => 'fitting', 'name' => $flName, 'description' => $desc,
                        'quantity' => 1, 'quote_id' => $quoteId,
                        'root_link' => false, // lives under the spool assembly
                        'data' => $boqData,
                    ]);
                    if (!isset($flEntity['error'])) {
                        $components->handle_create([
                            'entity_id' => $flEntity['id'], 'type' => 'material',
                            'data' => $fl['material'],
                        ]);
                        // D5: flange count rides the LINK to the assembly
                        $links->handle_create(['from_id' => $asmId, 'to_id' => $flEntity['id'], 'type' => 'contains', 'quantity' => max((float)$fl['qty'], 1)]);
                    }
                }
                $imported++;
            } else {
                $rowName = $this->entityName($desc, $size, $type, $r['spec'] ?? []);
                // D5: singular entity — BoQ qty goes on the quote-root link
                // via link_quantity (G8 hook creates it).
                $e = $entities->handle_create([
                    'type' => $type, 'name' => $rowName, 'description' => $desc,
                    'quote_id' => $quoteId,
                    'link_quantity' => $qty,
                    'data' => $this->boqLineageData($r, $boqFileId, $desc),
                ]);
                if (isset($e['error'])) { $errors++; continue; }
                $imported++;
            }
        }

        $systems = new \api\systems();
        $systems->user_id = $owner;
        $overview = $systems->handle_recalculate_entity(['entity_id' => $quoteId]);

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'total_cost' => $overview['total_cost'] ?? null,
        ];
    }

    /**
     * build_from_map — the cell-picker path: the user opened a spreadsheet as
     * cells (handle_cells), mapped columns → fields, and now wants the chosen
     * rows turned into quote entities.
     *
     * Input: {
     *   quote_id,
     *   file_id,
     *   sheet?,                      // sheet name (defaults to active)
     *   header_row,                 // 1-based header row index
     *   fields: {abc_no:'A', description:'G', size:'M', qty:'J', uom:'K', cls:'B', lining:'O', ...},
     *   row_filter?: {col:'I', equals:'Straight Pipe'},  // optional: include only rows where this col matches
     *   type?: 'part'               // entity type for created items (default 'part')
     * }
     * Output: {created:n, skipped:n, ids:[...], errors:[]}
     */
    public function handle_build_from_map($input = [])
    {
        $quoteId = \getVal($input, 'quote_id');
        $fileId = \getVal($input, 'file_id');
        $headerRow = (int)(\getVal($input, 'header_row') ?: 0);
        $fields = \getVal($input, 'fields', []);
        if (!$quoteId) return ['error' => 'quote_id is required.'];
        if (!$fileId) return ['error' => 'file_id is required.'];
        if (!$headerRow) return ['error' => 'header_row is required.'];
        if (!is_array($fields) || empty($fields)) return ['error' => 'fields mapping is required.'];

        $quote = $this->getEntity($quoteId);
        if (!$quote || $quote['type'] !== 'quote') return ['error' => 'Quote not found.', 'error_code' => 404];
        $owner = $this->effOwnerId();

        // Re-extract the raw cells for this file/sheet.
        $cellsResp = $this->handle_cells(['file_id' => $fileId, 'sheet' => \getVal($input, 'sheet')]);
        if (isset($cellsResp['error'])) return $cellsResp;
        $cells = $cellsResp['cells'] ?? [];
        $activeSheet = $cellsResp['active'] ?? null;

        // Index cells by coordinate for O(1) lookup.
        $byCoord = [];
        foreach ($cells as $c) $byCoord[$c['coord']] = $c['v'];

        // Which data rows to process: every row > header_row whose
        // "description"-mapped column (or first mapped field) is non-empty.
        $keyCol = $fields['description'] ?? reset($fields);
        $rowNums = [];
        foreach ($cells as $c) {
            if ($c['r'] <= $headerRow) continue;
            $rowNums[$c['r']] = true;
        }
        $rowNums = array_keys($rowNums);
        sort($rowNums, SORT_NUMERIC);

        $filter = \getVal($input, 'row_filter');
        $type = \getVal($input, 'type', 'part');

        $entities = new \api\entities(); $entities->user_id = $owner;
        $links = new \api\links();       $links->user_id = $owner;

        $created = 0; $skipped = 0; $ids = []; $errors = [];
        $createdSpecs = [];   // per-entity spec context for import-time material matching
        foreach ($rowNums as $r) {
            $get = function ($col) use ($byCoord, $r) {
                if (!$col) return '';
                $v = $byCoord[$col . $r] ?? null;
                return $v === null ? '' : (string)$v;
            };
            $desc = trim($get($fields['description'] ?? ''));
            if ($desc === '') { $skipped++; continue; }

            // Optional row filter (e.g. only Specifications == 'Straight Pipe').
            if (is_array($filter) && !empty($filter['col']) && isset($filter['equals'])) {
                if (strcasecmp(trim($get($filter['col'])), trim((string)$filter['equals'])) !== 0) {
                    $skipped++; continue;
                }
            }

            $abc = trim($get($fields['abc_no'] ?? ''));
            $size = trim($get($fields['size'] ?? ''));
            $qty = trim($get($fields['qty'] ?? ''));
            $uom = trim($get($fields['uom'] ?? ''));
            $cls = trim($get($fields['cls'] ?? ''));
            $lining = trim($get($fields['lining'] ?? ''));
            $section = trim($get($fields['section'] ?? ''));
            $uniqueRef = trim($get($fields['unique'] ?? ''));
            $name = ($abc !== '' ? $abc . ' - ' : '') . $desc;

            // ── Translate client columns into system fields ──
            // Size → parsed DN/NB/OD hint (raw string kept as boq_size).
            // No digits in the raw size ⇒ no parsed hint (never invent a DN).
            $specDn = null; $specKind = null;
            $sizeKindRaw = trim($get($fields['size_kind'] ?? ''));
            if ($size !== '' && preg_match('/\d{2,4}/', $size)) {
                $specDn = $this->extractDn($size);
                if (preg_match('/NB/i', $size . ' ' . $sizeKindRaw))       $specKind = 'NB';
                elseif (preg_match('/DN/i', $size . ' ' . $sizeKindRaw))   $specKind = 'DN';
                else                                                       $specKind = 'OD';
            }
            // Section / type descriptor → entity-type refinement to OUR taxonomy.
            // ONLY the section's controlled vocabulary decides (never free-text
            // descriptions — "Welded Fittings to ANSI" must not flip a pipe).
            $effType = $type;
            if ($type === 'part' && $section !== '') {
                if (preg_match('/(FLANGE|TEE|ELBOW|BEND|REDUCER|COUPLING|CAP|NIPPLE|UNION|GASKET|VALVE|STUB|SPACER)/i', $section)) {
                    $effType = 'fitting';
                }
            }

            // Per-cell lineage: every mapped column keeps its source coord so
            // the item traces back to the exact ingress cell.
            $cellRefs = [];
            foreach ($fields as $fname => $col) {
                if ($col) $cellRefs[$fname] = $col . $r;
            }
            $data = [
                'boq_source_file' => $fileId,
                'boq_sheet'       => $activeSheet,
                'boq_row_idx'     => $r,
                'boq_item_no'     => $abc !== '' ? $abc : null,
                'boq_desc'        => $desc,
                'boq_size'        => $size !== '' ? $size : null,
                'boq_qty'         => $qty !== '' ? (float)$qty : null,
                'boq_unit'        => $uom !== '' ? $uom : null,
                'boq_class'       => $cls !== '' ? $cls : null,
                'boq_lining'      => $lining !== '' ? $lining : null,
                'boq_section'     => $section !== '' ? $section : null,
                'spec_dn'         => $specDn,
                'spec_kind'       => $specKind,   // NB | DN | OD
                'unique_ref'      => $uniqueRef !== '' ? $uniqueRef : null,
                'cell_refs'       => $cellRefs,
            ];

            // D5: BoQ quantity belongs on the quote-root contains-LINK, not the entity.
            $e = $entities->handle_create([
                'type' => $effType, 'name' => $name, 'description' => $desc,
                'quote_id' => $quoteId, 'data' => $data,
                'link_quantity' => $qty !== '' && is_numeric($qty) ? (float)$qty : 1,
            ]);
            if (!isset($e['error'])) {
                $ids[] = $e['id'];
                $created++;
                $createdSpecs[] = ['id' => $e['id'], 'section' => $section, 'dn' => $specDn,
                    'kind' => $specKind, 'desc' => $desc, 'cls' => $cls,
                    'qty' => is_numeric($qty) ? (float)$qty : 0, 'uom' => $uom];
            } else { $errors[] = $e['error']; }
        }

        // ── Import-time material auto-match (Option C flow) ──
        // Structured spec match against the shared library; confident hits get
        // a material component. Comps are inserted directly (NOT via
        // components.handle_create) so we don't trigger 200+ upward recalcs —
        // the single batch recalc below covers all of them.
        $matched = 0; $unmatched = 0;
        if (\getVal($input, 'match_materials', true) && $createdSpecs) {
            $materialsApi = new \api\materials(); $materialsApi->user_id = $owner;
            // One library fetch for the whole batch (scoring is in-memory).
            $libCandidates = $materialsApi->handle_list(['limit' => 2000]);
            if (!isset($libCandidates['error'])) {
                foreach ($createdSpecs as $cs) {
                    $cands = \api\materials::scoreSpec([
                        'section' => $cs['section'], 'dn' => (string)$cs['dn'],
                        'kind' => $cs['kind'], 'desc' => $cs['desc'], 'cls' => $cs['cls'],
                    ], $libCandidates);
                    if (empty($cands)) { $unmatched++; continue; }
                    $best = $cands[0];
                    if ((float)($best['match_score'] ?? 0) < 0.7) { $unmatched++; continue; }
                    $compData = ['materialLibraryId' => $best['id']];
                    // Meter-based BoQ rows on pipes: the BoQ quantity IS the cut length.
                    if ($cs['uom'] !== '' && stripos($cs['uom'], 'met') === 0
                        && strtoupper((string)($best['profile'] ?? '')) === 'PIPE') {
                        $compData['length'] = (int)round($cs['qty'] * 1000); // m -> mm
                    }
                    $this->pgCrud->save(['table' => 'component', 'data' => [
                        'entity_id' => $cs['id'], 'type' => 'material',
                        'data' => $compData, 'user_id_owner' => $owner,
                    ]]);
                    $matched++;
                }
            }
        }

        // One recalc for the whole batch.
        $systems = new \api\systems(); $systems->user_id = $owner;
        $systems->handle_recalculate_entity(['entity_id' => $quoteId]);

        return ['created' => $created, 'skipped' => $skipped, 'ids' => $ids,
            'materials_matched' => $matched, 'materials_unmatched' => $unmatched, 'errors' => $errors];
    }

    /**
     * smart_map — ask pi (via the localhost bridge) to propose a column→field
     * mapping for a sheet. Heuristics in the UI run first; this is the "Let pi
     * map it" fallback for messy sheets. Cached per (file_id, sheet).
     * Input: { file_id, sheet? }  Output: { fields: {abc_no:'B',...}, source:'pi' }
     */
    public function handle_smart_map($input = [])
    {
        $fileId = \getVal($input, 'file_id');
        if (!$fileId) return ['error' => 'file_id is required.'];

        $cacheDir = sys_get_temp_dir() . '/fab_cells_cache';
        $cacheFile = $cacheDir . '/sm_' . md5($fileId . '|' . (\getVal($input, 'sheet') ?: '')) . '.json';
        if (is_file($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached)) return $cached;
        }

        // Small sample for the prompt.
        $cells = $this->handle_cells([
            'file_id' => $fileId,
            'sheet' => \getVal($input, 'sheet'),
            'max_rows' => 20,
        ]);
        if (isset($cells['error'])) return $cells;

        $catalog = "abc_no = item/position number\n"
            . "description = item description text\n"
            . "size = the numeric size value (DN/NB/OD number)\n"
            . "size_kind = a column telling whether size is NB/DN/OD kind\n"
            . "qty = quantity or forecast take-off amount\n"
            . "uom = unit of measure (Meter, EA...)\n"
            . "cls = material class like CS / HDPE\n"
            . "lining = lining specification\n"
            . "section = section / type descriptor (e.g. Straight Pipe, Flange)\n"
            . "unique = unique reference flag";

        $prompt = "You map spreadsheet columns to a quoting system's fields.\n"
            . "Target fields:\n{$catalog}\n\n"
            . "headerLabels (col letter -> label): " . json_encode($cells['headerLabels'] ?? []) . "\n\n"
            . "Sample data rows (coord -> value): " . json_encode(array_slice($cells['cells'] ?? [], 0, 120)) . "\n\n"
            . "Return ONLY minified JSON, no prose, no markdown fences, shaped exactly like: "
            . '{"fields":{"abc_no":"B","description":"H","size":"Q","size_kind":"D","qty":"K","uom":"O","cls":"C","section":"E","lining":"","unique":""}} '
            . "using single capital column letters from headerLabels. Use empty string when no column fits.";

        set_time_limit(300);
        $ch = curl_init('http://127.0.0.1:8787');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['prompt' => $prompt, 'timeout' => 240]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 250,
        ]);
        $resp = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if (!$resp) return ['error' => 'pi bridge unreachable: ' . $curlErr];
        $decoded = json_decode((string)$resp, true);
        $text = trim((string)($decoded['text'] ?? ''));
        if ($text === '') return ['error' => 'pi returned no text'];

        if (!preg_match('/\{.*\}/s', $text, $m)) return ['error' => 'pi output unparseable', 'raw' => substr($text, 0, 400)];
        $j = json_decode($m[0], true);
        if (!isset($j['fields']) || !is_array($j['fields'])) {
            return ['error' => 'pi output missing fields map', 'raw' => substr($text, 0, 400)];
        }

        $out = ['fields' => $j['fields'], 'source' => 'pi'];
        @mkdir($cacheDir, 0700, true);
        file_put_contents($cacheFile, json_encode($out));
        return $out;
    }

    /**
     * Parse a spool/closure description to determine its flange ends.
     * Returns [{name, qty, material:{materialLibraryId, weldType, pipeWt}}]
     * for each flange child to create on the assembly.
     *
     * Description patterns:
     *   "FLG TO SLIP ON PLATE FLANGE" → SO (welded)
     *   "FLG TO WELD NECK" → WN
     *   "FLG TO BLIND" → BLIND (bolted — 0 weld)
     *   "NO FLG" / no flange mention → one SO + one blind (closure default)
     */
    private function decomposeSpoolFlanges($size, $desc, $section)
    {
        $d = strtoupper((string)$desc);
        $dn = $this->extractDn($size);

        // Determine flange types from the description.
        // Look for "FLG TO <TYPE>" patterns.
        $hasFlgTo = preg_match('/FLG\s+TO\s+(\w+)/i', $d, $m);
        if ($hasFlgTo) {
            $ftype = strtoupper($m[1]);
            $wtype = $this->matchWeldType($ftype);
            // Two ends of the same type (both welded or both bolted).
            $qty = $wtype !== 'NONE' ? 2 : 2;
            return [
                ['name' => $this->flangeName($wtype, $size, $ftype, $desc), 'qty' => 2,
                 'material' => $this->flangeMaterialData($dn, $wtype, $desc)],
            ];
        }

        // FLG TO ... at EACH END with different types (rare but possible):
        // e.g. "FLG TO SLIP ON ONE END, FLG TO WELD NECK OTHER"
        // For now, handle the common case: uniform flange type per spool.

        // No "FLG TO" found — could be closures with loose flange, or spools
        // with no flanges. Closures default to 1 welded + 1 loose.
        if ($section === 'PIPE CLOSURE' || preg_match('/LOOSE\s+FLANGE/i', $d)) {
            return [
                ['name' => $this->flangeName('SO', $size, 'SLIP ON', $desc), 'qty' => 1,
                 'material' => $this->flangeMaterialData($dn, 'SO', $desc)],
                ['name' => $this->flangeName('LOOSE', $size, 'LOOSE', $desc), 'qty' => 1,
                 'material' => $this->flangeMaterialData($dn, 'LOOSE', $desc)],
            ];
        }

        // Spool with no explicit flange type in desc — default to SO (most common).
        return [
            ['name' => $this->flangeName('SO', $size, 'SLIP ON', $desc), 'qty' => 2,
             'material' => $this->flangeMaterialData($dn, 'SO', $desc)],
        ];
    }

    /** Extract DN number from a size string like "200NB" / "DN200" / "200" */
    private function extractDn($size)
    {
        $s = trim((string)$size);
        if (preg_match('/(\d{2,4})\s*NB/i', $s, $m)) return $m[1];
        if (preg_match('/DN\s*(\d{2,4})/i', $s)) return preg_replace('/^.*?(\d{2,4}).*$/', '$1', $s);
        $clean = preg_replace('/\D/', '', $s);
        return $clean !== '' ? $clean : '100';
    }

    /** Map a flange type keyword to a weldType the cost engine understands. */
    private function matchWeldType($ftype)
    {
        $map = [
            'SLIP' => 'SO', 'SLIPON' => 'SO',
            'WELD' => 'WN', 'WELDECK' => 'WN', 'NECK' => 'WN',
            'BLIND' => 'BLIND', 'BL' => 'BLIND',
            'SOCKET' => 'SW', 'SOCK' => 'SW',
            'LOOSE' => 'LOOSE', 'LAP' => 'LAP',
            'THREADED' => 'THREADED',
        ];
        foreach ($map as $key => $val) {
            if (stripos($ftype, $key) !== false) return $val;
        }
        return 'NONE';
    }

    /** Build the flange child's material component. */
    private function flangeMaterialData($dn, $wtype, $desc)
    {
        $libId = $this->lookupLibraryId('flange', $dn);
        $pipeLib = $this->lookupLibraryRow('pipe', $dn);
        $data = ['weldType' => $wtype];
        if ($libId) $data['materialLibraryId'] = $libId;
        // pipeOd + pipeWt describe the mating pipe so the flange line carries
        // full context for the estimator (weld size metadata etc.). Source
        // them from the mating pipe's library row so the flange comp is
        // complete even without a matching flange library entry.
        if ($pipeLib) {
            $libData = is_array($pipeLib['data'] ?? null) ? $pipeLib['data'] : [];
            if (!empty($libData['od'])) $data['pipeOd'] = (float)$libData['od'];
            if (empty($data['pipeWt'])) {
                $wt = (float)($libData['wt'][0] ?? $libData['wt'] ?? 0);
                if ($wt > 0) $data['pipeWt'] = $wt;
            }
        }
        $pipeWt = $this->extractPipeWt($desc);
        if ($pipeWt) $data['pipeWt'] = $pipeWt;
        return $data;
    }

    /** Build the pipe child's material component. */
    private function buildPipeMaterialData($size, $desc, $lenMm, $boqData)
    {
        $dn = $this->extractDn($size);
        $libId = $this->lookupLibraryId('pipe', $dn);
        $data = ['length' => $lenMm, 'quantity' => 1];
        if ($libId) $data['materialLibraryId'] = $libId;
        $pipeWt = $this->extractPipeWt($desc);
        if ($pipeWt) $data['pipeWt'] = $pipeWt;
        // Include pipeOd so the cost engine's material pricing + area calc work
        // even if the library match fails.
        $pipeLib = $this->lookupLibraryRow('pipe', $dn);
        if ($pipeLib) {
            $libData = is_array($pipeLib['data'] ?? null) ? $pipeLib['data'] : [];
            if (!empty($libData['od'])) $data['pipeOd'] = (float)$libData['od'];
            if (!empty($libData['wt'])) $data['pipeWt'] = (float)$libData['wt'];
        }
        $data['buttWeldQty'] = 0;
        $data['boq_source_file'] = $boqData['boq_source_file'] ?? null;
        $data['boq_item_no'] = $boqData['boq_item_no'] ?? null;
        $data['boq_section'] = $boqData['boq_section'] ?? null;
        return $data;
    }

    /** Look up a library material entity by category + DN. */
    /**
     * Look up a library material entity by category + DN. Returns full entity row
     * (with data JSONB) for OD/WT extraction, or null.
     */
    private function lookupLibraryRow($category, $dn)
    {
        $mats = new \api\materials();
        $mats->user_id = $this->effOwnerId();
        // Pipes are library_category='material' with profile='Pipe'.
        // Flanges/fittings/fasteners have their own library_category.
        $libCat = $category === 'pipe' ? 'material' : $category;
        $res = $mats->handle_list([
            'search' => 'DN ' . $dn,
            'library_category' => $libCat,
            'limit' => 10,
        ]);
        if (isset($res['error']) || empty($res)) return null;
        foreach ($res as $r) {
            $rnb = isset($r['nb']) ? (string)$r['nb'] : '';
            $rdn = isset($r['dn']) ? (string)$r['dn'] : '';
            if ($rnb === (string)$dn || $rdn === (string)$dn) {
                // For pipe category, additionally verify profile is Pipe/Tube.
                if ($category === 'pipe' && !preg_match('/pipe|tube/', strtolower((string)($r['profile'] ?? '')))) continue;
                return $r;
            }
        }
        return $res[0] ?? null;
    }

    /**
     * Look up a library material entity ID by category + DN.
     */
    private function lookupLibraryId($category, $dn)
    {
        $row = $this->lookupLibraryRow($category, $dn);
        return $row['id'] ?? null;
    }

    /** Extract pipe wall thickness (mm) from a description like "6MM" or "8MM". */
    private function extractPipeWt($desc)
    {
        if (preg_match('/(\d+)MM\s*[,)]/i', $desc, $m)) return (float)$m[1];
        if (preg_match('/(\d+)\s*MM.*(?:PIPE|SPOOL)/i', $desc, $m)) return (float)$m[1];
        return null;
    }

    /** Build a flange display name. */
    private function flangeName($wtype, $size, $flangeDesc, $boqDesc)
    {
        $label = $wtype === 'LOOSE' ? 'Loose Flg' : 'Flg ' . $wtype;
        return $label . ' ' . $size;
    }

    /**
     * Build the BoQ lineage data block stored in entity.data.
     * Lets any entity trace back to its source BoQ row + document file.
     */
    private function boqLineageData($row, $fileId, $desc = '')
    {
        $data = [];
        if ($fileId) {
            $data['boq_source_file'] = $fileId;   // → files_meta.id → serve.php?id=
        }
        $data['boq_item_no']  = $row['item_no'] ?? null;
        $data['boq_row_idx']  = $row['row'] ?? null;
        $data['boq_section']  = $row['section'] ?? null;
        $data['boq_bill']     = $row['bill'] ?? null;
        $data['boq_desc']     = trim((string)($row['desc'] ?? $desc ?? ''));
        $data['boq_size']     = $row['size'] ?? null;
        $data['boq_qty']      = $row['qty'] ?? null;
        $data['boq_unit']     = $row['unit'] ?? null;
        if (!empty($row['spec'])) $data['boq_spec'] = $row['spec'];
        return $data;
    }

    /** Guess MIME type from filename extension (for non-data-URI uploads). */
    private function mimeTypeFor($filename)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $map = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xlsm' => 'application/vnd.ms-excel.sheet.macroEnabled.12',
            'xls'  => 'application/vnd.ms-excel',
            'csv'  => 'text/csv',
            'pdf'  => 'application/pdf',
            'step' => 'application/octet-stream',
            'stp'  => 'application/octet-stream',
            'iges' => 'application/iges',
            'igs'  => 'application/iges',
            'dwg'  => 'application/acad',
            'dxf'  => 'application/dxf',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }

    /** Build a concise entity name: type + size + spec tags (from description). */
    private function entityName($desc, $size, $type, $spec)
    {
        $base = '';
        if ($type === 'assembly') $base = 'Spool/Closure';
        elseif ($type === 'fitting') $base = 'Fitting';
        elseif ($type === 'fastener') $base = 'Fastener';
        else $base = 'Part';
        if ($size) $base .= ' ' . $size;
        if (!empty($spec['grade'])) $base .= ' ' . $spec['grade'];
        if (!empty($spec['coating'])) $base .= ' ' . $spec['coating'];
        return $base;
    }
}

\api\dispatchIfEntry(__FILE__);
