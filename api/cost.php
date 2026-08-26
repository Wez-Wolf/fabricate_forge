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

    /** Transport related to paint & lining: R per ton (in-house). */
    const TRANSPORT_PER_TON = 850;
    /** R/sqm paint & lining rates by execution mode (in-house vs subcontract). */
    const PAINT_RATES = [
        'inhouse' => [
            'ext' => 45.0, 'int' => 35.0, 'line' => 0.0,
        ],
        'subcontract' => [
            'ext' => 65.0, 'int' => 55.0, 'line' => 0.0,
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
     * Look up a material (shared reference data — reads are global).
     * Materials are entities (type='material') with specification/dimensions/
     * rate components; the legacy material_library row shape is reconstructed
     * so the kind-aware costing below reads `data.*` exactly as before.
     */
    private function getLibraryMaterial($materialId)
    {
        if (!$materialId) return null;
        $m = $this->getMaterialEntity($materialId);
        if (!$m) return null;
        return $this->materialRowShape($m['entity'], $m['comps']);
    }

    // ── ECS compute + write ────────────────────────────

    /**
     * Compute material cost from COMPONENT DATA ONLY — no entity kinds.
     * Pricing precedence (first match wins):
     *   1. costPerEa  → bought-out item:  costPerEa × quantity
     *   2. costPerM   → linear stock:     costPerM × lengthM × quantity
     *   3. otherwise  → mass-based:       massKg × unitCostPerKg × quantity
     * Hours are NEVER derived here (except shop handling when the material
     * comp explicitly carries shopHrsPerKg) — all other hours come from
     * process components entered by the estimator.
     *
     * @return array{massKg,matCost,bmHrs,wHrs,mHrs,extAreaM2,intAreaM2,weldLenM,buttLenM,filletLenM,weldSizeUsed,od,wt,weldType,unitCostPerKg}
     */
    private function priceMaterial($matData, $libraryItem, $quantity, $lengthMm)
    {
        $libData = is_array($libraryItem['data'] ?? null) ? $libraryItem['data'] : [];
        $unitCostPerKg = (float)($libraryItem['unit_cost'] ?? 0);
        $lengthM = $lengthMm / 1000;

        // Per-item variables (captured on the material component by edititem)
        $weldSizeOverride = (float)($matData['weldSize'] ?? 0);
        $costPerM = (float)($matData['costPerM'] ?? 0);
        $costPerEa = (float)($matData['costPerEa'] ?? 0);
        $shopHrsPerKg = (float)($matData['shopHrsPerKg'] ?? 0);

        // Mass from the mass system (entity-agnostic physics). The caller
        // passes the TOTAL cut length (primary + D1 green secondary) so every
        // pricing path — costPerM, areas, AND mass — sees the same length.
        $massResult = self::massCompute($matData, $libraryItem, $lengthMm);
        $massKg = $massResult['massKg'];
        $od = $massResult['od'];
        $wt = $massResult['wt'];

        // Unit-cost fallback when the library row carries no price: estimate
        // per-kg from the material TYPE (a material property, not an entity kind).
        if ($unitCostPerKg <= 0) {
            $materialType = strtolower((string)($libraryItem['material_type'] ?? $libraryItem['grade'] ?? ''));
            if (str_contains($materialType, 'stainless') || str_contains($materialType, '304') || str_contains($materialType, '316')) $unitCostPerKg = 6.5;
            elseif (str_contains($materialType, 'aluminum')) $unitCostPerKg = 4.5;
            else $unitCostPerKg = 3.2;
        }

        // ── Material cost: data-driven precedence, no entity kinds. ──
        if ($costPerEa > 0) {
            $matCost = $costPerEa * $quantity;                       // bought-out
        } elseif ($costPerM > 0 && $lengthM > 0) {
            $matCost = $costPerM * $lengthM * $quantity;             // linear stock
        } else {
            $matCost = $massKg * $unitCostPerKg * $quantity;         // mass-based
        }

        // Shop handling: BM hrs/kg ONLY when explicitly carried on the
        // material component (any item). No implicit per-kind defaults —
        // every other hour comes from process components.
        $bmHrs = $shopHrsPerKg > 0 ? $massKg * $shopHrsPerKg : 0.0;

        // ── Surface areas: sum whichever data fields exist. ──
        $extAreaM2 = 0.0;
        if (!empty($libData['paintAreaPerM']) && $lengthM > 0) $extAreaM2 += (float)$libData['paintAreaPerM'] * $lengthM;
        if (!empty($libData['paintArea'])) $extAreaM2 += (float)$libData['paintArea'];
        if (!empty($libData['extArea'])) $extAreaM2 += (float)$libData['extArea'];
        if ($lengthMm > 0 && (float)($matData['width'] ?? 0) > 0) $extAreaM2 += $lengthMm * (float)$matData['width'] / 1e6;
        // Internal area: cylindrical bore when OD/WT/length are known (geometry).
        $intAreaM2 = ($od > 0 && $wt > 0 && $lengthM > 0)
            ? \api\weldmodel::pipeIntAreaM2($od, $wt, $lengthM) : 0.0;

        // ── Weld metadata (display only — never drives hours). ──
        $weldSizeUsed = $weldSizeOverride > 0 ? $weldSizeOverride : (($wt > 0) ? \api\weldmodel::weldSizeFor($wt) : null);
        $weldLenM = 0.0; $buttLenM = 0.0; $filletLenM = 0.0;
        if (!empty($libData['od']) || !empty($libData['weldCirc'])) {
            $weldLenM = \api\weldmodel::fittingWeldLengthM($libData['od'] ?? [], $libData['weldCirc'] ?? []);
            $buttLenM = $weldLenM;
        }
        $ftype = strtoupper((string)($matData['weldType'] ?? $libData['type'] ?? '')) ?: null;

        return [
            'massKg' => $massKg, 'matCost' => $matCost, 'bmHrs' => $bmHrs,
            'wHrs' => 0.0, 'mHrs' => 0.0, 'extAreaM2' => $extAreaM2,
            'intAreaM2' => $intAreaM2, 'weldLenM' => $weldLenM,
            'buttLenM' => $buttLenM, 'filletLenM' => $filletLenM,
            'weldSizeUsed' => $weldSizeUsed, 'od' => $od, 'wt' => $wt,
            'weldType' => $ftype,
            'unitCostPerKg' => $unitCostPerKg,
        ];
    }

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

        // Memoization: if the cost component is fresh (entity + driving
        // components unchanged since last calc), return it without recomputing.
        // Watchers trigger recalculateUpward on mutation, so by the time we get
        // here the entity is usually stale — but during an upward walk we may
        // reach a parent whose own inputs are unchanged (only a child changed),
        // and we skip its own calc while still rolling it up.
        if ($this->isEntityCostFresh($entityId)) {
            $existing = $this->getComponents($entityId, 'cost');
            if ($existing) {
                return ['data' => $existing[0]['data'], 'component_id' => $existing[0]['id']];
            }
        }

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
        $itemMargin = $entityData['marginPercent'] ?? null;
        $marginPercent = $itemMargin !== null
            ? (float)$itemMargin
            : (float)\getVal($options, 'margin_percent', self::DEFAULT_MARGIN_PERCENT);

        // READ: material component → library. Costing is data-driven off the
        // component fields (costPerEa / costPerM / mass × rate) — no entity
        // kinds. The library category is kept ONLY as a display label.
        $matData = $this->getMaterialData($entityId);
        $libraryItem = $this->getLibraryMaterial($matData['materialLibraryId'] ?? null);
        $libData = is_array($libraryItem['data'] ?? null) ? $libraryItem['data'] : [];
        $kind = ($libData['kind'] ?? '')
            ?: (($libraryItem['library_category'] ?? '') ?: (strtolower((string)($libraryItem['profile'] ?? '')) ?: 'material'));

        // Primary length from material data; D1 green secondary length (if set)
        // is extra length for material cost calculation.
        $lengthMm = (float)($matData['length'] ?? 0);
        $secondaryLengthMm = (float)($matData['length_secondary'] ?? 0);
        $totalLengthMm = $lengthMm + $secondaryLengthMm;
        $m = $this->priceMaterial($matData, $libraryItem, $quantity, $totalLengthMm);
        $massKg = $m['massKg']; $matCost = $m['matCost'];
        $bmHrs = $m['bmHrs']; $wHrs = $m['wHrs']; $mHrs = $m['mHrs'];
        $extAreaM2 = $m['extAreaM2']; $intAreaM2 = $m['intAreaM2'];
        $weldLenM = $m['weldLenM']; $buttLenM = $m['buttLenM']; $filletLenM = $m['filletLenM'];
        $weldSizeUsed = $m['weldSizeUsed']; $wt = $m['wt'];
        $ftype = $m['weldType'];
        $unitCostPerKg = $m['unitCostPerKg'];

        // Explicit surface areas on the material component override derived areas.
        if (isset($matData['extArea'])) $extAreaM2 = (float)$matData['extArea'];
        if (isset($matData['intArea'])) $intAreaM2 = (float)$matData['intArea'];

        // Weld metal mass (kg) — butt + fillet cross-section × length × steel density.
        $weldMetalKg = 0.0;
        if ($buttLenM > 0 || $filletLenM > 0) {
            $weldMetalKg = (\api\weldmodel::buttAreaPerM($wt) * $buttLenM
                          + \api\weldmodel::filletAreaPerM((float)($weldSizeUsed ?? 0)) * $filletLenM) * 7850 / 1e6;
        }

        // ── PROCESS FRAGMENT: the process system prices its own hours. ──
        // Material-side shop handling (shopHrsPerKg on the material comp)
        // merges into boilermaking hours before pricing.
        $hours = \api\process::hoursForEntity($entityId, $this);
        error_log("FRAGDBG hours=" . json_encode($hours) . " entity=$entityId");
        if (($m['bmHrs'] ?? 0) > 0) {
            $hours['boilermaking'] = ($hours['boilermaking'] ?? 0) + (float)$m['bmHrs'];
        }
        $rates = $this->getAllEffectiveRates($entityId);
        $proc = \api\process::pricedFragment($hours, $rates, $quantity);

        $allHours = $proc['hours'];
        $perTrade = $proc;
        $processTotal = $proc['processTotal'];
        $labTotal = $proc['labor'];
        $bmHrs = $proc['boilerHrs'];
        $wHrs = $proc['weldHrs'];
        $mHrs = $proc['machHrs'];

        // L3 on-costs. Paint & lining are DERIVED from core areas × R/sqm rates,
        // with in-house vs subcontract selection; flat per-item overrides win.
        $onCosts = $entity['data']['onCosts'] ?? [];
        $paintingOpts = $onCosts['painting'] ?? [];
        $liningOpts = $onCosts['lining'] ?? [];
        // Paint & lining only apply when explicitly configured on the item
        // (mode chosen or any rate entered) — otherwise stay 0. Lining config
        // (onCosts.lining) is a sibling of painting, not nested under it.
        $paintConfigured = !empty($paintingOpts) || !empty($liningOpts) || !empty($entityData['paintMode']);
        $paintMode = $paintingOpts['mode'] ?? $entityData['paintMode'] ?? 'inhouse';
        $paintMode = in_array($paintMode, ['inhouse', 'subcontract'], true) ? $paintMode : 'inhouse';
        $rateSet = self::PAINT_RATES[$paintMode];

        $extPaintRate = (float)\getVal($paintingOpts, 'extPaint', $rateSet['ext']);
        $intPaintRate = (float)\getVal($paintingOpts, 'intPaint', $rateSet['int']);
        $lineRate = (float)\getVal($liningOpts, 'line', \getVal($paintingOpts, 'line', $rateSet['line']));
        $useSurface = $extPaintRate > 0 || $intPaintRate > 0 || $lineRate > 0;

        // Painting = ext + int paint; Lining = internal line
        $paint = ($paintConfigured && $useSurface) ? ($extAreaM2 * $extPaintRate + $intAreaM2 * $intPaintRate) * $quantity : 0.0;
        $lining = ($paintConfigured && $useSurface) ? $intAreaM2 * $lineRate * $quantity : 0.0;
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
        // House defaults only kick in when the caller specified NO on-cost at
        // all. If any on-cost option was passed explicitly, it's an explicit
        // spec — leave the unpassed ones at zero (the 5-layer contract).
        $explicitOnCostOpts = $consumablesRaw !== null || $servicesRaw !== null || $ndtRaw !== null;
        // House default policy applies only to FABRICATED items — i.e. items
        // with process hours (bought-outs carry no process comps, so they get
        // no defaults). Generic: data-driven, no entity-type check.
        $hasProcessHours = array_sum($allHours) > 0;
        $applyDefaultPolicy = !$explicitOnCostOpts && empty($onCosts) && !$paintConfigured && self::APPLY_DEFAULT_ON_COSTS && $hasProcessHours;
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
                // component_id rides along so orchestration (recalculate_entity)
                // can PATCH rolled values onto the exact comp it just wrote.
                // (Without it, patchComponentData(null) silently no-ops and
                // rolled totals never persist.)
                $results[$id]['component_id'] = $r['component_id'] ?? null;
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

    /**
     * Read the cost components for a SET of entity ids in one query.
     * Returns { entityId: costData } (only entities that HAVE a cost comp).
     * This is the ONE seam components/quotes/reports use to project cost onto
     * rows — they must not filter `type='cost'` comps themselves (cost ADR).
     * Pure read: never computes or writes.
     */
    public function get_costs_by_entities($entityIds)
    {
        if (!$entityIds) return [];
        $ids = array_values(array_unique(array_filter($entityIds)));
        if (!$ids) return [];
        $res = $this->pgCrud->read([
            'table' => 'component',
            'where' => 'entity_id = ANY($1::uuid[]) AND type = $2 AND user_id_owner = $3',
            'params' => ['{' . implode(',', $ids) . '}', 'cost', $this->effOwnerId()],
        ]);
        $out = [];
        foreach (($res['data'] ?? []) as $c) {
            if (!empty($c['entity_id'])) $out[$c['entity_id']] = $c['data'] ?? [];
        }
        return $out;
    }

    /**
     * Delete all cost components for an entity root's member entities — the
     * destructive reset used by recalculate_entity. Owned here (cost ADR) so
     * no caller runs DELETE on the component table.
     * Input: { entity_id }
     */
    public function clear_entity_costs($entityId)
    {
        if (!$entityId) return ['error' => 'entity_id is required.'];
        $this->pgCrud->execute(
            "DELETE FROM component
             WHERE type = 'cost'
               AND user_id_owner = \$1
               AND (quote_id = \$2 OR entity_id IN (
                   SELECT id FROM entity WHERE quote_id = \$2 AND user_id_owner = \$1
               ))",
            [$this->effOwnerId(), $entityId]
        );
        return true;
    }

    /**
     * Patch (merge) fields onto an entity's cost component — the incremental
     * write seam (used by recalculate_entity to attach rolled totals). Pure
     * write; no-op if the entity has no cost comp yet.
     * Input: { entity_id, patch }
     */
    public function patch_entity_cost($entityId, $patch)
    {
        if (!$entityId || !is_array($patch) || empty($patch)) return null;
        $comps = $this->getComponents($entityId, 'cost');
        if (!$comps) return null;
        $this->patchComponentData($comps[0]['id'], $patch);
        return $comps[0]['id'];
    }

    /**
     * Write/upsert a cost component — the ONE write seam. Non-cost code that
     * persists cost (e.g. systems.php recalc's root totals) calls THIS instead
     * of touching the component table directly (cost ADR).
     * Input: { entity_id, data, quote_id? }
     */
    public function write_entity_cost($entityId, $costData, $quoteId = null)
    {
        if (!$entityId) return ['error' => 'entity_id is required.'];
        $comps = $this->getComponents($entityId, 'cost');
        if ($comps) {
            $this->patchComponentData($comps[0]['id'], $costData);
            return $comps[0]['id'];
        }
        $res = $this->pgCrud->save([
            'table' => 'component',
            'data' => [
                'entity_id' => $entityId,
                'type' => 'cost',
                'data' => $costData,
                'quote_id' => $quoteId,
                'user_id_owner' => $this->effOwnerId(),
            ],
        ]);
        return $res['data']['id'] ?? null;
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
}

\api\dispatchIfEntry(__FILE__);
