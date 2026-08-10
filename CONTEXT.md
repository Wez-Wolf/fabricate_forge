

## codebase-map
# Auto-scanned: 2026-08-10

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
    - nav/
      - nav.css
      - nav.html
      - nav.js
    - orders/
      - orders.css
      - orders.html
      - orders.js
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
    - tools/
      - tools.css
      - tools.html
      - tools.js
  - lib/
    - config.php
    - init.php
    - svg.php
    - vue.php
  - scripts/
    - seed-materials.php
    - seed-prefabs.php
    - seed-test-quote.php
  - seed-data/
    - fasteners.json
    - fittings.json
    - materials.json
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
  php: 30
  vue: 0
  js/ts: 22
  styles: 23
  docs: 3

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
    - --text-dim: #64748b
    - --text-dim: #cbd5e1
  fonts:
    - Inter
    - Inter,-apple-system,sans-serif

key_files:
  # (add contextual notes as you discover work)
