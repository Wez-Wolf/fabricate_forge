# Fabricate Forge — Phase 1 UX Improvements

## Summary

Implemented critical Phase 1 improvements from the UX/Workflow Audit to address navigation clarity, cost transparency, and process visibility gaps.

## Changes Made

### 1. Breadcrumb Navigation Trail
**Files:** `components/quote/_breadcrumb/*`
- New breadcrumb component showing: `Quotes / Customer / Quote#ID / CurrentTab`
- Fully navigable—each level is clickable to jump back
- Added to quote detail view header
- Reduces navigation confusion and provides context trail

**Impact:** Users now have clear location awareness in nested quote workflows.

### 2. Cost Breakdown Visualization
**Files:** `components/quote/cost/cost.html`, `cost.js`, `cost.css`
- Added visual breakdown card with proportional bars for:
  - Material (green)
  - Process Hours (amber)
  - On-costs (cyan)
  - Margin (violet)
- Each bar shows the percentage of total cost
- Breakdown values displayed alongside bars
- Help tooltip explaining the cost model

**Impact:** Cost transparency—users can now see at a glance what drives price, making it easier to optimize margins and explain quotes to customers.

### 3. Process Hours Visibility in Items List
**Files:** `components/quote/entities/entities.js`, `entities.html`
- Added "Process Hrs" column to the Items table (both catalog and usage views)
- Shows total hours (boiler + weld + machine) per item
- Displays "—" if no process hours set, making it obvious which items need process data
- Sortable and filterable like other columns

**Impact:** Users can quickly see which items lack process definitions, and understand the fabrication complexity at a glance.

### 4. Help Tooltip System
**Files:** `components/_help-tooltip/*`
- New lightweight reusable help tooltip component
- Shows on click or hover with context-sensitive help text
- Positioned top/right/bottom/left
- Used on cost breakdown card to explain the 5-layer cost model

**Impact:** Reduces support burden by providing in-app help without cluttering the UI.

## Design System Alignment

All improvements use existing Forge design tokens:
- Colors: `--accent`, `--info`, `--text`, `--border`, etc.
- Typography: Consistent sizing and weights
- Spacing: Flexbox/grid-based layouts
- Dark/light theme support built-in

## Next Steps (Phase 2)

1. **BoQ Upload UX** — Dropzone affordance, progress bar, format guidance
2. **EditItem Redesign** — Right-side drawer instead of modal, quick-edit toolbar
3. **Empty State Guidance** — Helpful prompts when quote has no items
4. **Margin Control Panel** — Visible margin cascade (entity → quote → pref → default)

## Testing Checklist

- [ ] Breadcrumb navigation works on all quote detail views
- [ ] Cost breakdown bars calculate correctly for various cost compositions
- [ ] Process hours display correctly for items with/without process components
- [ ] Help tooltip appears and hides correctly
- [ ] All improvements work in both light and dark themes
- [ ] Mobile responsive (dropzone, tooltip positioning)

## Files Changed

**New:**
- `components/quote/_breadcrumb/breadcrumb.js`
- `components/quote/_breadcrumb/breadcrumb.html`
- `components/quote/_breadcrumb/breadcrumb.css`
- `components/_help-tooltip/help-tooltip.js`
- `components/_help-tooltip/help-tooltip.html`
- `components/_help-tooltip/help-tooltip.css`

**Modified:**
- `components/quote/view/view.js` — register breadcrumb component
- `components/quote/view/view.html` — add breadcrumb element
- `components/quote/cost/cost.js` — register help-tooltip component
- `components/quote/cost/cost.html` — add cost breakdown visualization
- `components/quote/cost/cost.css` — add breakdown card styles
- `components/quote/entities/entities.js` — add process hours column, totalProcessHours method
- `components/quote/entities/entities.html` — (no changes needed)

## Version Notes

Recommend version bump to 1.1.0 once all Phase 1 work is complete and tested. Create a `VERSION` file in project root and update `CHANGELOG.md` with user-facing improvements.
