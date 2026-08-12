<?php
/**
 * scripts/build-boq-import.php
 *
 * Parses the Sandsloot Decline Piping tender BoQ (md exports) into a deduped
 * line-item CSV for the BOM import. Groups pipes by size/schedule/grade,
 * dedups fittings by type+size, sums quantities.
 */
$dir = '/var/www/html/fabricate_forge/data/md/PB23068_EX_MUG_Sandsloot_Decline_Piping_Tender_BoQ_03_08_2026_REV_3';
$files = ['Clarified_Water_B4.md', 'Service_Water_BL2.md', 'Fire_Water_GL2.md', 'Compressed_Air_GL2.md', 'Potable_Water_GL2.md'];

$lines = [];
function csv($s) { $s = str_replace(chr(34), chr(34).chr(34), trim($s)); return chr(34).$s.chr(34); }

$groups = [];
foreach ($files as $f) {
    foreach (file("$dir/$f", FILE_IGNORE_NEW_LINES) as $row) {
        if (!preg_match('/^\|\s*(\d+\.\d+)\s*\|(.*)$/', $row, $m)) continue;
        $cells = array_map('trim', explode('|', substr($row, 1, -1)));
        if (count($cells) < 8) continue;
        $desc = trim($cells[4] ?? ''); $size = trim($cells[6] ?? '');
        $unit = strtolower(trim($cells[7] ?? '')); $qty = (float)str_replace(',', '', trim($cells[8] ?? '0'));
        if ($desc === '' || $qty <= 0) continue;
        $lines[] = ['item' => $cells[0], 'desc' => $desc, 'size' => $size, 'unit' => $unit, 'qty' => $qty];
    }
}

function classify($desc) {
    $d = strtoupper(preg_replace('/\s+/', ' ', trim($desc)));
    if (preg_match('/^PIPE (SPOOL|CLOSURE)/', $d)) return 'PIPE';
    if (preg_match('/^(\d+ LG\s*)?SEGMENTED BEND|^ELBOW/', $d)) return 'ELBOW';
    if (preg_match('/^EQUAL TEE|^REDUCING TEE|^FLANGED CS REDUCING TEE|^TEE,/', $d)) return 'TEE';
    if (preg_match('/^BLIND FLANGE|^PLATE FLANGE|^SCREWED PLATE FLANGE|^CS\s*,\s*BLIND FLANGES|^CS\s*,\s*FLANGES|^FLANGE/', $d)) return 'FLANGE';
    if (preg_match('/^GASKET|^SPIRAL WOUND|^CNAF/', $d)) return 'GASKET';
    if (preg_match('/^NIPPLE/', $d)) return 'NIPPLE';
    if (preg_match('/^COUPLING|HALF COUPLING/', $d)) return 'COUPLING';
    if (preg_match('/^U-BOLT/', $d)) return 'U-BOLT';
    if (preg_match('/^\d+\s*(of|off|x)\s*M\d+|^M\d+\s*(x|off|of)|^STUD BOLT|^BOLT|^NUT|^WASHER/', $d)) return 'FASTENER';
    if (preg_match('/^CAP/', $d)) return 'CAP';
    if (preg_match('/VALVE/', $d)) return 'VALVE';
    if (preg_match('/O-LET|WELDOLET/', $d)) return 'FITTING';
    return 'OTHER';
}
function normDesc($d) { $d = strtoupper(preg_replace('/\s+/', ' ', trim($d))); $d = preg_replace('/\s*,\s*/', ',', $d); return rtrim($d, '.'); }

function pipeKey($size, $desc) {
    $d = strtoupper($desc);
    $dn = preg_match('/^(\d+)/', $size, $m) ? $m[1] : (preg_match('/DN\s*(\d+)/', $d, $m) ? $m[1] : '?');
    $sched = preg_match('/SCH\s*(\d+|STD|XS|XXS)/', $d, $m) ? $m[1] : (preg_match('/MEDIUM/', $d) ? 'MED' : '?');
    $grade = preg_match('/A106\s*GR\s*[A-B]/i', $d) ? 'A106' : (preg_match('/SANS\s*719/i', $d) ? 'SANS719' : (preg_match('/SANS\s*62/i', $d) ? 'SANS62' : '?'));
    return "DN$dn|SCH$sched|$grade";
}
function lengthMm($desc) { return preg_match('/(\d{2,5})\s*LG\b/i', $desc, $m) ? (int)$m[1] : 0; }
// fastener size M#
function fastenerSize($desc) { return preg_match('/M(\d+)/i', $desc, $m) ? $m[1] : '?'; }
// pipe grade for library hint
function pipeLibHint($size, $desc) {
    $d = strtoupper($desc);
    $dn = preg_match('/^(\d+)/', $size, $m) ? $m[1] : '';
    $sched = preg_match('/SCH\s*(\d+|STD|XS)/', $d, $m) ? strtoupper($m[1]) : '';
    $grade = preg_match('/A106/i', $d) ? 'A106B' : (preg_match('/SANS\s*719/i', $d) ? 'SANS 719' : (preg_match('/SANS\s*62/i', $d) ? 'SANS 62' : ''));
    $schedLib = $sched === '80' ? '#80' : ($sched === 'STD' ? 'STD' : ($sched === 'XS' ? 'XS' : ''));
    if ($grade === 'A106B' && $schedLib) return "PIPE DN $dn $schedLib $grade";
    if ($grade === 'SANS 62') return "PIPE DN $dn SANS 62 MED";
    if ($grade === 'SANS 719') return "PIPE DN $dn SANS 719";
    return '';
}

$groups = [];
foreach ($lines as $l) {
    $c = classify($l['desc']);
    if ($c === 'PIPE') {
        $k = 'PIPE|' . pipeKey($l['size'], $l['desc']);
        if (!isset($groups[$k])) $groups[$k] = ['type'=>'PIPE','desc'=>$l['desc'],'size'=>$l['size'],'unit'=>'m','qty'=>0];
        $groups[$k]['qty'] += $l['qty'];
    } else {
        $k = $c . '|' . strtoupper($l['size']) . '|' . normDesc($l['desc']);
        if (!isset($groups[$k])) $groups[$k] = ['type'=>$c,'desc'=>trim($l['desc']),'size'=>$l['size'],'unit'=>$l['unit'],'qty'=>0];
        $groups[$k]['qty'] += $l['qty'];
    }
}

// ── Build CSV: item, description, material, qty, length, width, thickness, type ──
$csv = "item,description,material,qty,length,width,thickness,type\n";
$n = 1;
foreach ($groups as $g) {
    $len = lengthMm($g['desc']);
    $mat = '';
    $dn = preg_match('/^(\d+)/', $g['size'], $m) ? $m[1] : (preg_match('/DN\s*(\d+)/i', $g['desc'], $m) ? $m[1] : '');
    $dU = strtoupper($g['desc']);
    switch ($g['type']) {
        case 'PIPE':
            $mat = pipeLibHint($g['size'], $g['desc']);
            break;
        case 'ELBOW':
            $mat = pipeLibHint($g['size'], $g['desc']); // fabricated from pipe
            break;
        case 'TEE':
            $mat = $dn ? "TEE DN$dn" : '';
            break;
        case 'FLANGE':
            $mat = $dn ? "Flange DN$dn 600 lb ANSI B 16.5 WN" : '';
            break;
        case 'NIPPLE':
            $mat = $dn ? "Nipple DN$dn" : '';
            break;
        case 'FASTENER':
            $fs = fastenerSize($g['desc']);
            $mat = $fs !== '?' ? "M$fs x 60 Hex Bolt" : '';
            break;
        case 'VALVE':
            if (preg_match('/DUAL AIR RELEASE|VACUUM BREAKER/i', $dU)) $mat = $dn ? "DUAL AIR RELEASE & VACUUM BREAKER VALVE DN$dn" : '';
            elseif (preg_match('/CL\s*600|CLASS\s*600/i', $dU)) $mat = $dn ? "GATE VALVE DN$dn CL 600" : '';
            elseif (preg_match('/PN\s*16/i', $dU)) $mat = $dn ? "GATE VALVE DN$dn PN 16" : '';
            else $mat = $dn ? "GATE VALVE DN$dn PN 10" : '';
            break;
        case 'GASKET':
            if (preg_match('/CNAF/i', $dU)) $mat = $dn ? "CNAF GASKET DN$dn" : '';
            elseif (preg_match('/SPIRAL WOUND/i', $dU)) $mat = $dn ? "SPIRAL WOUND GASKET DN$dn" : '';
            else $mat = $dn ? "GASKET CL 600 RJ SOFT IRON OVAL RING DN$dn" : '';
            break;
        case 'FITTING': // weldolets / o-lets
            $mat = $dn ? "WELDOLET DN$dn SCH 80" : '';
            break;
        case 'U-BOLT':
            if (preg_match('/GRIP/i', $dU)) $mat = $dn ? "U-BOLT GRIP DN$dn" : '';
            else $mat = $dn ? "U-BOLT GUIDE DN$dn" : '';
            break;
        case 'COUPLING':
            $mat = $dn ? "HALF COUPLING DN$dn THREADED" : '';
            break;
    }
    $qty = $g['qty'];
    // Pipes: quantity = number of pieces (total metres ÷ spool length) so the
    // cost engine's qty × length = total metres.
    if ($g['type'] === 'PIPE' && $len > 0) {
        $qty = round($qty / ($len / 1000), 2);
    }
    // Segmented bends: fabricated from pipe — give a nominal developed length
    // (the BoQ quotes them per No.) so the pipe-material cost applies.
    if ($g['type'] === 'ELBOW' && $len === 0) {
        $len = 600; // 0.6 m nominal developed length
    }
    // Flanges "BORE SCH 80" — set thickness = SCH80 pipe WT so the weld
    // model can compute real weld hours (wtForWeld falls back to thickness).
    $thk = '';
    if ($g['type'] === 'FLANGE' && preg_match('/SCH\s*80/i', $g['desc'])) {
        $wtMap = [25 => 3.91, 32 => 4.24, 40 => 4.85, 50 => 5.54, 65 => 7.01, 80 => 7.62,
                  90 => 7.62, 100 => 8.56, 125 => 9.53, 150 => 11.13, 200 => 12.7,
                  250 => 15.09, 300 => 16.56, 350 => 17.48, 400 => 16.66, 450 => 19.05, 500 => 21.44];
        $dn = (int)($dn ?: 0);
        if (isset($wtMap[$dn])) $thk = $wtMap[$dn];
    }
    $csv .= "$n," . csv($g['desc']) . "," . csv($mat) . "," . round($qty, 2) . ",{$len},,{$thk}," . strtolower($g['type']) . "\n";
    $n++;
}
file_put_contents('/tmp/boq_import.csv', $csv);
echo "Wrote /tmp/boq_import.csv: " . ($n - 1) . " rows\n";
