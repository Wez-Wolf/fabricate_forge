#!/usr/bin/env node
/**
 * Build seed-data/flanges.json from
 * data/Flanges TS -28.07.26.xlsm — "flange details" sheet.
 *
 * 632 rows; main data block columns:
 *   SIZE | FLANGE DETAIL | Mass kg | Paint Area (m²) | PIPE OD | TYPE |
 *   RATING | MASS kg | PAINT AREA (m²) | FLANGE OD | PIPE OD
 *
 * CORE flange costing data per the spec:
 *   - Mass ea (kg)            → data.massKg
 *   - Flange Type             → data.type  (WN / SO / SW / SCRD / BLIND, from RATING suffix)
 *   - Area (m²)               → data.paintArea (external paint coverage)
 *   - Pipe OD + rating        → data.pipeOd, data.rating, data.standard
 *   - Pipe WT comes from the pipe item the flange fits (selected at quote time)
 *
 * Usage: NODE_PATH=/var/www/html/fabricate/node_modules node scripts/build-flanges-seed.js
 */
const XLSX = require("xlsx");
const fs = require("fs");
const path = require("path");

const SRC = "data/Flanges TS -28.07.26.xlsm";
const OUT = path.join(__dirname, "..", "seed-data", "flanges.json");

const wb = XLSX.readFile(SRC);
const rows = XLSX.utils.sheet_to_json(wb.Sheets["flange details"], { defval: null });

const num = (v) => (v === null || v === "" || isNaN(Number(v)) ? null : Number(v));
const seen = new Set();
const out = [];

for (const r of rows) {
  const size = String(r.SIZE ?? "").trim();
  const rating = String(r.RATING ?? "").trim().replace(/\s+/g, " ");
  const detail = String(r["FLANGE DETAIL"] ?? "").trim();
  if (!size || !rating || !detail) continue;

  const name = `Flange DN${size} ${rating}`;
  if (seen.has(name)) continue;
  seen.add(name);

  // Flange type from rating suffix: WN / SO / SW / SCRD / BLIND
  const typeMatch = rating.match(/\b(WN|SO|SW|SCRD|BLIND)\b\s*$/i);
  const type = typeMatch ? typeMatch[1].toUpperCase() : null;

  // Standard family: ANSI B 16.5 / BS 4504 / SANS 1123
  let standard = null;
  if (/ANSI|ASME|B 16\.5|B16\.5/i.test(rating)) standard = "ANSI B16.5";
  else if (/BS 4504/i.test(rating)) standard = "BS 4504";
  else if (/SANS 1123/i.test(rating)) standard = "SANS 1123";
  else if (/BS 10/i.test(rating)) standard = "BS 10";

  out.push({
    name,
    profile: "Flange",
    materialType: "Carbon Steel",
    grade: standard,
    density: 7850,
    unitCost: 0,
    dimensions: `DN${size}`,
    data: {
      kind: "flange",
      dn: num(size),
      rating,
      type,                    // WN | SO | SW | SCRD | BLIND | null
      standard,
      massKg: num(r["Mass kg"]),
      paintArea: num(r["Paint Area (m²)"]),
      pipeOd: num(r["PIPE OD"]),
      flangeOd: num(r["FLANGE OD"]),
      description: detail,
    },
  });
}

fs.writeFileSync(OUT, JSON.stringify(out, null, 2) + "\n");

const byType = {};
for (const m of out) {
  const t = m.data.type || "(none)";
  byType[t] = (byType[t] || 0) + 1;
}
const byStd = {};
for (const m of out) byStd[m.data.standard] = (byStd[m.data.standard] || 0) + 1;
console.log(`Wrote ${OUT}`);
console.log(`  flanges: ${out.length} (from ${rows.length} raw rows)`);
console.log("  by type:", JSON.stringify(byType));
console.log("  by standard:", JSON.stringify(byStd));
const withMass = out.filter((m) => m.data.massKg != null).length;
const withPaint = out.filter((m) => m.data.paintArea != null).length;
const withPipeOd = out.filter((m) => m.data.pipeOd != null).length;
console.log(`  with mass: ${withMass} | with paint area: ${withPaint} | with pipe OD: ${withPipeOd}`);
