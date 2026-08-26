
## codebase-map
# Auto-scanned: 2026-08-13

project: fabricate_forge
framework: Forge (Vue 2.6 + PHP)
root: /var/www/html/fabricate_forge
  forge_path: /var/www/html/forge (from .env FORGE_PATH)
  icm: ./CONTEXT.md

entry_points:
  - index.php: SPA shell (`<div id="main" start_comp="nav" default_tab="dashboard">`)
  - comp.php: component resolver proxy → forge/php/comp.php
  - bootstrap.php: session hardening + security headers

routes:
  - /landing: public welcome (welcome-first; nav.js redirects unauth here)
  - /login, /signup: forge auth components (forge/components/auth/)
  - /join: onboard component (invite-link signup via team.preview_invite)
  - /forgot-password: forgot component → auth.forgot_password
  - /reset-password/<token>: reset component → auth.reset_password
  - /nav/<tab>[/<id>]: authed shell; quote detail is /nav/quotes/<id> → quote detail shell (setPage 'quote')

tree:
  - api/
    - _base.php
    - admin.php
    - auth.php
    - boms.php
    - clients.php
    - components.php
    - cost.php
    - entities.php
    - import.php
    - links.php
    - materials.php
    - orders.php
    - prefabs.php
    - process.php
    - procurement.php
    - production.php
    - quotes.php
    - rates.php
    - reports.php
    - rfq.php
    - suppliers.php
    - systems.php
    - team.php
    - tools.php
    - user.php
    - weldmodel.php
  - assets/
  - components/
    - about/
      - about.css
      - about.html
      - about.js
    - admin/
      - admin.css
      - admin.html
      - admin.js
    - client/
      - list/
        - list.css
        - list.html
        - list.js
    - clients/
      - clients.css
      - clients.html
      - clients.js
      - select/
        - select.css
        - select.html
        - select.js
    - dashboard/
      - dashboard.css
      - dashboard.html
      - dashboard.js
    - edititem/
      - edititem.css
      - edititem.html
      - edititem.js
    - forgot/
      - forgot.css
      - forgot.html
      - forgot.js
    - landing/
      - landing.css
      - landing.html
      - landing.js
    - library/
      - library.css
      - library.html
      - library.js
      - fasteners/
        - fasteners.html
        - fasteners.js
      - fittings/
        - fittings.html
        - fittings.js
      - flanges/
        - flanges.html
        - flanges.js
      - pipe/
        - pipe.html
        - pipe.js
      - plates/
        - plates.html
        - plates.js
      - sections/
        - sections.html
        - sections.js
      - tube/
        - tube.html
        - tube.js
    - material/
      - edit/
        - edit.css
        - edit.html
        - edit.js
      - list/
        - list.css
        - list.html
        - list.js
      - select/
        - select.css
        - select.html
        - select.js
    - nav/
      - nav.css
      - nav.html
      - nav.js
    - onboard/
      - onboard.css
      - onboard.html
      - onboard.js
    - prefab/
      - picker/
        - picker.css
        - picker.html
        - picker.js
    - prefabs/
      - prefabs.css
      - prefabs.html
      - prefabs.js
    - quote/
      - form/
        - form.css
        - form.html
        - form.js
      - items/
        - items.css
        - items.html
        - items.js
      - rfq/
        - rfq.css
        - rfq.html
        - rfq.js
      - view/
        - view.css
        - view.html
        - view.js
    - quotes/
      - quotes.css
      - quotes.html
      - quotes.js
    - reports/
      - reports.css
      - reports.html
      - reports.js
    - reset/
      - reset.css
      - reset.html
      - reset.js
    - settings/
      - settings.css
      - settings.html
      - settings.js
    - suppliers/
      - suppliers.css
      - suppliers.html
      - suppliers.js
    - takeoff/
      - split/
        - split.css
        - split.html
        - split.js
    - tools/
      - tools.css
      - tools.html
      - tools.js
  - data/
    - backups/
    - md/
      - 3D_BENDS_DATA/
      - FLANGE_ASME_B16_5_CLASS_600_RTJ/
      - Flanges_TS_-28_07_26/
      - PB23068_EX_MUG_Sandsloot_Decline_Piping_Tender_BoQ_03_08_2026_REV_3/
      - PIPE_DETAILS__SUMMARY_A106B-SANS_719-SANS_62/
      - PIPE_FITTING_DATA/
      - PIPE_FITTING_DATA-_MASTER_TABLE_AS_RANGE/
  - lib/
    - config.php
    - init.php
    - svg.php
    - vue.php
  - scripts/
    - build-fittings-seed.js
    - build-flanges-seed.js
    - build-pipes-seed.js
    - get_sheet_names.py
    - get_sheets.py
    - migrate-material-library.php
    - purge-test-data.sql
    - seed-edit-test.php
    - seed-materials.php
    - seed-prefabs.php
    - seed-spool-mock.php
    - setup-5-mock-quotes.php
    - test-cost-engine.php
    - test-mock-estimation.php
    - xlsx-to-rows.py
    - xlsx_to_md.js
    - sandsloot/
      - 01_parse_boq.php
    - seed-test-quote.php
    - seed-spool-mock.php
    - setup-5-mock-quotes.php
    - test-cost-engine.php
    - test-mock-estimation.php
    - xlsx-to-rows.py
    - xlsx_to_md.js
    - archive/  (one-off migrations: backfill-short-names.php, recalc-quote.php, reencode-quote.php)
  - sandsloot/
    - 01_parse_boq.php
    - 02_preview_quote.php
    - 03_seed_quote.php
    - output/
  - seed-data/
    - fasteners.json
    - fittings.json
    - flanges.json
    - materials.json
    - pipes.json
  - tests/
    - phases/

scripts:
  (none detected)

database_configs:
  - .env: env vars
  - api/_base.php: DB connection (PHP)

routes:
  (none detected)

external_deps:
  - api

file_counts:
  php: 40
  vue: 0
  js/ts: 41
  styles: 31
  docs: 41

symbols:
  components:
    - nav (shell), dashboard, quotes, quote detail shell (quote/view), quote-form, quote-items, edititem, quote-rfq, clients, client-select, client-list, suppliers, library (+sub-tables), tools, prefabs, shop-floor (Orders/Procurement/Production subtabs), quote detail tabs (overview/entities/bom/materials/tree/checks/process/rfq), reports, settings, about, admin, onboard, forgot, reset, landing, takeoff-split
  api_endpoints:
    - admin.php, auth.php, team.php, _base.php, boms.php, clients.php, components.php, cost.php, entities.php, import.php, links.php, materials.php, orders.php, prefabs.php, process.php, procurement.php, production.php, quotes.php, rates.php, reports.php, rfq.php, rfq_documents.php, suppliers.php, systems.php, tools.php, user.php, weldmodel.php
  tables:
    - auth, user, user_prefs, password_reset, team, team_member, pending_invite, entity, component, link, client, supplier, company_settings, material_library (legacy mirror — materials are entities), rfq_document, order, prefab_template, prefab_instance, purchase_order, supplier_quote, received_goods, production_record, production_variance

design_system:
  colors:
    - --accent: #2563eb
    - --accent: #3b82f6
    - --accent-hover: #3b82f6
    - --accent-hover: #60a5fa
    - --accent-soft: #dbeafe
    - --accent-soft: rgba(59,130,246,0.15)
    - --bg: #0f172a
    - --bg: #f8fafc
    - --border: #475569
    - --border: #cbd5e1
    - --border-hover: #64748b
    - --border-hover: #94a3b8
    - --forge-button-disabled-bg: rgba(59, 130, 246, 0.4)
    - --forge-button-text: #ffffff
    - --forge-form-submit-text: #ffffff
    - --hover-bg: rgba(37,99,235,0.05)
    - --hover-bg: rgba(59,130,246,0.08)
    - --secondary: #7c3aed
    - --secondary: #8b5cf6
    - --text: #1e293b
  fonts:
    - Inter
    - Inter,-apple-system,sans-serif
    - var

key_files:
  # (add contextual notes as you discover work)

## Domain glossary

- **Quote** — a customer quotation. An `entity` row with type='quote'; carries customer info, currency, validity, margin, status + history in its data JSONB. Lifecycle: draft → submitted → approved → invoiced (rejected from submitted; every state can return to draft).
- **Entity** — anything costed inside a quote: part | assembly | fastener | fitting | material | quote. Owns components and links. Belongs to exactly one quote via quote_id (materials are the exception — the shared library).
- **Component** — typed data block attached to an entity: basic, dimensions, material, cost, process, rate, specification, notes, status, cadData. Merged via jsonb (partial patches).
- **Material** — reference data (entity type='material') with specification/dimensions/rate components. The shared library is owned by the canonical library owner; users can have their own. Editable only via the library API; quote entities reference via materialLibraryId (instance dims on the comp, base data on the material).
- **Fitting** — bought-in pipe hardware (flanges/elbows/valves/gaskets…). Entity type 'fitting': cost = purchase price + margin — no fabrication variables unless an explicit process comp exists.
- **Link** — relationship between two entities: contains (parent→child, the BOM edge), references, suppliedBy, uses, dependsOn, relatedTo. Carries a quantity (structural — entity quantity is the BoQ count).
- **BOM** — bill of materials = the `contains` link tree rooted at a quote/assembly. Tree tab renders it recursively; cost rolls up children.
- **Prefab** — reusable assembly template (prefab_template). Instantiate = copy its ECS tree into a quote (with server-side recalc); Bake = save a quote's assembly as a template.
- **Cost columns (12)** — material, boilerHrs, weldHrs, machHrs, labor, consumables, services, ndt, lining, paint, transport, total. The quote detail overview grid + reports key on these.
- **Trade** — process discipline the cost engine prices: boilermaking, welding, machining, painting, assembly, qualityControl, surfaceTreatment, cutting, drilling, grinding, bending.
- **Margin** — quote.data.marginPercent → user_prefs.defaultMarkupPercent → default chain; per-entity override via entity.data.marginPercent.
- **BoQ intake** — the RFQ tab's flow: upload the client's tender BoQ (xlsx/csv/pdf/model) → the document is **persisted** in the Forge DB file store (files_meta + files_data, SHA-256 deduped) AND parsed (api/import.php parse_boq) → normalized rows with issue flags (ok/unclear/error/duplicate/skip) → review grid → import into the quote. Every imported entity carries `boq_source_file` (→ files_meta.id → serve.php), `boq_item_no`, `boq_section`, `boq_qty`, `boq_unit` in its data JSONB for full source traceability. Nothing is silently dropped. The original document is viewable via `serve.php?id=<file_id>&auth_id=<token>`.
- **Procurement docs** — Purchase Order (po), Supplier Quote (sq, against a material), Received Goods (rg). Form a simple 3-table purchasing flow.
- **Production record** — actual vs estimated hours per entity+trade; variance rows derived at record time.
- **Team** — grouping of users via invite (team.php). Owner creates teams; invite by email → existing user auto-joins, new signup auto-joins via invite_code. One team per user. Powers the onboard/join flow.
- **Supplier** — material supplier (supplier table). Linked to material entities (rate component); used in the take-off split to generate per-supplier RFQ CSVs/PDFs.
- **BoQ provenance** — lineage data carried in every entity's `data` JSONB: `boq_source_file` (UUID → files_meta), `boq_item_no` (original BoQ line number), `boq_section`, `boq_qty`, `boq_unit`, `boq_desc`. Set during RFQ import; lets the tree/entities/takeoff views trace any entity back to its source BoQ row and view the original document via serve.php.

## Pricing & structure directives (USER DECISIONS — override everything below)

- **D1 Green length** — extra/green length is `length_secondary` on the material comp; priced in material cost AND take-off.
- **D2 Threading** — a machining operation: manual `machining` process op on the item. No auto thread-hours.
- **D3 Welding** — manual process comps on parts AND assemblies (type + UoM hrs/kg/m + description). No auto joint detection. Assemblies carry their own welding (spool = linked parts/fittings + own welding on Process tab); assemblies never carry material.
- **D4 Spec authority** — these directives are the pricing spec; engine code is not.
- **D5 SINGULAR ENTITY MODEL** — an entity is ONE unique part/assembly (quantity always 1). Quantity is LINK data on contains-links; rollups multiply along links; quote total = root rolled_total. Imports/add surfaces must not stamp entity quantity.
- **STRUCTURAL TRUTH RULE** — the link table is the only structure store (contains edges carry parentage AND quantity). `entity.quote_id` = denormalized scoping metadata, never used for parent resolution or quantity. No 'contains' component may exist.

## Architecture decisions (ADRs)

- **ECS data model** — entity/component/link with uniform `(id, type, data JSONB, owner)` shape; generic handlers. Hard to reverse (every table/endpoint builds on it).
- **Real-time: none** — no DDP/websockets; REST request/response with reads composing from component-set queries (systems.overview = entity + persisted cost comp, entities.list, components.get_by_quote). recalculate_entity is the only system-invocation path (on mutation).
- **Server-side cost math** — calculators (tools.php) and quote costs (cost.php) share one engine so tool results always equal quote numbers. Weld math lives in `api/weldmodel.php` (pure static class, no DB).
- **Quote detail is a page, not a tab** — /nav/quotes/<id> mounts the quote detail shell via forge-nav.setPage('quote'); nav re-resolves on onPathChange so back/forward restores the list.
- **dispatchIfEntry guard** — cross-endpoint includes (quotes→systems→cost) never double-dispatch; each file dispatches only as the HTTP entry.
- **Team / invite model** — teams (team.php) group users; invite flow is cookie-free for explicit links but uses `fab_invite` cookie as a fallback. Owner-only mutation; preview is public for the landing page.
- **Reads never execute systems** — load_quote removed; `overview` (entity + persisted cost comp) + `recalculate_entity` (the only system-invocation path) + entities.list + components.get_by_quote compose. Reads never write.
- **~~Per-piece quantity semantics~~ SUPERSEDED by D5** — entity quantity was the BoQ count; under D5 entities are singular and ALL quantity lives on contains-links.
- **Bought-in fittings** — flanges/elbows/valves/gaskets are type 'fitting' (not 'part'); cost = purchase price + margin; fabrication (SO-flange welding + fit-up) lives on the assembly's process comp.
- **Materials-as-entities** — the library is `type='material'` entities with specification/dimensions/rate components; `material_library` table is the legacy seed mirror (migration: scripts/migrate-material-library.php). Editable only via materials.php (the library).
- **BoQ intake / RFQ tab** — client BoQs are uploaded and **persisted** (Forge DB file store via `forge\db\Files`), parsed via `api/import.php::parse_boq` into normalized rows with issue flags (ok/unclear/error/duplicate/skip); the RFQ tab (`api/rfq.php` + `api/rfq_documents.php` + `components/quote/rfq`) = upload → review grid → import. Imported entities carry `boq_source_file` provenance. xlsx parsed via `scripts/xlsx-to-rows.py` (python/openpyxl — no PHP spreadsheet lib). serve.php at project root streams persisted files.
- **Welding is a property of the assembly/joint, not the part (ECS composition rule)** — a bare pipe/plate part is just material at given dimensions, priced by material; it has NO inherent weld/thread hours. Flanges and fittings are **bought-out** (type 'fitting'): cost = purchase price + margin, no fabrication variables on their own line. All fabrication hours — butt/slip-on/socket-weld, threading, fit-up — arise **only at the joint where a part meets a bought-out fitting/flange**, and they live in the **containing assembly** (rolled up via the cost tree), NOT on the pipe or the fitting itself. Therefore: costing a joint is derived from the *connection* (weld vs thread, which ends, what OD/WT), not from a magic per-entity count. Current model is HALF-implemented: the fitting carve-out (`$isFitting` zeroes fabrication) is in, but joint hours are still computed per-entity inside cost.php and pipe carries weld hours when `buttWeldQty` happens to be set — no assembly-level joint derivation, no thread concept, and the bend/green-length between joints is not modelled. (Cost-seam ADR says cost.php owns the writes; this ADR is about WHERE joint hours are sourced: the containing assembly's joint descriptors.)
- **Cost is ONE system, ONE component type (ECS boundary)** — cost-related state for an entity lives in exactly one `component` row of `type='cost'` (the L1–L5 result). It is the single source of truth for cost. `api/cost.php` is the ONLY system that reads or writes it:
  - computing (L1–L5), persisting/upserting the comp, and reading it back are all in cost.php.
  - `process`, `rates`, `weldmodel`, and the `material`/`rate`/`process` component types are **internal inputs** to cost.php — no caller outside cost.php reaches for them directly (process.extract stays as a read helper the UI can call, but pricing/persisting cost is cost.php-only).
  - every other consumer — `systems` (overview/rollup), `quotes` (totals/export), `boms` (takeoff/compat), `components.get_by_quote`, the UI — obtains cost **only** by asking cost.php's seam (e.g. `get_entity`/`get_by_quote`), never by querying `type='cost'` rows or re-deriving material math itself.
  - **Read vs compute:** reads return the persisted comp and are pure; computing/writing happens only on mutation via `recalculate_entity` (aligns with the existing *Reads never execute systems* ADR).
  - This supersedes the older *Server-side cost math* and *Reads never execute systems* ADRs' *scattered* reading — the guarantee (tool numbers == quote numbers, reads never write) is unchanged, only the seam is now owned by one system.
  - **Verdict-checkable:** `git grep "type.*cost" api/` must return only cost.php (+ the systems.php recalc write, which is delegated to cost.php). Any other file holding a `type='cost'` read/write is a regression of this ADR.
- **Systems-mediated reads** — screens never call ECS core (`entities/`components/`links.php`) for entity-scoped views; they call the generic `systems.entity_items` / `systems.entity_tree` (and `overview`). ECS endpoints remain internal seams that systems compose server-side, plus generic CRUD for non-view contexts. Parent resolution walks the **link table** — never the denormalized `quote_id` column (structural truth rule). Motivation: quote view's direct `entities.list` silently truncated at 2,000 rows and dumped every component row on page open — unowned read paths get no scoping enforcement. A quote is just an entity with children; nothing quote-flavored belongs in graph reads.
