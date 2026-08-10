# quotes.add_items — batch line-item creation + single recalc
RES=$(authed quotes.php '{"action":"create","input":{"name":"Batch Quote","margin_percent":20}}')
assert_no_error "create batch quote" "$RES"
BQ_ID=$(jq -r '.id' <<<"$RES")

# 3 items in one call (one part w/ quantity, one assembly, one with description)
RES=$(authed quotes.php "{\"action\":\"add_items\",\"input\":{\"quote_id\":\"$BQ_ID\",\"items\":[
  {\"name\":\"Base Plate\",\"type\":\"part\",\"quantity\":2,\"description\":\"500x500x10\"},
  {\"name\":\"Skid Frame\",\"type\":\"assembly\",\"quantity\":1},
  {\"name\":\"M20 Bolt\",\"type\":\"fastener\",\"quantity\":16}
]}}")
assert_no_error "batch add 3 items" "$RES"
assert_jq "items_created = 3" "$RES" '.items_created' "3"
assert_jq "quote has 3 entities" "$RES" '.entities | length' "3"
assert_jq "quote total = 0 (no components)" "$RES" '.total_cost' "0"
assert_jq "totals returned" "$RES" '.totals.material' "0"

# types + quantity landed correctly
assert_jq "entity[0] is part" "$RES" '.entities[0].type' "part"
assert_jq "entity[0] qty 2" "$RES" '.entities[0].quantity' "2"
assert_jq "entity[1] is assembly" "$RES" '.entities[1].type' "assembly"
assert_jq "entity[2] is fastener" "$RES" '.entities[2].type' "fastener"

# invalid type falls back to part; blank names skipped
RES=$(authed quotes.php "{\"action\":\"add_items\",\"input\":{\"quote_id\":\"$BQ_ID\",\"items\":[
  {\"name\":\"Widget\",\"type\":\"gadget\"},
  {\"name\":\"   \"},
  {\"name\":\"Gasket\",\"quantity\":4}
]}}")
assert_no_error "batch add mixed validity" "$RES"
assert_jq "2 more created" "$RES" '.items_created' "2"
assert_jq "now 5 entities" "$RES" '.entities | length' "5"
assert_jq "bad type fell back to part" "$RES" '.entities[3].type' "part"
assert_jq "qty 4 landed" "$RES" '.entities[4].quantity' "4"

# validation: no items → error; unknown quote → 404
RES=$(authed quotes.php "{\"action\":\"add_items\",\"input\":{\"quote_id\":\"$BQ_ID\",\"items\":[]}}")
assert_jq "empty items rejected" "$RES" '.error != null' "true"
RES=$(authed quotes.php '{"action":"add_items","input":{"quote_id":"00000000-0000-0000-0000-000000000000","items":[{"name":"X"}]}}')
assert_jq "unknown quote rejected" "$RES" '.error_code' "404"
