# Changelog — Fabricate Forge

All notable changes to this project are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com).

## [1.1.0-phase1.1] — 2026-08-26

### Added (Phase 1 UX Improvements)

#### Navigation & Context
- **Breadcrumb trail** — Quotes / Customer / Quote#ID / CurrentTab. Clickable navigation to jump between quote levels. Reduces context loss in nested workflows.
- Context preservation when navigating between quote detail tabs

#### Cost Transparency
- **Visual cost breakdown** — Proportional bar chart showing Material | Process Hours | On-costs | Margin composition
- Breakdown card on Cost tab with color-coded values and percentages
- Cost model help tooltip explaining the 5-layer calculation: material + process + on-costs + transport + margin
- Improved cost section header with inline help icon

#### Process Hours Visibility
- **Process Hours column** in Items list (catalog and usage views)
- Shows total fabrication hours (boiler + weld + machine) per item
- Displays "—" for items with no process data, making data gaps obvious
- Sortable and filterable like other columns

#### Component System
- **Help tooltip component** (`_help-tooltip`) — Reusable lightweight (i) icon with hover/click tooltips
- Positioned variants (top, right, bottom, left)
- Integrated into cost breakdown card
- Ready for system-wide adoption to reduce support burden

### Changed

- Quote detail header now includes breadcrumb trail for better context
- Cost tab redesigned with visual breakdown before detailed tables
- Quote view component registry updated to lazy-load breadcrumb and help tooltip
- Entities table column definitions expanded to include process hours

### Design System Updates

- No new tokens needed—all changes use existing Forge design tokens
- Colors: semantic map for breakdown bars (green=material, amber=process, cyan=on-costs, violet=margin)
- Full dark/light theme support via CSS variables

### Technical Details

- **Files added:** 6 new component files (breadcrumb, help-tooltip)
- **Files modified:** 7 quote-related components
- **No API changes** — all improvements are UI/UX only
- **No database migrations** — uses existing data structures

## [1.0.0] — 2026-08-13

### Initial Release

- Complete manufacturing quotation system with ECS data model
- Quote lifecycle: draft → submitted → approved → invoiced
- Complex cost engine: 5-layer calculation (material, process hours, on-costs, transport, margin)
- BoQ intake pipeline: upload, parse, review, import with validation
- Material library with fuzzy matching
- Prefab templates for reusable assemblies
- Procurement workflows: PO, supplier quotes, received goods
- Production tracking: actual vs. estimated hours
- Reports & analytics
- Team management with invite-based collaboration
- Support for multiple currencies (USD, ZAR)
- Dark/light theme support

---

## Roadmap — Upcoming Phases

### Phase 2 (Planned)
- **BoQ Upload UX** — Dropzone affordance, progress indication, format guidance
- **EditItem Redesign** — Right-side drawer (not modal), quick-edit toolbar, inline field editing
- **Empty State Guidance** — Helpful prompts and CTAs when quote/items/library empty
- **Margin Control Panel** — Visible margin cascade display (entity → quote → pref → default)
- **Import Versioning** — History of BoQ imports, rollback capability

### Phase 3 (Planned)
- **Procurement Workflow Clarity** — Pipeline view (Quotes → POs → Received → Production)
- **Supplier Quote Tracking** — Table for tracking multi-supplier RFQs and price comparisons
- **Onboarding Sequence** — Guided tour for new users, demo quote walkthrough
- **Video Demos** — 3-5 key workflow videos embedded in help system

### Phase 4 (Planned)
- **User Guide** — Markdown-based contextual help, searchable glossary
- **Accessibility Audit** — WCAG 2.1 AA compliance review
- **Design System Refinement** — Updated DESIGN.md tokens, component library docs
- **Adoption Metrics** — Usage analytics, support ticket reduction tracking

---

## Versioning Strategy

**Format:** MAJOR.MINOR.PATCH-PHASE[.ITERATION]

- **MAJOR:** Breaking changes (API, data model, deprecations)
- **MINOR:** Feature additions (new workflows, components, capabilities)
- **PATCH:** Bug fixes, performance, non-user-facing improvements
- **PHASE:** Development phase indicator (phase1, phase2, etc.) for work-in-progress
- **ITERATION:** Optional sub-version for incremental phase work (e.g., phase1.1, phase1.2)

**Examples:**
- `1.1.0-phase1.1` — Phase 1 initial UX improvements
- `1.1.0-phase1.2` — Phase 1 iteration 2 (additional Phase 1 work)
- `1.2.0` — Phase 1 complete, version bumped to 1.2.0
- `2.0.0` — Major breaking change (e.g., API restructure, data model change)

**Release Cycle:**
- Version file updated at start of phase work
- Changelog entries added per commit or daily standup
- Formal release (remove `-phase` tag) when phase is complete and tested
- Bump MINOR version for feature phases, MAJOR for breaking changes
