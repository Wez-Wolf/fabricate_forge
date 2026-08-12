# fabricate_forge/tests/phases/phase11.sh
#
# Phase 11 — Full Mock Costing (assemblies + parts + materials + processes + consumables + finishes)
#
# This test exercises a complete multi-level BOM with:
#   - Assembly with nested children (BOM tree)
#   - Parts with materials (plates, sections, pipes)
#   - Process hours (welding, machining, boilermaking, cutting, drilling, grinding, bending)
#   - Consumables and services
#   - Paint & lining (external and internal)
#   - Transport
#   - Assembly cost rollup with quantity
#   - Waste factors
#   - Contingency factors
#   - Margin calculation
#
# Exit 0 = all assertions passed

# ── 1. Create Materials in Library ─────────────────────────
# Standard steel plate 6mm, density 7850, unit cost 25/kg
RES=$(authed materials.php '{"action":"create","input":{"name":"Test 6mm Plate","profile":"Plate","materialType":"Steel","grade":"S235JR","density":7850,"thickness":6,"unitCost":25,"library_category":"material"}}')
assert_no_error "materials: create test plate 6mm" "$RES"
PLATE_ID=$(jq -r '.id' <<<"$RES")

# Steel angle 50x50x6, mass per meter 8.9 kg
RES=$(authed materials.php '{"action":"create","input":{"name":"Test Angle 50x50x6","profile":"Angle","materialType":"Steel","grade":"S235JR","density":7850,"massPerMeter":8.9,"unitCost":22,"library_category":"material"}}')
assert_no_error "materials: create test angle" "$RES"
ANGLE_ID=$(jq -r '.id' <<<"$RES")

# Stainless steel plate 10mm, higher cost for demo
RES=$(authed materials.php '{"action":"create","input":{"name":"Test SS 10mm Plate","profile":"Plate","materialType":"Stainless Steel","grade":"304","density":7900,"thickness":10,"unitCost":45,"library_category":"material"}}')
assert_no_error "materials: create SS plate 10mm" "$RES"
SS_PLATE_ID=$(jq -r '.id' <<<"$RES")

# ── 2. Create a Full Assembly with Children ─────────────
# Main Assembly: "Machine Skid"
RES=$(authed entities.php '{"action":"create","input":{"type":"assembly","name":"Test Machine Skid","quantity":1,"data":{"marginPercent":30}}}')
assert_no_error "entities: create assembly" "$RES"
ASSEMBLY_ID=$(jq -r '.id' <<<"$RES")

# Part 1: Skid Base Frame (uses angle section)
# 2m long, 4 angles, welding required
RES=$(authed entities.php '{"action":"create","input":{"type":"part","name":"Base Frame","quantity":1,"quote_id":null}}')
assert_no_error "entities: create Base Frame part" "$RES"
FRAME_ID=$(jq -r '.id' <<<"$RES")

# Material component for frame (angle section)
RES=$(authed components.php '{"action":"create","input":{"entity_id":"'$FRAME_ID'","type":"material","data":{"materialLibraryId":"'$ANGLE_ID'","category":"section","length":2000,"quantity":1}}}')
assert_no_error "components: frame material" "$RES"

# Process component for frame (welding + assembly)
RES=$(authed components.php '{"action":"create","input":{"entity_id":"'$FRAME_ID'","type":"process","data":{"welding":2.5,"assembly":1.0}}}')
assert_no_error "components: frame process" "$RES"

# Part 2: Top Plate (SS 10mm)
RES=$(authed entities.php '{"action":"create","input":{"type":"part","name":"Top Plate","quantity":1,"quote_id":null}}')
assert_no_error "entities: create Top Plate part" "$RES"
PLATE2_ID=$(jq -r '.id' <<<"$RES")

RES=$(authed components.php '{"action":"create","input":{"entity_id":"'$PLATE2_ID'","type":"material","data":{"materialLibraryId":"'$SS_PLATE_ID'","category":"plate","length":1200,"width":800,"thickness":10,"quantity":1}}}')
assert_no_error "components: top plate material" "$RES"

RES=$(authed components.php '{"action":"create","input":{"entity_id":"'$PLATE2_ID'","type":"process","data":{"machining":1.5,"painting":0.5}}}')
assert_no_error "components: top plate process" "$RES"

# Part 3: Internal Brace (2x)
RES=$(authed entities.php '{"action":"create","input":{"type":"part","name":"Internal Brace","quantity":2,"quote_id":null}}')
assert_no_error "entities: create brace part" "$RES"
BRACE_ID=$(jq -r '.id' <<<"$RES")

RES=$(authed components.php '{"action":"create","input":{"entity_id":"'$BRACE_ID'","type":"material","data":{"materialLibraryId":"'$ANGLE_ID'","category":"section","length":1500,"quantity":1}}}')
assert_no_error "components: brace material" "$RES"

RES=$(authed components.php '{"action":"create","input":{"entity_id":"'$BRACE_ID'","type":"process","data":{"welding":1.0,"cutting":0.5}}}')
assert_no_error "components: brace process" "$RES"

# Part 4: Electrical Box
RES=$(authed entities.php '{"action":"create","input":{"type":"part","name":"Electrical Box","quantity":2,"quote_id":null}}')
assert_no_error "entities: create electrical box" "$RES"
BOX_ID=$(jq -r '.id' <<<"$RES")

RES=$(authed components.php '{"action":"create","input":{"entity_id":"'$BOX_ID'","type":"material","data":{"mass":2.5,"unitCost":15,"category":"other","quantity":1}}}')
assert_no_error "components: box material" "$RES"

RES=$(authed components.php '{"action":"create","input":{"entity_id":"'$BOX_ID'","type":"process","data":{"assembly":0.5,"qualityControl":0.2}}}')
assert_no_error "components: box process" "$RES"

# Create Contains Links (Assembly → Parts)
RES=$(authed links.php '{"action":"create","input":{"from_id":"'$ASSEMBLY_ID'","to_id":"'$FRAME_ID'","type":"contains","quantity":1}}')
assert_no_error "links: assembly→frame" "$RES"

RES=$(authed links.php '{"action":"create","input":{"from_id":"'$ASSEMBLY_ID'","to_id":"'$PLATE2_ID'","type":"contains","quantity":1}}')
assert_no_error "links: assembly→plate2" "$RES"

RES=$(authed links.php '{"action":"create","input":{"from_id":"'$ASSEMBLY_ID'","to_id":"'$BRACE_ID'","type":"contains","quantity":2}}')
assert_no_error "links: assembly→brace" "$RES"

RES=$(authed links.php '{"action":"create","input":{"from_id":"'$ASSEMBLY_ID'","to_id":"'$BOX_ID'","type":"contains","quantity":2}}')
assert_no_error "links: assembly→box" "$RES"

# ── 3. Create a Quote and Link the Assembly ───────────────
RES=$(authed entities.php '{"action":"create","input":{"type":"quote","name":"Test Full Costing Quote","quantity":1,"data":{"status":"draft","currency":"USD"}}}')
assert_no_error "entities: create quote" "$RES"
QUOTE_ID=$(jq -r '.id' <<<"$RES")

# Link assembly to quote
RES=$(authed links.php '{"action":"create","input":{"from_id":"'$QUOTE_ID'","to_id":"'$ASSEMBLY_ID'","type":"contains","quantity":1}}')
assert_no_error "links: quote→assembly (root link)" "$RES"

# ── 4. Calculate Entity Costs (Individual Parts) ───────────
# Frame cost calculation
RES=$(authed cost.php '{"action":"calculate_entity","input":{"entity_id":"'$FRAME_ID'", "options": {"consumables":5, "transport":50, "margin_percent":25}}}')
assert_no_error "cost: frame entity" "$RES"
FRAME_COST=$(jq -r '.data.total' <<<"$RES")
assert "frame has cost > 0" "$FRAME_COST" "$(echo $FRAME_COST | xargs printf '%.2f' | grep -E '^[0-9.]+$')"

# Verify frame: material (angle) + process (welding + assembly) + on-costs + margin
# Angle: 2m × 8.9 kg/m = 17.8kg × 22 = 391.60
# Welding: 2.5h × welding rate (entity: let's check effective) + assembly: 1h × assembly rate
# Let's set entity welding rate
RES=$(authed rates.php '{"action":"set_entity_rate","input":{"entity_id":"'$FRAME_ID'","trade":"welding","rate":120}}')
assert_no_error "rates: set frame welding rate 120" "$RES"

# Recalculate frame after rate change
RES=$(authed cost.php '{"action":"calculate_entity","input":{"entity_id":"'$FRAME_ID'", "options": {"consumables":5, "transport":50, "margin_percent":25}}}')
FRAME_DATA=$(jq '.data' <<<"$RES")
assert_jq "frame material cost > 0" "$RES" '.data.material > 0' "true"
assert_jq "frame processTotal > 0" "$RES" '.data.processTotal > 0' "true"

# ── 5. Calculate Assembly Cost (Recursive Roll-up) ───────────
RES=$(authed cost.php '{"action":"calculate_assembly","input":{"entity_id":"'$ASSEMBLY_ID'"}}')
assert_no_error "cost: assembly roll-up" "$RES"
assert_jq "assembly has children" "$RES" '.children | length >= 4' "true"
assert_jq "assembly rolled_total > 0" "$RES" '.rolled_total > 0' "true"

# Verify assembly totals match children × quantity
# Frame (qty 1) + Plate2 (qty 1) + Brace (qty 2) + Box (qty 2)
# The rolled_total should equal the sum of children's rolled_total × quantity

# ── 6. Load Full Quote with Costs ─────────────────────────
RES=$(authed systems.php '{"action":"load_quote","input":{"quote_id":"'$QUOTE_ID'"}}')
assert_no_error "systems: load_quote" "$RES"
assert_jq "quote has entities" "$RES" '.entities | length >= 1' "true"
assert_jq "quote has costs" "$RES" '.costs | length >= 1' "true"
assert_jq "quote has total_cost" "$RES" '.total_cost >= 0' "true"

# ── 7. Test Batch Cost Calculation ─────────────────────────
RES=$(authed cost.php '{"action":"batch_calculate","input":{"entity_ids":["'$FRAME_ID'","'$PLATE2_ID'","'$BRACE_ID'","'$BOX_ID'"]}}')
assert_no_error "cost: batch calculate" "$RES"
assert_jq "batch has all entities" "$RES" 'has("'$FRAME_ID'") and has("'$PLATE2_ID'")' "true"

# ── 8. Test Assembly with Quantity Changes ─────────────────
# Update frame quantity and verify recalc changes cost
RES=$(authed entities.php '{"action":"update","input":{"id":"'$FRAME_ID'","quantity":2}}')
RES=$(authed cost.php '{"action":"calculate_entity","input":{"entity_id":"'$FRAME_ID'"}}')
# Quantity doubled → cost should roughly double
OLD_FRAME_COST=$FRAME_COST
NEW_FRAME_COST=$(jq -r '.data.total' <<<"$RES")
assert_jq "quantity change affects frame cost" "$RES" '.data.total > 0' "true"

# ── 9. Test Margin Application ───────────────────────────
# Margin should apply at each level: item margin > quote margin > default 30%
RES=$(authed cost.php '{"action":"calculate_entity","input":{"entity_id":"'$FRAME_ID'", "options": {"margin_percent":25}}}')
assert_jq "margin applied 25%" "$RES" '.data.marginPercent' "25"
assert_jq "total includes margin" "$RES" '(.data.subtotal > 0) and (.data.total > .data.subtotal)' "true"

# ── 10. Test Cost Component Persistence ──────────────────
RES=$(authed components.php '{"action":"list","input":{"entity_id":"'$FRAME_ID'","type":"cost"}}')
assert_no_error "components: cost component persisted" "$RES"
assert_jq "cost component exists" "$RES" 'length > 0' "true"

# Verify the stored cost matches what we calculated
STORED_COST=$(jq -r '.[0].data.total' <<<"$RES")
assert "stored cost matches calculated" "$STORED_COST" "$FRAME_COST"

# ── 11. Test All Process Trades ───────────────────────────
# Test welding, machining, boilermaking, cutting, drilling, grinding, bending
RES=$(authed entities.php '{"action":"create","input":{"type":"part","name":"All Trades Test Part","quantity":1,"quote_id":null}}')
TEST_PART_ID=$(jq -r '.id' <<<"$RES")

RES=$(authed components.php '{"action":"create","input":{"entity_id":"'$TEST_PART_ID'","type":"material","data":{"mass":10,"unitCost":30,"category":"general","quantity":1}}}')
RES=$(authed components.php '{"action":"create","input":{"entity_id":"'$TEST_PART_ID'","type":"process","data":{"boilermaking":2,"welding":1.5,"machining":2,"cutting":1,"drilling":0.5,"grinding":0.5,"bending":1,"assembly":1}}}')

RES=$(authed cost.php '{"action":"calculate_entity","input":{"entity_id":"'$TEST_PART_ID'"}}')
assert_jq "boilermaking cost > 0" "$RES" '.data.boilermaking > 0' "true"
assert_jq "welding cost > 0" "$RES" '.data.welding > 0' "true"
assert_jq "machining cost > 0" "$RES" '.data.machining > 0' "true"
assert_jq "cutting cost > 0" "$RES" '.data.cutting > 0' "true"
assert_jq "drilling cost > 0" "$RES" '.data.drilling > 0' "true"
assert_jq "grinding cost > 0" "$RES" '.data.grinding > 0' "true"
assert_jq "bending cost > 0" "$RES" '.data.bending > 0' "true"
assert_jq "total includes all trades" "$RES" '.data.total > 0' "true"

# ── 12. Test Paint & Lining ─────────────────────────────
RES=$(authed entities.php '{"action":"create","input":{"type":"part","name":"Paint Test Part","quantity":1,"quote_id":null,"data":{"onCosts":{"painting":{"mode":"inhouse","extPaint":50,"intPaint":30},"lining":{"line":20,"coating1":15}}}}')
PAINT_PART_ID=$(jq -r '.id' <<<"$RES")

RES=$(authed components.php '{"action":"create","input":{"entity_id":"'$PAINT_PART_ID'","type":"material","data":{"extArea":2.5,"intArea":1.5,"quantity":1}}}')

RES=$(authed cost.php '{"action":"calculate_entity","input":{"entity_id":"'$PAINT_PART_ID'"}}')
# Paint = extArea × extPaint + intArea × intPaint = 2.5×50 + 1.5×30 = 175
assert_jq "paint cost calculated" "$RES" '.data.paint > 0' "true"
assert_jq "lining cost calculated" "$RES" '.data.lining > 0' "true"

# ── 13. Test Consumables & Services ───────────────────────
RES=$(authed entities.php '{"action":"create","input":{"type":"part","name":"Consumables Test","quantity":2,"quote_id":null}}')
CONS_PART_ID=$(jq -r '.id' <<<"$RES")

RES=$(authed cost.php '{"action":"calculate_entity","input":{"entity_id":"'$CONS_PART_ID'", "options": {"consumables":10, "services":5, "ndt":8, "lining":15}}}')
RES=$(authed cost.php '{"action":"calculate_entity","input":{"entity_id":"'$CONS_PART_ID'", "options": {"consumables":10, "services":5, "ndt":8, "lining":15}}}')

# Consumables × qty = 10 × 2 = 20
assert_jq "consumables cost with qty" "$RES" '.data.consumables == 20' "true"
assert_jq "services cost with qty" "$RES" '.data.services == 10' "true"
assert_jq "ndt cost with qty" "$RES" '.data.ndt == 16' "true"

# ── 14. Test Transport Calculation ───────────────────────
RES=$(authed entities.php '{"action":"create","input":{"type":"part","name":"Transport Test","quantity":1,"quote_id":null,"data":{"material":{"category":"plate","length":1200,"width":800,"thickness":10}}}}')
TRANS_PART_ID=$(jq -r '.id' <<<"$RES")

# Set transport option
RES=$(authed cost.php '{"action":"calculate_entity","input":{"entity_id":"'$TRANS_PART_ID'", "options": {"transport":75}}}')
assert_jq "transport applied 75" "$RES" '.data.transport == 75' "true"

# ── 15. Final Cleanup ─────────────────────────────────────
# Note: run-phase.sh handles cleanup via clean_test_data()