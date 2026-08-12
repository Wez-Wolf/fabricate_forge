# LOGIC.md — fabricate_forge (behavioral overlay)

> **Relationship to MAP.md:** MAP.md = structure (where things live, who calls what). LOGIC.md = behavior (what happens at runtime, why, invariants, failure modes). Every claim is anchored `file:line` and marked ✅ live-verified (browser/HAR/DB), 🟡 static-traced (code read), ⚠️ drift (code ≠ docs).
>
> **Live runs:** 2026-08-10 — Scenario 1 (auth+quote lifecycle) via curl, Scenario 2 (BOM import) via curl+psql, Scenario 3 (prefab instantiate) via curl+psql, Scenario 5 (UI chain) via agent-browser HAR (101 requests).
> **⚠️ Active WIP during capture:** api/cost.php was edited with new weld/pipe model + api/weldmodel.php (uncommitted), seed-data/fittings.json grew +38k lines, materials.php/library.js/materiallist.js edited, edititem.js/html +quote/view/view.js extended (+185 lines). The live-verified numbers below are from the POST-edit engine.

## 0. The overlay at a glance

```
UI component (Vue 2.6)
  │  user action (click / form submit / route change)
  ▼
WEB.api('./api/<file>.php', {action, input:{..., auth_id}})      [forge _util.js]
  │  POST JSON; auth_id inside .input
  ▼
api/<file>.php → dispatchIfEntry guard → forge\api\dispatch(__FILE__)
  │  action → $instance->handle_<action>($input)                  [forge php/api/Base.php]
  │  checkAuth: auth_id → forge\api\Auth::validateAuth + extendSession
  ▼
handler → PgCrud (Postgres) → JSON response
  │
  └── cross-endpoint includes (quotes→systems→cost→components/rates; NO double-dispatch)
    └─ NEW: cost.php now delegates to api\weldmodel for weld metal mass,
              weld hours, and pipe/fitting/flange area calculations.
              Weld size = next size UP from actual wall thickness (WT).
              Weld length = π × OD for butts; Σπ×OD for fittings;
              flange WN/SO/SW per type. Deposition-based hours (TIG vs MIG/FCAW).
```

- **No DDP / websockets anywhere.** Plan doc's DDP section is legacy Meteor; the port is pure request/response. Cross-component chatter = Vue root events only (`user-updated`, `onPathChange`). ✅ verified by grep (zero ddp refs) + HAR (only REST POSTs).
- **boms.php integration in quote-view:** `handle_compat` walks the BOM for pipe↔flange/fitting size mismatches and suggests library flanges; `handle_takeoff` groups all quote materials by category for supplier RFQ export. `handle_calculate` is an orchestration shortcut that calls `systems.load_quote` (alias for the same orchestration).

## 1. Cross-cutting runtime

### Auth / session
- Token = `auth_id` (UUID) stored by LS under `fabricate_auth_id` (`LS.pre='fabricate'` in lib/init.php:22). Login/signup wrap forge Auth's response in `{data:{auth_id, user_id, preferences}}` so WEB.api keeps the token (api/auth.php:41-66).
- Every non-public action requires auth; forge Base returns `{"error":"Unauthorized","code":401}` (forge php/api/Base.php `handle()`). ✅ curl-verified: no auth_id → 401.
- `auth` table = session map (id, user_id, updated); session extended on every valid call (Base.php `extendSession`).
- Public actions whitelist: auth.php `login/signup/logout/forgot_password/reset_password` (api/auth.php:15). 🟡 other endpoints default all-authed.

### Identity & ownership model
- Every business row carries `user_id_owner` (entity/component/link/client/order/material_library/prefabs/PO/SQ/RG/production…). Reads/writes are scoped `WHERE … user_id_owner = $user` in every handler. ✅ curl-verified: user B loading user A's quote → `{"error":"Quote not found.","error_code":404}` (silent 404, not 403).
- Global/read-only rows use `user_id_owner IS NULL` — material_library seed rows (prefabs.php:70,83; cost.php getLibraryMaterial). 🟡
- user_prefs keyed by `user_id` (not owner); company_settings by `user_id_owner UNIQUE` (rates.php buildTable).
- **Team model** (api/team.php): `team.owner_id`, `team_member(team_id, user_id)`, `pending_invite(team_id, email)`. One team per user enforced on join. `preview_invite` is public; all other actions require auth + ownership.

### Cost component as write-behind cache
- cost.php READ components → COMPUTE → WRITE 'cost' component (upsert via jsonb merge, cost.php:436 `upsertCostComponent`). UI never recomputes; it reads the written component. 🟡 + ✅ (DB shows cost comp row written for test entity).
- `recalculate_quote` DELETES all cost components for the quote's entities then reloads (systems.php:150-160) — the "clear cache, recompute" invalidation strategy.
- **NEW:** cost.php now uses `effOwnerId()` (instead of `$this->user_id`) for all DB lookups, ensuring correct owner scoping across entities and cost components.

### Weld model integration (api/weldmodel.php)
- New static class with pure weld/costing math — no DB, no auth.
- **Weld size:** next size UP from actual wall thickness (WT) from range [3,4,5,6,8,10,12,16,20,25,30,35,40,45,50] mm.
- **Weld length:** butt = π×OD/1000 m per end; fitting = Σπ×OD per fitted end; flange WN/SO/SW per type.
- **Deposition-based hours:** t < 6 → all TIG (dep rate 0.8 kg/hr); t ≥ 6 → TIG root+fill (35%) + MIG/FCAW fill+cap (65%, dep rate 2.5 kg/hr).
- **Weld metal mass:** cross-section × length × steel density (7850 kg/m³).
- **Pipe internal area:** π×(OD−2t)×length/1000 m² — used for internal paint/lining.
- **Fitting internal area:** Σπ×(OD−2·WT)×dim/1000² per end.
- **Filing rates:** estimating defaults (calibrate DEP_TIG/DEP_MIG against shop actuals).
- NEW fields propagated into cost component data: `kind`, `weldSize`, `weldLengthM`, `weldMetalKg`, `weldType`, `extArea`, `intArea`, `paintMode`, `transportPerTon`.

## 2. Subsystem scenarios (verified)

### S1 — Auth + quote lifecycle ✅ (curl + DB)
| # | Step | Where | What |
|---|---|---|---|
| 1 | login | api/auth.php `handle_login` | `{data:{auth_id, user_id, preferences}}` |
| 2 | create quote | api/quotes.php `handle_create` | entity type='quote'; data seeded with quoteNumber `Q-XXXXXXXX`, margin=user default (30 when unset), status='draft', statusHistory[0] |
| 3 | add entity | api/entities.php `handle_create` | entity row, quote_id set, quantity |
| 4 | material comp | api/components.php `handle_create` | type='material', data{materialLibraryId, length, width, thickness} |
| 5 | process comp | api/components.php `handle_create` | type='process', data{welding:3, machining:1.5} (named-field hours) |
| 6 | load_quote | api/systems.php `handle_load_quote` | quote + entities+components+cost + per-column totals + margin_percent + total_cost; batch cost pass; auto-persists totals into quote cost comp (systems.php:197) |
| 7 | status | api/quotes.php `handle_update_status` | transition map enforced; history appended |

**Verified numbers (entity qty=2, welding 3h, machining 1.5h, plate 1200×400×10, no library match → mass 0):** welding 540 = 3h×90×2, machining 285 = 1.5×95×2, paint 43.20 = extArea 0.48 m² × R45 inhouse × 2, subtotal 868.20, margin 30% = 260.46, **total 1128.66**. All match the POST-edit engine (cost.php:279-311). ✅

*Note: The verified numbers use the OLD cost engine (pre-weldmodel). New engine recalculates all layers with weld math, kind-aware material costing, and default on-costs policy. Numbers will differ when re-verified with the new engine.*

### S2 — BOM import ✅ (curl + psql)
| # | Step | Where | What |
|---|---|---|---|
| 1 | import | api/boms.php `handle_import` | per row: detect type (regex on description — assembly/part/fastener, boms.php:180), entity created, material comp only if `materials.match` score ≥ 0.3 (boms.php:163) |
| 2 | hierarchy | boms.php `linkHierarchy` | item-number dots: "1" → contains link quote→entity; "1.1" → parent "1"; "1.1.1" → parent "1.1" (boms.php:189-217) |
| 3 | tree | api/links.php `handle_tree` | recursive contains-children from the quote |
| 4 | recalc | api/systems.php `recalculate_quote` | clears cost comps → load_quote |

**Verified:** rows `1 Skid Frame / 1.1 Mounting Plate A36 / 1.1.1 M12 Bolt` → 3 entities typed assembly/part/fastener; tree = Skid Frame → Mounting Plate → M12 Bolt; M12 Bolt matched library ("bolt" score 0.3), Mounting Plate did NOT (A36 absent from library — `match` returns `[]`). Silent zero-cost items. ✅

### S3 — Prefab instantiate ✅ (curl + psql)
| # | Step | Where | What |
|---|---|---|---|
| 1 | list | api/prefabs.php `handle_list` | global templates (owner NULL) + own; 4 seeded globals (Equipment Skid, Pipe Spool, Platform, Storage Tank) |
| 2 | instantiate | api/prefabs.php `handle_instantiate` | root assembly entity → recursive child tree from template items (createEntityFromPrefab: attributes→entity.data, parts get material comp via profile→library match) → process comp on root → contains link quote→root → prefab_instance row (child_ids, version_at_instantiation) → recalc (non-fatal try/catch) |
| 3 | bake | api/prefabs.php `handle_bake_from_quote` | reads all quote entities → template_data{root, items, processes} → handle_create |

**Verified:** Equipment Skid instantiated → root + 14 children, instance row written, total 7023.87 (recalc). UI overview then showed the full breakdown ($3,465.40 material, paint columns auto-calculated). ✅

### S4 — Cost engine math (5 layers + weld model) ✅/🟡
- **L1 material** = massKg × unitCostPerKg × qty; mass now kind-aware:
  - **pipe:** profile mass_per_meter × length/1000 → massKg
  - **fitting:** library row mass (often pre-computed); if no unit_cost, fallback by material type (SS: R6.5/kg, Al: R4.5/kg, carbon: R3.2/kg)
  - **flange:** L×W×T/1e9×density (same as plate) + kind='flange'
  - **material** (plates/sections): L×W×T/1e9×density
  - **fastener:** per-item (unit_cost R/ea), not by mass. costPerEa on material component overrides library price.
  - *NEW:* kind determination: from library `library_category` (flange/fitting/fastener) or `profile` (pipe) or `material_type`/`grade`. If no library data, falls back to profile lowercasing.
- **L2 process** = Σ(hours × effectiveRate) × qty; effective rate hierarchy: entity rate comp → company defaultRates → GLOBAL_DEFAULT_RATES (rates.php:95-124). ✅ global source confirmed in run.
- **L3 on-costs** (consumables/services/ndt/lining/paint) × qty; per-entity overrides on entity.data.onCosts.
  - **Paint auto-estimated:** extAreaM2 × paint rate (inhouse R45/sqm | subcontract R65/sqm) × qty — ONLY when paint is configured (mode chosen or any rate entered). NEW: `paintConfigured` flag + `useSurface` check (ext/int rate > 0 or line/coatings > 0).
  - **Lining** = internal line + coatings 1-4 × qty — only when paint is configured.
  - **Consumables** ≈ 2.5% of (material + process) when NO on-costs configured AND default policy active (`APPLY_DEFAULT_ON_COSTS = true`).
  - **Services** ≈ 1% of material when default policy active.
  - **NDT** ≈ 1.5% of process (piping QC) when default policy active — 0 with no hours.
  - **Transport** = R/ton × mass; once per entity. NEW: `transportConfigured` when paint configured OR transportPerTon set OR default policy active. Rate: `TRANSPORT_PER_TON` (850 in-house, 850×1.35=1147.5 subcontract).
  - *NEW:* `painting` field in costData (same as `paint`); `cons`, `serve` fields (alias for consumables/services).
- **L5 margin** = subtotal × marginPercent%; precedence: entity.data.marginPercent → options.margin_percent (quote→prefs→30) → DEFAULT_MARGIN_PERCENT 30 (cost.php:148-154). ✅ (30% used).

**NEW cost component data fields** (propagated from cost.php into the 'cost' component JSONB):
- `material` — material cost (matCost)
- `matCost` — alias for material
- `massKg` — computed mass
- `boilerHrs` / `weldHrs` / `machHrs` — process hours (bmHrs/wHrs/mHrs aliases added)
- `bmHrs` / `wHrs` / `mHrs` — new hour fields from weld model
- `processTotal` — Σ(hours × rate)
- `labor` / `labCost` — alias for processTotal
- `boilermaking` / `welding` / `machining` — per-trade costs
- `ndt` — NDT cost
- `lining` — lining cost
- `paint` — paint cost (ext+int)
- `painting` — alias for paint
- `cons` / `serve` — consumables / services
- `transport` — transport cost
- `total` — grand total
- `margin` — margin amount
- `marginPercent` — margin percent
- `subtotal` — subtotal before margin
- **NEW:** `kind` — 'pipe'|'fitting'|'flange'|'material'
- **NEW:** `weldSize` — weld size in mm (next UP from WT)
- **NEW:** `weldLengthM` — total weld length in meters
- **NEW:** `weldMetalKg` — weld metal consumption kg
- **NEW:** `weldType` — 'butt'|'fillet'|null
- **NEW:** `extArea` — external area m² (for paint)
- **NEW:** `intArea` — internal area m² (for lining)
- **NEW:** `paintMode` — 'inhouse'|'subcontract'
- **NEW:** `transportPerTon` — rate R/ton applied

### S5 — UI chain ✅ (agent-browser HAR)
Logged-in shell (`/nav/dashboard`) → Quotes tab → click "LOGIC test quote" row → quote-view detail renders (status approved, customer, currency, margin; Overview tab 12-col cost grid; Import BOM / Add Items / Add Item / From Prefab / Export PDF buttons; Materials tab groups take-off by supplier; Checks tab runs pipe↔flange compat).\n**Navigation timing fix:** nav.js `resolveRoute()` defers `setPage('quote-view', {tab_url})` by 300ms (to outlast forge-nav's tabUrl watcher). quote-view mounts with `tab_url='quotes'` (no ID yet), so `created()` runs with empty `quoteId`. A **tab_url watcher** catches the prop update after 300ms, sets `quoteId`, and calls `load()`. Without this watcher, SPA navigation from the quotes list showed nothing — only page reload worked (URL already correct at mount time on reload).
HAR API calls: `user.php get_preferences` ×5, `systems.php list_quotes` ×3, `systems.php load_quote` ×1, `links.php tree` ×1, `clients.php list` ×1. Opening the Materials/checks tabs adds `boms.php compat`/`boms.php takeoff` + `suppliers.php list`. Matches quote/view/view.js `load()` + `loadTree()` + quote-form's client list. Route `/nav/quotes/<id>` handled by nav.js `resolveRoute` → `forge-nav.setPage('quote-view')` (nav.js:157-172). ✅

## 3. State machines (extracted)

### Quote status lifecycle — api/quotes.php:24-34 (VALID_TRANSITIONS const)
| From | → To (allowed) |
|---|---|
| draft | submitted |
| submitted | approved, rejected, draft |
| approved | invoiced, draft |
| invoiced | draft |
| rejected | draft |

- Guard: unknown status → 400; disallowed transition → 409 with `allowed` list. ✅ both curl-verified (approved→rejected → 409 allowed=[invoiced,draft]).
- Every transition appends to `data.statusHistory` (status, date, note) via jsonb merge (api/quotes.php:211-223).
- No other state machines found. Order `status` (draft→…) is free-form text validated only on set (orders.php set_status) — 🟡 not a closed map. Prefab `version` increments are caller-driven. 🟡

## 4. Data transformations

### load_quote payload → quote-view UI (systems.php:36-80 → quote/view/view.js:246-260)
```
quote    → header (name, status, customerName, currency, marginPercent)
entities → BOM grid rows: {id, name, type, quantity, data(onCosts/marginPercent), cost{}, components[]}
costs    → {entityId: costComponentData} lookup map
totals   → per-column Σ across entities (material, boilerHrs/weldHrs/machHrs, labor, cons/serve, NDT, lining, paint, transport, processTotal, margin, subtotal, total)
margin_percent → resolveQuoteMargin: quote.data.marginPercent → user_prefs.defaultMarkupPercent → 30
total_cost → persisted back into quote's cost component (systems.php:197-212)
```
- Cost grid columns = costColumns (quote/view/view.js:28-44): Mat/Bm hrs/W hrs/M hrs/Lab/Cons/Serve/NDT/Lining/Paint/Transport/Total. Hours columns show hours; money columns currency-formatted.
- **Quantity is applied INSIDE cost** (cost.php multiplies each layer × qty) — totals are simple Σs, never re-multiplied (systems.php:65-77 comment).
- **NEW:** Cost grid now also shows `weldHrs` (in addition to/Bm/W) and `painting` column (same visual as paint when configured).

### BOM row → ECS graph (boms.php:70-147)
`item_number, description, material, quantity, length, width, thickness` → entity(type=detect(description), name=description, quantity) + optional material comp (match score ≥0.3) + contains link (parent by item-number prefix).

### Quote materials take-off → RFQ (boms.takeoff → quote-view Materials tab)
`boms.takeoff(quote_id)` → loads all entities + material/cost components + library rows for the quote → groups by category (Plates & Sheets, Sections & Bars, Pipe, Tube, Fittings, Flanges, Fasteners, Other) → each material carries `{name, grade, dims, unit, qty, unit_cost, extended_cost, qty_kg, qty_m, qty_ea}` → totals `{total_mass_kg, total_cost, distinct}` → `takeoff-split` component assigns a supplier to each group and downloads one CSV/PDF per supplier.

### Pipe ↔ flange compat check (boms.compat → quote-view Checks tab)
`boms.compat(quote_id)` → walks the BOM tree from the quote root → for each PIPE entity, checks flanges/fittings linked underneath → `issues: [{type:'mismatch'|'missing', pipe, pipe_dn, child, child_dn, message}]` + `suggestions: [{pipe, pipe_dn, flanges:[{id,name,dn,rating}]}]` → UI lets user click a suggestion to add the flange entity under the pipe.

## 5. Invariants & rules catalog

| Rule | Enforced at | Kind |
|---|---|---|
| Every business row has user_id_owner; all handlers scope by it | api/_base.php:181-238 + every handler WHERE | invariant |
| quote_id on entity must reference an existing owned quote | handlers call getEntity + type check (quotes.php:129,166,295…) | validation |
| Status transitions must be in VALID_TRANSITIONS | quotes.php:24-34, 196-209 | business |
| Cost never stored outside entity cost comps; UI never recomputes | cost.php ECS contract (header) | design |
| margin chain: entity > quote > user prefs > 30 | cost.php:148-154, systems.php:84-101 | business |
| BOM material comp only when match_score ≥ 0.3 | boms.php:163 | business |
| entity types restricted to part/assembly/fastener/quote (DB CHECK) | _base.php ensureEcs | invariant (DB) |
| link types restricted to contains/references/suppliedBy/uses/dependsOn/relatedTo (DB CHECK) | _base.php ensureEcs | invariant (DB) |
| component types restricted to 10 typed kinds (DB CHECK) | _base.php ensureEcs | invariant (DB) |
| Soft-delete via is_active; hard deletes only in recalc (cost comps) & cleanup | quotes.php:247, systems.php:150 | design |
| Prefab instantiate recalc is non-fatal (try/catch) | prefabs.php:306-309 | design |
| Cost rounding: round2 at every stored number | cost.php:458 r2 | design |
| list_quotes is light — reads persisted cost comp, never recalcs | systems.php:170-194 | design (perf) |
| **NEW:** kind-aware material costing — pipe/fitting/flange/material each have distinct mass/cost formulas | cost.php:101-133, 220-290 | design |
| **NEW:** default on-costs policy when none configured (2.5% consumables, 1% services, 1.5% NDT, transport by mass) | cost.php:361-388, APPLY_DEFAULT_ON_COSTS | business |
| **NEW:** paint auto-estimation only when paint configured (mode/rate/area) | cost.php:334-377 | business |
| **NEW:** weld model integration — size/length/hours/mass from api/weldmodel.php | cost.php:231-299, api/weldmodel.php | design |

## 6. Failure modes

| Failure | Where | Behavior |
|---|---|---|
| Unauthenticated call | forge Base handle | 401 `{"error":"Unauthorized","code":401}` — explicit ✅ |
| Cross-user access | getEntity/WHERE owner | 404 "Quote not found." — **silent** (no 403, no leak of existence) ✅ |
| Invalid transition | quotes.php | 409 + allowed list ✅ |
| BOM material no-match | boms.php matchMaterial | item imported **with zero material cost**, no warning surfaced (UI toast claims "materials matched") ⚠️ |
| load_quote on non-quote entity | systems.php:41 | 404 "Quote not found." |
| recalc failure during instantiate | prefabs.php:306 | swallowed — instance still created, `total_cost:null`, no error to UI |
| A36-style searches | materials.php match | `[]` — empty results indistinguishable from "no such material" |
| CSV/textarea parse | quote/view/view.js parseBomRows | malformed lines skipped silently; empty input → toast "No valid rows to import" |
| recalc clears cost comps for ALL quote entities, incl. those without inputs | systems.php:150-160 | cost comps deleted then rewritten — fine, but a mid-crash leaves quotes without cached totals (recovered on next load) |
| **NEW:** boms.takeoff on empty quote | boms.php handle_takeoff | returns `{materials:[],totals:{...0},error:'Quote has no items.'}` — no crash |
| **NEW:** compat finds missing flanges | boms.php handle_compat | suggestions populated from library; user can click to add — no auto-insert
| **NEW:** Weld size too large for wall thickness | api/weldmodel::weldSizeFor | returns largest size (50mm) if WT exceeds range; no error surfaced |
| **NEW:** Paint silently zero when extArea=0 and paint not configured | cost.php paint derivation | `paint` stays 0; no warning if user expects auto-estimation |
| **NEW:** Default on-costs not applied when paint IS configured | cost.php:361 | `applyDefaultPolicy` check: only when `empty($onCosts) && !$paintConfigured && self::APPLY_DEFAULT_ON_COSTS` |
| **NEW:** Fitting/flange with no unit_cost falls back to material-type rate | cost.php:223-230 | SS: R6.5/kg, Al: R4.5/kg, carbon: R3.2/kg — may misprice exotic materials |
| **NEW:** Transport rate multiplier for subcontract (1.35×) | cost.php:381 | `transportRatePerTon` = 850 × 1.35 = 1147.5; easy to miss in audit |

## 7. Drift log

| # | Code | Docs | Status |
|---|---|---|---|
| 1 | **cost-engine + UI WIP landed mid-session** (uncommitted): cost.php rewritten with weldmodel integration (PAINT_RATES, PIPE_SHOP_HRS_PER_KG, TRANSPORT_PER_TON, paint auto-estimation, default on-costs policy, kind-aware material costing), new api/weldmodel.php + scripts/test-cost-engine.php, edititem.js/html +quote/view/view.js extended (+185 lines), materials.php/library.js/materiallist.js, fittings.json +38k lines | MAP.md/CONTEXT.md describe the old engine | ⚠️ docs + MAP.md §7 decision #5 need refresh after WIP lands (as of 17:00 WIP still uncommitted on main) |
| 2 | PLAN.md "DDP Event Mapping" section | Zero DDP code exists | ⚠️ legacy, never implemented (MAP.md already flags) |
| 3 | api file list in earlier CONTEXT.md was missing 12 endpoints | — | fixed in this session (MAP.md §3) |
| 4 | cost component holds BOTH legacy keys (mHrs/wHrs/bmHrs/cons/serve/matCost/labCost/paintMode) and current keys (weldHrs/machHrs/consumables/…) merged | cost.php writes only current keys | ⚠️ legacy keys appear because... `patchComponentData` jsonb-merges onto any pre-existing comp; the legacy keys likely written by an earlier cost.php version and never cleared. Harmless but confusing; verify after WIP settles |
| 5 | seed-data/fittings.json +38k lines, materials.php/library.js/materiallist.js modified (uncommitted) | MAP.md seed-data section | ⚠️ WIP in flight |
| 6 | tests/phases phase3 cost assertions | cost.php changed | ✅ resolved — ran `./tests/run-phase.sh phase3` (API_BASE=127.0.0.1:8099): 50/50 passed against the new engine. Note: phase3 passes explicit options; the new auto-paint path (inhouse R45/sqm) is only exercised when no paint option is given and extArea > 0. |
| 7 | **NEW:** weldmodel.php untracked WIP file — not in git history until committed | — | ✅ resolved — committed; pure math class, static methods only, included by cost.php |
| 8 | quoteview → quote/view rename + components/{clientlist→client/list, materialedit→material/edit, etc.} | MAP.md §2, §3, §8; LOGIC.md §2 S5, §4 | ✅ resolved — docs updated in this session to use quote-view tag

## 8. Map overlay index (MAP.md ↔ LOGIC.md)

| MAP.md section | LOGIC.md section |
|---|---|
| §1 Boot chain (init.php) | §0 overlay |
| §2 Component hierarchy | §0 overlay, §2 S5 |
| §3 API callers (matrix) | §0, §1, §2 S1-S3 chains |
| §4 DDP event bus (none) | §0, §1 |
| §6 Database | §1 (ownership, team model), §5 (DB CHECKs) |
| §7 Architectural decisions | §1, §2 S4, §5 |
| §5 Forge vs custom | §0, §1 |
| §8 New: boms compat/takeoff | §4 data transformations |

## 9. Replay recipes

```bash
# S1 auth + lifecycle (replace API with live base)
API=http://127.0.0.1:8099/api
curl -s -X POST $API/auth.php -d '{"action":"login","input":{"email":"api-test@fabricate.local","pass":"TestPass123!"}}'
# → auth_id; use inside .input for every subsequent call:
curl -s -X POST $API/quotes.php -d '{"action":"create","input":{"name":"replay","auth_id":"<AID>"}}'
curl -s -X POST $API/systems.php -d '{"action":"load_quote","input":{"quote_id":"<QID>","auth_id":"<AID>"}}'
# guards:
curl -s -X POST $API/quotes.php -d '{"action":"update_status","input":{"quote_id":"<QID>","status":"rejected","auth_id":"<AID>"}}'   # 409
curl -s -X POST $API/systems.php -d '{"action":"load_quote","input":{"quote_id":"<QID>"}}'                                              # 401

# S1b team invite flow
# owner creates team + invites by email; existing user joins, new signup auto-joins
curl -s -X POST $API/team.php -d '{"action":"create","input":{"name":"Acme Builders","auth_id":"<AID>"}}'
curl -s -X POST $API/team.php -d '{"action":"invite","input":{"team_id":"<TID>","email":"colleague@example.com","auth_id":"<AID>"}}'
curl -s -X POST $API/team.php -d '{"action":"my_team","input":{"auth_id":"<AID>"}}'

# S2 BOM import
curl -s -X POST $API/boms.php -d '{"action":"import","input":{"quote_id":"<QID>","rows":[{"item_number":"1","description":"Skid Frame"},{"item_number":"1.1","description":"Mounting Plate A36","material":"A36"}]}}'
curl -s -X POST $API/links.php -d '{"action":"tree","input":{"entity_id":"<QID>","depth":10}}'

# S2b takeoff + compat (quote-view Materials / Checks tabs)
curl -s -X POST $API/boms.php -d '{"action":"takeoff","input":{"quote_id":"<QID>","auth_id":"<AID>"}}'  # → {materials, totals}
curl -s -X POST $API/boms.php -d '{"action":"compat","input":{"quote_id":"<QID>","auth_id":"<AID>"}}'  # → {issues, suggestions, ok}
curl -s -X POST $API/suppliers.php -d '{"action":"list","input":{"limit":200,"auth_id":"<AID>"}}'      # for takeoff-split

# S3 prefab
curl -s -X POST $API/prefabs.php -d '{"action":"list","input":{}}'
curl -s -X POST $API/prefabs.php -d '{"action":"instantiate","input":{"prefab_id":"<PFID>","quote_id":"<QID>"}}'

# S5 UI HAR
agent-browser network har start /tmp/flow.har
agent-browser open http://127.0.0.1:8099/        # then LS.set: localStorage.setItem('fabricate_auth_id','<AID>'); location.reload()
# drive: click Quotes tab → click quote row
agent-browser network har stop /tmp/flow.har

# DB ground truth
export $(grep -v '^#' .env | grep '=' | xargs)
PGPASSWORD=$DB_PASS psql -h $DB_HOST -p $DB_PORT -U $DB_USER -d $DB_NAME -c "select jsonb_pretty(data) from component where type='cost' and entity_id='<EID>';"

# Test phase3 (new engine)
./tests/run-phase.sh 3

# Inspect new cost component fields
PGPASSWORD=$DB_PASS psql -h $DB_HOST -p $DB_PORT -U $DB_USER -d $DB_NAME -c \
  "select jsonb_pretty(data) from component where type='cost' and entity_id='<EID>';" | jq '.kind, .weldSize, .weldLengthM, .weldMetalKg, .weldType, .extArea, .intArea, .paintMode, .transportPerTon'
```

## 10. Complete file inventory

> Tag = `components/a/b/x` → `a-b-x`. Loads via: 🚀 boot (`start_comp`) / 🔗 route / 💬 template ref / 📦 popup / ⚙ script / 📄 manual.
> Component rows group the html/js/css triplet (25 top-level dirs + 17 subdirs = 42 component dirs). Inventory total: 145+ files on disk (excl. data/ reference + .git) — all covered below.

### Root / boot
| File | Role | Loads via | Flags |
|---|---|---|---|
| index.php | SPA shell; `#main start_comp=nav default_tab=dashboard` | 🚀 | ✅ open in browser |
| bootstrap.php | session cookie hardening + security headers | ⚙ every PHP entry | |
| comp.php | proxy → forge/php/comp.php resolver | 🔗 comp.php?comp= | |
| style.css | design tokens + app styles (dark default) | 📄 index.php | |
| .env / .env.example | DB creds, APP_URL, FORGE_PATH | ⚙ | |
| MAP.md / CONTEXT.md / DESIGN.md / PLAN.md | docs | 📄 | PLAN.md partially stale (drift #2) |
| .gitignore | — | | |

### lib/
| File | Role | Flags |
|---|---|---|
| lib/config.php | .env + config.json loader (loadConfig) | |
| lib/vue.php | serves forge vue.js | |
| lib/init.php | boot order: _util → router → LS.pre='fabricate' → svg-cache clear → **landing-first processPath patch** → processClear patch → forge_comp_js cache-bust → isReservedTag('nav') override | the app's routing brain |
| lib/svg.php | forge SVG icon server passthrough | forge-svg fetch target |

### api/ (24 files)
| File | Handlers (action → handle_) | Flags |
|---|---|---|
| api/_base.php | ECS ensureEcs, dispatchIfEntry, Base (user scoping, getEntity/getComponents/getLinks/patchComponentData) | core |
| api/auth.php | login signup logout verify forgot_password reset_password (extends forge Auth) | public actions |
| api/user.php | get_preferences update_preferences login signup (proxy) | |
| api/admin.php | get_settings update_settings list_users set_user_role | admin only UI-side |
| api/team.php | preview_invite create list invite revoke_invite join members remove_member my_team | 🆕 team/invite model |
| api/entities.php | list get get_full search create update delete | ECS core |
| api/components.php | list get get_by_quote create update replace delete | ECS core |
| api/links.php | list tree create update delete validate_cycle | ECS core; tree used by quote-view |
| api/cost.php | calculate_entity calculate_assembly batch_calculate get_cost | ⚠️ WIP — see drift #1; now includes weldmodel integration, kind-aware material costing, default on-costs policy, paint derivation |
| api/systems.php | list_quotes load_quote recalculate_quote | orchestration |
| api/quotes.php | list get create update update_status delete add_entity remove_entity add_items export_pdf | workflow layer |
| api/boms.php | calculate compat import takeoff | |
| api/materials.php | list get get_density match create update delete | ✅ pipe/fitting/flange attribute columns added (od wt schedule nb nps mass_kg paint_area_per_m ext_area) — 2026-08-10 |
| api/prefabs.php | list get create update delete instantiate bake_from_quote | |
| api/process.php | get_registry extract aggregate calculate_entity + static extractItems/mergeHours/sumHours | |
| api/rates.php | globals company entity get_effective get_all_effective set_company_rates set_entity_rate | hierarchy |
| api/orders.php | list get create update set_status delete | |
| api/procurement.php | po_list po_create po_update po_set_status sq_list sq_create rg_list rg_create | |
| api/production.php | record_list record_create record_variance quote_summary | |
| api/reports.php | margin_summary cost_by_client monthly_summary cost_by_trade quote_funnel | |
| api/suppliers.php | list get create update delete | 🆕 supplier mgmt |
| api/tools.php | calculate (pure math: plate/section/general/welding/machining/assembly/tank/pipe) density | no tables |
| api/weldmodel.php | (static class — pure math, no handlers; included by cost.php) | 🆕 weld math (sizes, deposition, hours, areas) |

### components/ (27 top-level dirs + 8 subdirs = 35 component dirs)
| Component dir | Tag | Role / dataflow | Loads via | Flags |
|---|---|---|---|---|
| nav | nav | shell: tabs, auth gate, quote-view routing | 🚀 | ✅ HAR-verified |
| landing | landing | public welcome page → login/signup | 🔗 /landing | |
| forgot | forgot | /forgot-password → auth.php forgot_password | 🔗 | |
| reset | reset | /reset-password/<token> → auth.php reset_password | 🔗 | |
| dashboard | dashboard | stats cards; systems.list_quotes + user.get_preferences | 🔗 tab | |
| quotes | quotes | main list; systems.list_quotes, clients.list, quotes.create | 🔗 tab | ✅ HAR-verified |
| quote-form | quote-form | New/Edit popup body; user.get_preferences | 📦 POPUP | |
| client-select | client-select | picker trigger → POPUP client/list; clients.list/create | 💬 | |
| client-list | client-list | searchable client list (popup body) | 📦 POPUP | |
| clients | clients | client CRUD page; clients.list/create | 🔗 tab | |
| quote-view | quote-view | quote detail: 7 tabs (Overview | Entities | BOM | Materials | Tree | Checks | Process), 12-col cost grid, tree-node comp; systems.load_quote/recalculate, quotes.update/update_status/export_pdf/add_items/import, components.update/create, materials.list, prefab-picker/list, links.tree, boms.compat/takeoff, suppliers.list | 🔗 /nav/quotes/<id> | ✅ HAR-verified; largest comp |
| quote-items | quote-items | batch line-item popup → quotes.add_items | 📦 POPUP | |
| edititem | edititem | entity editor popup (material-select + trades); entities.update, components.update/create | 📦 POPUP | |
| material-select | material-select | picker trigger → POPUP material/list | 💬 | |
| material-list | material-list | searchable material list (popup body); materials.list | 📦 POPUP | ⚠️ modified |
| prefab-picker | prefab-picker | prefab picker popup; prefabs.list | 📦 POPUP | |
| prefabs | prefabs | templates page: list/create/instantiate/bake; systems.list_quotes/load_quote | 🔗 tab | |
| library | library | materials library page; materials.list | 🔗 tab | ⚠️ modified |
| tools | tools | calculators; tools.calculate/density | 🔗 tab | |
| orders | orders | orders page; orders.list/create/update, systems.list_quotes | 🔗 tab | |
| procurement | procurement | PO/SQ/RG tabs; procurement.* | 🔗 tab | |
| production | production | records+variance; production.record_list/create | 🔗 tab | |
| reports | reports | reports; systems.list_quotes, reports.*, quotes.export_pdf | 🔗 tab | |
| settings | settings | prefs+company rates; user.get/update_preferences, admin.get/update_settings | 🔗 tab | |
| about | about | static overview | 🔗 tab | |
| admin | admin | user mgmt (admin role); admin.list_users/set_user_role | 🔗 tab (admin) | |
| material-edit | material-edit | inline material editor (library, WIP) | 📦 inline | 🆕 |
| onboard | onboard | first-run setup wizard | 🚀 boot | 🆕 untracked |
| suppliers | suppliers | supplier management page | 🔗 tab | 🆕 untracked |
| takeoff-split | takeoff-split | split takeoff by supplier group (CSV/PDF) | 📦 POPUP | 🆕 untracked |
| library/fasteners | — | sub-table for fasteners in library | 📦 inline | 🆕 |
| library/fittings | — | sub-table for fittings in library | 📦 inline | 🆕 |
| library/flanges | — | sub-table for flanges in library | 📦 inline | 🆕 |
| library/pipe | — | sub-table for pipe in library | 📦 inline | 🆕 |
| library/plates | — | sub-table for plates in library | 📦 inline | 🆕 |
| library/sections | — | sub-table for sections in library | 📦 inline | 🆕 |
| library/tube | — | sub-table for tube in library | 📦 inline | 🆕 |

### seed-data / scripts
| File | Role | Flags |
|---|---|---|
| seed-data/materials.json | global library seed (102+ rows) | |
| seed-data/fittings.json | pipe fittings seed | ⚠️ +38k lines uncommitted |
| seed-data/fasteners.json / flanges.json / pipes.json | fastener/flange/pipe seeds | |
| scripts/seed-materials.php | DB seed for materials | ⚠️ modified |
| scripts/seed-edit-test.php | entity material/process cost patching test | |
| scripts/test-cost-engine.php | end-to-end cost engine smoke test (pipe/flange/fitting vs real library rows) | 🆕 untracked, part of cost-engine WIP |
| scripts/test-mock-estimation.php | mock estimation engine test | 🆕 |
| scripts/seed-prefabs.php | global prefab templates seed | |
| scripts/seed-test-quote.php | test quotes incl. Tank Skid | |
| scripts/build-fittings-seed.js / build-flanges-seed.js / build-pipes-seed.js | xlsx→json builders | 🆕 |
| scripts/build-boq-import.php / import-boq-quote.php | BOQ import pipeline | |
| scripts/setup-5-mock-quotes.php | multi-quote fixture loader | |
| scripts/xlsx_to_md.js / get_sheets.py / get_sheet_names.py | data/md pipeline | |
| scripts/purge-test-data.sql | hard-delete test rows | ⚙ tests only |

### tests/
| File | Role |
|---|---|
| tests/run-phase.sh | harness: login → fixture → assert (jq) → cleanup; clean_test_data SQL |
| tests/phases/phase1..11.sh | ECS / ref data / cost / orchestration / lifecycle / support / library / modules / extras / reports / weldmodel (11 files; phase11 new) |

### assets/
| File | Role |
|---|---|
| assets/hero-drawing.jpg, hero-steel.jpg | landing page hero imagery |

### data/ (excluded from code inventory — reference data)
| Path | Role |
|---|---|
| data/*.xlsx/.xlsm | piping reference sources (bends, flanges, pipe details, BoQ) |
| data/md/ | md exports consumed by seed builders |

## 11. Next traces to verify live (backlog)

- [x] ~~Re-run `./tests/run-phase.sh phase3`~~ — ✅ 50/50 green on new paint/transport engine (2026-08-10); note the auto-paint path is untested by the phase (options always passed explicitly)
- [x] ~~quoteview → quote/view rename~~ — ✅ doc refs updated in this session
- [ ] Dashboard stats math — verify list_quotes + client-side aggregation matches DB (dashboard.js)
- [ ] reports.php margin_summary/cost_by_client — cross-check numbers vs load_quote totals
- [ ] procurement + production full CRUD cycle (po_create→po_set_status→rg_create)
- [ ] export_pdf — open generated HTML, verify totals row vs quote-view grid
- [ ] admin set_user_role → nav visibleTabs (admin tab appears for new admin)
- [ ] boms import with material present in library (e.g. "304" plate) — verify nonzero material cost flows through
- [ ] tools.php calculate consistency vs cost.php for the same inputs (AD decision #5)
- [ ] **NEW:** Re-verify S4 cost engine scenarios with the new weldmodel-integrated engine — welding/hours/mass now computed from weldmodel; compare against old verified numbers
- [ ] **NEW:** Test default on-costs policy (items with no on-costs configured) — verify consumables=2.5%, services=1%, NDT=1.5% of material+process, transport by ton
- [ ] **NEW:** Test kind-aware material costing — pipe/fitting/flange/material paths with and without library matches; verify fallback rates (SS/Al/carbon)
- [ ] **NEW:** Run phase11 (full mock costing) — multi-level BOM rollup, all trades, on-costs, paint+transport+margin
- [ ] **NEW:** Test team invite flow end-to-end (create team → invite email → signup auto-join → members list)
- [ ] **NEW:** Test boms.compat on a pipe without a flange — verify suggestions populated from library