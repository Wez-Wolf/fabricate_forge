
## codebase-map
# Auto-scanned: 2026-08-12

project: fabricate_forge
framework: unknown
root: /var/www/html/fabricate_forge
  icm: ./CONTEXT.md

entry_points:
  - index.php: PHP entry
  - comp.php: component resolver -> core/php/comp.php

tree:
  - api/
    - admin.php
    - auth.php
    - _base.php
    - boms.php
    - clients.php
    - components.php
    - cost.php
    - entities.php
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
    - clientlist/
      - clientlist.css
      - clientlist.html
      - clientlist.js
    - clients/
      - clients.css
      - clients.html
      - clients.js
    - clientselect/
      - clientselect.css
      - clientselect.html
      - clientselect.js
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
    - materialedit/
      - materialedit.css
      - materialedit.html
      - materialedit.js
    - materiallist/
      - materiallist.css
      - materiallist.html
      - materiallist.js
    - materialselect/
      - materialselect.css
      - materialselect.html
      - materialselect.js
    - nav/
      - nav.css
      - nav.html
      - nav.js
    - onboard/
      - onboard.css
      - onboard.html
      - onboard.js
    - orders/
      - orders.css
      - orders.html
      - orders.js
    - prefabpicker/
      - prefabpicker.css
      - prefabpicker.html
      - prefabpicker.js
    - prefabs/
      - prefabs.css
      - prefabs.html
      - prefabs.js
    - procurement/
      - procurement.css
      - procurement.html
      - procurement.js
    - production/
      - production.css
      - production.html
      - production.js
    - quoteform/
      - quoteform.css
      - quoteform.html
      - quoteform.js
    - quoteitems/
      - quoteitems.css
      - quoteitems.html
      - quoteitems.js
    - quotes/
      - quotes.css
      - quotes.html
      - quotes.js
    - quoteview/
      - quoteview.css
      - quoteview.html
      - quoteview.js
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
    - takeoffsplit/
      - takeoffsplit.css
      - takeoffsplit.html
      - takeoffsplit.js
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
    - build-boq-import.php
    - build-fittings-seed.js
    - build-flanges-seed.js
    - build-pipes-seed.js
    - get_sheet_names.py
    - get_sheets.py
    - import-boq-quote.php
    - seed-edit-test.php
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
    (none detected)
  api_endpoints:
    (none detected)
  tables:
    (none detected)

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
- **Entity** — anything costed inside a quote: part | assembly | fastener | quote. Owns components and links. Belongs to exactly one quote via quote_id.
- **Component** — typed data block attached to an entity: basic, dimensions, material, cost, process, rate, specification, notes, status, cadData. Merged via jsonb (partial patches).
- **Link** — relationship between two entities: contains (parent→child, the BOM edge), references, suppliedBy, uses, dependsOn, relatedTo. Carries a quantity.
- **BOM** — bill of materials = the `contains` link tree rooted at a quote/assembly. Tree tab renders it recursively; cost rolls up children.
- **Prefab** — reusable assembly template (prefab_template). Instantiate = copy its ECS tree into a quote (with server-side recalc); Bake = save a quote's assembly as a template.
- **Cost columns (12)** — material, boilerHrs, weldHrs, machHrs, labor, consumables, services, ndt, lining, paint, transport, total. The quoteview overview grid + reports key on these.
- **Trade** — process discipline the cost engine prices: boilermaking, welding, machining, painting, assembly, qualityControl, surfaceTreatment, cutting, drilling, grinding, bending.
- **Margin** — quote.data.marginPercent → user_prefs.defaultMarkupPercent → default chain; per-entity override via entity.data.marginPercent.
- **Procurement docs** — Purchase Order (po), Supplier Quote (sq, against a material), Received Goods (rg). Form a simple 3-table purchasing flow.
- **Production record** — actual vs estimated hours per entity+trade; variance rows derived at record time.

## Architecture decisions (ADRs)

- **ECS data model kept from Meteor** — entity/component/link with uniform `(id, type, data JSONB, owner)` shape; generic handlers. Hard to reverse (every table/endpoint builds on it).
- **Real-time dropped** — no DDP/websockets in the port; REST + orchestration endpoints (systems.load_quote = one call for quote+entities+costs+totals). Plan doc's DDP mapping is legacy, never implemented.
- **Server-side cost math** — calculators (tools.php) and quote costs (cost.php) share one engine so tool results always equal quote numbers.
- **Quote detail is a page, not a tab** — /nav/quotes/<id> mounts quoteview via forge-nav.setPage; nav re-resolves on onPathChange so back/forward restores the list.
- **dispatchIfEntry guard** — cross-endpoint includes (quotes→systems→cost) never double-dispatch; each file dispatches only as the HTTP entry.
