<?php
/**
 * fabricate_forge/api/cost.php
 *
 * CostSystem — the 5-layer cost engine, implemented as a pure ECS system.
 *
 * ECS contract: READ components → COMPUTE → WRITE a 'cost' component.
 * The system never stores cost outside the entity's own component graph, and
 * the UI never re-computes — it reads the written 'cost' component.
 *
 * 5-layer model (mirrors cost-system.js in the original app):
 *   L1 material   = massKg × unitCostPerKg × quantity
 *   L2 process    = Σ(hours × effectiveRate) × quantity  (Bm/W/M hrs exposed)
 *   L3 on-costs   = consumables + services + ndt + lining + paint (× quantity)
 *   L4 logistics  = transport (once per entity)
 *   L5 margin     = subtotal × marginPercent%
 *
 * Per-entity on-cost overrides (Cons/Serve/NDT/Lining/Paint/Transport) can be
 * stored on entity.data.onCosts (set from the quote UI); explicit options win.
 *
 * Rates: entity rate component → company defaults → GLOBAL_DEFAULT_RATES.
 */
namespace api;

include_once(__DIR__ . "/_base.php");
include_once(__DIR__ . "/rates.php");
include_once(__DIR__ . "/process.php");
include_once(__DIR__ . "/weldmodel.php");

class cost extends Base
{
    const DEFAULT_MARGIN_PERCENT = 30;

    /**
     * House on-cost policy: when an entity has NO on-costs configured, apply
     * defaults so Cons/Serve/NDT/Transport aren't silently zero. Set to false
     * to disable (items with explicit on-costs always keep their own values).
     */
    const APPLY_DEFAULT_ON_COSTS = true;

    // ── Estimating defaults (calibrate against your shop!) ──
    /** Pipe shop handling: BM hours per kg (handling/cutting/fitting fee). */
    const PIPE_SHOP_HRS_PER_KG = 0.05;
    /** Transport related to paint & lining: R per ton (in-house). */
    const TRANSPORT_PER_TON = 850;
    /** R/sqm paint & lining rates by execution mode (in-house vs subcontract). */
    const PAINT_RATES = [
        'inhouse' => [
            'ext' => 45.0, 'int' => 35.0, 'line' => 0.0,
            'coating1' => 0.0, 'coating2' => 0.0, 'coating3' => 0.0, 'coating4' => 0.0,
        ],
        'subcontract' => [
            'ext' => 65.0, 'int' => 55.0, 'line' => 0.0,
            'coating1' => 0.0, 'coating2' => 0.0, 'coating3' => 0.0, 'coating4' => 0.0,
        ],
    ];

    protected function buildTable()
    {
        $this->ensureEcsTables();
    }

    // ── ECS read helpers (component accessors) ─────────

    /**
     * Extract the material component data for an entity (first match).
     */
    private function getMaterialData($entityId)
    {
        $comps = $this->getComponents($entityId, 'material');
        return $comps[0]['data'] ?? [];
    }

    /**
     * Extract all process component data for an entity (merged hours).
     */
    private function getProcessHours($entityId)
    {
        $comps = $this->getComponents($entityId, 'process');
        $hours = [];
        foreach ($comps as $c) {
            $hours = \api\process::mergeHours($hours, $c['data'] ?? []);
        }
        return $hours;
    }

    /**
     * Look up a material library row (global or own).
     */
    private function getLibraryMaterial($materialId)
    {
        if (!$materialId) return null;
        $res = $this->pgCrud->read([
            'table' => 'material_library',
            'where' => 'id = $1 AND (user_id_owner IS NULL OR user_id_owner = $2)',
            'params' => [$materialId, $this->effOwnerId()],
            'limit' => 1,
        ]);
        return $res['data'][0] ?? null;
    }

    /**
     * Compute mass (kg) from a material component:
     *   - profile (mass_per_meter × length / 1000)
     *   - plate/sheet (L×W×T / 1e9 × density)
     *   - explicit mass field wins
     */
    private function calcMass($materialData, $libraryItem)
    {
        if (!empty($materialData['mass'])) return (float)$materialData['mass'];

        $density = (float)($libraryItem['density'] ?? $materialData['density'] ?? 0);
        $length = (float)($materialData['length'] ?? 0);
        $width = (float)($materialData['width'] ?? 0);
        $thickness = (float)($materialData['thickness'] ?? $libraryItem['thickness'] ?? 0);
        $category = $materialData['category'] ?? ($libraryItem['library_category'] ?? '');

        // Section/profile: mass_per_meter × length / 1000 (mm → m)
        $massPerMeter = (float)($libraryItem['mass_per_meter'] ?? 0);
        if ($massPerMeter > 0 && $length > 0) {
            return $massPerMeter * $length / 1000;
        }
        // Plate: volume (m³) × density
        if ($category === 'plate' && $length > 0 && $width > 0 && $thickness > 0 && $density > 0) {
            return $length * $width * $thickness / 1e9 * $density;
        }
        // Density-only fallback (approx cylinder/bar)
        if ($density > 0 && $length > 0 && $width > 0) {
            return $length * $width * $thickness / 1e9 * $density;
        }
        return 0.0;
    }

    // ── ECS compute + write ────────────────────────────

    /**
     * Calculate entity cost: READ components → COMPUTE 5 layers →
     * WRITE 'cost' component → return it.
     *
     * Input: { entity_id, options?: { consumables, services, paint,
     *                                  transport, margin_percent } }
     */
    public function handle_calculate_entity($input = [])
    {
        $entityId = \getVal($input, 'entity_id');
        if (!$entityId) return ['error' => 'entity_id is required.'];
        $entity = $this->getEntity($entityId);
        if (!$entity) return ['error' => 'Entity not found.', 'error_code' => 404];

        // Normalize entity.data — legacy rows may store it as a JSON array of
        // merged objects ([] + '||' merge bug). Fold lists into one object.
        $entityData = $entity['data'] ?? [];
        if (is_array($entityData) && isset($entityData[0]) && is_array($entityData[0])) {
            $folded = [];
            foreach ($entityData as $obj) {
                if (is_array($obj)) $folded = array_merge($folded, $obj);
            }
            $entityData = $folded;
        }
        $entity['data'] = $entityData;

        $options = \getVal($input, 'options', []);
        $quantity = (float)($entity['quantity'] ?? 1);
        // Margin precedence: line-item override (entity.data.marginPercent) →
        // quote-global (options.margin_percent, passed by load_quote from the
        // quote's data.marginPercent or the user's defaultMarkupPercent) →
        // DEFAULT_MARGIN_PERCENT.
        $itemMargin = $entityData['marginPercent'] ?? null;
        $marginPercent = $itemMargin !== null
            ? (float)$itemMargin
            : (float)\getVal($options, 'margin_percent', self::DEFAULT_MARGIN_PERCENT);

        // READ: material component → library → kind-aware costing.
        // kind = 'pipe' | 'fitting' | 'flange' | 'material' (plates/sections).
        $matData = $this->getMaterialData($entityId);
        $libraryItem = $this->getLibraryMaterial($matData['materialLibraryId'] ?? null);
        $libData = is_array($libraryItem['data'] ?? null) ? $libraryItem['data'] : [];
        $kind = $libData['kind'] ?? null;
        if (!$kind) {
            $libCat = $libraryItem['library_category'] ?? '';
            $profile = strtolower((string)($libraryItem['profile'] ?? ''));
            $kind = $libCat === 'flange' ? 'flange'
                : ($libCat === 'fitting' ? 'fitting'
                : ($libCat === 'fastener' ? 'fastener'
                : ($profile === 'pipe' ? 'pipe' : 'material')));
        }
        $unitCostPerKg = (float)($libraryItem['unit_cost'] ?? 0);

        // ── Unit-cost fallback for fittings/flanges ──
        // Library piping rows (fittings/flanges) carry mass but often no
        // unit_cost (seeded from reference data, not price lists). When that
        // happens, fall back to a per-kg rate by material type so the line
        // still prices sensibly. Users can always override with costPerEa on
        // the material component (the edititem form) for exact pricing.
        if ($unitCostPerKg <= 0 && ($kind === 'fitting' || $kind === 'flange' || $kind === 'pipe' || $kind === 'material')) {
            $materialType = strtolower((string)($libraryItem['material_type'] ?? $libraryItem['grade'] ?? ''));
            if (str_contains($materialType, 'stainless') || str_contains($materialType, '304') || str_contains($materialType, '316')) {
                $unitCostPerKg = 6.5;   // SS ~ R130/kg
            } elseif (str_contains($materialType, 'aluminum')) {
                $unitCostPerKg = 4.5;
            } else {
                $unitCostPerKg = 3.2;   // carbon steel ~ R65/kg
            }
        }

        // Per-item variables (captured on the material component by edititem)
        $lengthMm = (float)($matData['length'] ?? 0);
        $lengthM = $lengthMm / 1000;
        $buttWeldQty = (int)($matData['buttWeldQty'] ?? 0);
        $weldSizeOverride = (float)($matData['weldSize'] ?? 0);
        $costPerM = (float)($matData['costPerM'] ?? 0);        // pipe: R/m
        $costPerEa = (float)($matData['costPerEa'] ?? 0);      // fitting/flange: R/ea
        $shopHrsPerKg = (float)($matData['shopHrsPerKg'] ?? 0); // pipe: BM hrs/kg
        $pipeWtForWeld = (float)($matData['pipeWt'] ?? 0);     // flange: WT of pipe fitted to

        // Per-unit accumulators; totals are × quantity below.
        $massKg = 0.0; $matCost = 0.0; $bmHrs = 0.0; $wHrs = 0.0; $mHrs = 0.0;
        $extAreaM2 = 0.0; $intAreaM2 = 0.0;
        $weldSizeUsed = null; $weldLenM = 0.0;
        $buttLenM = 0.0; $filletLenM = 0.0;   // for weld-metal mass

        switch ($kind) {
            case 'pipe':
                // Core: kg/m + paint area/m (OD-based). Variables: cost R/m, butt weld qty.
                $kgPerM = (float)($libraryItem['mass_per_meter'] ?? $libData['kgPerM'] ?? 0);
                $od = (float)($libData['od'] ?? 0);
                $wt = (float)($libData['wt'] ?? 0);
                $massKg = $kgPerM * $lengthM;
                $matCost = ($costPerM > 0 ? $costPerM * $lengthM : $massKg * $unitCostPerKg) * $quantity;
                // Shop handling fee: BM hrs / kg
                $bmHrs = $massKg * ($shopHrsPerKg > 0 ? $shopHrsPerKg : self::PIPE_SHOP_HRS_PER_KG);
                // Butt welds: qty × π × OD, weld size next-up from WT
                if ($buttWeldQty > 0 && $od > 0) {
                    $weldLenM = $buttWeldQty * \api\weldmodel::buttLengthM($od);
                    $buttLenM = $weldLenM;
                    $weldSizeUsed = $weldSizeOverride > 0 ? $weldSizeOverride : \api\weldmodel::weldSizeFor($wt);
                    $wHrs = \api\weldmodel::buttWeldHours($wt, $weldLenM);
                }
                $extAreaM2 = (float)($libData['paintAreaPerM'] ?? 0) * $lengthM;
                $intAreaM2 = \api\weldmodel::pipeIntAreaM2($od, $wt, $lengthM);
                break;

            case 'fitting':
                // Core: mass ea, type, schedule/spec, area. Weld length = Σ end × π.
                $massKg = (float)($libData['massKg'] ?? 0);
                $wtRef = (float)($libData['wt'][0] ?? 0);
                $weldLenM = \api\weldmodel::fittingWeldLengthM($libData['od'] ?? [], $libData['weldCirc'] ?? []);
                $buttLenM = $weldLenM;
                $weldSizeUsed = $weldSizeOverride > 0 ? $weldSizeOverride : \api\weldmodel::weldSizeFor($wtRef);
                $wHrs = \api\weldmodel::buttWeldHours($wtRef, $weldLenM);
                $extAreaM2 = (float)($libData['extArea'] ?? 0);
                $intAreaM2 = \api\weldmodel::fittingIntAreaM2($libData['od'] ?? [], $libData['wt'] ?? [], $libData['dims'] ?? []);
                $matCost = ($costPerEa > 0 ? $costPerEa : $massKg * $unitCostPerKg) * $quantity;
                break;

            case 'flange':
                // Core: mass ea, type (WN/SO/SW/BLIND/LOOSE), area, pipe OD.
                // Weld from type — a LOOSE/BLIND flange is bolted, not welded.
                // The material component's weldType overrides the library type
                // (so a loose flange on a closure prices with ZERO welds).
                $massKg = (float)($libData['massKg'] ?? 0);
                $ftype = strtoupper((string)($matData['weldType'] ?? $libData['type'] ?? ''));
                $pipeOd = (float)($libData['pipeOd'] ?? 0);
                $wtForWeld = $pipeWtForWeld > 0 ? $pipeWtForWeld : (float)($matData['thickness'] ?? 0);
                $weldSizeUsed = $weldSizeOverride > 0 ? $weldSizeOverride : \api\weldmodel::weldSizeFor($wtForWeld);
                $wl = \api\weldmodel::flangeWeldLengthM($ftype, $pipeOd);
                $weldLenM = $wl['butt'] + $wl['fillet'];
                $buttLenM = $wl['butt'];
                $filletLenM = $wl['fillet'];
                $wHrs = \api\weldmodel::buttWeldHours($wtForWeld, $wl['butt'])
                      + \api\weldmodel::filletWeldHours($weldSizeUsed, $wl['fillet']);
                $extAreaM2 = (float)($libData['paintArea'] ?? 0);
                $matCost = ($costPerEa > 0 ? $costPerEa : $massKg * $unitCostPerKg) * $quantity;
                break;

            case 'fastener':
                // Fasteners are priced PER ITEM (unit_cost is R/ea in the
                // library), not by mass. costPerEa on the material component
                // overrides the library price.
                $massKg = (float)($libData['massKg'] ?? $matData['mass'] ?? 0);
                $matCost = ($costPerEa > 0 ? $costPerEa : $unitCostPerKg) * $quantity;
                break;

            default:
                // Plates / sections / generic — mass-based as before.
                $massKg = $this->calcMass($matData, $libraryItem);
                $matCost = $massKg * $unitCostPerKg * $quantity;
                $extAreaM2 = ($lengthMm > 0 && (float)($matData['width'] ?? 0) > 0)
                    ? $lengthMm * (float)$matData['width'] / 1e6 : 0.0;
                break;
        }

        // Weld metal mass (kg) — butt + fillet cross-section × length × steel
        // density. Surfaces how much weld metal each joint consumes (drives
        // electrode/wire usage for consumables). Per unit; × quantity later.
        $weldMetalKg = 0.0;
        if ($buttLenM > 0 || $filletLenM > 0) {
            $weldMetalKg = (\api\weldmodel::buttAreaPerM((float)($wtForWeld ?? $wt ?? 0)) * $buttLenM
                          + \api\weldmodel::filletAreaPerM((float)($weldSizeUsed ?? 0)) * $filletLenM) * 7850 / 1e6;
        }

        // READ: process hours → price via rate hierarchy.
        // Auto hours (weld model + shop handling) merge with manual hours from the form.
        $hours = $this->getProcessHours($entityId);
        $rates = $this->getAllEffectiveRates($entityId);
        $processItems = \api\process::extractItems($hours); // $hours is a named-field map {trade: hrs}

        $allHours = ['boilermaking' => $bmHrs, 'welding' => $wHrs, 'machining' => $mHrs];
        foreach ($processItems as $it) {
            $allHours[$it['name']] = ($allHours[$it['name']] ?? 0) + $it['time'];
        }
        $perTrade = []; $processTotal = 0.0;
        foreach ($allHours as $trade => $hrs) {
            if ($hrs <= 0) continue;
            $rate = $rates[$trade]['rate'] ?? 0;
            $cost = round($hrs * $rate * $quantity, 2);
            $perTrade[$trade] = $cost;
            $processTotal += $cost;
        }
        $labTotal = $processTotal;
        $bmHrs = $allHours['boilermaking'] ?? 0;
        $wHrs = $allHours['welding'] ?? 0;
        $mHrs = $allHours['machining'] ?? 0;

        // L3 on-costs. Paint & lining are DERIVED from core areas × R/sqm rates,
        // with in-house vs subcontract selection; flat per-item overrides win.
        $onCosts = $entity['data']['onCosts'] ?? [];
        $paintingOpts = $onCosts['painting'] ?? [];
        // Paint & lining only apply when explicitly configured on the item
        // (mode chosen or any rate entered) — otherwise stay 0.
        $paintConfigured = !empty($paintingOpts) || !empty($entityData['paintMode']);
        $paintMode = $paintingOpts['mode'] ?? $entityData['paintMode'] ?? 'inhouse';
        $paintMode = in_array($paintMode, ['inhouse', 'subcontract'], true) ? $paintMode : 'inhouse';
        $rateSet = self::PAINT_RATES[$paintMode];

        $extPaintRate = (float)\getVal($paintingOpts, 'extPaint', $rateSet['ext']);
        $intPaintRate = (float)\getVal($paintingOpts, 'intPaint', $rateSet['int']);
        $lineRate = (float)\getVal($paintingOpts, 'line', $rateSet['line']);
        $coatingSum = 0.0;
        foreach (['coating1', 'coating2', 'coating3', 'coating4'] as $c) {
            $coatingSum += (float)\getVal($paintingOpts, $c, $rateSet[$c]);
        }
        $useSurface = $extPaintRate > 0 || $intPaintRate > 0 || $lineRate > 0 || $coatingSum > 0;

        // Painting = ext + int paint; Lining = internal line + coatings 1-4
        $paint = ($paintConfigured && $useSurface) ? ($extAreaM2 * $extPaintRate + $intAreaM2 * $intPaintRate) * $quantity : 0.0;
        $lining = ($paintConfigured && $useSurface) ? $intAreaM2 * ($lineRate + $coatingSum) * $quantity : 0.0;
        if (isset($onCosts['paint'])) $paint = (float)$onCosts['paint'] * $quantity;
        if (isset($onCosts['lining'])) $lining = (float)$onCosts['lining'] * $quantity;

        // Flat per-unit on-costs (× quantity below). When an item has NO
        // on-costs configured at all, apply the house default policy so the
        // Cons/Serve/NDT columns aren't silently zero:
        //   consumables  ≈ 2.5% of (material + process)
        //   services     ≈ 1% of material
        //   ndt          ≈ 1.5% of process (piping QC) — 0 with no hours
        //   transport    ≈ TRANSPORT_PER_TON × mass
        $consumablesRaw = \getVal($options, 'consumables', $onCosts['consumables'] ?? null);
        $servicesRaw = \getVal($options, 'services', $onCosts['services'] ?? null);
        $ndtRaw = \getVal($options, 'ndt', $onCosts['ndt'] ?? null);
        $applyDefaultPolicy = empty($onCosts) && !$paintConfigured && self::APPLY_DEFAULT_ON_COSTS;
        if ($applyDefaultPolicy) {
            $matPerUnit = $quantity > 0 ? $matCost / $quantity : 0;
            $procPerUnit = $quantity > 0 ? $processTotal / $quantity : 0;
            if ($consumablesRaw === null) $consumablesRaw = ($matPerUnit + $procPerUnit) * 0.025;
            if ($servicesRaw === null) $servicesRaw = $matPerUnit * 0.01;
            if ($ndtRaw === null) $ndtRaw = $procPerUnit * 0.015;
        }
        $consumables = (float)($consumablesRaw ?? 0) * $quantity;
        $services = (float)($servicesRaw ?? 0) * $quantity;
        $ndt = (float)($ndtRaw ?? 0) * $quantity;

        // L4 transport — R/ton × mass; once per entity. Charges on its own when
        // the default policy is active (delivery to site is in the BoQ rate).
        $transportMode = $paintingOpts['transportMode'] ?? $paintMode;
        $transportRatePerTon = (float)\getVal($paintingOpts, 'transportPerTon',
            self::TRANSPORT_PER_TON * ($transportMode === 'subcontract' ? 1.35 : 1.0));
        $transportConfigured = $paintConfigured || isset($paintingOpts['transportPerTon']) || $applyDefaultPolicy;
        $transportByTon = $transportConfigured ? ($massKg * $quantity) / 1000 * $transportRatePerTon : 0.0;
        if (isset($onCosts['transport'])) $transportByTon = (float)$onCosts['transport'];
        $transport = (float)\getVal($options, 'transport', $transportByTon);

        // Subtotal + L5 margin
        $subtotal = $matCost + $processTotal + $consumables + $services + $ndt + $lining + $paint + $transport;
        $margin = $subtotal * ($marginPercent / 100);
        $total = $subtotal + $margin;

        // The full cost component payload
        $costData = [
            'material' => self::r2($matCost),
            'matCost' => self::r2($matCost),
            'massKg' => self::r2($massKg),
            // Process hours (for the quote grid: Bm/W/M hrs)
            'boilerHrs' => self::r2($bmHrs),
            'weldHrs' => self::r2($wHrs),
            'machHrs' => self::r2($mHrs),
            'bmHrs' => self::r2($bmHrs),
            'wHrs' => self::r2($wHrs),
            'mHrs' => self::r2($mHrs),
            'processTotal' => self::r2($processTotal),
            'labor' => self::r2($labTotal), // alias — Lab cost = Σ(hours × rate)
            'labCost' => self::r2($labTotal),
            'boilermaking' => self::r2($perTrade['boilermaking'] ?? 0),
            'welding' => self::r2($perTrade['welding'] ?? 0),
            'machining' => self::r2($perTrade['machining'] ?? 0),
            'cutting' => self::r2($perTrade['cutting'] ?? 0),
            'drilling' => self::r2($perTrade['drilling'] ?? 0),
            'grinding' => self::r2($perTrade['grinding'] ?? 0),
            'bending' => self::r2($perTrade['bending'] ?? 0),
            'assembly' => self::r2($perTrade['assembly'] ?? 0),
            'consumables' => self::r2($consumables),
            'services' => self::r2($services),
            'ndt' => self::r2($ndt),
            'lining' => self::r2($lining),
            'paint' => self::r2($paint),
            'painting' => self::r2($paint),
            'transport' => self::r2($transport),
            'cons' => self::r2($consumables),
            'serve' => self::r2($services),
            'total' => self::r2($total),
            'margin' => self::r2($margin),
            'marginPercent' => $marginPercent,
            'subtotal' => self::r2($subtotal),
            'total' => self::r2($total),
            'unitCost' => self::r2($quantity ? $total / $quantity : 0),
            'currency' => $entity['data']['currency'] ?? 'USD',
            'lastUpdated' => date('c'),
            'details' => [
                'quantity' => $quantity,
                'materialUnit' => self::r2($unitCostPerKg),
                'materialMass' => self::r2($massKg),
                'kind' => $kind,
                'weldSize' => $weldSizeUsed,
                'weldLengthM' => self::r2($weldLenM),
                'weldMetalKg' => self::r2($weldMetalKg),
                'weldType' => isset($ftype) ? $ftype : null,
                'extArea' => self::r2($extAreaM2),
                'intArea' => self::r2($intAreaM2),
                'paintMode' => $paintMode,
                'transportPerTon' => self::r2($transportRatePerTon),
            ],
        ];

        // WRITE: upsert the 'cost' component on the entity
        $compId = $this->upsertCostComponent($entityId, $costData);

        return ['component_id' => $compId, 'entity_id' => $entityId, 'data' => $costData];
    }

    /**
     * Assembly cost: own cost + recursive BOM rollup (children × quantity).
     * WRITE: child costs are their own cost components (computed recursively);
     * the assembly's cost component includes the rolled-up totals.
     */
    public function handle_calculate_assembly($input = [])
    {
        $entityId = \getVal($input, 'entity_id');
        if (!$entityId) return ['error' => 'entity_id is required.'];
        $maxDepth = (int)\getVal($input, 'depth', 10);

        $tree = $this->buildCostTree($entityId, 0, $maxDepth);

        // Roll up: each child's total × link quantity, summed at each level
        $this->rollUp($tree);

        return $tree;
    }

    /**
     * Batch: calculate cost for many entities in one call (kills N+1).
     * Input: { entity_ids: [...], options?: { margin_percent, consumables, … } }
     * — writes cost components on each.
     */
    public function handle_batch_calculate($input = [])
    {
        $ids = \getVal($input, 'entity_ids', []);
        if (!is_array($ids) || empty($ids)) return ['error' => 'entity_ids (array) is required.'];

        $options = \getVal($input, 'options', []);
        $results = [];
        foreach ($ids as $id) {
            $r = $this->handle_calculate_entity(['entity_id' => $id, 'options' => $options]);
            if (isset($r['error'])) {
                $results[$id] = $r;
            } else {
                $results[$id] = $r['data'];
            }
        }
        return $results;
    }

    /**
     * Read the written cost component for an entity (no recompute).
     * Input: { entity_id }
     */
    public function handle_get_cost($input = [])
    {
        $entityId = \getVal($input, 'entity_id');
        if (!$entityId) return ['error' => 'entity_id is required.'];

        $comps = $this->getComponents($entityId, 'cost');
        if (!$comps) return ['error' => 'No cost component yet — run calculate_entity first.', 'error_code' => 404];
        return $comps[0]['data'];
    }

    // ── Internal ───────────────────────────────────────

    /**
     * Upsert the cost component: update if one exists, else create.
     */
    private function upsertCostComponent($entityId, $costData)
    {
        $comps = $this->getComponents($entityId, 'cost');
        if ($comps) {
            $this->patchComponentData($comps[0]['id'], $costData);
            return $comps[0]['id'];
        }
        $entity = $this->getEntity($entityId);
        $res = $this->pgCrud->save([
            'table' => 'component',
            'data' => [
                'entity_id' => $entityId,
                'type' => 'cost',
                'data' => $costData,
                'quote_id' => $entity['quote_id'] ?? null,
                'user_id_owner' => $this->effOwnerId(),
            ],
        ]);
        return $res['data']['id'] ?? null;
    }

    /**
     * Build a nested cost tree: each node = entity snapshot + its own cost.
     */
    private function buildCostTree($entityId, $depth, $maxDepth, $visited = [])
    {
        if ($depth > $maxDepth || in_array($entityId, $visited)) return null;
        $visited[] = $entityId;

        $entity = $this->getEntity($entityId);
        if (!$entity) return null;

        $own = $this->handle_calculate_entity(['entity_id' => $entityId]);
        $node = [
            'id' => $entity['id'],
            'name' => $entity['name'],
            'type' => $entity['type'],
            'quantity' => (float)($entity['quantity'] ?? 1),
            'own_cost' => $own['data'] ?? null,
            'children' => [],
        ];

        $links = $this->getLinks($entityId, 'contains');
        foreach ($links['out'] as $link) {
            $child = $this->buildCostTree($link['to_id'], $depth + 1, $maxDepth, $visited);
            if ($child) {
                $child['link_quantity'] = (float)($link['quantity'] ?? 1);
                $node['children'][] = $child;
            }
        }
        return $node;
    }

    /**
     * Post-order rollup: child total × link quantity accumulates into parent.
     */
    private function rollUp(&$node)
    {
        $rollup = 0.0;
        foreach ($node['children'] as &$child) {
            $this->rollUp($child);
            $childTotal = (float)($child['rolled_total'] ?? $child['own_cost']['total'] ?? 0);
            $rollup += $childTotal * (float)($child['link_quantity'] ?? 1);
        }
        $ownTotal = (float)($node['own_cost']['total'] ?? 0);
        $node['rolled_total'] = self::r2($ownTotal + $rollup);
        return $node;
    }

    private function getAllEffectiveRates($entityId)
    {
        $ratesApi = new \api\rates();
        $ratesApi->user_id = $this->effOwnerId();
        return $ratesApi->handle_get_all_effective(['entity_id' => $entityId]);
    }

    /** Round to 2 decimals (mirrors round2 util). */
    public static function r2($n)
    {
        return round((float)$n, 2);
    }
}

\api\dispatchIfEntry(__FILE__);
