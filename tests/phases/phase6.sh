# fabricate_forge/tests/phases/phase6.sh
#
# Phase 6 — Support layer: auth / user / admin / boms.
#
# This phase has its own auth flow:
#   1. signup a dedicated test user (or login if exists)
#   2. login → auth_id
#   3. exercise user prefs, company settings, BOM import
#   4. admin endpoints (role-gated — uses the same user, expects 403 unless admin)
#
# NOTE: run-phase.sh logs in as $TEST_EMAIL first. For phase 6 we re-login as
# the SUPPORT test user to prove signup works end-to-end.

SUPPORT_EMAIL="support-test@fabricate.local"
SUPPORT_PASS="Support123!"

# ── Clean support-test's rows (this phase has its own user) ──
# Removes prefs/settings/entities from prior runs so assertions are idempotent.
export PGPASSWORD="$(grep '^DB_PASS=' .env | cut -d= -f2)"
psql -h "$(grep '^DB_HOST=' .env | cut -d= -f2)" -U "$(grep '^DB_USER=' .env | cut -d= -f2)" -d "$(grep '^DB_NAME=' .env | cut -d= -f2)" -q <<SQL
  DELETE FROM component WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$SUPPORT_EMAIL');
  DELETE FROM link      WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$SUPPORT_EMAIL');
  DELETE FROM entity    WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$SUPPORT_EMAIL');
  DELETE FROM material_library WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$SUPPORT_EMAIL');
  DELETE FROM company_settings WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$SUPPORT_EMAIL');
  DELETE FROM user_prefs WHERE user_id IN (SELECT id FROM "user" WHERE email = '$SUPPORT_EMAIL');
SQL
unset PGPASSWORD
say "cleaned support-test data"

# ── 1. Signup (public) ────────────────────────────────
RES=$(api auth.php "{\"action\":\"signup\",\"input\":{\"email\":\"$SUPPORT_EMAIL\",\"pass\":\"$SUPPORT_PASS\"}}")
# signup may already exist on re-runs — accept either created or existing
if jq -e '.error' <<<"$RES" >/dev/null 2>&1 && ! echo "$RES" | grep -qi "already"; then
  die "signup failed: $RES"
fi
say "signup ok (or already exists)"

# ── 2. Login → auth_id ────────────────────────────────
RES=$(api auth.php "{\"action\":\"login\",\"input\":{\"email\":\"$SUPPORT_EMAIL\",\"pass\":\"$SUPPORT_PASS\"}}")
assert_no_error "login" "$RES"
SUP_AUTH=$(jq -r '.data.auth_id // .auth_id // empty' <<<"$RES")
assert "got auth_id" "$( [[ -n "$SUP_AUTH" ]] && echo yes || echo no )" "yes"

# authed wrapper for this phase's user
sauthed() {
  local file="$1"; shift
  local payload="$1"
  if jq -e '.input' <<<"$payload" >/dev/null 2>&1; then
    api "$file" "$(jq --arg a "$SUP_AUTH" '.input.auth_id = $a' <<<"$payload")"
  else
    api "$file" "$(jq --arg a "$SUP_AUTH" '.auth_id = $a' <<<"$payload")"
  fi
}

# ── 3. Session verify ─────────────────────────────────
RES=$(api auth.php "{\"action\":\"verify\",\"input\":{\"auth_id\":\"$SUP_AUTH\"}}")
assert_no_error "verify session" "$RES"

# ── 4. User prefs: get defaults, update merge ─────────
RES=$(sauthed user.php '{"action":"get_preferences","input":{}}')
assert_no_error "get preferences" "$RES"
assert_jq "default markup 30" "$RES" '.defaultMarkupPercent' "30"
assert_jq "default currency USD" "$RES" '.defaultCurrency' "USD"

RES=$(sauthed user.php '{"action":"update_preferences","input":{"data":{"defaultMarkupPercent":35,"defaultCurrency":"ZAR"}}}')
assert_no_error "update preferences" "$RES"
assert_jq "markup updated to 35" "$RES" '.defaultMarkupPercent' "35"
assert_jq "currency updated to ZAR" "$RES" '.defaultCurrency' "ZAR"

# ── 5. Company settings (admin.php) ───────────────────
RES=$(sauthed admin.php '{"action":"get_settings","input":{}}')
assert_no_error "get company settings" "$RES"
assert_jq "default rates welding 90" "$RES" '.defaultRates.welding' "90"

RES=$(sauthed admin.php '{"action":"update_settings","input":{"data":{"companyName":"Fabricate Test Co","defaultMarkupPercent":28}}}')
assert_no_error "update company settings" "$RES"
assert_jq "company name set" "$RES" '.companyName' "Fabricate Test Co"
assert_jq "markup updated to 28" "$RES" '.defaultMarkupPercent' "28"
# defaultRates preserved through merge
assert_jq "defaultRates preserved" "$RES" '.defaultRates.welding' "90"

# ── 6. Admin user mgmt: non-admin → 403 ───────────────
RES=$(sauthed admin.php '{"action":"list_users","input":{}}')
assert_jq "non-admin list_users rejected (403)" "$RES" '.error_code' "403"

RES=$(sauthed admin.php "{\"action\":\"set_user_role\",\"input\":{\"user_id\":\"x\",\"role\":\"admin\"}}")
assert_jq "non-admin set_user_role rejected (403)" "$RES" '.error_code' "403"

# ── 7. BOM import → ECS graph ─────────────────────────
RES=$(sauthed entities.php '{"action":"create","input":{"type":"quote","name":"BOM Test Quote"}}')
assert_no_error "create bom quote" "$RES"
BOM_QID=$(jq -r '.id' <<<"$RES")

# Seed a material so match can resolve it
RES=$(sauthed materials.php '{"action":"create","input":{"name":"BOM A36 Plate","profile":"plate","category":"Carbon Steel","grade":"A36","density":7850,"thickness":10,"unit_cost":2.5,"library_category":"material","aliases":["A36","ms plate"]}}')
assert_no_error "create bom material" "$RES"
BOM_MAT_ID=$(jq -r '.id' <<<"$RES")

RES=$(sauthed boms.php "{\"action\":\"import\",\"input\":{\"quote_id\":\"$BOM_QID\",\"rows\":[
  {\"item_number\":\"1\",\"description\":\"Skid Frame\",\"quantity\":1},
  {\"item_number\":\"1.1\",\"description\":\"Mounting Plate\",\"material\":\"A36\",\"quantity\":4,\"length\":1200,\"width\":400,\"thickness\":10},
  {\"item_number\":\"1.1.1\",\"description\":\"M12 Bolt\",\"material\":\"bolt\",\"quantity\":16}
]}}")
assert_no_error "bom import" "$RES"
assert_jq "3 entities imported" "$RES" '.imported' "3"
assert_jq "skipped count 0" "$RES" '.skipped_count' "0"

# Type detection
assert_jq "Skid Frame detected assembly" "$RES" '.entities[] | select(.item_number=="1") | .type' "assembly"
assert_jq "Mounting Plate detected part" "$RES" '.entities[] | select(.item_number=="1.1") | .type' "part"
assert_jq "M12 Bolt detected fastener" "$RES" '.entities[] | select(.item_number=="1.1.1") | .type' "fastener"

# ── 8. BOM hierarchy links built from item numbers ────
RES=$(sauthed systems.php "{\"action\":\"load_quote\",\"input\":{\"quote_id\":\"$BOM_QID\"}}")
assert_no_error "load bom quote" "$RES"
assert_jq "quote has 3 entities" "$RES" '.entities | length' "3"

# Skid Frame (assembly) has contains-links to children
FRAME_ID=$(jq -r ".entities[] | select(.name==\"Skid Frame\") | .id" <<<"$RES")
RES2=$(sauthed links.php "{\"action\":\"tree\",\"input\":{\"entity_id\":\"$FRAME_ID\"}}")
assert_no_error "bom tree from frame" "$RES2"
assert_jq "frame has 1 child (plate)" "$RES2" '.children | length' "1"
assert_jq "plate has 1 child (bolt)" "$RES2" '.children[0].children | length' "1"

# Material component resolved on the plate via match
RES3=$(sauthed entities.php "{\"action\":\"get\",\"input\":{\"id\":\"$(jq -r '.children[0].id' <<<"$RES2")\",\"include_components\":1}}")
assert_jq "plate has material component" "$RES3" '.components | map(select(.type=="material")) | length' "1"
assert_jq "material library id resolved" "$RES3" '.components[] | select(.type=="material") | .data.materialLibraryId' "$BOM_MAT_ID"

# ── 9. BOM calculate → total via cost engine ──────────
RES=$(sauthed boms.php "{\"action\":\"calculate\",\"input\":{\"quote_id\":\"$BOM_QID\"}}")
assert_no_error "bom calculate" "$RES"
assert_jq "bom total > 0" "$RES" '.total_cost > 0' "true"
