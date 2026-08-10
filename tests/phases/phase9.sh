# fabricate_forge/tests/phases/phase9.sh
#
# Phase 9 — Full-port extras: tank/pipe tools + forgot/reset password flow.
#
# Covers:
#   tools.php    tank builder math (shell/head area, mass, cost) + pipe schedule takeoff
#   auth.php     forgot_password → reset_password → login with new password
#
# NOTE: run-phase.sh logs in as $TEST_EMAIL and cleans that user's rows first.

# ── 1. Tank builder ─────────────────────────────────
RES=$(authed tools.php '{"action":"calculate","input":{"tool":"tank","inputs":{"diameter":1200,"length":3000,"thickness":10,"heads":2,"quantity":1,"materialRate":25,"wasteFactor":10,"materialType":"steel"}}}')
assert_no_error "tools: tank calc" "$RES"
assert_jq "tank shell area 11.31 m²" "$RES" '.shellArea' "11.31"
assert_jq "tank head area 2.26 m²" "$RES" '.headArea' "2.26"
assert_jq "tank total area 13.57 m²" "$RES" '.totalArea' "13.57"
assert_jq "tank mass 1065.38 kg" "$RES" '.massKg' "1065.38"
assert_jq "tank capacity 3393 L" "$RES" '.capacityLitres' "3393"
assert_jq "tank material cost 26634.42" "$RES" '.materialCost' "26634.42"
assert_jq "tank total with 10% waste 29297.86" "$RES" '.totalCost' "29297.86"

RES=$(authed tools.php '{"action":"calculate","input":{"tool":"tank","inputs":{"diameter":1000,"length":2000,"thickness":8,"heads":1,"quantity":2,"materialRate":30,"wasteFactor":5,"materialType":"aluminum"}}}')
assert_no_error "tools: tank aluminum" "$RES"
# shell = π×1.0×2.0 = 6.28; head = π×0.5² = 0.79; total = 7.07; vol = 0.05655; mass = 0.05655×2700×2 = 305.36
assert_jq "tank aluminum mass" "$RES" '.massKg' "305.36"
assert_jq "tank aluminum cost" "$RES" '.materialCost' "9160.88"

# ── 2. Pipe schedule takeoff ─────────────────────────
RES=$(authed tools.php '{"action":"calculate","input":{"tool":"pipe","inputs":{"nominalSize":"50","schedule":"40","lengthM":6,"quantity":1,"materialRate":25,"materialType":"steel"}}}')
assert_no_error "tools: pipe DN50 sch40" "$RES"
assert_jq "pipe OD 60.3" "$RES" '.od' "60.3"
assert_jq "pipe wall 3.91" "$RES" '.wall' "3.91"
assert_jq "pipe weight/m 5.44 kg" "$RES" '.weightPerM' "5.44"
assert_jq "pipe total weight 32.62 kg" "$RES" '.totalWeight' "32.62"
assert_jq "pipe cost 815.62" "$RES" '.materialCost' "815.62"

RES=$(authed tools.php '{"action":"calculate","input":{"tool":"pipe","inputs":{"nominalSize":"100","schedule":"80","lengthM":12,"quantity":2,"materialRate":20,"materialType":"steel"}}}')
assert_no_error "tools: pipe DN100 sch80" "$RES"
assert_jq "DN100 sch80 wall 8.56" "$RES" '.wall' "8.56"
# wpm = π×(114.3−8.56)×8.56×7850/1e6 = 22.32; ×24m = 535.73; ×20 = 10714.55
assert_jq "DN100 sch80 weight/m 22.32" "$RES" '.weightPerM' "22.32"
assert_jq "DN100 sch80 cost" "$RES" '.materialCost' "10714.55"

RES=$(authed tools.php '{"action":"calculate","input":{"tool":"pipe","inputs":{"nominalSize":"999","schedule":"40","lengthM":6,"quantity":1,"materialRate":25,"materialType":"steel"}}}')
assert_jq "pipe unknown size rejected (400)" "$RES" '.error_code' "400"

# ── 3. Forgot → reset → login ────────────────────────
# Use a dedicated user so we don't clobber the phase runner's password.
RESET_EMAIL="reset-test@fabricate.local"
RESET_PASS="OldPass123!"
# signup (or login if exists) the reset user
RES=$(api auth.php "{\"action\":\"signup\",\"input\":{\"email\":\"$RESET_EMAIL\",\"pass\":\"$RESET_PASS\"}}")
if jq -e '.error' <<<"$RES" >/dev/null 2>&1 && ! echo "$RES" | grep -qi "already"; then
  die "signup failed: $RES"
fi
say "reset user ready"

RES=$(api auth.php "{\"action\":\"forgot_password\",\"input\":{\"email\":\"$RESET_EMAIL\"}}")
assert_no_error "forgot_password" "$RES"
RESET_TOKEN=$(jq -r '.data.token // empty' <<<"$RES")
assert "got reset token" "$( [[ -n "$RESET_TOKEN" ]] && echo yes || echo no )" "yes"

# unknown email → still 'sent' (no enumeration)
RES=$(api auth.php '{"action":"forgot_password","input":{"email":"nobody@nowhere.invalid"}}')
assert_no_error "forgot_password unknown email (no enumeration)" "$RES"
assert_jq "unknown email still sent:true" "$RES" '.data.sent' "true"

# reset with a bad token → error
RES=$(api auth.php '{"action":"reset_password","input":{"token":"badtoken","pass":"NewPass123!"}}')
assert_jq "bad token rejected" "$RES" '.error_code' "400"

# reset with the real token → success
RES=$(api auth.php "{\"action\":\"reset_password\",\"input\":{\"token\":\"$RESET_TOKEN\",\"pass\":\"NewPass123!\"}}")
assert_no_error "reset_password" "$RES"
assert_jq "reset success" "$RES" '.data.success' "true"

# token is single-use → second reset attempt fails
RES=$(api auth.php "{\"action\":\"reset_password\",\"input\":{\"token\":\"$RESET_TOKEN\",\"pass\":\"AnotherPass!\"}}")
assert_jq "token single-use enforced" "$RES" '.error_code' "400"

# old password no longer works; new password logs in
RES=$(api auth.php "{\"action\":\"login\",\"input\":{\"email\":\"$RESET_EMAIL\",\"pass\":\"$RESET_PASS\"}}")
assert_jq "old password rejected" "$RES" '.error' "Invalid email or password"

RES=$(api auth.php "{\"action\":\"login\",\"input\":{\"email\":\"$RESET_EMAIL\",\"pass\":\"NewPass123!\"}}")
assert_no_error "new password logs in" "$RES"
assert_jq "new password auth_id" "$RES" '.data.auth_id != ""' "true"
