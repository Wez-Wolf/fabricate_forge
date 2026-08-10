# fabricate_forge/tests/phases/phase7.sh
#
# Phase 7 — Seeded library verification.
# Verifies the GLOBAL material library (102 rows) is:
#   1. present and owner-scoped correctly (user_id_owner NULL = global)
#   2. searchable by the API
#   3. matchable (BOM import linking works against real data)
#   4. density/mass data usable by the cost engine
#
# Requires: scripts/seed-materials.php has been run.

# ── 1. Library present + global scoping ────────────────
RES=$(authed materials.php '{"action":"list","input":{"limit":200}}')
assert_no_error "list global materials" "$RES"
assert "≥ 100 global materials" "$(jq 'length >= 100' <<<"$RES")" "true"
assert_jq "all rows global (no owner)" "$RES" '[.[] | select(.user_id_owner != null)] | length' "0"

# Category split
RES2=$(authed materials.php '{"action":"list","input":{"library_category":"fastener","limit":50}}')
assert "fasteners present" "$(jq 'length >= 10' <<<"$RES2")" "true"
RES3=$(authed materials.php '{"action":"list","input":{"library_category":"fitting","limit":50}}')
assert "fittings present" "$(jq 'length >= 5' <<<"$RES3")" "true"

# ── 2. Real catalog entries exist ──────────────────────
RES=$(authed materials.php '{"action":"list","input":{"search":"S235JR Plate 10mm","limit":5}}')
assert_no_error "search S235JR Plate 10mm" "$RES"
assert_jq "found the plate" "$RES" 'map(.name) | index("S235JR Plate 10mm") != null' "true"
assert_jq "plate density 7850" "$RES" '.[] | select(.name=="S235JR Plate 10mm") | .density' "7850"

RES=$(authed materials.php '{"action":"list","input":{"search":"M12 x 40 Hex Bolt","limit":5}}')
assert_no_error "search M12 bolt" "$RES"
assert_jq "bolt is fastener category" "$RES" '.[] | select(.name=="M12 x 40 Hex Bolt") | .library_category' "fastener"

# ── 3. Match scoring (BOM import path) ─────────────────
RES=$(authed materials.php '{"action":"match","input":{"search":"S235JR plate"}}')
assert_no_error "match S235JR plate" "$RES"
assert "match returns candidates" "$(jq 'length > 0' <<<"$RES")" "true"
assert_jq "top candidate is a plate" "$RES" '(.[0].profile | ascii_downcase | contains("plate")) or (.[0].name | ascii_downcase | contains("plate"))' "true"

# ── 4. Cost engine uses seeded data (real material price) ──
# Create a part with a seeded material → cost uses the library's density/unit_cost
RES=$(authed materials.php '{"action":"list","input":{"search":"S235JR Plate 10mm","limit":1}}')
PLATE_ID=$(jq -r '.[0].id' <<<"$RES")
PLATE_COST=$(jq -r '.[0].unit_cost' <<<"$RES")
PLATE_DENSITY=$(jq -r '.[0].density' <<<"$RES")
say "plate: density=$PLATE_DENSITY unit_cost=$PLATE_COST"

RES=$(authed entities.php '{"action":"create","input":{"type":"part","name":"Seeded Plate Part","quantity":1}}')
assert_no_error "create part" "$RES"
PART_ID=$(jq -r '.id' <<<"$RES")

RES=$(authed components.php "{\"action\":\"create\",\"input\":{\"entity_id\":\"$PART_ID\",\"type\":\"material\",\"data\":{\"materialLibraryId\":\"$PLATE_ID\",\"category\":\"plate\",\"length\":1000,\"width\":1000,\"thickness\":10,\"quantity\":1}}}")
assert_no_error "material component w/ seeded lib" "$RES"

# mass = 1000×1000×10/1e9 × 7850 = 78.5 kg ; material = 78.5 × unit_cost
RES=$(authed cost.php "{\"action\":\"calculate_entity\",\"input\":{\"entity_id\":\"$PART_ID\"}}")
assert_no_error "cost uses seeded material" "$RES"
EXPECTED_MASS=$(python3 -c "print(round(1000*1000*10/1e9*$PLATE_DENSITY, 2))")
assert_jq "mass matches seeded density ($EXPECTED_MASS)" "$RES" '.data.massKg' "$EXPECTED_MASS"
assert_jq "material cost > 0" "$RES" '.data.material > 0' "true"
assert_jq "total > material" "$RES" '.data.total > .data.material' "true"
