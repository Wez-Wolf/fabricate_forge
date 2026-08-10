#!/usr/bin/env bash
# fabricate_forge/tests/run-phase.sh
#
# Per-phase API test runner — ground truth against a LIVE database, not php -l.
#
# Usage:
#   ./tests/run-phase.sh <phase> [--verbose]
#
# Phases:
#   phase1  ECS core (entities / components / links)
#   phase2  Reference data (materials / rates / process)
#   phase3  Cost engine (cost.php)
#   phase4  Orchestration (systems.php)
#   phase5  Quote lifecycle (quotes.php)
#   phase6  Support (auth / user / admin / boms)
#   phase7  Seeded library (material_library, 102 rows)
#   phase8  Business modules (tools / orders / procurement / production / prefabs)
#   phase9  Full-port extras (tank/pipe tools + forgot/reset password)
#   phase10 Reports & analytics (cost by client / funnel / monthly / trade / margin)
#
# Each phase test:
#   1. Logs in as the dedicated test user (forge auth → auth_id)
#   2. Creates its own fixtures (scoped to that user)
#   3. Hits the real endpoint via WEB.api-shaped HTTP POST
#   4. Asserts on the JSON response (jq)
#   5. Cleans up (delete + purge script per phase)
#
# Exit 0 = all assertions passed. Exit 1 = a phase failed.

set -euo pipefail

cd "$(dirname "$0")/.."

API_BASE="${API_BASE:-http://localhost/fabricate_forge/api}"
TEST_EMAIL="${TEST_EMAIL:-api-test@fabricate.local}"
TEST_PASS="${TEST_PASS:-TestPass123!}"
PHASE="${1:?usage: ./tests/run-phase.sh <phase>}"
VERBOSE="${2:-}"
DB_NAME="${DB_NAME:-fabricate_forge}"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
pass=0; fail=0

say()  { printf "${GREEN}✓${NC} %s\n" "$1"; }
warn() { printf "${YELLOW}!${NC} %s\n" "$1"; }
die()  { printf "${RED}✗ %s${NC}\n" "$1"; exit 1; }

# ── HTTP helper: WEB.api-shaped POST (action + input merged like dispatch) ──
api() {
  local file="$1"; shift
  local payload="$1"
  curl -s -X POST "$API_BASE/$file" \
    -H 'Content-Type: application/json' \
    -H 'X-Requested-With: XMLHttpRequest' \
    -d "$payload"
}

# ── Assert helpers (jq required) ──
assert()   { # assert <desc> <expected> <actual>
  if [[ "$2" == "$3" ]]; then pass=$((pass+1)); say "$1"
  else fail=$((fail+1)); warn "$1 — expected [$2] got [$3]"; fi
}
assert_jq() { # assert_jq <desc> <json> <jq-expr> <expected>
  local got; got=$(jq -r "$3" <<<"$2" 2>/dev/null || echo '__jq_err__')
  assert "$1" "$4" "$got"
}
assert_no_error() { # assert_no_error <desc> <json> — arrays + objects w/o .error both pass
  local err; err=$(jq -r '.error // "none"' <<<"$2" 2>/dev/null || echo 'none')
  if [[ "$err" == "none" ]]; then pass=$((pass+1)); say "$1"
  else fail=$((fail+1)); warn "$1 — unexpected error: $err"; fi
}

# ── Auth: login and get auth_id ──
login() {
  local res
  res=$(api auth.php "{\"action\":\"login\",\"input\":{\"email\":\"$TEST_EMAIL\",\"pass\":\"$TEST_PASS\"}}")
  AUTH_ID=$(jq -r '.data.auth_id // .auth_id // empty' <<<"$res" 2>/dev/null || true)
  if [[ -z "$AUTH_ID" ]]; then
    die "login failed: $res"
  fi
  say "logged in as $TEST_EMAIL"
}

# ── Clean: remove test user's ECS rows + materials so phases are idempotent ──
# Direct SQL against the DB (owner-scoped, test-user only) — keeps prod API
# endpoints free of test concerns. Uses the same owner scoping as the purge
# script. Requires psql + the .env DB creds.
clean_test_data() {
  [[ "${SKIP_CLEAN:-0}" == "1" ]] && return
  local dbhost dbname dbuser dbpass
  dbhost=$(grep '^DB_HOST=' .env | cut -d= -f2); dbhost=${dbhost:-127.0.0.1}
  dbname=$(grep '^DB_NAME=' .env | cut -d= -f2)
  dbuser=$(grep '^DB_USER=' .env | cut -d= -f2)
  dbpass=$(grep '^DB_PASS=' .env | cut -d= -f2)
  if [[ -z "$dbname" || -z "$dbuser" ]]; then
    warn "clean skipped — DB_NAME/DB_USER not in .env"
    return
  fi
  export PGPASSWORD="$dbpass"
  psql -h "$dbhost" -U "$dbuser" -d "$dbname" -v ON_ERROR_STOP=0 -q <<SQL
  DELETE FROM component WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$TEST_EMAIL');
  DELETE FROM link      WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$TEST_EMAIL');
  DELETE FROM entity    WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$TEST_EMAIL');
  DELETE FROM material_library WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$TEST_EMAIL');
  DELETE FROM company_settings WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$TEST_EMAIL');
  DELETE FROM user_prefs WHERE user_id IN (SELECT id FROM "user" WHERE email = '$TEST_EMAIL');
  DELETE FROM "order"    WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$TEST_EMAIL');
  DELETE FROM purchase_order   WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$TEST_EMAIL');
  DELETE FROM supplier_quote   WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$TEST_EMAIL');
  DELETE FROM received_goods   WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$TEST_EMAIL');
  DELETE FROM production_record   WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$TEST_EMAIL');
  DELETE FROM production_variance WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$TEST_EMAIL');
  DELETE FROM prefab_template  WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$TEST_EMAIL');
  DELETE FROM prefab_instance  WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$TEST_EMAIL');
  DELETE FROM client WHERE user_id_owner IN (SELECT id FROM "user" WHERE email = '$TEST_EMAIL');
SQL
  unset PGPASSWORD
  say "cleaned test data (owner-scoped)"
}

# auth wrapper: inject auth_id INTO input — matches WEB.api's exact payload
# shape: WEB.api does data.input.auth_id = auth_id (forge _util.js). dispatch()
# reads $params = $input['input'], so auth_id must live inside input.
authed() { # authed <file> <json-object-without-auth_id>
  local file="$1"; shift
  local payload="$1"
  # payload is {action, input:{...}} → inject auth_id into .input
  # if payload lacks an input key, forge's dispatch treats the whole object as input
  if jq -e '.input' <<<"$payload" >/dev/null 2>&1; then
    api "$file" "$(jq --arg a "$AUTH_ID" '.input.auth_id = $a' <<<"$payload")"
  else
    api "$file" "$(jq --arg a "$AUTH_ID" '.auth_id = $a' <<<"$payload")"
  fi
}

PHASE_SCRIPT="tests/phases/${PHASE}.sh"
if [[ ! -f "$PHASE_SCRIPT" ]]; then
  die "no test script for phase: $PHASE (expected $PHASE_SCRIPT)"
fi

echo "── $PHASE ──────────────────────────────"

# Authenticate first — every phase needs a valid auth_id
login

# Idempotency: wipe this test user's rows before the phase runs
clean_test_data

source "$PHASE_SCRIPT"

echo ""
echo "── results ─────────────────────────────"
echo "passed: $pass   failed: $fail"
[[ $fail -eq 0 ]] && exit 0 || exit 1
