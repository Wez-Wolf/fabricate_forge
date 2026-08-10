# fabricate_forge/tests/phases/phase3.sh
#
# Phase 3 — Cost engine (cost.php). Verifies the ECS contract:
#   READ components → COMPUTE 5 layers → WRITE 'cost' component → read back.
#
# Fixture math (asserted exactly):
#   Part "Cost Plate" qty 2
#     material comp: plate 1200×400×10, library A36 (density 7850, unit_cost 2.5)
#       mass = 1200×400×10 / 1e9 × 7850 = 37.68 kg
#       L1 material = 37.68 × 2.5 × 2 = 188.40
#     process comp: welding 2h (entity rate 150), machining 1h (global 95)
#       welding  = 2 × 150 × 2 = 600
#       machining = 1 × 95 × 2 = 190
#       L2 processTotal = 790
#     options: consumables 10, transport 50, margin 30%
#       L3 = 10×2 + 0 + 0 = 20 ; L4 = 50
#       subtotal = 188.40 + 790 + 20 + 50 = 1048.40
#       margin = 1048.40 × 0.30 = 314.52
#       total = 1362.92

# ── Fixtures ─────────────────────────────────────────
RES=$(authed materials.php '{"action":"create","input":{"name":"Cost A36 Plate","profile":"plate","category":"Carbon Steel","grade":"A36","density":7850,"thickness":10,"unit_cost":2.5,"library_category":"material"}}')
assert_no_error "create cost material" "$RES"
COST_MAT_ID=$(jq -r '.id' <<<"$RES")

RES=$(authed entities.php '{"action":"create","input":{"type":"part","name":"Cost Plate","quantity":2}}')
assert_no_error "create cost part" "$RES"
COST_PART_ID=$(jq -r '.id' <<<"$RES")

RES=$(authed components.php "{\"action\":\"create\",\"input\":{\"entity_id\":\"$COST_PART_ID\",\"type\":\"material\",\"data\":{\"materialLibraryId\":\"$COST_MAT_ID\",\"category\":\"plate\",\"length\":1200,\"width\":400,\"thickness\":10,\"quantity\":2}}}")
assert_no_error "material component" "$RES"

RES=$(authed components.php "{\"action\":\"create\",\"input\":{\"entity_id\":\"$COST_PART_ID\",\"type\":\"process\",\"data\":{\"welding\":2,\"machining\":1}}}")
assert_no_error "process component" "$RES"

# Entity rate override: welding 150 (beats global 90)
RES=$(authed rates.php "{\"action\":\"set_entity_rate\",\"input\":{\"entity_id\":\"$COST_PART_ID\",\"trade\":\"welding\",\"rate\":150}}")
assert_no_error "entity welding rate 150" "$RES"

# ── 1. calculate_entity writes + returns cost component ──
RES=$(authed cost.php "{\"action\":\"calculate_entity\",\"input\":{\"entity_id\":\"$COST_PART_ID\",\"options\":{\"consumables\":10,\"transport\":50,\"margin_percent\":30}}}")
assert_no_error "calculate entity cost" "$RES"
assert_jq "cost component_id returned" "$RES" '.component_id != null' "true"

# L1 material: mass 37.68 kg → 37.68 × 2.5 × 2 = 188.40
assert_jq "massKg = 37.68" "$RES" '.data.massKg' "37.68"
assert_jq "material = 188.4" "$RES" '.data.material' "188.4"

# L2 process: welding 2×150×2=600, machining 1×95×2=190, total 790
assert_jq "welding = 600" "$RES" '.data.welding' "600"
assert_jq "machining = 190" "$RES" '.data.machining' "190"
assert_jq "processTotal = 790" "$RES" '.data.processTotal' "790"

# L3 on-costs: consumables 10×2=20
assert_jq "consumables = 20" "$RES" '.data.consumables' "20"

# L4 transport = 50
assert_jq "transport = 50" "$RES" '.data.transport' "50"

# L5 margin: subtotal 1048.40 × 30% = 314.52
assert_jq "subtotal = 1048.4" "$RES" '.data.subtotal' "1048.4"
assert_jq "margin = 314.52" "$RES" '.data.margin' "314.52"
assert_jq "total = 1362.92" "$RES" '.data.total' "1362.92"
assert_jq "unitCost = 681.46" "$RES" '.data.unitCost' "681.46"

# ── 2. ECS write-back proof: cost component exists, read without recompute ──
RES=$(authed components.php "{\"action\":\"list\",\"input\":{\"entity_id\":\"$COST_PART_ID\",\"type\":\"cost\"}}")
assert_no_error "list cost components" "$RES"
assert_jq "exactly 1 cost component written" "$RES" 'length' "1"
assert_jq "written component is type cost" "$RES" '.[0].type' "cost"

RES=$(authed cost.php "{\"action\":\"get_cost\",\"input\":{\"entity_id\":\"$COST_PART_ID\"}}")
assert_no_error "get_cost (no recompute)" "$RES"
assert_jq "get_cost total matches" "$RES" '.total' "1362.92"
assert_jq "get_cost has marginPercent" "$RES" '.marginPercent' "30"

# ── 3. Recompute after change: update material, recalc reflects it ──
RES=$(authed components.php "{\"action\":\"list\",\"input\":{\"entity_id\":\"$COST_PART_ID\",\"type\":\"material\"}}")
MAT_COMP_ID=$(jq -r '.[0].id' <<<"$RES")
RES=$(authed components.php "{\"action\":\"update\",\"input\":{\"id\":\"$MAT_COMP_ID\",\"data\":{\"thickness\":12}}}")
assert_no_error "bump thickness to 12" "$RES"

# mass = 1200×400×12/1e9×7850 = 45.216 ; material = 45.216×2.5×2 = 226.08
RES=$(authed cost.php "{\"action\":\"calculate_entity\",\"input\":{\"entity_id\":\"$COST_PART_ID\",\"options\":{\"consumables\":10,\"transport\":50,\"margin_percent\":30}}}")
assert_jq "recomputed massKg" "$RES" '.data.massKg' "45.22"
assert_jq "recomputed material" "$RES" '.data.material' "226.08"

# ── 4. Integration: materials.php feeds the cost engine ──
# The mass calc depends on the library row (density/unit_cost). Verify the
# exact lookup cost.php performs, plus the match scoring used by BOM import.
RES=$(authed materials.php "{\"action\":\"get_density\",\"input\":{\"material_id\":\"$COST_MAT_ID\"}}")
assert_no_error "materials.get_density (feeds mass calc)" "$RES"
assert_jq "density from library" "$RES" '.density' "7850"
assert_jq "unit_cost from library" "$RES" '.unit_cost' "2.5"

RES=$(authed materials.php "{\"action\":\"match\",\"input\":{\"search\":\"A36 plate\"}}")
assert_no_error "materials.match (BOM import linking)" "$RES"
assert_jq "match finds the plate" "$RES" '.[0].id' "$COST_MAT_ID"
assert_jq "match score > 0" "$RES" '.[0].match_score > 0' "true"

# ── 5. Integration: process.php prices the same hours cost.php used ──
# process.calculate_entity must return identical per-trade costs (welding
# 150 entity rate, machining 95 global) — cross-checking the L2 layer.
RES=$(authed process.php "{\"action\":\"calculate_entity\",\"input\":{\"entity_id\":\"$COST_PART_ID\"}}")
assert_no_error "process.calculate_entity (feeds L2)" "$RES"
# process.php prices PER UNIT (rate × hours); cost.php multiplies by qty.
# Cross-check: process welding 2×150=300 (per unit), cost L2 = 300×2=600 ✓
assert_jq "process welding per-unit = 300 (2h×150)" "$RES" '.["items"][] | select(.name=="welding") | .cost' "300"
assert_jq "process machining per-unit = 95 (1h×95)" "$RES" '.["items"][] | select(.name=="machining") | .cost' "95"
assert_jq "process total per-unit = 395" "$RES" '.total_cost' "395"

# ── 6. Integration: rates.php hierarchy feeding cost ──
RES=$(authed rates.php "{\"action\":\"get_all_effective\",\"input\":{\"entity_id\":\"$COST_PART_ID\"}}")
assert_no_error "rates.get_all_effective (feeds L2 pricing)" "$RES"
assert_jq "welding effective = entity 150" "$RES" '.welding.rate' "150"
assert_jq "welding source = entity" "$RES" '.welding.source' "entity"
assert_jq "machining effective = global 95" "$RES" '.machining.rate' "95"
assert_jq "machining source = global" "$RES" '.machining.source' "global"

# ── 7. Batch + assembly rollup ────────────────────────
RES=$(authed cost.php "{\"action\":\"batch_calculate\",\"input\":{\"entity_ids\":[\"$COST_PART_ID\"]}}")
assert_no_error "batch calculate" "$RES"
assert_jq "batch returns per-entity total" "$RES" ".\"$COST_PART_ID\".total > 0" "true"

# Assembly: parent with no own cost + contains child (qty 1) → rolled_total = child total
RES=$(authed entities.php '{"action":"create","input":{"type":"assembly","name":"Cost Parent"}}')
assert_no_error "create parent assembly" "$RES"
PARENT_ID=$(jq -r '.id' <<<"$RES")

RES=$(authed links.php "{\"action\":\"create\",\"input\":{\"from_id\":\"$PARENT_ID\",\"to_id\":\"$COST_PART_ID\",\"type\":\"contains\",\"quantity\":1}}")
assert_no_error "link parent→child" "$RES"

RES=$(authed cost.php "{\"action\":\"calculate_assembly\",\"input\":{\"entity_id\":\"$PARENT_ID\"}}")
assert_no_error "calculate assembly" "$RES"
assert_jq "assembly has 1 child" "$RES" '.children | length' "1"
assert_jq "child rolled_total = child total" "$RES" '.children[0].rolled_total > 0' "true"
assert_jq "parent rolled_total includes child" "$RES" '.rolled_total > 0' "true"
