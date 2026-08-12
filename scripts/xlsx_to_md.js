#!/usr/bin/env node
/**
 * Convert every sheet in every Excel file under data/ to markdown tables.
 * Output: data/md/<file-stem>/<sheet-name>.md
 *
 * Usage: node scripts/xlsx_to_md.js [data-dir]
 */
const XLSX = require("xlsx");
const fs = require("fs");
const path = require("path");

const dataDir = path.resolve(process.argv[2] || "data");
const outRoot = path.join(dataDir, "md");

function slug(s) {
  return s.replace(/[^\w\-]+/g, "_").replace(/_+/g, "_").replace(/^_|_$/g, "") || "sheet";
}

function fillMerges(ws, rows) {
  if (!ws["!merges"]) return rows;
  for (const m of ws["!merges"]) {
    const v = rows[m.s.r] && rows[m.s.r][m.s.c];
    if (v === undefined || v === null || v === "") continue;
    for (let r = m.s.r; r <= m.e.r; r++) {
      for (let c = m.s.c; c <= m.e.c; c++) {
        if (!rows[r]) rows[r] = [];
        if (rows[r][c] === undefined || rows[r][c] === null || rows[r][c] === "") {
          rows[r][c] = v;
        }
      }
    }
  }
  return rows;
}

function cellToMd(v) {
  if (v === undefined || v === null) return "";
  if (typeof v === "string") {
    return v.replace(/\r\n/g, " ").replace(/\n/g, " ").replace(/\|/g, "\\|").trim();
  }
  if (typeof v === "number") {
    // trim float noise
    return Number.isInteger(v) ? String(v) : String(parseFloat(v.toPrecision(10)));
  }
  if (v instanceof Date) return v.toISOString().slice(0, 10);
  return String(v);
}

function sheetToMd(ws, sheetName) {
  const raw = XLSX.utils.sheet_to_json(ws, { header: 1, defval: "", raw: true, blankrows: false });
  const rows = fillMerges(ws, raw);
  const height = ws["!ref"] ? XLSX.utils.decode_range(ws["!ref"]).e.r + 1 : rows.length;

  // find last row that has any content
  let last = -1;
  for (let i = 0; i < rows.length; i++) {
    if (rows[i].some((c) => c !== "" && c !== undefined && c !== null)) last = i;
  }
  if (last < 0) return `# ${sheetName}\n\n*(empty sheet)*\n`;

  const content = rows.slice(0, last + 1);
  const width = Math.max(...content.map((r) => r.length), 1);

  // decide header row: first row that is not entirely empty
  let headerIdx = 0;
  for (let i = 0; i < content.length; i++) {
    if (content[i].some((c) => c !== "")) { headerIdx = i; break; }
  }
  const header = content[headerIdx];
  const body = content.slice(headerIdx + 1);

  const out = [];
  out.push(`# ${sheetName}`);
  out.push("");
  out.push(`> Source: ${path.basename(ws._file || "?")} — ${sheetName} (${height} rows × ${width} cols)`);
  out.push("");

  // header row
  const headCells = Array.from({ length: width }, (_, c) => cellToMd(header[c]));
  out.push("| " + headCells.join(" | ") + " |");
  out.push("|" + Array(width).fill("---").join("|") + "|");

  let rowCount = 0;
  for (const r of body) {
    if (!r.some((c) => c !== "")) continue;
    const cells = Array.from({ length: width }, (_, c) => cellToMd(r[c]));
    out.push("| " + cells.join(" | ") + " |");
    rowCount++;
  }
  if (rowCount === 0) out.push("| " + Array(width).fill("—").join(" | ") + " |");
  out.push("");
  out.push(`_${rowCount} data rows_`);
  out.push("");
  return out.join("\n");
}

let total = 0;
for (const f of fs.readdirSync(dataDir)) {
  if (!/\.(xlsx|xlsm|xls)$/i.test(f)) continue;
  const filePath = path.join(dataDir, f);
  let wb;
  try {
    wb = XLSX.readFile(filePath, { cellDates: true, dense: false });
  } catch (e) {
    console.error(`SKIP ${f}: ${e.message}`);
    continue;
  }
  const stem = path.basename(f, path.extname(f)).replace(/[^\w\-]+/g, "_");
  const outDir = path.join(outRoot, stem);
  fs.mkdirSync(outDir, { recursive: true });
  for (const sheetName of wb.SheetNames) {
    const ws = wb.Sheets[sheetName];
    ws._file = f;
    const md = sheetToMd(ws, sheetName);
    const outFile = path.join(outDir, slug(sheetName) + ".md");
    fs.writeFileSync(outFile, md);
    console.log(`✓ ${f} :: ${sheetName} -> ${path.relative(dataDir, outFile)}`);
    total++;
  }
}
console.log(`\nDone: ${total} markdown files written under ${outRoot}`);
