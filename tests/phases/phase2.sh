# fabricate_forge/tests/phases/phase2.sh
#
# Phase 2 — Reference data: materials / rates / process.
# Exercises every handler against the live DB and asserts on real JSON.
#
# Fixtures (scoped to the test user):
#   Material: user-created plate "Test Plate A36" (density 7850, unit_cost 2.5)
#   Rate:     entity rate override on a part + company default rate
#   Process:  process component with named-field hours

# ── 1. Material create ────────────────────────────────
RES=$(authed materials.php '{"action":"create","input":{"name":"Test Plate A36","profile":"plate","category":"Carbon Steel","grade":"A36","density":7850,"thickness":10,"unit_cost":2.5,"library_category":"material","aliases":["A36","ms plate"]}}')
assert_no_error "create material" "$RES"
MAT_ID=$(jq -r '.id' <<<"$RES")
assert_jq "material density" "$RES" '.density' "7850"
assert_jq "material unit_cost" "$RES" '.unit_cost' "2.5"
assert_jq "material owner set (user-owned)" "$RES" '.user_id_owner != null' "true"

# ── 2. Material list + filters ────────────────────────
RES=$(authed materials.php "{\"action\":\"list\",\"input\":{\"search\":\"A36\"}}")
assert_no_error "search materials" "$RES"
assert "search found the plate" "$(jq -r "map(select(.id == \"$MAT_ID\")) | length" <<<"$RES")" "1"

RES=$(authed materials.php "{\"action\":\"list\",\"input\":{\"library_category\":\"material\"}}")
assert_no_error "list by library_category" "$RES"

# ── 3. Density lookup ─────────────────────────────────
RES=$(authed materials.php "{\"action\":\"get_density\",\"input\":{\"material_id\":\"$MAT_ID\"}}")
assert_no_error "density lookup" "$RES"
assert_jq "density value" "$RES" '.density' "7850"
assert_jq "unit_cost in lookup" "$RES" '.unit_cost' "2.5"

# ── 4. Material matching (score ranking) ──────────────
RES=$(authed materials.php "{\"action\":\"match\",\"input\":{\"search\":\"A36\"}}")
assert_no_error "material match" "$RES"
assert_jq "match returns ranked candidates" "$RES" 'length > 0' "true"
assert_jq "top candidate is the plate" "$RES" '.[0].name' "Test Plate A36"
assert_jq "match score present" "$RES" '.[0].match_score > 0' "true"

# ── 5. Rates: globals + company + hierarchy ───────────
RES=$(authed rates.php '{"action":"globals","input":{}}')
assert_no_error "global rates" "$RES"
assert_jq "welding global rate is 90" "$RES" '.rates.welding' "90"
assert_jq "machining global rate is 95" "$RES" '.rates.machining' "95"

# Company default override
RES=$(authed rates.php '{"action":"set_company_rates","input":{"rates":{"welding":110,"assembly":80}}}')
assert_no_error "set company rates" "$RES"
assert_jq "company welding = 110" "$RES" '.defaultRates.welding' "110"

RES=$(authed rates.php '{"action":"get_effective","input":{"trade":"welding"}}')
assert_no_error "effective rate (company wins)" "$RES"
assert_jq "company rate beats global" "$RES" '.source' "company"
assert_jq "welding effective = 110" "$RES" '.rate' "110"

# ── 6. Entity rate override (highest priority) ────────
RES=$(authed entities.php '{"action":"create","input":{"type":"part","name":"Rate Test Part"}}')
assert_no_error "create part for rate test" "$RES"
RATE_PART_ID=$(jq -r '.id' <<<"$RES")

RES=$(authed rates.php "{\"action\":\"set_entity_rate\",\"input\":{\"entity_id\":\"$RATE_PART_ID\",\"trade\":\"welding\",\"rate\":150}}")
assert_no_error "set entity rate" "$RES"
assert_jq "entity rate wins over company" "$RES" '.source' "entity"
assert_jq "welding entity = 150" "$RES" '.rate' "150"

# ── 7. Process: registry + extraction ─────────────────
RES=$(authed process.php '{"action":"get_registry","input":{}}')
assert_no_error "process registry" "$RES"
assert_jq "registry has 11 trades" "$RES" '.trades | length' "11"
assert_jq "registry welding rate" "$RES" '.rates.welding' "90"

RES=$(authed process.php '{"action":"extract","input":{"data":{"welding":2.5,"machining":1.0}}}')
assert_no_error "extract named-field hours" "$RES"
assert_jq "extracted 2 items" "$RES" '.items | length' "2"
assert_jq "total hours 3.5" "$RES" '.total_hours' "3.5"

# Legacy items-array format
RES=$(authed process.php '{"action":"extract","input":{"data":{"items":[{"name":"welding","time":2},{"name":"drilling","time":1.5}]}}}')
assert_no_error "extract items-array" "$RES"
assert_jq "items-array total 3.5" "$RES" '.total_hours' "3.5"

# ── 8. Process pricing (hours × rate hierarchy) ───────
# Put a process component on the rate-test part (entity welding = 150)
RES=$(authed components.php "{\"action\":\"create\",\"input\":{\"entity_id\":\"$RATE_PART_ID\",\"type\":\"process\",\"data\":{\"welding\":2,\"machining\":1}}}")
assert_no_error "create process component" "$RES"

RES=$(authed process.php "{\"action\":\"calculate_entity\",\"input\":{\"entity_id\":\"$RATE_PART_ID\"}}")
assert_no_error "calculate entity process" "$RES"
# welding: 2h × 150 (entity rate) = 300 ; machining: 1h × 95 (global) = 95 → 395
assert_jq "welding priced at entity rate 150" "$RES" '.items[] | select(.name=="welding") | .rate' "150"
assert_jq "machining priced at global 95" "$RES" '.items[] | select(.name=="machining") | .rate' "95"
assert_jq "total process cost = 395" "$RES" '.total_cost' "395"
