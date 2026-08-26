# MAP.md — fabricate_forge — structure map

> Maintained by codebase-map. Regenerate when the architecture changes.
> **Behavior (functions + dataflow): see LOGIC.md** · Working notes/todos: AUDIT_TODO.md · Design tokens: DESIGN.md.

## 0. Layers (one screen)

| Layer | Contents | Detail |
|---|---|---|
| UI (Vue 2.6 + Forge) | `components/*` (~46 dirs), `lib/init.php` boot patchwork, forge primitives (`forge/components/*`) | §2 |
| Transport | `ROUTER`, `WEB.api()` POST `/api/*.php` with `auth_id`; server: `action` → `handle_{action}` | flow in LOGIC.md §1 |
| Business logic (PHP) | `api/*.php` — ECS core (`_base.php`), computation systems (mass/process/cost), orchestrator (`systems.php`), workflows (quotes/boms/rfq/prefabs) | functions in LOGIC.md §3 |
| Data (Postgres) | ECS triple + domain tables | §6 |

## 1. Boot chain

```
index.php (SPA shell, <div id="main" start_comp="nav" default_tab="dashboard">)
├── bootstrap.php   — session hardening + security headers
├── lib/vue.php     — Vue 2.6 runtime
├── lib/init.php    — the app's routing brain:
│    forge JS load order → LS.pre='fabricate' → comp.js/MAIN
│    → landing-first processPath (public: /landing /login /signup /join→onboard
│      /forgot-password /reset-password/<token>)
│    → session-loss redirect (WEB.api wrapper: auth_id present→absent → gotoLanding)
│    → forge_comp_js() cache-bust (project mtime) → isReservedTag('nav') override
│    → deep links fall through to forge `_pp` WITHOUT navigating, so /nav/quotes/<id>
│      keeps its id; nav.resolveRoute mounts the page (init.php:152)
└── comp.php        — component resolver proxy → forge/php/comp.php
```

Component resolution priority: project `components/{name}/{name}.{html,js,css}` → forge `forge/components/*` → auth category fallback.

## 2. Component hierarchy

```
#main (Vue root — MAIN)
├── nav (shell) ── forge-nav (:tabs, :tab-url)
│   ├── dashboard        quote stats cards
│   ├── quotes           list (forge-search + forge-list)
│   │   └── quote-form (POPUP) → client-select → client/list
│   ├── /nav/quotes/<id> → quote shell (mounted via nav.resolveRoute, NOT a tab)
│   │   ├── Overview tab — 12-col cost grid (default)
│   │   ├── Items tab    — List | Tree | Process sub-views (lazy)
│   │   ├── Materials tab— Take-off | BOM | RFQ sub-views (lazy)
│   │   ├── edititem (POPUP) → material-select → material/list
│   │   └── prefab-picker, quote-items (POPUP)
│   ├── clients / suppliers / library / prefabs / orders /
│   │   procurement (PO|SQ|RG tabs) / production / reports / settings / admin (role-gated)
│   └── forge-svg ×3 (logo / theme / logout)
├── landing (public) → onboard via /join (team invites)
└── login / signup / forgot / reset (public)
```

Quote shell notes: mounted via `forge-nav.setPage('quote')` on route match (nav.js `resolveRoute`, deferred 300ms past forge-nav's tabUrl watcher); sub-views lazy-load via `COMP.externLoadComponent`. Pickers emit selections to hosts via v-model/onSelect.

## 3. Who calls what (UI side)

```mermaid
flowchart LR
    subgraph UI [components/]
        EDIT[edititem] --> MIXIN[FAB_EDIT_MIXIN lib/init.php]
        VIEW[quote/view shell] 
        BOMC[quote/bom · rfq · tree]
    end
    subgraph API [api/]
        SYS[systems.php<br/>recalculate_entity · overview · list_quotes]
        COST[cost.php<br/>calculate_entity · batch_calculate]<br/>+ process.php + rates.php + weldmodel.php
        ECS[entities/components/links.php]
        INTAKE[rfq.php → import.php · boms.php]
        WF[quotes.php · prefabs.php · materials.php]
    end
    DB[(Postgres: entity · component · link)]
    MIXIN -- "entities.update<br/>components.create/update" --> ECS
    MIXIN -- "links.update (qty)" --> ECS
    VIEW -- "overview / recalculate / export_pdf" --> SYS
    BOMC -- "takeoff / upload+import / tree" --> INTAKE
    SYS --> COST
    COST --> ECS
    COST --> DB
    ECS --> DB
    INTAKE --> ECS
```

All calls: `WEB.api('./api/<file>.php', {action, input})`, auth_id from LS. Per-endpoint handlers: **LOGIC.md §3** (single source).

| Component | Endpoints → actions |
|---|---|
| nav | user.get_preferences, auth.logout, team.join |
| dashboard | user.get_preferences, systems.list_quotes |
| quote-list / quote-form | clients.list/create, systems.list_quotes, quotes.create |
| quote shell | systems.overview + recalculate_entity, quotes.get/update/update_status/export_pdf/add_items, **systems.entity_items / entity_tree (Items tab reads — ADR: systems-mediated reads)**, materials.list, prefabs.instantiate, boms.takeoff, suppliers.list, rfq.upload/import, import.parse_boq |
| library / pickers | materials.list, prefabs.list, clients.list |
| prefabs page | prefabs.list/create/instantiate/bake_from_quote, systems.overview |
| orders / procurement / production | their own CRUD endpoints (+systems.list_quotes) |
| settings | user/admin prefs + rates, team.my_team |
| reports | reports.* summaries, quotes.export_pdf, systems.list_quotes |
| admin | admin.list_users/set_user_role, team.* |

Cross-endpoint includes server-side (never double-dispatch, `dispatchIfEntry`): quotes→systems→cost→components/rates/weldmodel; rfq→import/entities/components/links/systems; prefabs→systems/materials. No DDP/websockets anywhere.

## 4. Forge vs custom

- **Forge used:** forge-nav, button, form, input, list, loader, popup, search, select, svg, tabs, toast, auth comps; core JS `_util/router/comp/user/vue/theme-init`.
- **Custom:** ~46 component dirs (quote/ with 11 subdirs; checks/ deleted in tab consolidation). Theming via `--forge-*` tokens only (DESIGN.md), never `!important`.

## 5. Database (Postgres)

```
Auth/session:  auth, user, user_prefs, password_reset
Team:          team, team_member, pending_invite
ECS core:      entity, component, link        (id, type, data JSONB, user_id_owner)
Reference:     client, supplier, company_settings, material_library (LEGACY MIRROR)
RFQ/file:      rfq_document, files_meta, files_data
Business:      order, prefab_template, prefab_instance,
               purchase_order, supplier_quote, received_goods,
               production_record, production_variance
```

ECS type sets (DB CHECKs): entity ∈ part/assembly/fastener/fitting/material/quote · link ∈ contains/references/suppliedBy/uses/dependsOn/relatedTo · component ∈ 10 typed kinds. Every row owner-scoped; soft-delete via `is_active`.
Materials are entities (type='material'); `material_library` = legacy seed mirror bridged by `_base.materialRowShape` — migration pending.

## 6. Architecture decisions (ADRs — one line each)

1. **ECS core** — entity/component/link JSONB tables, uniform shape, generic handlers. Hard to reverse.
2. **Quote = entity** (type='quote'); quotes.php is a thin workflow layer on top.
3. **Cost = ONE system, ONE component type** — api/cost.php exclusively owns the cost-comp; all consumers read through its seam; `recalculate_entity` is the only compute path; reads never write/recalc. Enforced by `tests/check-cost-seam.sh`. (Supersedes older scattered cost ADRs.)
4. **REST-only** — no websockets; cross-component chatter = Vue root events only.
5. **Server-side cost engine** — tools.php calculators share cost.php math (pure, no tables). Weld/pipe geometry (weldmodel.php) produces display metadata + surface areas only — welding/threading hours are MANUAL process components (D2/D3).
6. **Margin chain** — entity.data.marginPercent > quote > user prefs > 30%.
7. **Welcome-first routing** — unauth lands on /landing; public: landing/login/signup/join/forgot/reset.
8. **SPA deep links** — /nav/<tab>/<id>; quote detail mounted as special page via forge-nav.setPage.
9. **dispatchIfEntry** — cross-endpoint includes never double-dispatch.
10. **Project-scoped cache-bust** — comp.js `v=` from project mtimes; one app's edits don't bust other forge apps.
11. **Password reset dev-friendly** — token returned in response (no mailer); SHA-256, 1h, single-use. Change before production.
12. **Team/invite model** — invite by email; signup auto-joins via invite_code; one team per user; preview_invite is public.
13. **Supplier take-off** — takeoff groups by category+size; takeoff-split exports one CSV/PDF per supplier.
14. **Bought-in fittings** — type 'fitting' costs purchase price + margin; fabrication lives on assemblies as manual process comps.
15. **Materials-as-entities** — library = material entities; editable only via materials.php; material_library table = legacy mirror (migration pending).
16. **BoQ provenance** — every imported entity carries boq_source_file/item_no/section/qty/unit lineage → files_meta → serve.php streams original doc with auth. xlsx parsed via scripts/xlsx-to-rows.py; verified live end-to-end.

## 7. File index by role

| Role | Files |
|---|---|
| Entry points | index.php, comp.php, bootstrap.php, serve.php |
| Boot libs | lib/{vue,init,config,svg}.php |
| API | api/*.php (28) + _base.php (ECS core) — per-function map: LOGIC.md §3 |
| Nav pages | components/{dashboard,quotes,clients,suppliers,library,tools,prefabs,orders,procurement,production,reports,settings,about,admin}/ |
| Public pages | components/{landing,onboard,forgot,reset}/ + forge auth comps |
| Quote feature | components/quote/{view,overview,entities,bom,materials,tree,process,rfq}/ + edititem, quote-form/items, pickers, takeoff-split |
| Seed/data | seed-data/*.json, scripts/seed-*.php, scripts/build-*-seed.js (seeders still write legacy mirror) |
| Import pipeline | scripts/xlsx-to-rows.py, api/import.php, api/rfq.php (spool decomposition; weld hrs = manual process comps per D3) |
| Tests | tests/run-phase.sh + tests/phases/phase1..13.sh |
| Docs | CONTEXT.md, MAP.md, LOGIC.md, DESIGN.md, AUDIT_TODO.md |
