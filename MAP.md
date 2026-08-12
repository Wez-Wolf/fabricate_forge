# MAP.md — fabricate_forge relationship map

> Maintained by codebase-map. Regenerate when the architecture changes.
> **Behavioral overlay: see LOGIC.md** — dataflow chains, state machines, invariants, failure modes, verified with file:line anchors. This file is structure-only.

## 1. Boot chain

```
index.php (SPA shell, <div id="main" start_comp="nav" default_tab="dashboard">)
├── bootstrap.php            — session + security headers
├── lib/vue.php              — Vue 2.6 runtime (served from forge/js/core/vue.js)
├── lib/init.php             — boot patchwork: forge JS (util/router) → LS.pre='fabricate'
│                             → svg-cache clear (svg_clear_v1 marker) → landing-first routing
│                             → welcome-first processPath patch (public: landing, login,
│                             signup, join→onboard, forgot-password, reset-password/<token>)
│                             → processClear patch (→ /landing for unauth, /login on error)
│                             → forge_comp_js() (comp.js with project-mtime cache-bust `v`)
│                             → isReservedTag('nav') override
└── comp.php                 — component resolver proxy → forge/php/comp.php
```

`forge/js/core/comp.js` boots `COMP.initMain()`:
- Creates `MAIN` (Vue root) with template `<component :is="comp">` + `<forge-popup>` + `<forge-toast>`
- `processPath(parts)` (patched by init.php): 1 segment while logged out → landing/login/forgot/reset; else shell `nav` with `{default_tab, tab_url: parts[1]}` + query params
- `externLoadComponent(tag)` fetches `comp.php?comp=<tag>&v=<mtime>` → `{comp, template, newStyle}`; styles injected as `<style id=tag>`

**Component resolution (forge/php/comp.php priority):**
1. Project: `{tag} → components/{name}/{name}.{html,js,css}` (flat, then nested)
2. Forge: `forge-* → forge/components/{seg}/{last}`
3. Category fallback: `login/signup/join → forge/components/auth/{name}`

## 2. Component hierarchy

```
#main (Vue root — MAIN)
├── nav (shell — project)                       ← start_comp
│   └── forge-nav  (:tabs, :tab-url; setPage mounts tab comp)
│       ├── forge-svg ×3 (logo / theme toggle / logout)
│       ├── dashboard   — quote stats cards (pipeline, revenue, win rate, recent)
│       ├── quotes      — main table (forge-search + forge-list + status filter)
│       │   ├── quote-form (POPUP body)  ──> client-select ──> client/list (POPUP, searchable)
│       │   └── /nav/quotes/<id> → quote-view (page mounted via nav.resolveRoute + forge-nav.setPage)
│       │       ├── forge-tabs: Overview | Entities | BOM | Materials | Tree | Checks | Process (7 tabs)
│       │       ├── tree-node (local recursive comp) — entity BOM tree (collapse/expand, search, warnings)
│       │       ├── Materials tab — supplier take-off (boms.takeoff) grouped by category + takeoff-split (RFQ CSV/PDF per supplier)
│       │       ├── Checks tab — pipe↔flange/fitting compatibility (boms.compat → issues + suggestions)
│       │       ├── edititem (POPUP) ──> material-select ──> material/list (POPUP)
│       │       ├── prefab-picker (POPUP) — pick prefab to add
│       │       └── quote-items (POPUP) — batch line-item entry
│       ├── clients     — client mgmt (forge-list + forge-form POPUP)
│       ├── suppliers   — supplier mgmt (forge-list + forge-form POPUP)
│       ├── library     — materials library (forge-search + chips + forge-list)
│       ├── tools       — calculators (UI → api/tools.php math)
│       ├── prefabs     — assembly templates (forge-list; Instantiate/Bake)
│       ├── orders      — order mgmt (forge-list + forge-select status)
│       ├── procurement — forge-tabs: Purchase Orders | Supplier Quotes | Received Goods
│       ├── production  — records + variance (forge-list)
│       ├── reports     — quote reports (forge-list + export)
│       ├── settings    — forge-form ×2 (personal prefs / company process rates)
│       ├── about       — system overview
│       └── admin       — user mgmt, admin-only (visibleTabs filter)
├── landing  (public — welcome-first)  → onboard via /join (invite links team.php preview_invite)
├── login / signup / join   (forge auth category components)
├── forgot   (public — /forgot-password → auth.php forgot_password)
└── reset    (public — /reset-password/<token> → auth.php reset_password)
```

**Custom component dependency notes:**
- All picker components emit selections via v-model/input to their host (client-select, material-select) or onSelect (material-list, prefab-picker, client-list rows)
- quote-view is mounted NOT as a tab but via `forge-nav.setPage('quote-view')` when route is `/nav/quotes/<id>` (nav.js `resolveRoute`, deferred 300ms past forge-nav's tabUrl watcher; re-runs on `onPathChange` for back/forward)

## 3. API callers — component → endpoint → actions

All calls are `WEB.api('./api/<file>.php', {action, input})`. Auth = `auth_id` from `LS.get('auth_id')` (forge `Auth::validateAuth` server-side).

| Component | Endpoint | Actions |
|---|---|---|
| nav | user.php, auth.php | get_preferences, logout |
| landing | (forge auth comps) | login/signup via forge\Auth |
| dashboard | user.php, systems.php | get_preferences, list_quotes |
| quotes | clients.php, systems.php, quotes.php | list, list_quotes, create |
| quote-form | user.php | get_preferences |
| client-select / client-list | clients.php | list, create |
| clients | clients.php | list, create |
| quote-view | systems.php, quotes.php, components.php, materials.php, prefabs.php, links.php, boms.php, suppliers.php | load_quote, recalculate_quote, update, update_status, export_pdf, add_items, create, import, update/create (components), list (materials), instantiate, list (prefabs), tree (links), compat, takeoff, list (suppliers) |
| library / material-list | materials.php | list |
| prefabs | prefabs.php, systems.php | list, create, instantiate, bake_from_quote, list_quotes, load_quote |
| prefab-picker | prefabs.php | list |
| orders | orders.php, systems.php | list, create, update, list_quotes |
| procurement | procurement.php | po_list, po_create, po_update, po_set_status, sq_list, sq_create, rg_list, rg_create |
| production | production.php | record_list, record_create, record_variance, quote_summary |
| suppliers | suppliers.php | list, get, create, update, delete |
| onboard | team.php | preview_invite, join |
| admin | admin.php, team.php | list_users, set_user_role, team.list, team.create, team.invite, team.members, team.revoke_invite |
| settings | user.php, admin.php, team.php | get_preferences, update_preferences, get_settings, update_settings, my_team |
| nav | user.php, auth.php, team.php | get_preferences, logout, join |
| reports | systems.php, reports.php, quotes.php | list_quotes, margin_summary, cost_by_client, monthly_summary, cost_by_trade, export_pdf |
| tools | tools.php | calculate (pure math: material_plate/section/general, process_welding/machining/assembly, tank, pipe), density |
| forgot | auth.php | forgot_password |
| reset | auth.php | reset_password |

### Endpoint → handler index (action → `handle_{action}`)

| Endpoint | Handlers |
|---|---|
| admin.php | get_settings, list_users, set_user_role, update_settings |
| auth.php | login, signup, logout, verify, forgot_password, reset_password (extends forge\api\Auth; publicActions whitelist) |
| boms.php | calculate, compat, import, takeoff |
| clients.php | list, get, create, update, delete |
| components.php | list, get, get_by_quote, create, update, replace, delete |
| cost.php | calculate_entity, calculate_assembly, batch_calculate, get_cost |
| entities.php | list, get, get_full, search, create, update, delete |
| links.php | list, tree, create, update, delete, validate_cycle |
| materials.php | list, get, get_density, match, create, update, delete |
| orders.php | list, get, create, update, set_status, delete |
| prefabs.php | list, get, create, update, delete, instantiate, bake_from_quote |
| process.php | get_registry, extract, aggregate, calculate_entity |
| procurement.php | po_list, po_create, po_update, po_set_status, sq_list, sq_create, rg_list, rg_create |
| production.php | record_list, record_create, record_variance, quote_summary |
| quotes.php | list, get, create, update, update_status, delete, add_entity, remove_entity, add_items, export_pdf |
| rates.php | company, entity, globals, get_effective, get_all_effective, set_company_rates, set_entity_rate |
| reports.php | margin_summary, cost_by_client, monthly_summary, cost_by_trade, quote_funnel |
| suppliers.php | list, get, create, update, delete |
| systems.php | list_quotes, load_quote, recalculate_quote |
| team.php | preview_invite, create, list, invite, revoke_invite, join, members, remove_member, my_team |
| tools.php | calculate, density |
| user.php | get_preferences, update_preferences, login, signup (proxy → forge Auth) |
| weldmodel.php | (static class — pure math, no handlers; included by cost.php) |

### Cross-endpoint includes (compose layers, never re-dispatch thanks to `dispatchIfEntry`)
```
quotes.php → entities.php → systems.php → cost.php → components.php
          → systems.php → cost.php
boms.php   → systems.php, materials.php
process.php → components.php, rates.php
cost.php   → rates.php, weldmodel.php
prefabs.php → systems.php, materials.php
user.php   → auth.php (forge Auth)
```

## 4. DDP event bus

**None.** The Meteor app used DDP (publications + notifyWs); the Forge port **dropped real-time entirely**. All data flows are request/response REST via `WEB.api()`; pages reload on navigation/back (nav.js listens `onPathChange`). Cross-component chatter uses Vue root events only: `user-updated` (nav/user prefs) and `onPathChange` (route re-dispatch).

## 5. Forge vs custom boundary

**Forge components used** (from forge/components/): forge-nav, forge-button, forge-form, forge-input, forge-list, forge-loader, forge-popup (via global POPUP API), forge-search, forge-select, forge-svg, forge-tabs, forge-toast, plus forge auth comps (login/signup). Forge core JS: `_util.js` (UTIL/POPUP/TOAST/WEB), `router.js` (ROUTER), `comp.js` (MAIN/COMP), `user.js`, `theme-init.js`, `vue.js`.

**Project-custom** (components/): 25 top-level dirs, of which 6 are parent dirs (client/, material/, library/, prefab/, quote/, takeoff/) containing 17 subdirs = 42 component dirs total. Pickers (client-select, material-select) follow the progeny pattern: forge-select trigger → POPUP with searchable list.

**App-level PHP**: `lib/init.php` (boot patchwork: forge JS load order, SVG cache clear svg_clear_v1 marker, landing-first processPath routing with public routes /landing,/join→onboard,/login,/signup,/forgot-password,/reset-password/<token>, processClear fallback, forge_comp_js cache-bust, isReservedTag('nav') override), `api/_base.php` (ECS helpers + dispatchIfEntry guard).

## 6. Database (Postgres, 18 tables)

```
Auth/session:  auth, user, user_prefs, password_reset
Team model:    team, team_member, pending_invite
ECS core:      entity, component, link            (uniform: id, type, data JSONB, owner)
Reference:     client, supplier, company_settings, material_library
Business:      order, prefab_template, prefab_instance,
               purchase_order, supplier_quote, received_goods,
               production_record, production_variance
```

material_library also carries pipe/fitting/flange attribute columns (od, wt, schedule, nb, nps, mass_kg, paint_area_per_m, ext_area — added 2026-08-10 from the data/md reference); multi-end fitting data (od[]/wt[]/weldCirc[]…) stays in the `data` JSONB.

ECS model (from Meteor original): `entity` (part|assembly|fastener|quote), `component` (basic|dimensions|material|cost|process|rate|specification|notes|status|cadData), `link` (contains|references|suppliedBy|uses|dependsOn|relatedTo). Owner-scoping: every table carries `user_id_owner`.

**Team model** (api/team.php): `team` (owner_id, invite_code), `team_member` (user→team, one team per user enforced on join), `pending_invite` (email→team; signup auto-joins via matching invite_code). Preview is public (powers landing-page onboarding); all other actions require auth + ownership.

## 7. Architectural decisions

1. **ECS preserved from the Meteor app** — entity/component/link JSONB tables keep the original data model; all three share one shape so handlers are generic. (PLAN.md §4)
2. **Quote = entity** (type='quote'); `quotes.php` is a workflow layer (status lifecycle `VALID_TRANSITIONS`, history, PDF export) on top of the ECS core.
3. **DDP/websockets dropped** — REST request/response only; orchestration endpoints (`systems.load_quote`) return quote + entities + costs + totals in ONE call to kill N+1. (PLAN.md §DDP is legacy, not implemented)
4. **Welcome-first routing** — unauth users land on /landing (patch of `MAIN.processPath` in init.php); login/signup/forgot/reset-password/<token> stay public.
5. **Server-side cost engine** — tools.php calculators and cost.php share the same math so tool results == quote costs; tools.php is pure math (no tables). ⚠️ cost.php rewritten (uncommitted) with weld/pipe model integration: `api/weldmodel.php` (pure weld math — size/length/hours/mass/areas, no DB/auth) for butt/fillet/deposition-based hours; kind-aware material costing (pipe/fitting/flange/material); paint auto-estimation; default on-costs policy. See LOGIC.md §2 S4 and §10 file inventory.
6. **Project-scoped cache-bust** — `forge_comp_js()` derives comp.js `v=` from latest mtime of components/ + api/ so one app's edit never busts other forge apps.
7. **dispatchIfEntry guard** — cross-endpoint includes (quotes→systems→cost…) never double-dispatch; each file fires `forge\api\dispatch` only when it's the HTTP entry.
8. **Margin chain** — quote.data.marginPercent → user_prefs.defaultMarkupPercent → default; per-entity overrides allowed (entity.data.marginPercent).
9. **Password reset is dev-friendly** — token returned in the response (no mailer); SHA-256 hashed, 1h expiry, single-use. Marked for production change.
10. **SPA routing** — `/nav/<tab>[/<id>]`; quote detail is a special page (not a tab) mounted via `forge-nav.setPage('quote-view')`; nav listens `onPathChange` to restore tab page on back/forward.
11. **Team / invite model** — admin creates teams (api/team.php); invite by email → existing user auto-joins, new user's signup auto-joins via invite_code cookie; one team per user enforced. Landing page previews invite via public `preview_invite`.
12. **Supplier take-off** — boms.php `takeoff` groups all quote materials by category with supplier assignment; `takeoff-split` component generates one RFQ CSV/PDF per supplier. Suppliers (api/suppliers.php) are linked to material_library rows.

## 8. File index by role

| Role | Files |
|---|---|
| Entry points | index.php (SPA), comp.php (resolver proxy), bootstrap.php (headers) |
| Boot libs | lib/vue.php, lib/init.php, lib/config.php, lib/svg.php (→ forge svg server) |
| API endpoints | api/*.php (23 files; see §3) |
| API base | api/_base.php (ECS helpers, dispatchIfEntry, Base with user scoping) |
| Pages (nav tabs) | components/{dashboard,quotes,clients,suppliers,library,tools,prefabs,orders,procurement,production,reports,settings,about,admin}/ |
| Public pages | components/{landing,onboard,forgot,reset}/ + forge auth (login/signup) |
| Detail/pickers | components/{quote/view,quote-form,quote-items,edititem,material-select,client-list,material-list,prefab-picker,takeoff/split}/ |
| Shell | components/nav/ |
| Seed data | seed-data/{materials,fittings,fasteners,flanges,pipes}.json; scripts/seed-*.php; scripts/build-*-seed.js ⚠️ fittings.json +38k lines WIP |
| Data import | scripts/{xlsx_to_md.js,get_sheets.py,get_sheet_names.py}; data/*.xlsx(x) → data/md/ (piping reference) |
| Tests | tests/run-phase.sh + tests/phases/phase1..11.sh (live-DB API tests via jq) |
| Docs | PLAN.md (port plan), CONTEXT.md, DESIGN.md |
