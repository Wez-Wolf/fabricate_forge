# Ingest BOQ T26-132- KTZEBR9643-PIPING BOQ -TSV1.xlsx

## Status
Stage 1–5 DONE (pipeline proven end-to-end) · costing = R0 (B8, expected)

## Result
- Quote **Q-8BC0EDCA** (`1ff67c7f-f9b0-433b-aea8-c65dc375ea13`): 3,080 entities
- Import: imported=3080 skipped=0 errors=0
- Original xlsx in DB: file_id `9ce99761…`; MD IR in DB: file_id `6b5fe58b…`
  (/serve.php?id=6b5fe58b-4815-4332-895f-0f6a2d07230c)
- Round-trip verified: MD parse == extraction counts (24 sheets, 3655 raw /
  3080 clean; 575 zero-qty lines dropped per cleanRows rule)

## Decisions
- D1 (approved): Create NEW quote, use current upload flow.
- D2 (pending): Ingestion strategy given file structure — Option A vs B below.

## Done
- Quote created: **Q-8BC0EDCA** id `1ff67c7f-f9b0-433b-aea8-c65dc375ea13`
  (user wesley.stuart@innofuse.xyz, ZAR, margin 20%, draft)
- File uploaded+persisted via rfq.upload: file_id `9ce99761-3c36-4439-bf37-5b06d2f3fa3f`
  (serve.php?id=9ce99761…) — provenance anchor secured.

## Blockers log
- B1: localhost:80 = SPA fallback/503. Working endpoints:
  - prod vhost `https://fabricate.innofuse.xyz/api/*.php` (curl)
  - dev server `http://127.0.0.1:8099/api/*.php` (docroot = project root)
- B2: forge Auth login key is `pass` NOT `password`.
- B3: `auth_id` must sit INSIDE `input{}`, not top-level.
- B4: Workbook has 29 sheets. Default sheet pick = master ORIGINAL sheet →
  10,646 rows parsed, ALL flagged error (headers treated as data).
- B5: File is NOT a spool BoQ. It is a WBS/CBS hierarchy: group rows carry
  T/O qty (`Straight Pipe | 102 | Meter`) with `01 - Supply Cost` /
  `02 - Install Cost` children. Sizes in CBS L2/L3 cols, type in
  `Specifications` col (Straight Pipe / Flange / bends…).
- B6: Generic `xlsx-to-rows.py` extracts 0 rows from the clean per-category
  sheets (expects headers `qty|item`; here `Forecast (T/O) Quantity`,
  ABC3 ids). `parse_boq`'s classifySection also misses these descriptions.
- B7 (D5 conflict, pre-existing open item): rfq.import stamps entity.quantity;
  D5 wants qty on contains-links only. Current engine still tolerates it.

## Sheet inventory (key ones)
- Master: `Sec. 2.7 - Piping-ORIGINAL` (10,650r), `-TS`, `-TS SUPPLY` — WBS tree
- Category takeoffs (clean, 1 row/item): STRAIGHT PIPE(245) CS FLANGES(196)
  CS EQUAL TEES(245) 90 DEGREE BENDS(179) CS 45 PULLED BEND(210)
  CS BLANK FLANGES(178) CS HALF COUPLING(71) BARREL NIPPLE(51)
  BOLT SET & GASKETS(271) GASKETS(314) U BOLTS(284) PIPE SUPPORTS(295)
  CS/HDPE UNION, CON REDUCER, STUB & BACKING FLNG, HDPE * (pipe/bends/tee/
  bolt sets/blank flanges), PAINT(456)

## Options for D2
- **Option A — no app code changes:** rule-based extraction (DONE as fallback:
  /tmp/extract_boq.py → 3,644 rows / 3,080 clean).
- **Option B — extend app parser:** CBS adapter in xlsx-to-rows.py + import.php
  rules. CODE CHANGE — barred for now; candidate for later approval.
- **Option C (NEW, pending approval) — AI-assisted smart ingress:**
  still ZERO app code changes. Throwaway tool layers an LLM pass on top of
  extraction before the existing rfq.import call.

## Option C design (D3)
1. **Extract** raw category-sheet rows (existing script, unchanged).
2. **Deterministic spine stays rule-based** — qty, size, unit, section come
   from sheet columns ONLY. LLM never emits numbers that reach import.
3. **LLM pass per batch (~40 rows)** via `pi -p --provider kilo --model <free>`:
   - classify type: part | fitting | fastener (+ confidence)
   - clean description → short human-readable line-item name
   - extract spec tags: grade, standard (SANS/ASTM/ISO), schedule,
     pressure rating (PN16 / 700 kPa), coating (HDG/paint system)
   - flag anomalies: desc/type mismatch, likely duplicate, suspicious size
4. **Validate + repair:** JSON schema check; on failure → retry once →
   fall back to Option A row for that batch.
5. **Cache** LLM results to /tmp (reruns cost nothing).
6. **Import** cleaned rows via existing rfq.import (file_id lineage intact).
7. Verify via systems.overview + spot-check N rows against source sheet.

Model access: kilo provider (free) via `pi --print`; google key is depleted
(429). No app code touched; tool lives in /tmp.
Est: ~30–45 min build+run for 3,644 rows.

## Pipeline revision D4 (approved direction): xlsx → MD → ingest
User directive: source doc must live in the DB as the original AND as
markdown before ingestion. Chain of custody:

1. ✅ Original xlsx persisted: file_id `9ce99761-3c36-4439-bf37-5b06d2f3fa3f`
2. **Convert** xlsx → structured markdown (/tmp converter, deterministic):
   `## <SHEET>` per category sheet, pipe-table row per item
   (UNIQUE | type | size | qty | unit | spec | CBS code). Numbers verbatim.
3. **Persist MD into DB** via existing rfq.upload (any type stored; md just
   persists → own file_id + serve_url). Queryable, diffable, AI-friendly IR.
4. **Ingest FROM the MD**: parse MD tables → rule-based spine + Option C AI
   enrichment where proven → rfq.import, lineage = MD file_id.
5. Verify: systems.overview + spot-check vs source sheets.

### Blockers log (additions)
- B8 (OPEN): leaf entities import without material/pricing data → total_cost 0.
  Fix options: (a) approve Option B app change (CBS adapter + material comp
  creation in rfq.import), or (b) post-import enrichment attaching material
  comps / library links per line.
- B9: kilo-auto/free via pi rpc slow/flaky on 40-row batches (aborts/timeouts).
  Mitigation if kept: batch ≤20, timeout 120s, one retry, rule fallback.
  AI pass stays OFF until a full test slice passes.

## Stage 6 REVISED (user-specified product flow): unique-specs pipeline
Supersedes rename pass AND flat import shape. The general flow:

1. **UNIQUE LIST** — ingest RFQ doc → collapse BoQ lines into unique-spec
   catalog ("the one-offs"). Spec identity = type + size + wall/schedule +
   standard + grade + coating. One ENTITY per unique spec.
2. **ALIGN TO STANDARDS/LIBRARY** — parse each spec into engineering fields,
   match against material_library entities (SANS 62/719/1123, ISO 4427,
   ASME B16.5…). No match → candidate new library entry (human review).
3. **LINK FOR QTY** — area/sub WBS assemblies from the source doc;
   every usage of a spec = contains-link with qty; rollups multiply along
   links (D5 end-state). Entity names = unique tags (CBS code); readable
   words live in description.
4. **SUMMARY** — materials takeoff: qty×links aggregated per spec,
   grouped by category/standard/size → procurement (existing takeoff-split).

Existing app coverage: library entities ✓ links+qty ✓ computeRollups ✓
boms.takeoff ✓ takeoff-split ✓
Code-change ledger adds: B13 spec-parser service, B14 library matcher,
B15 unique-catalog generation in rfq.import.

T26-132 application: REBUILD quote via stages 1–4 from ORIGINAL sheet
(flat 3,080-row version discarded). Dry-run first, no writes.

