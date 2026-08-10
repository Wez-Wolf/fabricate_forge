# fabricate_forge/tests/phases/phase5.sh
#
# Phase 5 — Quote lifecycle (quotes.php).
# Verifies: create → status machine (valid/invalid transitions) → history →
# entity attach → total recalc → PDF export → soft delete.
#
# Fixture:
#   Quote + one part (material + welding) attached → exercises the full flow.

# ── 1. Create quote ───────────────────────────────────
RES=$(authed quotes.php '{"action":"create","input":{"name":"Lifecycle Quote","customer_name":"Acme Corp","currency":"ZAR","validity_days":30}}')
assert_no_error "create quote" "$RES"
QID=$(jq -r '.id' <<<"$RES")
assert_jq "quote type" "$RES" '.type' "quote"
assert_jq "quote starts draft" "$RES" '.data.status' "draft"
assert_jq "quote currency" "$RES" '.data.currency' "ZAR"
assert_jq "quote history seeded" "$RES" '.data.statusHistory | length' "1"
assert_jq "quote number generated" "$RES" '.data.quoteNumber != null' "true"

# ── 2. Status machine: valid transitions ──────────────
RES=$(authed quotes.php "{\"action\":\"update_status\",\"input\":{\"quote_id\":\"$QID\",\"status\":\"submitted\",\"note\":\"Sent to client\"}}")
assert_no_error "draft → submitted" "$RES"
assert_jq "status now submitted" "$RES" '.status' "submitted"
assert_jq "history has 2 entries" "$RES" '.statusHistory | length' "2"
assert_jq "history note recorded" "$RES" '.statusHistory[1].note' "Sent to client"

RES=$(authed quotes.php "{\"action\":\"update_status\",\"input\":{\"quote_id\":\"$QID\",\"status\":\"approved\"}}")
assert_no_error "submitted → approved" "$RES"
assert_jq "approved" "$RES" '.status' "approved"

RES=$(authed quotes.php "{\"action\":\"update_status\",\"input\":{\"quote_id\":\"$QID\",\"status\":\"invoiced\"}}")
assert_no_error "approved → invoiced" "$RES"
assert_jq "invoiced" "$RES" '.status' "invoiced"

# ── 3. Invalid transition must be rejected (409) ──────
RES=$(authed quotes.php "{\"action\":\"update_status\",\"input\":{\"quote_id\":\"$QID\",\"status\":\"approved\"}}")
assert_jq "invoiced → approved rejected (409)" "$RES" '.error_code' "409"
assert_jq "rejection lists allowed next" "$RES" '.allowed | index("draft") != null' "true"

RES=$(authed quotes.php "{\"action\":\"update_status\",\"input\":{\"quote_id\":\"$QID\",\"status\":\"nonsense\"}}")
assert_jq "unknown status rejected (400)" "$RES" '.error_code' "400"

# Reopen: invoiced → draft is valid
RES=$(authed quotes.php "{\"action\":\"update_status\",\"input\":{\"quote_id\":\"$QID\",\"status\":\"draft\"}}")
assert_no_error "invoiced → draft (reopen)" "$RES"
assert_jq "back to draft" "$RES" '.status' "draft"

# ── 4. Update fields (partial, JSONB merge) ───────────
RES=$(authed quotes.php "{\"action\":\"update\",\"input\":{\"quote_id\":\"$QID\",\"customer_name\":\"Beta Ltd\",\"due_date\":\"2026-12-01\"}}")
assert_no_error "update quote fields" "$RES"
assert_jq "customer updated" "$RES" '.data.customerName' "Beta Ltd"
assert_jq "currency preserved (merge)" "$RES" '.data.currency' "ZAR"
assert_jq "status preserved (merge)" "$RES" '.data.status' "draft"

# ── 5. Attach entity → total recalc ───────────────────
RES=$(authed materials.php '{"action":"create","input":{"name":"Lifecycle A36","profile":"plate","category":"Carbon Steel","grade":"A36","density":7850,"thickness":10,"unit_cost":2.5,"library_category":"material"}}')
LC_MAT_ID=$(jq -r '.id' <<<"$RES")

RES=$(authed entities.php "{\"action\":\"create\",\"input\":{\"type\":\"part\",\"name\":\"Lifecycle Part\",\"quantity\":1}}")
assert_no_error "create part" "$RES"
LC_PART_ID=$(jq -r '.id' <<<"$RES")

RES=$(authed components.php "{\"action\":\"create\",\"input\":{\"entity_id\":\"$LC_PART_ID\",\"type\":\"material\",\"data\":{\"materialLibraryId\":\"$LC_MAT_ID\",\"category\":\"plate\",\"length\":1000,\"width\":500,\"thickness\":10,\"quantity\":1}}}")
assert_no_error "part material" "$RES"
# mass = 1000×500×10/1e9×7850 = 39.25 kg ; material = 39.25×2.5 = 98.13 (r2)
# welding 2h × 90 × 1 = 180 ; subtotal = 278.12 ; margin 30% → total 361.56 (r2)

RES=$(authed components.php "{\"action\":\"create\",\"input\":{\"entity_id\":\"$LC_PART_ID\",\"type\":\"process\",\"data\":{\"welding\":2}}}")
assert_no_error "part process" "$RES"

RES=$(authed quotes.php "{\"action\":\"add_entity\",\"input\":{\"quote_id\":\"$QID\",\"entity_id\":\"$LC_PART_ID\"}}")
assert_no_error "attach entity to quote" "$RES"
assert_jq "quote now has 1 entity" "$RES" '.entities | length' "1"
assert_jq "grand total = 361.57" "$RES" '.total_cost' "361.56"

# ── 6. Get + list reflect state ───────────────────────
RES=$(authed quotes.php "{\"action\":\"get\",\"input\":{\"quote_id\":\"$QID\"}}")
assert_no_error "get quote" "$RES"
assert_jq "get returns entity" "$RES" '.entities | length' "1"

RES=$(authed quotes.php '{"action":"list","input":{}}')
assert_no_error "list quotes" "$RES"
assert "quote in list" "$(jq -r "map(select(.id == \"$QID\")) | length" <<<"$RES")" "1"
assert_jq "list shows persisted total" "$RES" ".[] | select(.id == \"$QID\") | .total_cost" "361.56"

# ── 7. PDF export ─────────────────────────────────────
RES=$(authed quotes.php "{\"action\":\"export_pdf\",\"input\":{\"quote_id\":\"$QID\"}}")
assert_no_error "export pdf" "$RES"
assert_jq "html is a document" "$RES" '.html | startswith("<!DOCTYPE html>")' "true"
assert_jq "html contains item name" "$RES" '.html | contains("Lifecycle Part")' "true"
assert_jq "html contains grand total" "$RES" '.html | contains("361.56")' "true"

# ── 8. Remove entity → total drops to 0 ───────────────
RES=$(authed quotes.php "{\"action\":\"remove_entity\",\"input\":{\"quote_id\":\"$QID\",\"entity_id\":\"$LC_PART_ID\"}}")
assert_no_error "remove entity" "$RES"
assert_jq "quote empty after removal" "$RES" '.entities | length' "0"
assert_jq "total zero after removal" "$RES" '.total_cost' "0"

# ── 9. Soft delete ────────────────────────────────────
RES=$(authed quotes.php "{\"action\":\"delete\",\"input\":{\"quote_id\":\"$QID\"}}")
assert_no_error "delete quote" "$RES"
RES=$(authed quotes.php "{\"action\":\"get\",\"input\":{\"quote_id\":\"$QID\"}}")
assert_jq "deleted quote not found (404)" "$RES" '.error_code' "404"
