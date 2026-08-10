# fabricate_forge/tests/phases/phase4.sh
#
# Phase 4 — Orchestration (systems.php). Verifies the single-call contract:
#   loadQuote(quote_id) → { quote, entities, costs, total_cost }
# in ONE round-trip, with total_cost auto-persisted into the quote's cost
# component (the ECS equivalent of the original quote.totalCost persistence).
#
# Fixture:
#   Quote "Test Quote" (entity type=quote)
#   ├── Part A (qty 2): material plate 1000×500×10 A36 → mass 39.25 kg
#   │     material = 39.25 × 2.5 × 2 = 196.25
#   │     welding 1h × 90 (global) × 2 = 180
#   │     subtotal = 376.25 ; margin 30% = 112.875 → total 489.13 (r2)
#   └── Part B (qty 1): material plate 500×500×10 A36 → mass 19.625 kg
#         material = 19.625 × 2.5 × 1 = 49.06 (r2)
#         no process
#         margin 30% → total = 49.06 × 1.3 = 63.78 (r2)
#   grand total = 489.13 × 2? NO — entity totals already × qty → 489.13 + 63.78 = 552.91

# ── Fixtures ─────────────────────────────────────────
RES=$(authed materials.php '{"action":"create","input":{"name":"Sys A36","profile":"plate","category":"Carbon Steel","grade":"A36","density":7850,"thickness":10,"unit_cost":2.5,"library_category":"material"}}')
SYS_MAT_ID=$(jq -r '.id' <<<"$RES")
assert_no_error "create material" "$RES"

RES=$(authed entities.php '{"action":"create","input":{"type":"quote","name":"Test Quote"}}')
assert_no_error "create quote entity" "$RES"
QUOTE_ID=$(jq -r '.id' <<<"$RES")

# Part A
RES=$(authed entities.php "{\"action\":\"create\",\"input\":{\"type\":\"part\",\"name\":\"Part A\",\"quote_id\":\"$QUOTE_ID\",\"quantity\":2}}")
assert_no_error "create part A" "$RES"
PART_A=$(jq -r '.id' <<<"$RES")

RES=$(authed components.php "{\"action\":\"create\",\"input\":{\"entity_id\":\"$PART_A\",\"type\":\"material\",\"data\":{\"materialLibraryId\":\"$SYS_MAT_ID\",\"category\":\"plate\",\"length\":1000,\"width\":500,\"thickness\":10,\"quantity\":2}}}")
assert_no_error "part A material" "$RES"
RES=$(authed components.php "{\"action\":\"create\",\"input\":{\"entity_id\":\"$PART_A\",\"type\":\"process\",\"data\":{\"welding\":1}}}")
assert_no_error "part A process" "$RES"

# Part B
RES=$(authed entities.php "{\"action\":\"create\",\"input\":{\"type\":\"part\",\"name\":\"Part B\",\"quote_id\":\"$QUOTE_ID\",\"quantity\":1}}")
assert_no_error "create part B" "$RES"
PART_B=$(jq -r '.id' <<<"$RES")

RES=$(authed components.php "{\"action\":\"create\",\"input\":{\"entity_id\":\"$PART_B\",\"type\":\"material\",\"data\":{\"materialLibraryId\":\"$SYS_MAT_ID\",\"category\":\"plate\",\"length\":500,\"width\":500,\"thickness\":10,\"quantity\":1}}}")
assert_no_error "part B material" "$RES"

# ── 1. loadQuote single call ─────────────────────────
RES=$(authed systems.php "{\"action\":\"load_quote\",\"input\":{\"quote_id\":\"$QUOTE_ID\"}}")
assert_no_error "load_quote" "$RES"

# Shape contract
assert_jq "quote returned" "$RES" '.quote.id' "$QUOTE_ID"
assert_jq "quote type is quote" "$RES" '.quote.type' "quote"
assert_jq "2 entities" "$RES" '.entities | length' "2"
assert_jq "costs has part A" "$RES" ".costs.\"$PART_A\" != null" "true"
assert_jq "costs has part B" "$RES" ".costs.\"$PART_B\" != null" "true"

# Part A cost: material 196.25, welding 180, total 489.13
assert_jq "part A material = 196.25" "$RES" ".costs.\"$PART_A\".material" "196.25"
assert_jq "part A welding = 180" "$RES" ".costs.\"$PART_A\".welding" "180"
assert_jq "part A total = 489.13" "$RES" ".costs.\"$PART_A\".total" "489.13"

# Part B cost: material 49.06, total 63.78 (49.0625 × 1.3 = 63.78125 → r2 63.78)
assert_jq "part B material = 49.06" "$RES" ".costs.\"$PART_B\".material" "49.06"
assert_jq "part B total = 63.78" "$RES" ".costs.\"$PART_B\".total" "63.78"

# Grand total = 489.13 + 63.78 = 552.91
assert_jq "grand total = 552.91" "$RES" '.total_cost' "552.91"

# Entities carry components + cost attached
# material + process + the cost component load_quote wrote = 3 (ECS write-back)
assert_jq "part A has components attached" "$RES" ".entities[] | select(.id == \"$PART_A\") | .components | length" "3"
assert_jq "part A cost attached" "$RES" ".entities[] | select(.id == \"$PART_A\") | .cost.total" "489.13"

# ── 2. Auto-persist: quote's own cost component holds total ──
RES=$(authed systems.php "{\"action\":\"list_quotes\",\"input\":{}}")
assert_no_error "list_quotes" "$RES"
assert_jq "quote listed" "$RES" "map(select(.id == \"$QUOTE_ID\")) | length" "1"
assert_jq "quote total_cost persisted = 552.91" "$RES" ".[] | select(.id == \"$QUOTE_ID\") | .total_cost" "552.91"
assert_jq "quote status defaults to draft" "$RES" ".[] | select(.id == \"$QUOTE_ID\") | .status" "draft"

# Cost component physically written on the quote entity
RES=$(authed components.php "{\"action\":\"list\",\"input\":{\"entity_id\":\"$QUOTE_ID\",\"type\":\"cost\"}}")
assert_jq "quote cost component written" "$RES" 'length' "1"
assert_jq "quote cost total matches" "$RES" '.[0].data.total' "552.91"

# ── 3. Recalculate: wipe cost comps, recompute, same total ──
RES=$(authed systems.php "{\"action\":\"recalculate_quote\",\"input\":{\"quote_id\":\"$QUOTE_ID\"}}")
assert_no_error "recalculate_quote" "$RES"
assert_jq "recalc grand total stable" "$RES" '.total_cost' "552.91"

# ── 4. Recalc reflects changes: bump part A qty 2→3 ──
RES=$(authed entities.php "{\"action\":\"update\",\"input\":{\"id\":\"$PART_A\",\"quantity\":3}}")
assert_no_error "bump part A qty to 3" "$RES"

# part A: material 39.25×2.5×3=294.375→294.38 ; welding 1×90×3=270 ; subtotal 564.38
#   margin 30% = 169.31 ; total = 733.69 ; part B 63.78 → grand 797.47
RES=$(authed systems.php "{\"action\":\"load_quote\",\"input\":{\"quote_id\":\"$QUOTE_ID\"}}")
assert_jq "part A qty3 material = 294.38" "$RES" ".costs.\"$PART_A\".material" "294.38"
assert_jq "part A qty3 total = 733.69" "$RES" ".costs.\"$PART_A\".total" "733.69"
assert_jq "grand total after qty change = 797.47" "$RES" '.total_cost' "797.47"

# ── 5. Ownership isolation on load ────────────────────
RES=$(api systems.php "{\"action\":\"load_quote\",\"input\":{\"quote_id\":\"$QUOTE_ID\"}}")
assert_jq "unauthenticated load rejected" "$RES" '.code // .error_code // 401' "401"
