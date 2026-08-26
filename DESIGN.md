# DESIGN.md — project design system

> Auto-detected by codebase-map. The Detected section regenerates each scan; edit the Principles section freely.

<!-- design-system:auto -->
## Detected tokens

### Colors (dark theme — default)
- --bg: #0f172a
- --surface: #1e293b
- --surface-2: #334155
- --surface-3: #475569
- --border: #475569
- --border-hover: #64748b
- --text: #f8fafc
- --text-dim: #cbd5e1
- --text-muted: #94a3b8
- --accent: #3b82f6
- --accent-hover: #60a5fa
- --accent-soft: rgba(59,130,246,0.15)
- --success: #10b981
- --warning: #f59e0b
- --error: #ef4444
- --info: #06b6d4
- --text-on-color: #ffffff
- --secondary: #8b5cf6
- --error-rgb: 239,68,68
- --hover-bg: rgba(59,130,246,0.08)
- --shadow-md: 0 4px 6px rgba(0,0,0,0.3)
### Colors (light theme — `html.light` / `html:not(.dark)`)
- --bg: #f8fafc · --surface: #ffffff · --surface-2: #f1f5f9 · --surface-3: #e2e8f0
- --border: #cbd5e1 · --border-hover: #94a3b8
- --text: #1e293b · --text-dim: #64748b · --text-muted: #94a3b8
- --accent: #2563eb · --accent-hover: #3b82f6 · --accent-soft: #dbeafe
- --success: #059669 · --warning: #d97706 · --error: #dc2626 · --info: #0284c7
- --error-rgb: 220,38,38 · --hover-bg: rgba(37,99,235,0.05) · --shadow-md: 0 4px 6px rgba(0,0,0,0.08)
### Color aliases (var() wrappers)
- --border-color: var(--border) · --color-bg: var(--bg) · --color_text_light: var(--text)
- --color-accent: var(--accent) · --color_primary: var(--accent) · --color-primary: var(--accent)
- --color-surface-card: var(--surface) · --color-text-on-card: var(--text)
- --color-text-secondary: var(--text-dim) · --color_text_secondary: var(--text-dim)
- --color_menu_background: var(--surface) · --color_menu_btn: var(--accent)
### Forge component overrides
- --forge-button-disabled-bg: rgba(59, 130, 246, 0.4)
- --forge-button-text: #ffffff
- --forge-form-submit-text: #ffffff
### Radius
- --radius-sm: 0.375rem · --radius-md: 0.5rem · --radius-lg: 0.75rem · --radius-xl: 1rem
### Transitions
- --transition-fast: 0.15s ease · --transition-normal: 0.3s ease
### Font sizes
- --fs-caption: 0.75rem · --fs-small: 0.8125rem · --fs-body: 0.9375rem
- --fs-title: 1.125rem · --fs-heading: 1.25rem · --fs-display: 1.75rem
### Spacing
- --sp-1: 0.25rem · --sp-2: 0.5rem · --sp-3: 0.75rem · --sp-4: 1rem
- --sp-5: 1.25rem · --sp-6: 1.5rem · --sp-8: 2rem · --sp-10: 2.5rem · --sp-12: 3rem
### Fonts
- Inter (Google Fonts)
- Inter, -apple-system, sans-serif (system fallback stack)
- var(--fs-body) (component-level font-size reference)
<!-- /design-system:auto -->

<!-- design-system:manual -->
## Principles

**Brand:** dark-first industrial theme (steel blue #3b82f6, violet #8b5cf6). Light theme swaps all surfaces/borders/text for light values. Status colours map to semantic defaults (green=success, amber=warning, red=error).

**Kind colour badges (quoteview tree):** pipe=green (#d1fae5), flange=amber (#fcd34d), fitting=blue (#e9eefb), material=gray (#e5e7eb), unknown=gray (#9ca3af). Text colour: light bg → #111827, dark bg → #ffffff.

**Token usage:** use `var(--token)` aliases (e.g. `--color-primary`, `--border-color`) in JS-driven styles, not raw hex values. Forge `--forge-*` tokens are already themed; override only when the brand palette diverges from forge defaults.
<!-- /design-system:manual -->
