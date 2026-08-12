#!/usr/bin/env node
/**
 * Build seed-data/pipes.json from
 * data/PIPE_DETAILS_ SUMMARY A106B-SANS 719-SANS 62.xlsm — "pipes" sheet.
 *
 * Each row: DN | OD (mm) | Schedule | WT (mm) | kg/m | Paint Area/m | PIPE DETAIL 1 | ...
 * These are the CORE pipe costing data:
 *   - kg/m                    → massPerMeter
 *   - Paint Area/m (OD-based) → data.paintAreaPerM  (external paint coverage)
 *   - OD, WT, Schedule, Spec  → data (used for weld size + internal area)
 *
 * Usage: NODE_PATH=/var/www/html/fabricate/node_modules node scripts/build-pipes-seed.js
 */
const XLSX = require("xlsx");
const fs = require("fs");
const path = require("path");

const SRC = "data/PIPE_DETAILS_ SUMMARY A106B-SANS 719-SANS 62.xlsm";
const OUT = path.join(__dirname, "..", "seed-data", "pipes.json");

const wb = XLSX.readFile(SRC);
const rows = XLSX.utils.sheet_to_json(wb.Sheets["pipes"], { defval: null });

const num = (v) => (v === null || v === "" || isNaN(Number(v)) ? null : Number(v));
const seen = new Set();
const out = [];

for (const r of rows) {
  const name = String(r["PIPE DETAIL 1"] || "").trim();
  if (!name) continue;
  if (seen.has(name)) continue; // file has a few exact dupes
  seen.add(name);

  const stdMatch = name.match(/(A106B|SANS 719|SANS 62)/i);
  const standard = stdMatch ? stdMatch[1].toUpperCase() : "A106B";

  out.push({
    name,
    profile: "Pipe",
    materialType: "Carbon Steel",
    grade: standard,
    density: 7850,
    unitCost: 0,
    massPerMeter: num(r["kg/m"]),
    data: {
      kind: "pipe",
      dn: num(r.DN),
      od: num(r["OD (mm)"]),
      wt: num(r["WT (mm)"]),
      schedule: String(r.Schedule || "").trim() || null,
      standard,
      nps: String(r['NPS(")'] || "").trim() || null,
      kgPerM: num(r["kg/m"]),
      paintAreaPerM: num(r["Paint Area/m"]),
      description: String(r["PIPE DETAIL 2"] || "").trim(),
    },
  });
}

fs.writeFileSync(OUT, JSON.stringify(out, null, 2) + "\n");

const byStd = {};
for (const m of out) byStd[m.data.standard] = (byStd[m.data.standard] || 0) + 1;
console.log(`Wrote ${OUT}`);
console.log(`  pipes: ${out.length} (from ${rows.length} raw rows)`);
console.log("  by standard:", JSON.stringify(byStd));
const withMass = out.filter((m) => m.massPerMeter != null).length;
const withPaint = out.filter((m) => m.data.paintAreaPerM != null).length;
console.log(`  with kg/m: ${withMass} | with paint area/m: ${withPaint}`);
