# fabricate_forge/tests/phases/phase10.sh
#
# Phase 10 — Reports & analytics (api/reports.php).
#
# Covers:
#   cost_by_client    — quote totals grouped by client
#   quote_funnel      — status counts
#   monthly_summary   — totals per calendar month
#   cost_by_trade     — process hours + cost per trade (from cost engine)
#   margin_summary    — avg margin, quote value, estimated margin
#
# NOTE: run-phase.sh logs in as $TEST_EMAIL and cleans that user's rows first.

# ── Fixtures: 2 clients + 2 quotes with process comps ──
RES=$(authed clients.php '{"action":"create","input":{"company_name":"Report Client A"}}')
assert_no_error "reports: create client A" "$RES"
CLIENT_A=$(jq -r '.id' <<<"$RES")

RES=$(authed clients.php '{"action":"create","input":{"company_name":"Report Client B"}}')
assert_no_error "reports: create client B" "$RES"
CLIENT_B=$(jq -r '.id' <<<"$RES")

# Quote 1 → Client A, approved, with a part that has 2h welding + 1h assembly
RES=$(authed entities.php '{"action":"create","input":{"type":"quote","name":"Report Quote 1","data":{"client_id":"'$CLIENT_A'","status":"approved"}}}')
assert_no_error "reports: create quote 1" "$RES"
Q1=$(jq -r '.id' <<<"$RES")

RES=$(authed entities.php '{"action":"create","input":{"type":"part","name":"Report Plate","quote_id":"'$Q1'","quantity":1}}')
assert_no_error "reports: quote 1 part" "$RES"
Q1_PART=$(jq -r '.id' <<<"$RES")

RES=$(authed components.php '{"action":"create","input":{"entity_id":"'$Q1_PART'","type":"process","data":{"welding":2,"assembly":1}}}')
assert_no_error "reports: quote 1 process comp" "$RES"

RES=$(authed components.php '{"action":"create","input":{"entity_id":"'$Q1_PART'","type":"material","data":{"mass":50,"materialLibraryId":null,"category":"general","unitCost":0}}}')
assert_no_error "reports: quote 1 material comp (mass 50kg)" "$RES"

# Quote 2 → Client B, invoiced, 4h machining
RES=$(authed entities.php '{"action":"create","input":{"type":"quote","name":"Report Quote 2","data":{"client_id":"'$CLIENT_B'","status":"invoiced"}}}')
assert_no_error "reports: create quote 2" "$RES"
Q2=$(jq -r '.id' <<<"$RES")

RES=$(authed entities.php '{"action":"create","input":{"type":"part","name":"Machined Shaft","quote_id":"'$Q2'","quantity":1}}')
Q2_PART=$(jq -r '.id' <<<"$RES")
RES=$(authed components.php '{"action":"create","input":{"entity_id":"'$Q2_PART'","type":"process","data":{"machining":4}}}')
assert_no_error "reports: quote 2 process comp" "$RES"

# Recalc both quotes (writes cost components the reports read)
RES=$(authed systems.php "{\"action\":\"recalculate_quote\",\"input\":{\"quote_id\":\"$Q1\"}}")
assert_no_error "reports: recalc quote 1" "$RES"
RES=$(authed systems.php "{\"action\":\"recalculate_quote\",\"input\":{\"quote_id\":\"$Q2\"}}")
assert_no_error "reports: recalc quote 2" "$RES"

# ── 1. cost_by_client ─────────────────────────────────
RES=$(authed reports.php '{"action":"cost_by_client","input":{}}')
assert_no_error "reports: cost by client" "$RES"
assert_jq "client A present" "$RES" '[.[] | select(.clientName=="Report Client A")] | length' "1"
assert_jq "client A quote count 1" "$RES" '.[] | select(.clientName=="Report Client A") | .count' "1"
assert_jq "client A total > 0" "$RES" '.[] | select(.clientName=="Report Client A") | .total > 0' "true"
assert_jq "client B present" "$RES" '[.[] | select(.clientName=="Report Client B")] | length' "1"
assert_jq "sorted by total desc (B before A)" "$RES" '.[0].clientName' "Report Client B"

# ── 2. quote_funnel ───────────────────────────────────
RES=$(authed reports.php '{"action":"quote_funnel","input":{}}')
assert_no_error "reports: quote funnel" "$RES"
assert_jq "funnel approved = 1" "$RES" '.approved' "1"
assert_jq "funnel invoiced = 1" "$RES" '.invoiced' "1"
assert_jq "funnel draft = 0" "$RES" '.draft' "0"

# ── 3. monthly_summary ────────────────────────────────
RES=$(authed reports.php '{"action":"monthly_summary","input":{}}')
assert_no_error "reports: monthly summary" "$RES"
assert_jq "monthly has current month" "$RES" '[.[] | select(.month == "'$(date +%Y-%m)'")] | length' "1"
assert_jq "monthly quotes this month = 2" "$RES" '.[] | select(.month == "'$(date +%Y-%m)'") | .count' "2"

# ── 4. cost_by_trade ──────────────────────────────────
RES=$(authed reports.php '{"action":"cost_by_trade","input":{}}')
assert_no_error "reports: cost by trade (all)" "$RES"
assert_jq "welding 2h" "$RES" '.trades[] | select(.name=="welding") | .hours' "2"
assert_jq "assembly 1h" "$RES" '.trades[] | select(.name=="assembly") | .hours' "1"
assert_jq "machining 4h" "$RES" '.trades[] | select(.name=="machining") | .hours' "4"
# welding priced at global rate 90 → 2×90 = 180; assembly 65 → 65; machining 95 → 380
assert_jq "welding cost 180" "$RES" '.trades[] | select(.name=="welding") | .cost' "180"
assert_jq "machining cost 380" "$RES" '.trades[] | select(.name=="machining") | .cost' "380"
assert_jq "total = 625" "$RES" '.total' "625"

# quote-scoped variant
RES=$(authed reports.php "{\"action\":\"cost_by_trade\",\"input\":{\"quote_id\":\"$Q1\"}}")
assert_no_error "reports: cost by trade (quote 1)" "$RES"
assert_jq "quote 1 has welding" "$RES" '.trades[] | select(.name=="welding") | .hours' "2"
assert_jq "quote 1 has no machining" "$RES" '[.trades[] | select(.name=="machining")] | length' "0"

# ── 5. margin_summary ─────────────────────────────────
RES=$(authed reports.php '{"action":"margin_summary","input":{}}')
assert_no_error "reports: margin summary" "$RES"
assert_jq "quote count 2" "$RES" '.quoteCount' "2"
assert_jq "default margin 30%" "$RES" '.avgMarginPercent' "30"
assert_jq "total quote value > 0" "$RES" '.totalQuoteValue > 0' "true"
# margin = value × 30/130 per quote; effective rate = margin/value × 100 = 23.08
assert_jq "effective margin rate 23.1%" "$RES" '.effectiveMarginRate' "23.1"
assert_jq "estimated margin > 0" "$RES" '.totalEstimatedMargin > 0' "true"
