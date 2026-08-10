<?php
/**
 * fabricate_forge/api/tools.php
 *
 * Calculator tools — server-side math for the Tools page, ported from the
 * original app's MaterialCalculator.vue / ProcessCalculator.vue components.
 *
 * Pure math: no tables, no side effects. Each `calculate` action returns the
 * full result set so the UI renders numbers exactly as computed.
 *
 * Actions:
 *   calculate  { tool, inputs } → results
 *     tool: material_plate | material_section | material_general |
 *           process_welding | process_machining | process_assembly
 *   density    { material_key } → density lookup (client prefill helper)
 */
namespace api;

include_once(__DIR__ . "/_base.php");

class tools extends Base
{
    // Material densities (kg/m³) — mirrors MATERIAL_PROPERTIES in the original.
    const DENSITIES = [
        'steel' => 7850,
        'stainless' => 7900,
        'aluminum' => 2700,
    ];

    // Weld type factors (timeFactor + complexity) — mirrors WELDING_FACTORS.
    const WELDING_FACTORS = [
        'butt'   => ['timeFactor' => 0.5,  'complexity' => 1.5],
        'fillet' => ['timeFactor' => 0.3,  'complexity' => 1.2],
        'lap'    => ['timeFactor' => 0.4,  'complexity' => 1.3],
        'corner' => ['timeFactor' => 0.45, 'complexity' => 1.4],
    ];

    // Machining operation factors — mirrors MACHINING_FACTORS.
    const MACHINING_FACTORS = [
        'milling'  => ['baseTime' => 10, 'complexity' => 2.0],
        'turning'  => ['baseTime' => 8,  'complexity' => 1.8],
        'drilling' => ['baseTime' => 2,  'complexity' => 1.2],
        'grinding' => ['baseTime' => 15, 'complexity' => 2.5],
        'cutting'  => ['baseTime' => 5,  'complexity' => 1.5],
    ];

    // Material machining factors — mirrors MATERIAL_MACHINING_FACTORS.
    const MATERIAL_MACHINING_FACTORS = [
        'steel' => 1.5, 'stainless' => 2.0, 'aluminum' => 0.8, 'plastic' => 0.6,
    ];

    protected function buildTable()
    {
        $this->ensureEcsTables(); // auth + infra only; no tables of its own
    }

    /**
     * Route a calculation by tool id.
     * Input: { tool, inputs: { ... } }
     */
    public function handle_calculate($input = [])
    {
        $tool = \getVal($input, 'tool');
        $in = \getVal($input, 'inputs', []);
        $in = is_array($in) ? $in : [];

        switch ($tool) {
            case 'material_plate':    return $this->calcMaterialPlate($in);
            case 'material_section':  return $this->calcMaterialSection($in);
            case 'material_general':  return $this->calcMaterialGeneral($in);
            case 'process_welding':   return $this->calcProcessWelding($in);
            case 'process_machining': return $this->calcProcessMachining($in);
            case 'process_assembly':  return $this->calcProcessAssembly($in);
            case 'tank':              return $this->calcTank($in);
            case 'pipe':              return $this->calcPipe($in);
            default:
                return ['error' => "Unknown tool: $tool", 'error_code' => 400];
        }
    }

    /**
     * Density lookup for prefill.
     * Input: { material_key }
     */
    public function handle_density($input = [])
    {
        $key = \getVal($input, 'material_key', 'steel');
        $density = self::DENSITIES[$key] ?? null;
        if ($density === null) {
            return ['error' => "Unknown material_key: $key", 'error_code' => 400];
        }
        return ['material_key' => $key, 'density' => $density];
    }

    // ── Material: plate ─────────────────────────────────

    private function calcMaterialPlate($in)
    {
        $thickness = (float)\getVal($in, 'thickness', 10);
        $length = (float)\getVal($in, 'length', 1000);
        $width = (float)\getVal($in, 'width', 500);
        $quantity = (float)\getVal($in, 'quantity', 1);
        $rate = (float)\getVal($in, 'materialRate', 25);
        $waste = (float)\getVal($in, 'wasteFactor', 12.5);
        $density = self::DENSITIES[\getVal($in, 'materialType', 'steel')] ?? self::DENSITIES['steel'];

        $area = ($length * $width) / 1000000;                       // m²
        $volume = $area * ($thickness / 1000);                      // m³
        $weight = $volume * $density * $quantity;                   // kg
        $materialCost = $weight * $rate;
        $totalCost = $materialCost * (1 + $waste / 100);

        return [
            'type' => 'plate',
            'area' => self::r2($area),
            'volume' => self::r6($volume),
            'weight' => self::r2($weight),
            'materialCost' => self::r2($materialCost),
            'totalCost' => self::r2($totalCost),
            'density' => $density,
        ];
    }

    // ── Material: section ───────────────────────────────

    private function calcMaterialSection($in)
    {
        $weightPerMeter = (float)\getVal($in, 'weightPerMeter', 20);
        $length = (float)\getVal($in, 'length', 3000);             // mm per piece
        $quantity = (float)\getVal($in, 'quantity', 5);
        $rate = (float)\getVal($in, 'materialRate', 25);
        $waste = (float)\getVal($in, 'wasteFactor', 10);

        $totalLength = ($length * $quantity) / 1000;               // m
        $totalWeight = $totalLength * $weightPerMeter;             // kg
        $materialCost = $totalWeight * $rate;
        $totalCost = $materialCost * (1 + $waste / 100);

        return [
            'type' => 'section',
            'totalLength' => self::r2($totalLength),
            'totalWeight' => self::r2($totalWeight),
            'materialCost' => self::r2($materialCost),
            'totalCost' => self::r2($totalCost),
        ];
    }

    // ── Material: general ───────────────────────────────

    private function calcMaterialGeneral($in)
    {
        $weight = (float)\getVal($in, 'weight', 100);
        $rate = (float)\getVal($in, 'materialRate', 25);
        $processing = (float)\getVal($in, 'processingFactor', 1.2);

        $materialCost = $weight * $rate;
        $totalCost = $materialCost * $processing;

        return [
            'type' => 'general',
            'materialCost' => self::r2($materialCost),
            'totalCost' => self::r2($totalCost),
        ];
    }

    // ── Process: welding ────────────────────────────────

    private function calcProcessWelding($in)
    {
        $weldType = \getVal($in, 'weldType', 'fillet');
        $factor = self::WELDING_FACTORS[$weldType] ?? self::WELDING_FACTORS['fillet'];
        $weldLength = (float)\getVal($in, 'weldLength', 1000);     // mm
        $quantity = (float)\getVal($in, 'quantity', 1);
        $thickness = (float)\getVal($in, 'materialThickness', 6);
        $quality = (float)\getVal($in, 'qualityFactor', 1);
        $laborRate = (float)\getVal($in, 'laborRate', 90);
        $consumableRate = (float)\getVal($in, 'consumableRate', 2);
        $equipmentRate = (float)\getVal($in, 'equipmentRate', 25);

        $totalLength = ($weldLength * $quantity) / 1000;           // m
        $thicknessFactor = 1 + ($thickness / 5) * 0.2;
        $weldingTime = ($totalLength * $factor['timeFactor'] * $thicknessFactor * $quality) / 60; // hours

        $laborCost = $weldingTime * $laborRate;
        $consumableCost = $totalLength * $consumableRate;
        $equipmentCost = $weldingTime * $equipmentRate;
        $totalCost = $laborCost + $consumableCost + $equipmentCost;

        return [
            'type' => 'welding',
            'weldType' => $weldType,
            'totalLength' => self::r2($totalLength),
            'weldingTime' => self::r2($weldingTime),
            'laborCost' => self::r2($laborCost),
            'consumableCost' => self::r2($consumableCost),
            'equipmentCost' => self::r2($equipmentCost),
            'totalCost' => self::r2($totalCost),
        ];
    }

    // ── Process: machining ──────────────────────────────

    private function calcProcessMachining($in)
    {
        $opType = \getVal($in, 'operationType', 'drilling');
        $opFactor = self::MACHINING_FACTORS[$opType] ?? self::MACHINING_FACTORS['drilling'];
        $matKey = \getVal($in, 'materialType', 'steel');
        $matFactor = self::MATERIAL_MACHINING_FACTORS[$matKey] ?? self::MATERIAL_MACHINING_FACTORS['steel'];

        $setup = (float)\getVal($in, 'setupTime', 30);             // min per setup
        $quantity = (float)\getVal($in, 'quantity', 10);
        $complexity = (float)\getVal($in, 'complexityFactor', 1);
        $laborRate = (float)\getVal($in, 'laborRate', 90);
        $toolWearRate = (float)\getVal($in, 'toolWearRate', 5);
        $machineRate = (float)\getVal($in, 'machineRate', 60);

        $totalSetupTime = ($setup * $quantity) / 60;               // hours
        $runTimePerPiece = ($opFactor['baseTime'] * $complexity * $matFactor) / 60;
        $totalRunTime = $runTimePerPiece * $quantity;
        $totalMachiningTime = $totalSetupTime + $totalRunTime;

        $laborCost = $totalMachiningTime * $laborRate;
        $toolWearCost = $quantity * $toolWearRate;
        $machineCost = $totalMachiningTime * $machineRate;
        $totalCost = $laborCost + $toolWearCost + $machineCost;

        return [
            'type' => 'machining',
            'operationType' => $opType,
            'totalSetupTime' => self::r2($totalSetupTime),
            'totalRunTime' => self::r2($totalRunTime),
            'totalMachiningTime' => self::r2($totalMachiningTime),
            'laborCost' => self::r2($laborCost),
            'toolWearCost' => self::r2($toolWearCost),
            'machineCost' => self::r2($machineCost),
            'totalCost' => self::r2($totalCost),
        ];
    }

    // ── Process: assembly ───────────────────────────────

    private function calcProcessAssembly($in)
    {
        $componentCount = (float)\getVal($in, 'componentCount', 20);
        $timePerComponent = (float)\getVal($in, 'timePerComponent', 2); // min
        $complexity = (float)\getVal($in, 'complexityFactor', 1);
        $inspection = (float)\getVal($in, 'inspectionTime', 15);       // min
        $laborRate = (float)\getVal($in, 'laborRate', 90);
        $fixtureCost = (float)\getVal($in, 'fixtureCost', 0);

        $totalAssemblyTime = ($componentCount * $timePerComponent * $complexity + $inspection) / 60; // hours
        $laborCost = $totalAssemblyTime * $laborRate;
        $inspectionCost = ($inspection / 60) * $laborRate;
        $totalCost = $laborCost + $fixtureCost + $inspectionCost;

        return [
            'type' => 'assembly',
            'totalAssemblyTime' => self::r2($totalAssemblyTime),
            'laborCost' => self::r2($laborCost),
            'fixtureCost' => self::r2($fixtureCost),
            'inspectionCost' => self::r2($inspectionCost),
            'totalCost' => self::r2($totalCost),
        ];
    }

    // ── Tank builder (cylindrical vessel takeoff) ───────

    private function calcTank($in)
    {
        $diameter = (float)\getVal($in, 'diameter', 1200);             // mm (shell OD)
        $length = (float)\getVal($in, 'length', 3000);                 // mm (shell length)
        $thickness = (float)\getVal($in, 'thickness', 10);             // mm
        $quantity = (float)\getVal($in, 'quantity', 1);
        $rate = (float)\getVal($in, 'materialRate', 25);
        $waste = (float)\getVal($in, 'wasteFactor', 10);
        $heads = (int)\getVal($in, 'heads', 2);                        // 0 | 1 | 2 flat heads
        $density = self::DENSITIES[\getVal($in, 'materialType', 'steel')] ?? self::DENSITIES['steel'];

        $dM = $diameter / 1000;
        $lM = $length / 1000;
        $tM = $thickness / 1000;

        // Shell: π·D·L (rolling a rectangle). Heads: flat circles π·r² each.
        $shellArea = M_PI * $dM * $lM;                                  // m²
        $headArea = $heads * M_PI * pow($dM / 2, 2);                    // m²
        $totalArea = $shellArea + $headArea;

        // Volume of metal = area × thickness; mass = volume × density × qty
        $metalVolume = $totalArea * $tM;                                // m³
        $massKg = $metalVolume * $density * $quantity;
        $materialCost = $massKg * $rate;
        $totalCost = $materialCost * (1 + $waste / 100);

        $capacitLiters = M_PI * pow($dM / 2, 2) * $lM * 1000;           // usable volume

        return [
            'type' => 'tank',
            'shellArea' => self::r2($shellArea),
            'headArea' => self::r2($headArea),
            'totalArea' => self::r2($totalArea),
            'massKg' => self::r2($massKg),
            'capacityLitres' => round($capacitLiters),
            'materialCost' => self::r2($materialCost),
            'totalCost' => self::r2($totalCost),
            'density' => $density,
        ];
    }

    // ── Pipe library (schedule reference + takeoff) ─────

    /**
     * Nominal pipe sizes: DN → [OD mm, sch40 wall mm, sch80 wall mm].
     * Compact standard-steel schedule reference (ASTM B36.10 approximations).
     */
    const PIPE_TABLE = [
        '15'  => [21.3, 2.77, 3.73],
        '20'  => [26.9, 2.87, 3.91],
        '25'  => [33.7, 3.38, 4.55],
        '32'  => [42.4, 3.56, 4.85],
        '40'  => [48.3, 3.68, 5.08],
        '50'  => [60.3, 3.91, 5.54],
        '65'  => [76.1, 5.16, 7.01],
        '80'  => [88.9, 5.49, 7.62],
        '100' => [114.3, 6.02, 8.56],
        '150' => [168.3, 7.11, 10.97],
        '200' => [219.1, 8.18, 12.70],
        '250' => [273.0, 9.27, 15.09],
        '300' => [323.9, 10.31, 17.48],
    ];

    private function calcPipe($in)
    {
        $dn = (string)\getVal($in, 'nominalSize', '50');
        $schedule = (string)\getVal($in, 'schedule', '40');
        $lengthM = (float)\getVal($in, 'lengthM', 6);                  // metres per length
        $quantity = (float)\getVal($in, 'quantity', 1);
        $rate = (float)\getVal($in, 'materialRate', 25);
        $density = self::DENSITIES[\getVal($in, 'materialType', 'steel')] ?? self::DENSITIES['steel'];

        $row = self::PIPE_TABLE[$dn] ?? null;
        if (!$row) {
            return ['error' => "Unknown nominal size: DN$dn", 'error_code' => 400];
        }
        $od = $row[0];
        $wall = ($schedule === '80') ? $row[2] : $row[1];

        // Weight per metre: π × (OD − t) × t × density / 1e6  (kg/m)
        $weightPerM = M_PI * ($od - $wall) * $wall * $density / 1000000;
        $totalLength = $lengthM * $quantity;
        $totalWeight = $weightPerM * $totalLength;
        $materialCost = $totalWeight * $rate;

        return [
            'type' => 'pipe',
            'nominalSize' => 'DN' . $dn,
            'schedule' => 'Sch ' . $schedule,
            'od' => $od,
            'wall' => $wall,
            'weightPerM' => self::r2($weightPerM),
            'totalLength' => self::r2($totalLength),
            'totalWeight' => self::r2($totalWeight),
            'materialCost' => self::r2($materialCost),
            'totalCost' => self::r2($materialCost),
        ];
    }

    private static function r2($v) { return round((float)$v, 2); }
    private static function r6($v) { return round((float)$v, 6); }
}

\api\dispatchIfEntry(__FILE__);
