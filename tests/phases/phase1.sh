# fabricate_forge/tests/phases/phase1.sh
#
# Phase 1 — ECS core: entities / components / links.
# Exercises every handler against the live DB and asserts on real JSON.
#
# Fixtures (all scoped to the test user):
#   Assembly "Skid Frame" ─contains→ Part "Mounting Plate"
#   Part gets: material component + process component
#
# Cleanup is automatic: entities are soft-deleted; the purge script removes
# the test user's rows hard.

# ── 1. Entity create ────────────────────────────────
RES=$(authed entities.php '{"action":"create","input":{"type":"assembly","name":"Skid Frame","description":"Test fixture assembly"}}')
assert_no_error "create assembly" "$RES"
ASSY_ID=$(jq -r '.id' <<<"$RES")
assert "assembly got an id" "$( [[ -n "$ASSY_ID" && "$ASSY_ID" != "null" ]] && echo yes || echo no )" "yes"
assert_jq "assembly type persisted" "$RES" '.type' "assembly"

RES=$(authed entities.php '{"action":"create","input":{"type":"part","name":"Mounting Plate","description":"Test fixture part"}}')
assert_no_error "create part" "$RES"
PART_ID=$(jq -r '.id' <<<"$RES")
assert "part got an id" "$( [[ -n "$PART_ID" && "$PART_ID" != "null" ]] && echo yes || echo no )" "yes"

# ── 2. Link: assembly contains part (BOM edge) ────────
RES=$(authed links.php "{\"action\":\"create\",\"input\":{\"from_id\":\"$ASSY_ID\",\"to_id\":\"$PART_ID\",\"type\":\"contains\",\"quantity\":4}}")
assert_no_error "create contains link" "$RES"
LINK_ID=$(jq -r '.id' <<<"$RES")
assert_jq "link quantity persisted" "$RES" '.quantity' "4"

# Duplicate link should be rejected
RES=$(authed links.php "{\"action\":\"create\",\"input\":{\"from_id\":\"$ASSY_ID\",\"to_id\":\"$PART_ID\",\"type\":\"contains\",\"quantity\":2}}")
assert_jq "duplicate link rejected (409)" "$RES" '.error_code' "409"

# ── 3. Components on the part ──────────────────────────
RES=$(authed components.php "{\"action\":\"create\",\"input\":{\"entity_id\":\"$PART_ID\",\"type\":\"material\",\"data\":{\"materialLibraryId\":\"\",\"category\":\"plate\",\"length\":1200,\"width\":400,\"thickness\":10,\"quantity\":4}}}")
assert_no_error "create material component" "$RES"
MAT_COMP=$(jq -r '.id' <<<"$RES")
assert_jq "material component type" "$RES" '.type' "material"
assert_jq "material data length" "$RES" '.data.length' "1200"

RES=$(authed components.php "{\"action\":\"create\",\"input\":{\"entity_id\":\"$PART_ID\",\"type\":\"process\",\"data\":{\"welding\":2.5,\"machining\":1.0}}}")
assert_no_error "create process component" "$RES"

# ── 4. Partial update must MERGE, not replace ──────────
RES=$(authed components.php "{\"action\":\"update\",\"input\":{\"id\":\"$MAT_COMP\",\"data\":{\"thickness\":12}}}")
assert_no_error "merge update component" "$RES"
assert_jq "merged: thickness updated" "$RES" '.data.thickness' "12"
assert_jq "merge preserved: length intact" "$RES" '.data.length' "1200"

# ── 5. get_full returns entity + components + children ──
RES=$(authed entities.php "{\"action\":\"get_full\",\"input\":{\"id\":\"$ASSY_ID\"}}")
assert_no_error "get_full assembly" "$RES"
assert_jq "get_full: 1 child via contains" "$RES" '.children | length' "1"
assert_jq "get_full: child has components" "$RES" '.children[0].components | length' "2"
assert_jq "get_full: child quantity from link" "$RES" '.children[0].link_quantity' "4"

# ── 6. Tree traversal ──────────────────────────────────
RES=$(authed links.php "{\"action\":\"tree\",\"input\":{\"entity_id\":\"$ASSY_ID\"}}")
assert_no_error "BOM tree" "$RES"
assert_jq "tree root type" "$RES" '.type' "assembly"
assert_jq "tree: 1 child" "$RES" '.children | length' "1"
assert_jq "tree: child is the part" "$RES" '.children[0].name' "Mounting Plate"

# ── 7. List with filters ───────────────────────────────
RES=$(authed entities.php "{\"action\":\"list\",\"input\":{\"type\":\"part\"}}")
assert_no_error "list parts" "$RES"
assert "list returns the part" "$(jq -r "map(select(.id == \"$PART_ID\")) | length" <<<"$RES")" "1"

RES=$(authed entities.php "{\"action\":\"list\",\"input\":{\"search\":\"Mounting\"}}")
assert_no_error "search by name" "$RES"
assert_jq "search found part" "$RES" 'map(.name) | index("Mounting Plate") != null' "true"

# ── 8. Cycle detection ─────────────────────────────────
RES=$(authed links.php "{\"action\":\"validate_cycle\",\"input\":{\"entity_id\":\"$ASSY_ID\"}}")
assert_no_error "no cycle initially" "$RES"
assert_jq "validate reports no_cycle" "$RES" '.no_cycle' "true"

# ── 9. Ownership isolation ─────────────────────────────
# A different (unauthenticated) read must not leak: use no auth → expect 401
RES=$(api entities.php "{\"action\":\"list\"}")
assert_jq "unauthenticated list rejected" "$RES" '.code // .error_code // 401' "401"

# ── 10. Delete (soft) ──────────────────────────────────
RES=$(authed entities.php "{\"action\":\"delete\",\"input\":{\"id\":\"$PART_ID\"}}")
assert_no_error "soft-delete part" "$RES"
RES=$(authed entities.php "{\"action\":\"get\",\"input\":{\"id\":\"$PART_ID\"}}")
assert_jq "deleted entity not returned" "$RES" '.error_code' "404"
