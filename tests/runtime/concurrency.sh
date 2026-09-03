#!/usr/bin/env bash
# Product assertions preserved from the accepted pre-reset CI workflow.
set -euo pipefail

# Resolve wp-env CLI container for concurrent workers
mapfile -t cli_containers < <(
  docker ps \
    --filter 'status=running' \
    --filter 'label=com.docker.compose.service=cli' \
    --format '{{.ID}}'
)
test "${#cli_containers[@]}" -eq 1
cli_container="${cli_containers[0]}"
docker inspect --format '{{.State.Status}}' "$cli_container" | grep -Fxq 'running'
docker exec --workdir /var/www/html "$cli_container" wp cli info
export WCOS_WP_ENV_CLI_CONTAINER="$cli_container"


# Verify real concurrent worker lease exclusion
raw="$(npx wp-env run cli wp eval '$o = wc_create_order(); $o->set_status("pending"); $o->save(); echo "WCOS_ORDER_ID=" . $o->get_id() . "\n";')"
order_id="$(printf '%s\n' "$raw" | sed -n 's/.*WCOS_ORDER_ID=\([0-9][0-9]*\).*/\1/p' | tail -n 1)"
test -n "$order_id"
worker='wp-content/plugins/wc-order-splitter/tests/integration/concurrency-lock-worker.php'

docker exec --workdir /var/www/html "$WCOS_WP_ENV_CLI_CONTAINER" wp eval-file "$worker" "$order_id" worker-a 4 > /tmp/wcos-worker-a.log 2>&1 &
worker_a_pid=$!
sleep 1
docker exec --workdir /var/www/html "$WCOS_WP_ENV_CLI_CONTAINER" wp eval-file "$worker" "$order_id" worker-b 0 > /tmp/wcos-worker-b.log 2>&1 &
worker_b_pid=$!

set +e
wait "$worker_a_pid"
worker_a_status=$?
wait "$worker_b_pid"
worker_b_status=$?
set -e

printf 'worker-a-status=%s\nworker-b-status=%s\n' "$worker_a_status" "$worker_b_status"
cat /tmp/wcos-worker-a.log
cat /tmp/wcos-worker-b.log
test "$worker_a_status" -eq 0
test "$worker_b_status" -eq 0
grep -Fq 'ACQUIRED worker-a' /tmp/wcos-worker-a.log
grep -Fq 'RELEASED worker-a' /tmp/wcos-worker-a.log
grep -Fq 'BLOCKED worker-b' /tmp/wcos-worker-b.log

docker exec --workdir /var/www/html "$WCOS_WP_ENV_CLI_CONTAINER" wp eval-file "$worker" "$order_id" worker-c 0 > /tmp/wcos-worker-c.log 2>&1
cat /tmp/wcos-worker-c.log
grep -Fq 'ACQUIRED worker-c' /tmp/wcos-worker-c.log
grep -Fq 'RELEASED worker-c' /tmp/wcos-worker-c.log

npx wp-env run cli wp eval '$o = wc_get_order('"$order_id"'); if ($o) { $o->delete(true); }'


# Verify real concurrent strategy Confirm single-success authority
fixture='wp-content/plugins/wc-order-splitter/tests/integration/strategy-confirm-race-fixture.php'
worker='wp-content/plugins/wc-order-splitter/tests/integration/strategy-confirm-race-worker.php'
cleanup='wp-content/plugins/wc-order-splitter/tests/integration/strategy-confirm-race-cleanup.php'

cleanup_race() {
  npx wp-env run cli wp eval-file "$cleanup" || true
}
trap cleanup_race EXIT

npx wp-env run cli wp eval-file "$fixture"
docker exec --workdir /var/www/html "$WCOS_WP_ENV_CLI_CONTAINER" wp eval-file "$worker" worker-a > /tmp/wcos-confirm-worker-a.log 2>&1 &
worker_a_pid=$!
docker exec --workdir /var/www/html "$WCOS_WP_ENV_CLI_CONTAINER" wp eval-file "$worker" worker-b > /tmp/wcos-confirm-worker-b.log 2>&1 &
worker_b_pid=$!

set +e
wait "$worker_a_pid"
worker_a_status=$?
wait "$worker_b_pid"
worker_b_status=$?
set -e

printf 'worker-a-status=%s\nworker-b-status=%s\n' "$worker_a_status" "$worker_b_status"
cat /tmp/wcos-confirm-worker-a.log
cat /tmp/wcos-confirm-worker-b.log
test "$worker_a_status" -eq 0
test "$worker_b_status" -eq 0

success_count="$(awk '/^CONFIRMED / { count++ } END { print count + 0 }' /tmp/wcos-confirm-worker-a.log /tmp/wcos-confirm-worker-b.log)"
rejected_count="$(awk '/^REJECTED / { count++ } END { print count + 0 }' /tmp/wcos-confirm-worker-a.log /tmp/wcos-confirm-worker-b.log)"
test "$success_count" -eq 1
test "$rejected_count" -eq 1

confirmed_line="$(awk '/^CONFIRMED / { print; exit }' /tmp/wcos-confirm-worker-a.log /tmp/wcos-confirm-worker-b.log)"
operation_id="$(printf '%s\n' "$confirmed_line" | awk '{ print $3 }')"
confirmation_token="$(printf '%s\n' "$confirmed_line" | awk '{ print $4 }')"
test -n "$operation_id"
test -n "$confirmation_token"

npx wp-env run cli wp eval '
  $fixture = get_option("wcos_strategy_confirm_race_fixture", array());
  $order = isset($fixture["order_id"]) ? wc_get_order(absint($fixture["order_id"])) : false;
  if (!$order instanceof WC_Order) { exit(1); }
  if (WCOS_Operation_Journal::get($order, "'"$operation_id"'")) { exit(1); }
  $record = WCOS_Split_Strategy_Confirmation_Store::verify(
    $order,
    "'"$operation_id"'",
    "'"$confirmation_token"'",
    absint($fixture["user_id"])
  );
  if (!is_array($record) || "confirmation" !== $record["replay_authority"]) { exit(1); }
  if (WCOS_Split_Strategy_Gates::CATEGORY !== $record["strategy"]) { exit(1); }
  WCOS_Split_Strategy_Confirmation_Store::delete("'"$operation_id"'");
  echo "strategy-confirm-race-authority-ok\n";
'

cleanup_race
trap - EXIT


# Verify real concurrent Return Confirm single-consumption authority
fixture='wp-content/plugins/wc-order-splitter/tests/integration/return-confirm-race-fixture.php'
worker='wp-content/plugins/wc-order-splitter/tests/integration/return-confirm-race-worker.php'
cleanup='wp-content/plugins/wc-order-splitter/tests/integration/return-confirm-race-cleanup.php'

cleanup_race() {
  npx wp-env run cli wp eval-file "$cleanup" || true
}
trap cleanup_race EXIT

npx wp-env run cli wp eval-file "$fixture"
docker exec --workdir /var/www/html "$WCOS_WP_ENV_CLI_CONTAINER" wp eval-file "$worker" worker-a > /tmp/wcos-return-confirm-worker-a.log 2>&1 &
worker_a_pid=$!
docker exec --workdir /var/www/html "$WCOS_WP_ENV_CLI_CONTAINER" wp eval-file "$worker" worker-b > /tmp/wcos-return-confirm-worker-b.log 2>&1 &
worker_b_pid=$!

set +e
wait "$worker_a_pid"
worker_a_status=$?
wait "$worker_b_pid"
worker_b_status=$?
set -e

printf 'worker-a-status=%s\nworker-b-status=%s\n' "$worker_a_status" "$worker_b_status"
cat /tmp/wcos-return-confirm-worker-a.log
cat /tmp/wcos-return-confirm-worker-b.log
test "$worker_a_status" -eq 0
test "$worker_b_status" -eq 0
success_count="$(awk '/^CONFIRMED / { count++ } END { print count + 0 }' /tmp/wcos-return-confirm-worker-a.log /tmp/wcos-return-confirm-worker-b.log)"
rejected_count="$(awk '/^REJECTED / { count++ } END { print count + 0 }' /tmp/wcos-return-confirm-worker-a.log /tmp/wcos-return-confirm-worker-b.log)"
test "$success_count" -eq 1
test "$rejected_count" -eq 1

confirmed_line="$(awk '/^CONFIRMED / { print; exit }' /tmp/wcos-return-confirm-worker-a.log /tmp/wcos-return-confirm-worker-b.log)"
operation_id="$(printf '%s\n' "$confirmed_line" | awk '{ print $3 }')"
confirmation_token="$(printf '%s\n' "$confirmed_line" | awk '{ print $4 }')"
test -n "$operation_id"
test -n "$confirmation_token"

npx wp-env run cli wp eval '
  $fixture = get_option("wcos_return_confirm_race_fixture", array());
  wp_set_current_user(absint($fixture["user_id"]));
  $child = isset($fixture["child_id"]) ? wc_get_order(absint($fixture["child_id"])) : false;
  if (!$child instanceof WC_Order) { exit(1); }
  if (WCOS_Operation_Journal::get($child, "'"$operation_id"'")) { exit(1); }
  $record = WCOS_Return_Confirmation_Store::verify($child, "'"$operation_id"'", "'"$confirmation_token"'", absint($fixture["user_id"]));
  if (!is_array($record) || "confirmation" !== $record["replay_authority"]) { exit(1); }
  WCOS_Return_Confirmation_Store::delete("'"$operation_id"'");
  echo "return-confirm-race-authority-ok\n";
'

cleanup_race
trap - EXIT


# Verify real concurrent Bulk Return Confirm single-consumption authority
fixture='wp-content/plugins/wc-order-splitter/tests/integration/bulk-return-confirm-race-fixture.php'
worker='wp-content/plugins/wc-order-splitter/tests/integration/bulk-return-confirm-race-worker.php'
cleanup='wp-content/plugins/wc-order-splitter/tests/integration/bulk-return-confirm-race-cleanup.php'

cleanup_bulk_race() {
  npx wp-env run cli wp eval-file "$cleanup" || true
}
trap cleanup_bulk_race EXIT

npx wp-env run cli wp eval-file "$fixture"
docker exec --workdir /var/www/html "$WCOS_WP_ENV_CLI_CONTAINER" wp eval-file "$worker" worker-a > /tmp/wcos-bulk-confirm-worker-a.log 2>&1 &
worker_a_pid=$!
docker exec --workdir /var/www/html "$WCOS_WP_ENV_CLI_CONTAINER" wp eval-file "$worker" worker-b > /tmp/wcos-bulk-confirm-worker-b.log 2>&1 &
worker_b_pid=$!

set +e
wait "$worker_a_pid"
worker_a_status=$?
wait "$worker_b_pid"
worker_b_status=$?
set -e

printf 'worker-a-status=%s\nworker-b-status=%s\n' "$worker_a_status" "$worker_b_status"
for worker_log in /tmp/wcos-bulk-confirm-worker-a.log /tmp/wcos-bulk-confirm-worker-b.log; do
  printf '%s\n' "--- $(basename "$worker_log") (sanitized) ---"
  sed -E \
    -e 's/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/[REDACTED_EMAIL]/g' \
    -e 's/[A-Za-z0-9]{40,}/[REDACTED_SECRET]/g' \
    "$worker_log"
done

test "$worker_a_status" -eq 0
test "$worker_b_status" -eq 0

grep -E '^(CONFIRMED|REJECTED) ' /tmp/wcos-bulk-confirm-worker-a.log /tmp/wcos-bulk-confirm-worker-b.log | sed -E 's#^/tmp/[^:]+:##'
success_count="$(awk '/^CONFIRMED / { count++ } END { print count + 0 }' /tmp/wcos-bulk-confirm-worker-a.log /tmp/wcos-bulk-confirm-worker-b.log)"
rejected_count="$(awk '/^REJECTED / { count++ } END { print count + 0 }' /tmp/wcos-bulk-confirm-worker-a.log /tmp/wcos-bulk-confirm-worker-b.log)"
test "$success_count" -eq 1
test "$rejected_count" -eq 1

confirmed_line="$(awk '/^CONFIRMED / { print; exit }' /tmp/wcos-bulk-confirm-worker-a.log /tmp/wcos-bulk-confirm-worker-b.log)"
batch_id="$(printf '%s\n' "$confirmed_line" | awk '{ print $3 }')"
anchor_id="$(printf '%s\n' "$confirmed_line" | awk '{ print $4 }')"
npx wp-env run cli wp eval '
  $fixture = get_option("wcos_bulk_return_confirm_race_fixture", array());
  wp_set_current_user(absint($fixture["user_id"]));
  $anchor = wc_get_order(absint("'"$anchor_id"'"));
  $record = $anchor instanceof WC_Order ? WCOS_Operation_Journal::get($anchor, "'"$batch_id"'") : null;
  $verified = is_array($record) ? WCOS_Bulk_Return_Journal_Context::assert_record($record) : null;
  if (!is_array($verified) || 1 !== count($verified["authority"]["operation_map"])) { exit(1); }
  echo "bulk-confirm-race-authority-ok\n";
'

cleanup_bulk_race
trap - EXIT


# Verify real concurrent overlapping Bulk Return current-row authority
fixture='wp-content/plugins/wc-order-splitter/tests/integration/bulk-return-execute-race-fixture.php'
worker='wp-content/plugins/wc-order-splitter/tests/integration/bulk-return-execute-race-worker.php'
postcheck='wp-content/plugins/wc-order-splitter/tests/integration/bulk-return-execute-race-postcheck.php'
cleanup='wp-content/plugins/wc-order-splitter/tests/integration/bulk-return-execute-race-cleanup.php'

cleanup_bulk_execute_race() {
  npx wp-env run cli wp eval-file "$cleanup" || true
}
trap cleanup_bulk_execute_race EXIT

npx wp-env run cli wp eval-file "$fixture"
docker exec --workdir /var/www/html "$WCOS_WP_ENV_CLI_CONTAINER" wp eval-file "$worker" worker-a 0 > /tmp/wcos-bulk-execute-worker-a.log 2>&1 &
worker_a_pid=$!
docker exec --workdir /var/www/html "$WCOS_WP_ENV_CLI_CONTAINER" wp eval-file "$worker" worker-b 1 > /tmp/wcos-bulk-execute-worker-b.log 2>&1 &
worker_b_pid=$!

set +e
wait "$worker_a_pid"
worker_a_status=$?
wait "$worker_b_pid"
worker_b_status=$?
set -e

printf 'worker-a-status=%s\nworker-b-status=%s\n' "$worker_a_status" "$worker_b_status"
for worker_log in /tmp/wcos-bulk-execute-worker-a.log /tmp/wcos-bulk-execute-worker-b.log; do
  printf '%s\n' "--- $(basename "$worker_log") (sanitized) ---"
  sed -E \
    -e 's/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/[REDACTED_EMAIL]/g' \
    -e 's/[A-Za-z0-9]{40,}/[REDACTED_SECRET]/g' \
    "$worker_log"
done

test "$worker_a_status" -eq 0
test "$worker_b_status" -eq 0

grep -E '^(RESULT|RETRY) ' /tmp/wcos-bulk-execute-worker-a.log /tmp/wcos-bulk-execute-worker-b.log | sed -E 's#^/tmp/[^:]+:##'
npx wp-env run cli wp eval-file "$postcheck"

cleanup_bulk_execute_race
trap - EXIT
