
## codebase-map
# Auto-scanned: 2026-08-12

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
  - /nav/<tab>[/<id>]: authed shell; quote detail is /nav/quotes/<id> → quote-view (setPage)

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
    - orders/
      - orders.css
      - orders.html
      - orders.js
    - prefab/
      - picker/
        - picker.css
        - picker.html
        - picker.js
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
    - quote/
      - form/
        - form.css
        - form.html
        - form.js
      - items/
        - items.css
        - items.html
        - items.js
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
    - build-boq-import.php
    - build-fittings-seed.js
    - build-flanges-seed.js
    - build-pipes-seed.js
    - get_sheet_names.py
    - get_sheets.py
    - import-boq-quote.php
    - purge-test-data.sql
    - seed-edit-test.php
    - seed-materials.php
    - seed-prefabs.php
    - seed-test-quote.php
    - setup-5-mock-quotes.php
    - test-cost-engine.php
    - test-mock-estimation.php
    - xlsx_to_md.js
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
    - nav (shell), dashboard, quotes, quote-view, quote-form, quote-items, edititem, clients, client-select, client-list, suppliers, library (+sub-tables), tools, prefabs, orders, procurement, production, reports, settings, about, admin, onboard, forgot, reset, landing, takeoff-split
  api_endpoints:
    - admin.php, auth.php, team.php, _base.php, boms.php, clients.php, components.php, cost.php, entities.php, links.php, materials.php, orders.php, prefabs.php, process.php, procurement.php, production.php, quotes.php, rates.php, reports.php, suppliers.php, systems.php, tools.php, user.php, weldmodel.php
  tables:
    - auth, user, user_prefs, password_reset, team, team_member, pending_invite, entity, component, link, client, supplier, company_settings, material_library, order, prefab_template, prefab_instance, purchase_order, supplier_quote, received_goods, production_record, production_variance

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
- **Cost columns (12)** — material, boilerHrs, weldHrs, machHrs, labor, consumables, services, ndt, lining, paint, transport, total. The quote-view overview grid + reports key on these.
- **Trade** — process discipline the cost engine prices: boilermaking, welding, machining, painting, assembly, qualityControl, surfaceTreatment, cutting, drilling, grinding, bending.
- **Margin** — quote.data.marginPercent → user_prefs.defaultMarkupPercent → default chain; per-entity override via entity.data.marginPercent.
- **Procurement docs** — Purchase Order (po), Supplier Quote (sq, against a material), Received Goods (rg). Form a simple 3-table purchasing flow.
- **Production record** — actual vs estimated hours per entity+trade; variance rows derived at record time.
- **Team** — grouping of users via invite (team.php). Owner creates teams; invite by email → existing user auto-joins, new signup auto-joins via invite_code. One team per user. Powers the onboard/join flow.
- **Supplier** — material supplier (supplier table). Linked to material_library rows; used in the take-off split to generate per-supplier RFQ CSVs/PDFs.

## Architecture decisions (ADRs)

- **ECS data model kept from Meteor** — entity/component/link with uniform `(id, type, data JSONB, owner)` shape; generic handlers. Hard to reverse (every table/endpoint builds on it).
- **Real-time dropped** — no DDP/websockets in the port; REST + orchestration endpoints (systems.load_quote = one call for quote+entities+costs+totals). Plan doc's DDP mapping is legacy, never implemented.
- **Server-side cost math** — calculators (tools.php) and quote costs (cost.php) share one engine so tool results always equal quote numbers. Weld math lives in `api/weldmodel.php` (pure static class, no DB).
- **Quote detail is a page, not a tab** — /nav/quotes/<id> mounts quote-view via forge-nav.setPage; nav re-resolves on onPathChange so back/forward restores the list.
- **dispatchIfEntry guard** — cross-endpoint includes (quotes→systems→cost) never double-dispatch; each file dispatches only as the HTTP entry.
- **Team / invite model** — teams (team.php) group users; invite flow is cookie-free for explicit links but uses `fab_invite` cookie as a fallback. Owner-only mutation; preview is public for the landing page.
