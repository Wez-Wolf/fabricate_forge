<?php
/**
 * fabricate_forge/api/import.php
 *
 * BoQ intake — turn messy client data into clean, flagged, reviewable rows.
 *
 * Client tenders arrive as xlsx/csv/paste with inconsistent formatting:
 * prose descriptions ("PIPE SPOOL 6000 MM, 6MM, PE ON BOTHS ENDS, ERW,
 * SANS 719 Gr B, GALVANISED TO SANS 121, FLG TO SLIP ON PLATE FLANGE SANS
 * 1123 T1000/3, FF…"), mixed units (6000MM vs 6M vs 6000LG), variant sizes
 * (200NB / DN200 / 200), subtotal + title rows. Nothing can be trusted.
 *
 * parse_boq takes raw rows and returns NORMALIZED rows, each carrying:
 *   - section / size / unit / qty  (normalized)
 *   - type (assembly|fitting|part|fastener — from the description)
 *   - spec tags (grade, standard, schedule, coating — extracted)
 *   - flags: [{level, code, msg}]  (unclear | error | duplicate)
 *
 * The review UI renders these rows with the flags inline; the human fixes
 * what the parser couldn't, then COMMITS via import_boq (which reuses the
 * sandsloot seed structure: assemblies → pipe + fittings + flanges).
 *
 * Input: { rows: [{ item_no?, desc, qty?, unit?, size?, section? }], quote_id? }
 *
 * CSV intake: handle_import_csv parses raw CSV text natively in PHP (PHP
 * has str_getcsv) — only XLSX needs the Python helper (scripts/xlsx-to-rows.py).
 */
namespace api;

include_once(__DIR__ . "/_base.php");

class import extends Base
{
    /**
     * Normalize one raw BoQ row → clean fields + spec tags + issue flags.
     */
    public function handle_parse_boq($input = [])
    {
        $rows = \getVal($input, 'rows', []);
        if (!is_array($rows) || empty($rows)) {
            return ['error' => 'rows (array) is required.'];
        }

        $out = [];
        $seen = [];   // duplicate detection: item_no|size|qty
        foreach ($rows as $i => $r) {
            $out[] = $this->normalizeRow($r, $i + 1, $seen);
        }
        return ['rows' => $out, 'counts' => $this->countFlags($out)];
    }

    /**
     * Import raw CSV BoQ data → normalized rows with issue flags.
     * CSV format: item_number,description,qty,unit,size,section (header optional).
     * Unlike XLSX (needs Python), CSV is parsed natively in PHP via str_getcsv.
     * Input: { csv_text: "item,desc,qty,unit,size,section\n1,Skid Frame,,,,assembly" }
     */
    public function handle_import_csv($input = [])
    {
        $csvText = \getVal($input, 'csv_text', '');
        if (!is_string($csvText) || $csvText === '') {
            return ['error' => 'csv_text (string) is required.'];
        }

        $rows = [];
        $lines = array_map('trim', preg_split('/\r\n|\r|\n/', $csvText));
        $headerDetected = false;
        $headerKeys = ['item_number', 'description', 'qty', 'unit', 'size', 'section',
                       'item', 'desc', 'quantity', 'length', 'width', 'thickness'];

        foreach ($lines as $line) {
            if ($line === '' || $line[0] === '#') continue;
            $parts = str_getcsv($line);
            if (!$headerDetected) {
                // If the first row's (cleaned) cells mostly match known headers,
                // treat it as a header and skip it.
                $hits = 0;
                foreach ($parts as $p) {
                    $norm = strtolower(preg_replace('/[^a-z0-9]/', '', $p));
                    if (in_array($norm, $headerKeys)) $hits++;
                }
                if ($hits >= 2) { $headerDetected = true; continue; }
                // else first data row falls through to positional mapping below
            }

            // Positional mapping (no header): item, desc, qty, unit, size, section
            if (count($parts) >= 2) {
                $rows[] = [
                    'item_number' => $parts[0] ?? '',
                    'description' => $parts[1] ?? 'Item',
                    'qty'         => $parts[2] ?? '',
                    'unit'        => $parts[3] ?? '',
                    'size'        => $parts[4] ?? '',
                    'section'     => $parts[5] ?? '',
                ];
            }
        }

        if (!$rows) {
            return ['rows' => [], 'counts' => ['ok' => 0, 'unclear' => 0, 'error' => 0, 'skip' => 0, 'duplicate' => 0]];
        }

        // Reuse the same normalization as parse_boq.
        $out = [];
        $seen = [];
        foreach ($rows as $i => $r) {
            $out[] = $this->normalizeRow($r, $i + 1, $seen);
        }

        return ['rows' => $out, 'counts' => $this->countFlags($out)];
    }

    // ── Normalization ─────────────────────────────────

    private function normalizeRow($r, $idx, &$seen)
    {
        $desc = trim((string)($r['desc'] ?? $r['description'] ?? ''));
        $flags = [];

        // Skip title/subtotal/footer noise (but surface it so nothing is lost)
        $upper = strtoupper($desc);
        if ($desc === '' || preg_match('/^(TOTAL|SUBTOTAL|SECTION \d|NOTES|PAGE|PROJECT|CLIENT|DATE)/', $upper)) {
            return [
                'row' => $idx, 'section' => '', 'size' => '', 'unit' => '', 'qty' => null,
                'type' => 'skip', 'desc' => $desc, 'spec' => [], 'flags' => [
                    ['level' => 'skip', 'code' => 'noise', 'msg' => 'Title/subtotal row — skipped, not imported'],
                ],
            ];
        }

        // Section — explicit tag wins, else classify from the description.
        $section = strtoupper(trim((string)($r['section'] ?? '')));
        if (!$section) $section = $this->classifySection($desc);
        if (!$section) $flags[] = ['level' => 'error', 'code' => 'no-section', 'msg' => 'Could not classify the item section from the description.'];

        // Size — normalize "200NB"/"DN200"/"200" → clean token; multi-size → primary.
        $size = $this->normalizeSize($r['size'] ?? '', $desc);
        if ($size['multi']) $flags[] = ['level' => 'unclear', 'code' => 'multi-size', 'msg' => "Multiple sizes detected — using primary {$size['value']} (from {$size['raw']})"];
        if ($size['error']) $flags[] = ['level' => 'error', 'code' => 'no-size', 'msg' => $size['error']];

        // Unit + qty — normalize length units (MM/LG → m) vs counts (NO/EA → ea).
        $unit = strtolower(trim((string)($r['unit'] ?? '')));
        $qty = $r['qty'] ?? null;
        $unitNorm = $this->normalizeUnit($unit, $desc);
        if ($unit && $unitNorm['changed']) $flags[] = ['level' => 'unclear', 'code' => 'unit', 'msg' => "Unit '{$unit}' normalized to '{$unitNorm['unit']}'"];
        $qtyNorm = $this->normalizeQty($qty, $unitNorm['unit']);
        if ($qtyNorm['error']) $flags[] = ['level' => 'error', 'code' => 'qty', 'msg' => $qtyNorm['error']];
        if (($qtyNorm['value'] ?? 0) <= 0) $flags[] = ['level' => 'error', 'code' => 'qty-zero', 'msg' => 'Quantity is zero or missing.'];

        // Type — from the description (assembly/fitting/fastener/part).
        $type = $this->detectType($desc, $section);

        // Spec extraction (grade, standard, schedule, coating) — the parts of
        // the long description that identify the material.
        $spec = $this->extractSpec($desc);

        // Duplicate detection — same size+qty+section appears twice (subtotal
        // repeats or copy-paste dupes in the tender).
        $dupKey = $section . '|' . $size['value'] . '|' . $qtyNorm['value'] . '|' . strtoupper($desc);
        if (isset($seen[$dupKey])) $flags[] = ['level' => 'unclear', 'code' => 'duplicate', 'msg' => 'Identical line appears elsewhere in the BoQ — check for subtotal repeat.'];
        $seen[$dupKey] = true;

        return [
            'row' => $idx,
            'section' => $section,
            'size' => $size['value'],
            'size_raw' => $size['raw'],
            'unit' => $unitNorm['unit'],
            'unit_raw' => $unit ?: null,
            'qty' => $qtyNorm['value'],
            'qty_raw' => $qty,
            'type' => $type,
            'desc' => $desc,
            'spec' => $spec,
            'flags' => $flags,
            // Provenance: carry the original BoQ item number + bill tag so
            // imported entities can trace back to their source row.
            'item_no' => $r['item_no'] ?? $r['item_number'] ?? null,
            'bill'    => $r['bill'] ?? null,
        ];
    }

    /** Classify the BoQ section from the description keywords. */
    private function classifySection($desc)
    {
        $d = strtoupper($desc);
        if (preg_match('/PIPE SPOOL|SPOOL/i', $d)) return 'PIPE SPOOL';
        if (preg_match('/PIPE CLOSURE|CLOSURE/i', $d)) return 'PIPE CLOSURE';
        if (preg_match('/SEG BEND|ELBOW|BEND/i', $d)) return 'ELBOW';
        if (preg_match('/REDUCING TEE/i', $d)) return 'REDUCING TEE';
        if (preg_match('/EQUAL TEE|TEE/i', $d)) return 'EQUAL TEE';
        if (preg_match('/GATE VALVE/i', $d)) return 'GATE VALVE';
        if (preg_match('/AIR RELEASE|VACUUM BREAKER/i', $d)) return 'AIR RELEASE VALVE';
        if (preg_match('/FLANGE|FLG\b/i', $d)) return 'FLANGES';
        if (preg_match('/BOLT|NUT|WASHER|STUD/i', $d)) return 'BOLTS';
        if (preg_match('/GASKET/i', $d)) return 'GASKET';
        if (preg_match('/WELDOLET|NIPPLE|COUPLING|FITTING/i', $d)) return 'FITTINGS';
        if (preg_match('/U-BOLT GUIDE/i', $d)) return 'U-BOLT GUIDES';
        if (preg_match('/U-BOLT GRIP/i', $d)) return 'U-BOLT GRIPS';
        return '';
    }

    /** "200NB"/"DN200"/"200" → "200"; "200NBx100NB" → primary with multi flag. */
    private function normalizeSize($size, $desc)
    {
        $raw = trim((string)($size !== '' ? $size : ''));
        if ($raw === '') {
            // Fall back to the first size token in the description
            if (preg_match('/(?:DN|NB)?\s*(\d{2,4})\s*(?:NB|MM|LG)?/i', $desc, $m)) {
                return ['value' => $m[1], 'raw' => $raw, 'multi' => false, 'error' => null];
            }
            return ['value' => '', 'raw' => '', 'multi' => false, 'error' => 'No size detected — enter the DN manually.'];
        }
        // Multi-size: 200NBx100NB → primary (larger)
        if (preg_match_all('/(\d{2,4})\s*NB/i', $raw, $ms)) {
            $nums = array_map('intval', $ms[1]);
            return ['value' => (string)max($nums), 'raw' => $raw, 'multi' => count($nums) > 1, 'error' => null];
        }
        $clean = preg_replace('/[^0-9]/', '', $raw);
        if ($clean === '') return ['value' => '', 'raw' => $raw, 'multi' => false, 'error' => "Unrecognized size token '{$raw}'."];
        return ['value' => $clean, 'raw' => $raw, 'multi' => false, 'error' => null];
    }

    /** MM / LG / M → m (length); NO / EA → ea (count); blank → guess from section. */
    private function normalizeUnit($unit, $desc)
    {
        // Strip trailing punctuation/whitespace: "No." → "no"
        $u = strtolower(trim(preg_replace('/[.\s]+$/', '', (string)$unit)));
        if ($u === 'mm' || $u === 'lg' || $u === 'm' || $u === 'meter' || $u === 'metre') {
            return ['unit' => 'm', 'changed' => $u !== 'm'];
        }
        if ($u === 'no' || $u === 'ea' || $u === 'nr' || $u === 'each' || $u === 'set' || $u === 'sets') {
            return ['unit' => 'ea', 'changed' => !in_array($u, ['ea', 'each'])];
        }
        if ($u === 'kg' || $u === 't' || $u === 'ton') return ['unit' => 'kg', 'changed' => $u !== 'kg'];
        if ($u === '') {
            // Guess: PIPE SPOOL/CLOSURE = length (m); everything else = count
            $sec = $this->classifySection($desc);
            $guess = ($sec === 'PIPE SPOOL' || $sec === 'PIPE CLOSURE') ? 'm' : 'ea';
            return ['unit' => $guess, 'changed' => false];
        }
        return ['unit' => $u, 'changed' => false];
    }

    private function normalizeQty($qty, $unit)
    {
        if ($qty === null || $qty === '') {
            return ['value' => null, 'error' => 'Quantity missing.'];
        }
        $n = (float)preg_replace('/[^0-9.]/', '', (string)$qty);
        if ($n <= 0) return ['value' => 0, 'error' => "Quantity '{$qty}' is not a positive number."];
        return ['value' => $n, 'error' => null];
    }

    /** Entity type from the description — same rules as boms.php detectEntityType. */
    private function detectType($desc, $section)
    {
        // Single source of truth in _base.php (same rules as boms.php).
        return $this->classifyEntityType($desc, $section);
    }

    /** Spec tags from the description — grade, standard, schedule, coating. */
    private function extractSpec($desc)
    {
        $spec = [];
        if (preg_match('/SANS\s*719[^,)]*/i', $desc, $m)) $spec['grade'] = trim($m[0]);
        elseif (preg_match('/A106\s*Gr\s*\w+/i', $desc, $m)) $spec['grade'] = str_replace('Gr ', '', $m[0]);
        if (preg_match('/SANS\s*1123[^,)]*/i', $desc, $m)) $spec['standard'] = trim($m[0]);
        elseif (preg_match('/ASME\s*B16\.5/i', $desc)) $spec['standard'] = 'ASME B16.5';
        if (preg_match('/SCH\s*\d+/i', $desc, $m)) $spec['schedule'] = strtoupper($m[0]);
        if (stripos($desc, 'GALVANISED') !== false || stripos($desc, 'GALVANIZED') !== false) $spec['coating'] = 'Galv';
        if (stripos($desc, 'ERW') !== false) $spec['weld'] = 'ERW';
        elseif (stripos($desc, 'SMLS') !== false) $spec['weld'] = 'SMLS';
        return $spec;
    }

    private function countFlags($rows)
    {
        $counts = ['ok' => 0, 'unclear' => 0, 'error' => 0, 'skip' => 0, 'duplicate' => 0];
        foreach ($rows as $r) {
            $flags = $r['flags'] ?? [];
            $levels = array_column($flags, 'level');
            $codes = array_column($flags, 'code');
            if (in_array('error', $levels)) $counts['error']++;
            elseif (in_array('duplicate', $codes)) $counts['duplicate']++;
            elseif (in_array('unclear', $levels)) $counts['unclear']++;
            elseif (in_array('skip', $levels)) $counts['skip']++;
            else $counts['ok']++;
        }
        return $counts;
    }
}

\api\dispatchIfEntry(__FILE__);
