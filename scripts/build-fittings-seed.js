#!/usr/bin/env node
/**
 * Build seed-data/fittings.json from
 * data/PIPE FITTING DATA- MASTER TABLE AS RANGE.xlsm — "Master FITTING Database-RANGE".
 *
 * 815 rows, all 6 fitting types (45/90 LRE, EQ/UN-EQ tee, CON/ECC RED),
 * standards B16.9 / JIS B2311 / JIS, series #40 / #80 / SGP/SPP.
 *
 * Output shape matches what scripts/seed-materials.php consumes:
 *   name, profile, materialType, grade(=standard), density, unitCost,
 *   dimensions, data{...} (pass-through payload with full dims/mass/area).
 *
 * Usage: NODE_PATH=/var/www/html/fabricate/node_modules node scripts/build-fittings-seed.js
 */
const XLSX = require("xlsx");
const fs = require("fs");
const path = require("path");

const SRC = "data/PIPE FITTING DATA- MASTER TABLE AS RANGE.xlsm";
const SHEET = "Master FITTING Database-RANGE";
const OUT = path.join(__dirname, "..", "seed-data", "fittings.json");
const OLD = path.join(__dirname, "..", "seed-data", "fittings.json");

const TYPE_LABEL = {
  "45° LRE":  "45° Long Radius Elbow",
  "90° LRE":  "90° Long Radius Elbow",
  "EQ TEE":   "Equal Tee",
  "UN-EQ TEE":"Unequal Tee",
  "CON RED":  "Concentric Reducer",
  "ECC RED":  "Eccentric Reducer",
};

const wb = XLSX.readFile(SRC);
const rows = XLSX.utils.sheet_to_json(wb.Sheets[SHEET], { defval: null });

// Keep the pre-existing flange placeholders (flange data lives in the other files).
const old = JSON.parse(fs.readFileSync(OLD, "utf8"));
const flanges = old.filter((m) => /flange/i.test(m.name || ""));

const out = [...flanges];
const names = new Set();
let skipped = 0;

for (const r of rows) {
  const type = String(r["Fitting Type"] || "").trim();
  const std = String(r["Standard"] || "").trim();
  const series = String(r["Series"] || "").trim();
  const size = String(r["Catalogue Size"] || "").trim();
  const label = TYPE_LABEL[type];
  if (!label) { skipped++; continue; }

  const sizeFmt = size.replace(/\s*x\s*/g, "×");
  let name = `${label} DN${sizeFmt} ${series} ${std}`;
  // Guarantee uniqueness (2 rows are "WT NOT LISTED" — no mass to disambiguate by)
  if (names.has(name)) name += ` (${r.Identifier})`;
  names.add(name);

  const num = (v) => (v === null || v === "" || isNaN(Number(v)) ? null : Number(v));

  const ext = num(r["External Area m²"]);
  const intRaw = num(r["Internal Area m²"]);
  // The source file's "Internal Area m²" column is mostly a row-index artifact
  // (values like N.001 — never a real area). Keep it only when plausible
  // (internal ≈ external surface area), else null.
  const intArea = intRaw !== null && ext !== null && intRaw > 0 && intRaw <= ext * 1.2 ? intRaw : null;

  out.push({
    name,
    profile: "Fitting",
    materialType: "Carbon Steel",
    grade: std,
    density: 7850,
    unitCost: 0,
    dimensions: `DN${sizeFmt}`,
    data: {
      kind: "fitting",
      type,
      standard: std,
      series,
      catalogueSize: size,
      endNb: [r["End 1 NB"], r["End 2 NB"], r["End 3 NB"]].map(num),
      od: [r["OD 1 mm"], r["OD 2 mm"], r["OD 3 mm"]].map(num),
      wt: [r["WT 1 mm"], r["WT 2 mm"], r["WT 3 mm"]].map(num),
      dims: [r["Dimension 1 mm"], r["Dimension 2 mm"], r["Dimension 3 / H mm"]].map(num),
      massKg: num(r["Mass kg"]),
      extArea: ext,
      intArea,
      weldCirc: [r["Weld Circ 1 m"], r["Weld Circ 2 m"], r["Weld Circ 3 m"]].map(num),
      totalWeldLength: num(r["Total Weld Length m"]),
      buttWeldT: num(r["BUTT WELD T"]),
      status: String(r["Status"] || "").trim(),
      description: String(r["Description"] || "").trim(),
    },
  });
}

fs.writeFileSync(OUT, JSON.stringify(out, null, 2) + "\n");

const byType = {};
for (const m of out) { if (m.data && m.data.type) byType[m.data.type] = (byType[m.data.type] || 0) + 1; }
const byStd = {};
for (const m of out) { if (m.data && m.data.standard) byStd[m.data.standard] = (byStd[m.data.standard] || 0) + 1; }

console.log(`Wrote ${OUT}`);
console.log(`  fittings: ${out.length} (${flanges.length} flange placeholders kept, ${rows.length} from master table, ${skipped} skipped)`);
console.log("  by type:", JSON.stringify(byType));
console.log("  by standard:", JSON.stringify(byStd));
console.log("  unique names:", names.size);
