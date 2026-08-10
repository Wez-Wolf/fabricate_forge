# fabricate_forge/tests/phases/phase8.sh
#
# Phase 8 — Business modules: tools / orders / procurement / production / prefabs.
#
# Covers:
#   tools.php        calculator math (material plate/section/general + welding/machining/assembly)
#   orders.php       order CRUD + status transitions
#   procurement.php  purchase orders, supplier quotes, received goods
#   production.php   records + auto variance + quote summary
#   prefabs.php      template CRUD + bake_from_quote + instantiate (ECS tree + recalc)
#
# NOTE: run-phase.sh logs in as $TEST_EMAIL and cleans that user's rows first.

# ── 1. Tools: material plate ─────────────────────────
RES=$(authed tools.php '{"action":"calculate","input":{"tool":"material_plate","inputs":{"thickness":10,"length":1000,"width":500,"quantity":1,"materialRate":25,"wasteFactor":12.5,"materialType":"steel"}}}')
assert_no_error "tools: material plate calc" "$RES"
assert_jq "plate weight = 39.25 kg" "$RES" '.weight' "39.25"
assert_jq "plate material cost = 981.25" "$RES" '.materialCost' "981.25"
assert_jq "plate total with 12.5% waste = 1103.91" "$RES" '.totalCost' "1103.91"

RES=$(authed tools.php '{"action":"calculate","input":{"tool":"material_section","inputs":{"weightPerMeter":20,"length":3000,"quantity":5,"materialRate":25,"wasteFactor":10}}}')
assert_no_error "tools: material section calc" "$RES"
assert_jq "section total length = 15 m" "$RES" '.totalLength' "15"
assert_jq "section total weight = 300 kg" "$RES" '.totalWeight' "300"
assert_jq "section total cost = 8250" "$RES" '.totalCost' "8250"

RES=$(authed tools.php '{"action":"calculate","input":{"tool":"process_welding","inputs":{"weldType":"fillet","weldLength":1000,"quantity":1,"materialThickness":6,"qualityFactor":1,"laborRate":90,"consumableRate":2,"equipmentRate":25}}}')
assert_no_error "tools: welding calc" "$RES"
assert_jq "weld total length = 1 m" "$RES" '.totalLength' "1"
# thicknessFactor = 1 + (6/5)*0.2 = 1.24; time = (1*0.3*1.24*1)/60 = 0.0062 h (r2 → 0.01)
assert_jq "weld time = 0.01 h" "$RES" '.weldingTime' "0.01"
assert_jq "weld labor = 0.56" "$RES" '.laborCost' "0.56"

RES=$(authed tools.php '{"action":"calculate","input":{"tool":"process_assembly","inputs":{"componentCount":20,"timePerComponent":2,"complexityFactor":1,"inspectionTime":15,"laborRate":90,"fixtureCost":50}}}')
assert_no_error "tools: assembly calc" "$RES"
# totalAssemblyTime = (20*2*1 + 15)/60 = 0.916666...h → 0.92
assert_jq "assembly time = 0.92 h" "$RES" '.totalAssemblyTime' "0.92"
assert_jq "assembly labor = 82.5" "$RES" '.laborCost' "82.5"
assert_jq "assembly total = 155" "$RES" '.totalCost' "155"

RES=$(authed tools.php '{"action":"calculate","input":{"tool":"bogus","inputs":{}}}')
assert_jq "tools: unknown tool rejected (400)" "$RES" '.error_code' "400"

RES=$(authed tools.php '{"action":"density","input":{"material_key":"aluminum"}}')
assert_jq "tools: aluminum density 2700" "$RES" '.density' "2700"

# ── 2. Orders ────────────────────────────────────────
RES=$(authed orders.php '{"action":"create","input":{"title":"Test Order Alpha","description":"Phase 8 fixture","total_value":1250.5}}')
assert_no_error "orders: create" "$RES"
ORDER_ID=$(jq -r '.id' <<<"$RES")
assert_jq "order default status draft" "$RES" '.status' "draft"

RES=$(authed orders.php "{\"action\":\"set_status\",\"input\":{\"id\":\"$ORDER_ID\",\"status\":\"won\"}}")
assert_no_error "orders: set status won" "$RES"
assert_jq "order status now won" "$RES" '.status' "won"

RES=$(authed orders.php "{\"action\":\"set_status\",\"input\":{\"id\":\"$ORDER_ID\",\"status\":\"bogus\"}}")
assert_jq "orders: invalid status rejected (400)" "$RES" '.error_code' "400"

RES=$(authed orders.php "{\"action\":\"update\",\"input\":{\"id\":\"$ORDER_ID\",\"notes\":\"prioritized\"}}")
assert_no_error "orders: update notes" "$RES"
assert_jq "notes persisted" "$RES" '.notes' "prioritized"

RES=$(authed orders.php '{"action":"list","input":{}}')
assert_no_error "orders: list" "$RES"
assert_jq "list contains the order" "$RES" "[.[] | select(.id == \"$ORDER_ID\")] | length" "1"

# ── 3. Procurement ───────────────────────────────────
RES=$(authed procurement.php '{"action":"po_create","input":{"supplier_name":"SteelCo","total_value":4500,"expected_date":"2026-09-01"}}')
assert_no_error "procurement: po create" "$RES"
PO_ID=$(jq -r '.id' <<<"$RES")
assert_jq "po default status draft" "$RES" '.status' "draft"

RES=$(authed procurement.php "{\"action\":\"po_set_status\",\"input\":{\"id\":\"$PO_ID\",\"status\":\"ordered\"}}")
assert_no_error "procurement: po set status" "$RES"
assert_jq "po status now ordered" "$RES" '.status' "ordered"

RES=$(authed procurement.php "{\"action\":\"po_set_status\",\"input\":{\"id\":\"$PO_ID\",\"status\":\"bogus\"}}")
assert_jq "procurement: invalid po status (400)" "$RES" '.error_code' "400"

RES=$(authed procurement.php '{"action":"sq_create","input":{"supplier_name":"FastenerWholesale","unit_price":0.35,"min_order_qty":100,"lead_time_days":7}}')
assert_no_error "procurement: supplier quote create" "$RES"
assert_jq "supplier quote price" "$RES" '.unit_price' "0.35"

RES=$(authed procurement.php '{"action":"rg_create","input":{"purchase_order_id":"'$PO_ID'","items":[{"name":"Plate","qty":2}]}}')
assert_no_error "procurement: received goods create" "$RES"

RES=$(authed procurement.php '{"action":"po_list","input":{}}')
assert_no_error "procurement: po list" "$RES"
assert_jq "po list has the po" "$RES" "[.[] | select(.id == \"$PO_ID\")] | length" "1"

RES=$(authed procurement.php '{"action":"sq_list","input":{}}')
assert_no_error "procurement: sq list" "$RES"
assert_jq "supplier quotes listed" "$RES" "length > 0" "true"

# ── 4. Production ────────────────────────────────────
RES=$(authed production.php '{"action":"record_create","input":{"entity_name":"Skid Frame","trade":"boilermaking","estimated_hours":10,"actual_hours":12,"notes":"overrun"}}')
assert_no_error "production: record create" "$RES"
assert_jq "record created" "$RES" '.record.actual_hours' "12"
# variance = +2h, +20%
assert_jq "variance computed (+2)" "$RES" '.variance.variance' "2"
assert_jq "variance pct = 20" "$RES" '.variance.variance_percent' "20"

RES=$(authed production.php '{"action":"quote_summary","input":{"quote_id":"00000000-0000-0000-0000-000000000000"}}')
assert_no_error "production: quote summary (no records)" "$RES"
assert_jq "summary zero totals" "$RES" '.total_estimated' "0"
assert_jq "summary zero variance" "$RES" '.variance' "0"

# ── 5. Prefabs ───────────────────────────────────────
# 5a. Template CRUD
RES=$(authed prefabs.php '{"action":"create","input":{"name":"Pipe Skid","type":"assembly","description":"Test template","template_data":{"root":{"id":"root","type":"assembly","name":"Pipe Skid"},"items":[{"id":"1","name":"Base Plate","type":"part","quantity":1,"attributes":{"profile":"Plate 10mm","length":1200,"width":400,"thickness":10}},{"id":"2","name":"Support Leg","type":"part","quantity":4,"attributes":{"profile":"Angle 50x50","length":500}}],"processes":[{"id":"default","name":"Assemble","trade":"assembly","durationHours":2}]}}}')
assert_no_error "prefabs: template create" "$RES"
PREFAB_ID=$(jq -r '.id' <<<"$RES")

RES=$(authed prefabs.php '{"action":"list","input":{}}')
assert_no_error "prefabs: list" "$RES"
assert_jq "template listed" "$RES" "[.[] | select(.id == \"$PREFAB_ID\")] | length" "1"

# 5b. Instantiate into a fresh quote → ECS tree + recalc
RES=$(authed entities.php '{"action":"create","input":{"type":"quote","name":"Prefab Test Quote"}}')
assert_no_error "prefabs: create target quote" "$RES"
PREFAB_QID=$(jq -r '.id' <<<"$RES")

RES=$(authed prefabs.php "{\"action\":\"instantiate\",\"input\":{\"prefab_id\":\"$PREFAB_ID\",\"quote_id\":\"$PREFAB_QID\"}}")
assert_no_error "prefabs: instantiate" "$RES"
assert_jq "root entity created" "$RES" '.root_entity_id != null and .root_entity_id != ""' "true"
assert_jq "2 child entities created" "$RES" '.child_ids | length' "2"
INST_ROOT=$(jq -r '.root_entity_id' <<<"$RES")

# Tree shape: root assembly → contains → 2 parts; root linked to quote
RES=$(authed links.php "{\"action\":\"tree\",\"input\":{\"entity_id\":\"$PREFAB_QID\"}}")
assert_no_error "prefabs: quote tree after instantiate" "$RES"
assert_jq "quote has 1 direct child (root)" "$RES" '.children | length' "1"
assert_jq "root has 2 children" "$RES" '.children[0].children | length' "2"

# Material component resolved on the Base Plate (profile lookup)
PLATE_ID=$(jq -r '.children[0].children[0].id' <<<"$RES")
RES=$(authed entities.php "{\"action\":\"get\",\"input\":{\"id\":\"$PLATE_ID\",\"include_components\":1}}")
assert_no_error "prefabs: plate entity get" "$RES"
assert_jq "plate has material component" "$RES" '.components | map(select(.type=="material")) | length' "1"

# Process component on the root (assembly 2h)
RES=$(authed entities.php "{\"action\":\"get\",\"input\":{\"id\":\"$INST_ROOT\",\"include_components\":1}}")
assert_no_error "prefabs: root entity get" "$RES"
assert_jq "root has process component" "$RES" '.components | map(select(.type=="process")) | length' "1"
assert_jq "root process has 2h assembly" "$RES" '.components[] | select(.type=="process") | .data.assembly' "2"

# Cost recalc ran (root + children cost components exist)
RES=$(authed systems.php "{\"action\":\"load_quote\",\"input\":{\"quote_id\":\"$PREFAB_QID\"}}")
assert_no_error "prefabs: quote recalc after instantiate" "$RES"
assert_jq "quote total cost computed" "$RES" '.total_cost != null' "true"

# 5c. bake_from_quote → new template
RES=$(authed prefabs.php "{\"action\":\"bake_from_quote\",\"input\":{\"quote_id\":\"$PREFAB_QID\",\"assembly_id\":\"$INST_ROOT\",\"name\":\"Baked Skid\"}}")
assert_no_error "prefabs: bake from quote" "$RES"
assert_jq "baked template created" "$RES" '.name' "Baked Skid"
assert_jq "baked template has items" "$RES" '.template_data.items | length' "3"

# 5d. Missing prefab → 404
RES=$(authed prefabs.php '{"action":"instantiate","input":{"prefab_id":"00000000-0000-0000-0000-000000000000","quote_id":"'$PREFAB_QID'"}}')
assert_jq "prefabs: unknown template → 404" "$RES" '.error_code' "404"
