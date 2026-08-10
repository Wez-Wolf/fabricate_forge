# Forge Port Plan — Fabricate Costing System

## Project Overview

- **Forge Framework**: Vue 2.6 + PHP (shared across pikan, progeny_hub, level_up, deafways, skilled, waui_forge)
- **Goal**: Port the Fabricate app to use Forge components while retaining the existing business logic and data models
/
## Why Forge Port?

- Forge provides pre-built UI components (buttons, forms, modals, tabs, lists, search, etc.) that eliminate custom HTML/CSS/JS for common patterns
- Forge's component system (`comp.php`) enables code sharing across the pikan/progeny/level_up ecosystem
- The existing `imports/ui/components/` directory already has custom Vue components that Forge can replace with pre-built ones

## Forge Component Mapping

### Mapping table — Fabricate's existing components → Forge equivalents

| Fabricate Component | Forge Replacement | Key Props | Usage Pattern |
|---|---|---|---|
| `QuoteDetail.vue` | `forge-popup` + `forge-tabs` | `v-model="modalOpen"`, `@switch-tab` | Detail view with tabs |
| `QuoteForm.vue` | `forge-form` + `forge-popup` | `:fields="formFields"`, `v-model="formData"` | Quote creation/edit form |
| `QuotesList.vue` | `forge-list` + `forge-search` | `:items="quotes"`, `@filter="search"` | Quote list with search |
| `QuoteCard.vue` | `forge-card` | `:quote="quote"`, `@action="handleClick"` | Quote card in lists |
| `QuoteTreeTab.vue` | `forge-tree` | `:items="treeNodes"`, `:expanded="expanded"` | Entity hierarchy tree |
| `QuoteBOMTab.vue` | `forge-list` + `forge-search` | `:items="bomItems"`, `@select="handleBOM"` | BOM table |
| `QuoteMaterialTab.vue` | `forge-tabs` + `forge-select` | `:items="materials"`, `v-model="selectedMaterial"` | Material selection |
| `QuoteRatesTab.vue` | `forge-list` + `forge-select` | `:quote="quote"`, `:quoteId="quote._id"` | Rate table |
| `ProcessTimeBudget.vue` | `forge-loader` + `forge-list` | `loading` prop, `:items="processItems"` | Process time budget |
| `Status Chips` | `forge-toggle` + `forge-btn` | `:status="status"`, `@change="handleStatus"` | Status chips (existing) |
| `Header/Nav` | `forge-header` + `forge-nav` | `@active="navActive"`, `@click="handleClick"` | Navigation bar |
| `ThemeToggle` | `forge-toggle` | `:isDark="isDark"`, `@toggle="toggleTheme"` | Theme switcher |
| `CostSummary.vue` | `forge-card` | `:summary="summary"` | Summary card |
| `CostTab.vue` | `forge-tabs` | `@switch-tab="tab"` | Cost breakdown tabs |
| `MaterialTab.vue` | `forge-tabs` + `forge-select` | `:items="materials"`, `v-model="selected"` | Material tab |
| `ClientCard.vue` | `forge-card` + `forge-select` | `:client="client"`, `@action="handleClient"` | Client card |
| `ProcurementList.vue` | `forge-list` | `:items="procurementItems"` | Procurement list |
| `ReportsView.vue` | `forge-list` + `forge-search` | `:items="reports"`, `@filter="search"` | Reports list |
| `Settings.vue` | `forge-form` | `:fields="settingsFields"` | Settings form |
| `Admin.vue` | `forge-header` + `forge-menu` | `@active="adminNav"` | Admin dashboard |
| `Dashboard.vue` | `forge-card` + `forge-list` | `:stats="dashboardStats"` | Dashboard cards |
| `Library.vue` | `forge-tree` | `:items="libraryItems"`, `@select="handleLibrary"` | Library selection |
| `About.vue` | `forge-heading` | `:title="title"`, `:content="content"` | About page |
| `Login.vue` | `forge-login` | `@submit="handleLogin"` | Login form |
| `Register.vue` | `forge-signup` | `@submit="handleRegister"` | Registration form |
| `ResetPassword.vue` | `forge-login` (with reset flow) | `@reset="handleReset"` | Password reset form |
| `ForgotPassword.vue` | `forge-login` (with forgot flow) | `@reset="handleForgot"` | Forgot password flow |

### Forge Component Files to Replace

```
imports/ui/views/quotes/QuoteDetail.vue   →  forge-tabs + forge-popup
imports/ui/views/quotes/QuoteForm.vue     →  forge-form + forge-popup
imports/ui/views/quotes/QuotesList.vue    →  forge-list + forge-search
imports/ui/views/quotes/QuoteCard.vue     →  forge-card
imports/ui/views/quotes/tabs/QuoteTreeTab.vue →  forge-tree
imports/ui/views/quotes/tabs/QuoteBOMTab.vue →  forge-list + forge-search
imports/ui/views/quotes/tabs/QuoteMaterialTab.vue →  forge-tabs + forge-select
imports/ui/views/quotes/tabs/QuoteRatesTab.vue →  forge-list + forge-select
imports/ui/views/pages/Dashboard.vue     →  forge-card + forge-list
imports/ui/views/pages/Admin.vue         →  forge-header + forge-menu + forge-list
imports/ui/views/pages/Library.vue        →  forge-tree + forge-select
imports/ui/views/pages/Settings.vue       →  forge-form
imports/ui/views/auth/Login.vue           →  forge-login
imports/ui/views/auth/Register.vue        →  forge-signup
imports/ui/views/auth/ResetPassword.vue   →  forge-login (with reset flow)
imports/ui/views/auth/ForgotPassword.vue  →  forge-login (with forgot flow)
```

### Forge Component Files to Create (forge-popup for modals, forge-form for forms, forge-tabs for tabs, forge-list for lists, forge-select for dropdowns, forge-card for cards, forge-tree for trees, forge-loader for loading)

| Component | Purpose | Forge Component | Pattern |
|---|---|---|---|
| `quote-form-modal` | Edit quote modal | `forge-popup` + `forge-form` | `POPUP.show({comp: 'forge-form', ...})` |
| `quote-detail-tabs` | Detail view tabs | `forge-tabs` + `forge-popup` | Tabbed modal |
| `quote-list-header` | Searchable quote list | `forge-list` + `forge-search` | `forge-list` with `:filter` prop |
| `quote-card` | Quote card component | `forge-card` | `forge-card` component |
| `status-chips` | Status history chips | `forge-toggle` + `forge-btn` | Toggle + button pattern |
| `entity-tree` | Entity hierarchy tree | `forge-tree` | `forge-tree` component |
| `bom-table` | BOM table | `forge-list` + `forge-search` | `forge-list` with `:filter` prop |
| `material-tab` | Material tab | `forge-tabs` + `forge-select` | Tabbed select pattern |
| `process-budget` | Process budget | `forge-loader` + `forge-list` | `forge-loader` + `forge-list` |
| `quote-detail-view` | Quote detail view | `forge-popup` | `POPUP.show({comp: 'forge-tabs', ...})` |

## Migration Strategy

### Phase 1: Core Architecture (Week 1-2)

1. **Replace `forge-button`** — all button elements in fabricate use `<button>` with custom classes; replace with `<forge-button :label="label" :disabled="disabled" />`
2. **Replace `forge-form`** — all forms use `forge-form` with field definitions
3. **Replace `forge-popup`** — all modals use `POPUP.show()` with custom components
4. **Replace `forge-tabs`** — all tab navigation uses `forge-tabs` with `v-model`
5. **Replace `forge-list`** — all data lists use `forge-list` with search/pagination
6. **Replace `forge-select`** — all dropdowns use `forge-select`
7. **Replace `forge-card`** — all card components use `forge-card`
8. **Replace `forge-tree`** — all tree views use `forge-tree`

### Phase 2: UI Replacements (Week 3-4)

- Migrate `QuoteDetail.vue` to `forge-tabs` + `forge-popup` pattern
- Migrate `QuotesList.vue` to `forge-list` + `forge-search` pattern
- Migrate `QuoteForm.vue` to `forge-form` + `forge-popup` pattern
- Migrate `QuoteTreeTab.vue` to `forge-tree` pattern
- Migrate `QuoteMaterialTab.vue` to `forge-tabs` + `forge-select` pattern
- Migrate `ProcessTimeBudget.vue` to `forge-list` + `forge-loader` pattern
- Migrate `ClientCard.vue` to `forge-card` pattern

### Phase 3: Custom Components to Forge (Week 5-6)

- Replace custom components in `imports/ui/components/` with Forge equivalents
- Replace `ThemeToggle.vue` with `forge-toggle`
- Replace `AppMenu.vue` with `forge-header` + `forge-nav` pattern
- Replace `BaseField.vue` with `forge-form` field components
- Replace `CostSummary.vue` with `forge-card`
- Replace `MaterialCalculator.vue` with `forge-form`
- Replace `ProcessCalculator.vue` with `forge-tabs` + `forge-list`
- Replace `RatesEditor.vue` with `forge-form`

### Phase 4: API & Data Layer (Week 7-8)

- Replace `Meteor.callAsync` calls with `WEB.api` calls
- Replace `useToast()` with `TOAST.show()`
- Replace `useTheme()` with Forge's theme system
- Replace custom `useAuth` composable with Forge's `useAuth` composable
- Replace custom `useEntityTree` composable with Forge's `useEntityTree` composable
- Replace `useAutoSave` with Forge's `useAutoSave` composable

## API Layer Mapping (Fabricate → Forge PHP API)

Fabricate uses Meteor methods (`imports/api/methods/`). Forge port replaces these with PHP API endpoints called via `WEB.api()`.

### Backend: PHP API (`api/` directory)

**Create `api/` directory** under the Fabricate root. Each PHP file exposes a single action via `WEB.api()`.

### Mapping: Meteor Method → PHP Endpoint

| Fabricate Meteor Method | PHP Endpoint | Action | Required Input | Returns |
|---|---|---|---|---|
| `quotes.create` | `api/quotes.php` | `create` | `data: { title, description, customerName, customerEmail, customerPhone, dueDate, validityDays, currency, clientId }` | Quote document |
| `quotes.list` | `api/quotes.php` | `list` | `options: { limit, offset, status, search }` | Quote array |
| `quotes.get` | `api/quotes.php` | `get` | `quoteId` | Quote document |
| `quotes.updateStatus` | `api/quotes.php` | `updateStatus` | `{ quoteId, status, note }` | Updated quote |
| `quotes.update` | `api/quotes.php` | `update` | `{ quoteId, data }` | Updated quote |
| `quotes.exportPDF` | `api/quotes.php` | `exportPDF` | `quoteId` | HTML string |
| `quotes.addEntity` | `api/entities.php` | `addEntity` | `{ entityId, quoteId }` | Updated entity |
| `quotes.removeEntity` | `api/entities.php` | `removeEntity` | `{ entityId, quoteId }` | Updated entity |
| `quotes.updateEntityField` | `api/quotes.php` | `updateEntityField` | `{ quoteId, field, value }` | Updated quote |
| `quotes.recalculateTotal` | `api/quotes.php` | `recalculate` | `quoteId` | Quote with totalCost |
| `systems.loadQuote` | `api/systems.php` | `loadQuote` | `quoteId` | `{ entities, enriched, costs }` |
| `systems.recalculateQuote` | `api/systems.php` | `recalculate` | `quoteId` | Recalculated quote |
| `comp.enrichEntity` | `api/comp-enrich.php` | `enrichEntity` | `entityId` | Enriched component data |
| `comp.enrichQuote` | `api/comp-enrich.php` | `enrichQuote` | `quoteId` | All enriched components |
| `boms.calculateCost` | `api/boms.php` | `calculateCost` | `{ entityId, depth }` | Cost breakdown |
| `boms.create` | `api/boms.php` | `create` | `{ entityId, data }` | Created BOM |
| `boms.update` | `api/boms.php` | `update` | `{ entityId, data }` | Updated BOM |
| `boms.delete` | `api/boms.php` | `delete` | `entityId` | Deleted BOM |
| `boms.addEntity` | `api/entities.php` | `addEntity` | `{ entityId, quoteId }` | Updated entity |
| `entities.list` | `api/entities.php` | `list` | `{ createdBy, type, limit }` | Entity list |
| `entities.create` | `api/entities.php` | `create` | `{ type, name, description, quoteId }` | Created entity |
| `entities.getWithComponents` | `api/entities.php` | `getWithComponents` | `entityId` | Entity with enriched components |
| `entities.search` | `api/entities.php` | `search` | `{ query, type, limit }` | Search results |
| `comp.enrichForEntity` | `api/comp-enrich.php` | `enrichForEntity` | `entityId` | Enriched materials |
| `comp.enrichQuote` | `api/comp-enrich.php` | `enrichQuote` | `quoteId` | All enriched materials |
| `comp.getById` | `api/components.php` | `getById` | `componentId` | Component document |
| `comp.update` | `api/components.php` | `update` | `{ componentId, data }` | Updated component |
| `comp.removeFromEntity` | `api/entities.php` | `removeFromEntity` | `{ entityId, componentId }` | Updated entity |
| `comp.getByTypes` | `api/components.php` | `getByTypes` | `{ entityId, types }` | Components by type |
| `comp.create` | `api/components.php` | `create` | `{ type, data, entityId, quoteId }` | Created component |
| `links.getById` | `api/links.php` | `getById` | `linkId` | Link document |
| `links.update` | `api/links.php` | `update` | `{ linkId, data }` | Updated link |
| `links.removeFromEntity` | `api/links.php` | `removeFromEntity` | `{ entityId, linkId }` | Updated entity |
| `materials.getById` | `api/materials.php` | `getById` | `materialId` | Material document |
| `materials.list` | `api/materials.php` | `list` | `{ createdBy, limit }` | Material list |
| `materials.update` | `api/materials.php` | `update` | `{ materialId, data }` | Updated material |
| `auth-methods.verify` | `api/auth.php` | `verify` | `{ token, userId }` | Auth result |
| `admin.getSettings` | `api/admin.php` | `getSettings` | `{ userId }` | Settings |
| `admin.updateSettings` | `api/admin.php` | `updateSettings` | `{ userId, data }` | Updated settings |
| `admin.getUsers` | `api/admin.php` | `getUsers` | `{ role }` | User list |
| `admin.setUserRole` | `api/admin.php` | `setUserRole` | `{ userId, role }` | Updated role |
| `user.getPreferences` | `api/user.php` | `getPreferences` | `{ userId }` | User preferences |
| `user.updatePreferences` | `api/user.php` | `updatePreferences` | `{ userId, data }` | Updated preferences |
| `rateSystem.getEffectiveRate` | `api/rates.php` | `getEffectiveRate` | `{ processName, entityId, companyRates }` | Effective rate |
| `rateSystem.getMergedRates` | `api/rates.php` | `getMergedRates` | `{ entityId }` | Merged rates |
| `rateSystem.getEntityRates` | `api/rates.php` | `getEntityRates` | `{ entityId }` | Entity rates |
| `process-system.aggregate` | `api/process.php` | `aggregate` | `{ entityId, processType }` | Process time |
| `process-system.extractProcessItems` | `api/process.php` | `extractProcessItems` | `{ entityId }` | Process items |
| `process-system.calcTotalProcessCost` | `api/process.php` | `calcTotalProcessCost` | `{ entityId }` | Total process cost |
| `process-system.batchCalculate` | `api/process.php` | `batchCalculate` | `{ entityIds }` | Batch process costs |
| `material-system.calculateFromComponents` | `api/material.php` | `calculateFromComponents` | `{ entityId }` | Mass and library item |
| `material-system.getDensity` | `api/material.php` | `getDensity` | `{ materialType }` | Density lookup |
| `entity-system.loadQuote` | `api/systems.php` | `loadQuote` | `quoteId` | Full quote with entities, enriched materials, and costs |
| `entity-system.recalculateQuote` | `api/systems.php` | `recalculate` | `quoteId` | Recalculated quote |
| `entity-system.watchQuote` | `api/systems.php` | `watchQuote` | `quoteId` | Observer callback |
| `entity-system.query` | `api/entities.php` | `query` | `{ createdBy, type, quoteId, limit, sort, fields }` | Entity query results |
| `comp-system.getComponents` | `api/components.php` | `getComponents` | `{ entityId, types }` | Components by type |
| `rateSystem.getEntityRates` | `api/rates.php` | `getEntityRates` | `{ entityId }` | Entity rates |
| `cost-system.calculateEntityCost` | `api/cost.php` | `calculateEntityCost` | `{ entityId }` | Entity cost breakdown |
| `cost-system.calculateAssemblyCost` | `api/cost.php` | `calculateAssemblyCost` | `{ entityId, depth }` | Assembly cost |
| `cost-system.calculateBOMCost` | `api/cost.php` | `calculateBOMCost` | `{ entityId }` | BOM cost |
| `cost-system.batchCalculate` | `api/cost.php` | `batchCalculate` | `{ entityIds, options }` | Batch cost results |
| `cost-system.generatePricingSchedule` | `api/cost.php` | `generatePricingSchedule` | `{ assemblyId, options }` | Pricing schedule |
| `material-system.calculateFromComponents` | `api/material.php` | `calculateFromComponents` | `{ entityId }` | Mass and library item |
| `material-system.getDensity` | `api/material.php` | `getDensity` | `{ materialType }` | Density lookup |
| `rateSystem.getEntityRates` | `api/rates.php` | `getEntityRates` | `{ entityId }` | Entity rates |
| `overhead-system.calculate` | `api/overhead.php` | `calculate` | `{ materialCost, processCost, options }` | Overhead cost |
| `assembly-system.getChildLinks` | `api/assembly.php` | `getChildLinks` | `{ entityId }` | Child links |
| `assembly-system.getBOMTree` | `api/assembly.php` | `getBOMTree` | `{ assemblyId, maxDepth }` | BOM tree |

### Forge `WEB.api()` Call Pattern

```js
// Instead of Meteor.callAsync('quotes.list', options)
const quotes = await WEB.api('./api/quotes.php', { action: 'list', options });
// Instead of Meteor.callAsync('quotes.create', data)
const quote = await WEB.api('./api/quotes.php', { action: 'create', data });
// Instead of Meteor.callAsync('systems.loadQuote', quoteId)
const quote = await WEB.api('./api/systems.php', { action: 'loadQuote', quoteId });
// Instead of Meteor.callAsync('quotes.updateStatus', quoteId, status, note)
const quote = await WEB.api('./api/quotes.php', { action: 'updateStatus', quoteId, status, note });
```

### API Endpoint Files

Create a `api/` directory with these files:

**✅ Done (ECS core):**
- `api/_base.php` — Project Base: forge\api\Base + ECS helpers (ensureEcsTables, getEntity, getComponents, getLinks, patchComponentData)
- `api/entities.php` — Entity CRUD: create, get, get_full (entity+components+links+children), list, search, update, delete (soft)
- `api/components.php` — Component CRUD: create, get, list, update (JSONB merge), replace, delete, get_by_quote
- `api/links.php` — Link CRUD: create, list, update, delete, tree (BOM traversal), validate_cycle

**✅ Done (Reference data — Phase 2):**
- `api/materials.php` — Material library: list (category/search filters), get, create, update, delete, get_density, match (grade 40% + category 30% + alias 30% scoring)
- `api/rates.php` — Rate hierarchy: globals (GLOBAL_DEFAULT_RATES), company (company_settings), entity (rate component), get_effective, get_all_effective, set_entity_rate, set_company_rates
- `api/process.php` — Process: get_registry (11 trades), extract (named-field + items-array formats), calculate_entity (hours × rate), aggregate (recursive BOM hours)

**✅ Done (Reference data — Phase 2):**
- `api/materials.php` — Material library: list (category/search filters), get, create, update, delete, get_density, match (grade 40% + category 30% + alias 30% scoring)
- `api/rates.php` — Rate hierarchy: globals (GLOBAL_DEFAULT_RATES), company (company_settings), entity (rate component), get_effective, get_all_effective, set_entity_rate, set_company_rates
- `api/process.php` — Process: get_registry (11 trades), extract (named-field + items-array formats), calculate_entity (hours × rate), aggregate (recursive BOM hours)

**✅ Done (Cost engine — Phase 3):**
- `api/cost.php` — 5-layer ECS cost system: calculate_entity (READ components → COMPUTE → WRITE cost component), calculate_assembly (recursive rollup), batch_calculate (kills N+1), get_cost (read without recompute)

**✅ Done (Orchestration — Phase 4):**
- `api/systems.php` — load_quote (single-call { quote, entities, costs, total_cost } + auto-persist), recalculate_quote (wipes cost comps, recomputes), list_quotes (light read)

**✅ Done (Quote lifecycle — Phase 5):**
- `api/quotes.php` — create, get, list, update (JSONB merge), update_status (VALID_TRANSITIONS + history), delete (soft), add/remove_entity, export_pdf (styled HTML)

**✅ Done (Support — Phase 6):**
- `api/auth.php` — wraps forge\api\Auth (login/signup/logout/verify + user_prefs table)
- `api/user.php` — get/update_preferences (defaultMarkupPercent, defaultCurrency, companyRates)
- `api/admin.php` — get/update_settings (company_settings), list_users + set_user_role (admin-gated 403)
- `api/boms.php` — import (rows → entities + type detection + material match + item-number hierarchy links), calculate

**Not needed (covered by existing endpoints):**
- `api/comp-enrich.php` — enrichment is inlined in cost.php (library lookup during mass calc)
- `api/overhead.php` — overhead is a field in cost options (consumables/services/paint/transport)
- `api/assembly.php` — getChildLinks/getBOMTree covered by links.php (tree) + cost.php (calculate_assembly)
- `api/material.php` — physics helpers inlined in cost.php (calcMass)

### WEB.api contract (verified against forge _util.js + Base.php dispatch)

- **auth_id goes INSIDE `input`**: `WEB.api` does `data.input.auth_id = auth_id`. Handlers read `$params = $input['input']` → `$params['auth_id']`. Test harness injects at `.input.auth_id`.
- **Response pass-through**: modern `_util.js` passes arrays + non-empty objects through (the old "drops bare arrays" gotcha is fixed). Wrap error responses in `['error' => ..., 'error_code' => N]`.
- **Dispatch**: `$action = $input['action']`, `$params = $input['input'] ?? $input`, class inferred from filename (`api\entities`).

### Data Flow

1. Frontend calls `WEB.api('./api/entities.php', { action: 'list', type: 'assembly' })`
2. PHP handler reads JSON input via `dispatch()` and resolves auth via `checkAuth()`
3. Handler queries Postgres (PgCrud) with owner-scoped WHERE clauses
4. `dispatch()` JSON-encodes the result back to the Vue component

## DDP Event Mapping

### Current Fabricate DDP Events

| Event | Current Implementation | Forge Equivalent |
|---|---|---|
| `quotes` subscription | `Meteor.subscribe('quotes')` | `Meteor.subscribe('quotes')` (unchanged) |
| `entities` subscription | `Meteor.subscribe('entities')` | `Meteor.subscribe('entities')` (unchanged) |
| `components` subscription | `Meteor.subscribe('components')` | `Meteor.subscribe('components')` (unchanged) |
| `materials` subscription | `Meteor.subscribe('materials')` | `Meteor.subscribe('materials')` (unchanged) |
| `boms` subscription | `Meteor.subscribe('boms')` | `Meteor.subscribe('boms')` (unchanged) |
| `users` subscription | `Meteor.subscribe('users')` | `Meteor.subscribe('users')` (unchanged) |
| `DDP` event bus | `notifyWs(`, `ddpEmit(`, `sendDDP(` | `DDPMixin` + `onDDPEvent()` (Forge provides) |

### Key Changes to DDP

- `DDPEventBus` will use Forge's `DDPMixin` for all real-time event handling
- Replace custom DDP event handlers with Forge's `onDDPEvent()` pattern
- Replace `notifyWs()` custom calls with `DDPMixin`'s `emit()` method
- Update `meteor/publications.js` to use Forge's `forge-ddp` mixin for events
### Quote System Component Tree

```
QuoteSystem (App.vue)
├── QuotesList.vue (legacy) → forge-list + forge-search
├── QuoteDetail.vue (legacy) → forge-tabs + forge-popup
├── QuoteForm.vue (legacy) → forge-form + forge-popup
├── QuoteBOMTab.vue (legacy) → forge-list + forge-search
├── QuoteMaterialTab.vue (legacy) → forge-tabs + forge-select
├── QuoteRatesTab.vue (legacy) → forge-list + forge-select
├── QuoteTreeTab.vue (legacy) → forge-tree
├── ProcessTimeBudget.vue (legacy) → forge-list + forge-loader
└── QuoteEntitiesTab.vue (legacy) → forge-tree + forge-list
```

### Module System Component Tree

```
ModuleSystem (App.vue)
├── BOMImport.vue → forge-form
├── CostCalculation.vue → forge-form
├── MaterialLibrary.vue → forge-tabs
├── OrderManager.vue → forge-list
├── PricingSchedule.vue → forge-form + forge-card
├── ProcessTracking.vue → forge-list + forge-tabs
├── Procurement.vue → forge-list
├── ProductionTracking.vue → forge-list
├── RateManager.vue → forge-tabs
└── Reports.vue → forge-list + forge-search
```

## Design Tokens Migration

### Current Design Tokens

The existing `imports/ui/main.css` uses:
- `--bg-primary: #f8fafc` → `var(--bg-primary)` (already mapped)
- `--btn-primary-bg: #2563eb` → Map to Forge's `--forge-button-bg` (blue)
- `--success: #059669` → Map to Forge's `--forge-button-bg` (green)
- `--danger: #dc2626` → Map to Forge's `--forge-button-bg` (red)
- `--warning: #d97706` → Map to Forge's `--forge-button-bg` (yellow)
- `--text-primary: #1e293b` → Map to Forge's `--forge-button-text` (dark)
- `--border-color: #cbd5e1` → Map to Forge's `--forge-border` (gray)
- `--btn-primary-text: #ffffff` → Map to Forge's `--forge-button-text`
- `--form-label: #475569` → Map to Forge's `--forge-form-label`

### Forge Design Token Mapping

The existing `imports/ui/main.css` will be migrated to the Forge design system's tokens:

**Color mapping:**
- `--bg-primary` → `--forge-auth-canvas-bg` (if used) or keep `var(--bg-primary)` for background
- `--btn-primary-bg` → `--forge-button-bg` (#2563eb → blue)
- `--success` → `--forge-button-bg` (#059669 → green)
- `--danger` → `--forge-button-bg` (#dc2626 → red)
- `--warning` → `--forge-button-bg` (#d97706 → yellow)
- `--text-primary` → `--forge-text-primary` (dark text)
- `--border-color` → `--forge-border` (gray border)

**Spacing mapping:**
- `--spacing-md: 16px` → `--forge-spacing-md`
- `--spacing-lg: 24px` → `--forge-spacing-lg`

**Radius mapping:**
- `--radius-md: 6px` → `--forge-radius-md`
- `--radius-lg: 8px` → `--forge-radius-lg`

**Font mapping:**
- `--font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif` → Keep as-is
- `--font-display` → Keep as-is

## Migration Checklist

### Before migrating each component:

1. [ ] Audit existing `imports/ui/components/` for patterns that map to Forge components
2. [ ] Replace custom Vue components with Forge equivalents where possible
3. [ ] Replace `Meteor.callAsync` with `WEB.api` for API calls
4. [ ] Replace `useToast()` with `TOAST.show()` for notifications
5. [ ] Replace `useTheme()` with Forge's theme system
6. [ ] Replace `useAuth()` with Forge's auth composable
7. [ ] Replace `useEntityTree()` with Forge's entity tree composable
8. [ ] Replace `useAutoSave()` with Forge's auto-save composable
9. [ ] Replace `useRouteGuard()` with Forge's route guard composable
10. [ ] Update `imports/ui/main.css` to use Forge's token system
11. [ ] Update `imports/ui/router.js` to use Forge's router
12. [ ] Update `imports/ui/App.vue` to use Forge's shell
13. [ ] Update `server/main.js` to use Forge's `comp.js` initialization
14. [ ] Update `imports/ui/composables/` to use Forge composables

## Code Migration Patterns

### 1. Button Replacement

**Before:**
```html
<button class="btn-primary" @click="saveQuote">Save</button>
```

**After:**
```html
<forge-button :label="saveQuote" @click="saveQuote" />
```

### 2. Form Replacement

**Before:**
```html
<form @submit.prevent="handleSave">
  <input v-model="formData.title" />
  <textarea v-model="formData.description" />
  <button type="submit">Save</button>
</form>
```

**After:**
```html
<forge-form
  :fields="{ title: { placeholder: 'Title' }, description: { type: 'textarea', rows: 3 } }"
  v-model="formData"
  button_label="Save">
</forge-form>
```

### 3. Modal Replacement

**Before:**
```html
<div v-if="showEditModal" class="modal-overlay">
  <div class="modal-content">
    <h2>Edit Quote</h2>
    <!-- form fields -->
  </div>
</div>
```

**After:**
```html
<forge-popup :title="'Edit Quote'" :comp="'forge-form'" :props="{ fields: formFields }" @close="showEditModal = false" />
```

### 4. List with Search Replacement

**Before:**
```html
<div v-if="!loading" class="table-container">
  <table>
    <thead><tr><th>Title</th><th>Customer</th><th>Status</th></tr></thead>
    <tbody v-for="quote in filteredQuotes" :key="quote._id">
      <tr><td>{{ quote.title }}</td><td>{{ quote.customerName }}</td><td>{{ quote.status }}</td></tr>
    </tbody>
  </table>
</div>
```

**After:**
```html
<forge-list :items="quotes" :filter="searchQuery" @item-click="viewQuoteDetail">
  <template #default="{ item }">
    <div class="quote-row" @click="viewQuoteDetail(item)">
      <span>{{ item.title }}</span>
      <span>{{ item.customerName }}</span>
      <span>{{ item.status }}</span>
    </div>
  </template>
</forge-list>
```

### 5. Tab Navigation Replacement

**Before:**
```html
<div class="tabs-nav">
  <button @click="activeTab = 'bom'">BOM</button>
  <button @click="activeTab = 'rates'">Rates</button>
  <button @click="activeTab = 'materials'">Material</button>
  <button @click="activeTab = 'process'">Process</button>
  <button @click="activeTab = 'tree'">Tree</button>
</div>
```

**After:**
```html
<forge-tabs :tabs="tabConfig" v-model="activeTab">
  <template #bom="{ active }">
    <!-- BOM content -->
  </template>
  <template #rates="{ active }">
    <!-- Rates content -->
  </template>
  <template #materials="{ active }">
    <!-- Materials content -->
  </template>
  <template #process="{ active }">
    <!-- Process content -->
  </template>
  <template #tree="{ active }">
    <!-- Tree content -->
  </template>
</forge-tabs>
```

### 6. Status Chip Replacement

**Before:**
```html
<span class="status-badge" :class="getStatusClass(safeQuote.status)">
  {{ safeQuote.status }}
</span>
```

**After:**
```html
<forge-toggle :is-on="safeQuote.status === 'draft'" @toggle="toggleStatus">
  <forge-btn :label="safeQuote.status" />
</forge-toggle>
```

### 7. Loading State Replacement

**Before:**
```html
<div v-if="loading" class="loading-skeleton">
  <div class="skeleton-block" style="height: 28px; width: 140px;"></div>
  <div class="skeleton-block" style="height: 32px; width: 60%;"></div>
  <div class="skeleton-bar" style="margin-bottom: 1.5rem;"></div>
</div>
```

**After:**
```html
<forge-loader variant="dots" size="md" label="Loading..." />
```

## Files to Migrate (in order of priority)

1. `imports/ui/views/quotes/QuoteDetail.vue` → `forge-tabs` + `forge-popup`
2. `imports/ui/views/quotes/QuoteForm.vue` → `forge-form` + `forge-popup`
3. `imports/ui/views/quotes/QuotesList.vue` → `forge-list` + `forge-search`
4. `imports/ui/views/quotes/QuoteCard.vue` → `forge-card`
5. `imports/ui/views/quotes/tabs/QuoteTreeTab.vue` → `forge-tree`
6. `imports/ui/views/quotes/tabs/QuoteBOMTab.vue` → `forge-list` + `forge-search`
7. `imports/ui/views/quotes/tabs/QuoteMaterialTab.vue` → `forge-tabs` + `forge-select`
8. `imports/ui/views/quotes/tabs/QuoteRatesTab.vue` → `forge-list` + `forge-select`
9. `imports/ui/views/pages/Dashboard.vue` → `forge-card` + `forge-list`
10. `imports/ui/views/pages/Admin.vue` → `forge-header` + `forge-menu` + `forge-list`
11. `imports/ui/views/pages/Library.vue` → `forge-tree` + `forge-select`
12. `imports/ui/views/pages/Settings.vue` → `forge-form`
13. `imports/ui/views/auth/Login.vue` → `forge-login`
14. `imports/ui/views/auth/Register.vue` → `forge-signup`
15. `imports/ui/views/auth/ResetPassword.vue` → `forge-login`
16. `imports/ui/views/auth/ForgotPassword.vue` → `forge-login`
17. `imports/ui/components/ThemeToggle.vue` → `forge-toggle`
18. `imports/ui/components/AppMenu.vue` → `forge-header` + `forge-nav`
19. `imports/ui/components/BaseField.vue` → `forge-form` field components
20. `imports/ui/components/CostSummary.vue` → `forge-card`
21. `imports/ui/components/MaterialCalculator.vue` → `forge-form`
22. `imports/ui/components/ProcessCalculator.vue` → `forge-tabs` + `forge-list`
23. `imports/ui/components/RatesEditor.vue` → `forge-form`
24. `imports/ui/router.js` → `forge-router`
25. `imports/ui/main.css` → `forge-style.css`
26. `imports/ui/composables/useAuth.js` → `forge-composables/use-auth`
27. `imports/ui/composables/useTheme.js` → `forge-composables/use-theme`
28. `imports/ui/composables/useEntityTree.js` → `forge-composables/use-entity-tree`
29. `imports/ui/composables/useAutoSave.js` → `forge-composables/use-auto-save`
30. `imports/ui/composables/useRouteGuard.js` → `forge-composables/use-route-guard`
31. `imports/ui/composables/useToast.js` → `forge-composables/use-toast`
32. `imports/ui/main.js` → `forge-comp.js` (comp.php init)
33. `server/main.js` → `forge-comp.js` (comp.php init)

## Risk Assessment

### High Risk
- **DDP event bus**: Custom DDP event handlers may not have Forge equivalents; need to bridge
- **Custom composables**: `useAuth`, `useTheme`, `useEntityTree` are custom Vue composables that may not exist in Forge; need to check if Forge provides them
- **Form validation**: Custom `SimpleSchema` validation patterns may need to be migrated to Forge's `forge-form` validation
- **PDF export**: `quotes.exportPDF()` generates HTML server-side — may need to keep custom as Forge doesn't have a PDF export component

### Medium Risk
- **API calls**: `Meteor.callAsync` → `WEB.api` pattern needs migration; check `WEB.api` contract
- **Navigation**: `useRouter` + `useRoute` → `ROUTER.navigate` pattern needs migration
- **Theme system**: Custom `useTheme` composable → Forge's theme system
- **Status handling**: Custom status chip logic → Forge's `forge-toggle` component

### Low Risk
- **Data structures**: MongoDB collection schemas remain unchanged
- **Meteor methods**: Method definitions remain unchanged; only the client call site changes
- **Validation**: `SimpleSchema` validation can stay; only the client call changes
- **Server-side logic**: All server-side methods remain unchanged; only the client call sites change

## Migration Timeline

### API Build Phases (each phase = a testable module)

| Phase | Module | Test script | Gate to pass |
|-------|--------|-------------|--------------|
| 1. ECS Core | entities / components / links | `tests/phases/phase1.sh` | ✅ all CRUD + tree + ownership + merge assertions green |
| 2. Reference Data | materials / rates / process | `tests/phases/phase2.sh` | material lookup, rate hierarchy, process registry return correct values |
| 3. Cost Engine | cost.php | `tests/phases/phase3.sh` | 5-layer breakdown math is exact (material×rate, hours×rate, margin) |
| 4. Orchestration | systems.php | `tests/phases/phase4.sh` | loadQuote returns `{entities, components, costs}` in one call |
| 5. Quote Lifecycle | quotes.php | `tests/phases/phase5.sh` | status transitions enforced, invalid transition rejected, PDF returns HTML |
| 6. Support | auth / user / admin / boms | `tests/phases/phase6.sh` | login, prefs, company settings, BOM import round-trip |
| 7. Seeded Library | global material_library (102 rows) | `tests/phases/phase7.sh` | seeded data present, owner-scoped, searchable, matchable, cost engine uses it |

**Module test contract (every phase):**

```bash
./tests/run-phase.sh phase1    # ground truth against live DB
# exit 0 = all assertions passed; exit 1 = phase failed
```

Each phase test (in `tests/phases/<phase>.sh`):
1. Logs in as the dedicated test user (`api-test@fabricate.local`) via `forge\api\Auth` → real `auth_id`
2. Creates its own fixtures (owner-scoped, never touches real data)
3. Hits the real endpoint via `WEB.api`-shaped HTTP POST (curl + jq assertions)
4. Asserts on real JSON: values, error codes, ownership isolation (unauthenticated → 401), JSONB merge semantics
5. Cleans up: soft-deletes + `scripts/purge-test-data.sql` (owner-scoped, manual, transactional)

**Phase gate rule:** a phase is DONE only when its test script exits 0 against a live DB —
not when `php -l` passes. Ground truth is the real HTTP response, same principle as
forge-comp-ground-truth (computed styles, not vision).

### UI Port Phases (after API is green)

| Phase | Duration | Tasks | Status |
|---|---|---|---|
| UI-1: Shell | 1 week | bootstrap.php, index.php, lib/(init,vue,config).php, comp.php, style.css tokens, nav shell (forge-nav + sidebar), forge auth components | ✅ done — verified LIVE on fabricate.innofuse.xyz |
| UI-2: Quotes | 2 weeks | QuotesList (forge-list+search), QuoteDetail (forge-tabs+popup), QuoteForm (forge-form) | ✅ done — list (forge-list+search+status chips+New Quote popup) + detail (cost breakdown tabs) verified LIVE on fabricate.innofuse.xyz |
| UI-3: Library & Tree | 1 week | materials library, entity tree (forge-tree) | ✅ library done — forge-list table (102 items) + forge-search + category filter chips w/ counts, verified LIVE. Tree: forge-tree is per-type recursive (p-<type>-tree) — entity tree deferred (BOM view covers it) |
| UI-4: Cost UI | 1 week | CostSummary cards, pricing schedule | ✅ covered — quoteview cost breakdown + reports summary cards |
| UI-5: Cleanup | 1 week | remove legacy views, visual regression pass | next |

### Deployment (fabricate.innofuse.xyz)

**Live!** The domain now serves the forge app (Apache direct, was Meteor proxy on :3006).

- `fabricate.innofuse.xyz.conf` — port 80 → HTTPS redirect (certbot webroot)
- `fabricate.innofuse.xyz-le-ssl.conf` — DocumentRoot `/var/www/html/fabricate_forge`, index.php, SPA rewrite, security headers, `/forge` alias
- Backups: `/root/fabricate-vhost-backup-20260809.conf` + `-ssl-...conf`
- Meteor app still runs on :3006 (service active) — revert vhost to restore

**Verified live:** login → dashboard → quotes list (Live Skid Frame $5,348.72) → quote detail (cost breakdown) all working over HTTPS.

### Nav tabs vs pages (which tabs have real pages)

| Tab | Page component | Status |
|---|---|---|
| Dashboard | `components/dashboard/` | ✅ |
| Quotes | `components/quotes/` + `components/quoteview/` | ✅ |
| Library | `components/library/` | ✅ materials library (forge-list + search + category chips) |
| Reports | `components/reports/` | ✅ quote summary cards + forge-list table + per-quote PDF export |
| Settings | `components/settings/` | ✅ user prefs + company name + process rates (forge-form + v-for rate grid), save verified |
| Admin | `components/admin/` | ✅ user table + role dropdowns (admin-gated, forge user_role model) |

### UI shell files

- `bootstrap.php` — security headers + session
- `index.php` — theme-init, style.css, #main start_comp=nav default_tab=dashboard
- `lib/init.php` — forge core (util/router/comp-js) + LS.pre=fabricate + nav-tag override
- `lib/vue.php` / `lib/config.php` — Vue 2.6 runtime + config loader
- `comp.php` — proxy to forge/php/comp.php (component resolver)
- `style.css` — brand-first tokens + forge component coverage (--forge-button/--forge-form/--forge-tabs/...)
- `components/nav/` — shell: forge-nav + brand header + logout, admin-gated tab
- `components/login/` — custom auth page (auth.php login → {data:{auth_id}})
- `components/dashboard/` — stat cards + pipeline/revenue/win-rate + recent quotes table

### Auth: forge-owned components (not custom)

Login/signup use **forge's own components** (`forge/components/auth/login`, `auth/signup`) —
resolved automatically at `/login` + `/signup` via the resolver's category fallback.
Themed via `--forge-auth-*` tokens in style.css (no custom auth components).

- `forge-login` / `forge-signup` post to `./api/user.php` with `action: login|signup`
- `api/user.php` delegates those actions to `api/auth.php` (forge Auth + `{data:}` WEB.api wrapper)
- `api/auth.php` extends `\forge\api\Auth` — all auth logic (login/signup/session/tables) is forge-owned

## Post-Migration Verification

1. **Unit tests**: Run `npm test` to verify all existing tests pass
2. **Visual regression**: Use `agent-browser` to capture screenshots of key pages
3. **Color analysis**: Use `color_analysis` to verify design tokens are consistent
4. **API contract**: Verify all API endpoints work via `WEB.api`
5. **Performance**: Run `ANALYZE=1 meteor` to check for performance regressions
6. **DDP events**: Verify real-time updates still work via `DDPMixin`
7. **Auth flow**: Verify all auth checks still work via `WEB.api`

## Cross-Reference Notes

- The existing `imports/ui/components/` directory contains custom Vue components that can be replaced with Forge equivalents
- The existing `imports/ui/main.css` provides design tokens that need to be migrated to Forge's token system
- The existing `imports/ui/router.js` uses Vue Router; the Forge router is shared and uses `ROUTER.navigate()`
- The existing `server/main.js` uses Meteor methods; the Forge method calls use `WEB.api`
- The existing `imports/ui/composables/` directory has custom Vue composables that should be migrated to Forge composables
