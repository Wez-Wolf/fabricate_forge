<?php
/**
 * fabricate_forge/api/weldmodel.php
 *
 * Pure weld / costing math — no DB, no auth. Used by cost.php to derive
 * process hours for pipe, flanges and pipe fittings from CORE data:
 *
 *   Weld size   — next size UP from actual wall thickness (WT), from:
 *                 [3, 4, 5, 6, 8, 10, 12, 16, 20, 25, 30, 35, 40, 45, 50] mm
 *   Weld length — end × π:  pipe butt = qty × π × OD
 *                           fitting   = Σ(π × OD) per fitted end
 *                           flange    = WN: 1 × butt, SO: 2 × fillet,
 *                                       SW: 1 × fillet, BLIND/SCRD: none
 *   Weld hours  — deposition-based:
 *                           weld metal kg = weld cross-section × length × ρ
 *                           < 6 mm  → all TIG
 *                           ≥ 6 mm  → TIG root + fill, MIG/FCAW cap
 *
 * The deposition rates are ESTIMATING DEFAULTS — calibrate DEP_* against
 * your shop's actual deposition rates. Everything is a static const so it
 * can be tuned in one place.
 */
namespace api;

class weldmodel
{
    /** Weld size range (mm) — next size up from actual WT. */
    const WELD_SIZES = [3, 4, 5, 6, 8, 10, 12, 16, 20, 25, 30, 35, 40, 45, 50];

    /** Steel density (kg per mm³) — 7850 kg/m³. */
    const STEEL_KG_PER_MM3 = 0.00000785;

    /** Deposition rates kg/hr (estimating defaults — calibrate!). */
    const DEP_TIG = 0.8;   // root / hot pass, thin wall
    const DEP_MIG = 2.5;   // fill + cap (MIG / FCAW)

    /** Butt-weld profile: single-V cross-section ≈ 0.85 × t² (mm²). */
    const BUTT_AREA_FACTOR = 0.85;

    /** TIG share of weld metal when wall ≥ 6 mm (root + hot + fill). */
    const TIG_SHARE_OVER_6 = 0.35;

    /** Threshold: below = all TIG; at/above = TIG root + MIG/FCAW cap. */
    const TIG_THRESHOLD_MM = 6;

    // ── Weld size ─────────────────────────────────────

    /**
     * Next weld size UP from actual WT. WT of 3.91 → 4; 5.49 → 6; 10.31 → 12.
     * Returns null when wt <= 0 (nothing to weld).
     */
    public static function weldSizeFor($wt)
    {
        $wt = (float)$wt;
        if ($wt <= 0) return null;
        foreach (self::WELD_SIZES as $s) {
            if ($wt <= $s) return $s;
        }
        return end(self::WELD_SIZES);
    }

    // ── Weld length (m) ───────────────────────────────

    /** Butt-joint weld length (m) for one end of pipe OD (mm): π × OD / 1000. */
    public static function buttLengthM($odMm)
    {
        return M_PI * (float)$odMm / 1000;
    }

    /**
     * Total butt weld length (m) for a fitting: Σ π × OD over its fitted ends.
     * Falls back to the stored weld-circumference data (already π × OD in m).
     */
    public static function fittingWeldLengthM($ods, $weldCircM = [])
    {
        $len = 0.0;
        $ods = is_array($ods) ? $ods : [];
        for ($i = 0; $i < count($ods); $i++) {
            $od = (float)($ods[$i] ?? 0);
            if ($od <= 0) continue;
            $circ = isset($weldCircM[$i]) ? (float)$weldCircM[$i] : 0;
            $len += $circ > 0 ? $circ : self::buttLengthM($od);
        }
        return $len;
    }

    /**
     * Flange weld lengths by flange type (m).
     *   WN   → 1 × butt weld        (pipe OD)
     *   SO   → 2 × fillet welds     (inside + outside hub)
     *   SW   → 1 × fillet weld      (socket)
     *   BLIND / SCRD / other → none
     */
    public static function flangeWeldLengthM($type, $odMm)
    {
        $butt = self::buttLengthM($odMm);
        $fillet = $butt; // fillet length per joint = circumference at pipe OD
        switch (strtoupper((string)$type)) {
            case 'WN':
                return ['butt' => $butt, 'fillet' => 0.0];
            case 'SO':
                return ['butt' => 0.0, 'fillet' => 2 * $fillet];
            case 'SW':
                return ['butt' => 0.0, 'fillet' => $fillet];
            // BLIND / LOOSE / LAP flanges are NOT welded — they're bolted to a
            // mating flange (or, for loose flanges, bolted onto the welded
            // flange of a closure). No weld length.
            case 'BLIND':
            case 'LOOSE':
            case 'LAP':
            default:
                return ['butt' => 0.0, 'fillet' => 0.0];
        }
    }

    // ── Weld metal mass ───────────────────────────────

    /** Butt-weld cross-section (mm²) for wall thickness t: 0.85 × t². */
    public static function buttAreaPerM($t)
    {
        $t = (float)$t;
        if ($t <= 0) return 0;
        return self::BUTT_AREA_FACTOR * $t * $t;
    }

    /** Fillet-weld cross-section (mm²) for leg size s: s² / 2. */
    public static function filletAreaPerM($s)
    {
        $s = (float)$s;
        if ($s <= 0) return 0;
        return $s * $s / 2;
    }

    /** Weld metal mass (kg) for a cross-section (mm²) over length (m).
     *  Volume mm³ = area (mm²) × length (m) × 1000;  × ρ (kg/mm³). */
    public static function weldMassKg($areaPerMmm2, $lengthM)
    {
        return $areaPerMmm2 * (float)$lengthM * 1000 * self::STEEL_KG_PER_MM3;
    }

    // ── Weld hours ────────────────────────────────────

    /**
     * Butt weld hours for wall t over length L:
     *   t < 6  → all TIG
     *   t ≥ 6  → TIG root+fill (TIG_SHARE_OVER_6) + MIG/FCAW fill+cap (rest)
     */
    public static function buttWeldHours($t, $lengthM)
    {
        $kg = self::weldMassKg(self::buttAreaPerM($t), $lengthM);
        if ($kg <= 0) return 0.0;
        $t = (float)$t;
        if ($t < self::TIG_THRESHOLD_MM) {
            return $kg / self::DEP_TIG;
        }
        $tigKg = $kg * self::TIG_SHARE_OVER_6;
        $migKg = $kg - $tigKg;
        return $tigKg / self::DEP_TIG + $migKg / self::DEP_MIG;
    }

    /**
     * Fillet weld hours for leg size s over length L:
     *   s < 6 → all TIG;  s ≥ 6 → TIG root + MIG/FCAW fill+cap
     */
    public static function filletWeldHours($s, $lengthM)
    {
        $kg = self::weldMassKg(self::filletAreaPerM($s), $lengthM);
        if ($kg <= 0) return 0.0;
        $s = (float)$s;
        if ($s < self::TIG_THRESHOLD_MM) {
            return $kg / self::DEP_TIG;
        }
        $tigKg = $kg * self::TIG_SHARE_OVER_6;
        $migKg = $kg - $tigKg;
        return $tigKg / self::DEP_TIG + $migKg / self::DEP_MIG;
    }

    // ── Surface areas ─────────────────────────────────

    /**
     * Internal surface area (m²) of a pipe run: π × (OD − 2t) × length.
     * Used for internal paint / lining.
     */
    public static function pipeIntAreaM2($odMm, $wtMm, $lengthM)
    {
        $id = (float)$odMm - 2 * (float)$wtMm;
        if ($id <= 0 || (float)$lengthM <= 0) return 0.0;
        return M_PI * $id / 1000 * (float)$lengthM;
    }

    /**
     * Approximate internal surface area (m²) of a fitting from its ends:
     * Σ π × (OD − 2·WT) × dimension / 1000 per fitted end.
     */
    public static function fittingIntAreaM2($ods, $wts, $dims)
    {
        $area = 0.0;
        $ods = is_array($ods) ? $ods : [];
        $wts = is_array($wts) ? $wts : [];
        $dims = is_array($dims) ? $dims : [];
        for ($i = 0; $i < count($ods); $i++) {
            $id = (float)($ods[$i] ?? 0) - 2 * (float)($wts[$i] ?? 0);
            $dim = (float)($dims[$i] ?? 0);
            if ($id <= 0 || $dim <= 0) continue;
            $area += M_PI * $id / 1000 * $dim / 1000;
        }
        return $area;
    }
}
