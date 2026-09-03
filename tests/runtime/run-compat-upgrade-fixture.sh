#!/usr/bin/env bash

set -euo pipefail

storage=${1:-}
case "$storage" in legacy|hpos|hpos-sync) ;; *) echo "compat-upgrade-runner-error: invalid storage $storage" >&2; exit 1 ;; esac

repo_root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)
baseline_sha=e1d8aeb8eff38f4ce69dad1a08993e17521c6359
baseline_tree=75140a414cd637d134f860d8a70e7f92cbe4853c
candidate_sha=$(git -C "$repo_root" rev-parse HEAD)
baseline_stage="$repo_root/tests/integration/.wcos-compat-007-baseline-stage"
candidate_stage="$repo_root/tests/integration/.wcos-compat-007-candidate-stage"
target_plugin=wcos-legacy-1-4-11
cleanup_file=wp-content/plugins/wc-order-splitter/tests/integration/compat-upgrade-fixture-cleanup.php
ledger_file=wp-content/plugins/wc-order-splitter/tests/integration/compat-upgrade-fixture-ledger.php

remove_target_plugin() {
	if npx wp-env run cli wp plugin is-installed "$target_plugin" >/dev/null 2>&1; then
		if npx wp-env run cli wp plugin is-active "$target_plugin" >/dev/null 2>&1; then
			npx wp-env run cli wp plugin deactivate "$target_plugin" >/dev/null
		fi
	fi
	npx wp-env run cli wp eval-file wp-content/plugins/wc-order-splitter/tests/integration/compat-upgrade-copy-stage.php cleanup-target
}

cleanup_runtime() {
	local cleanup_status=0
	remove_target_plugin || cleanup_status=1
	npx wp-env run cli wp eval-file "$cleanup_file" || cleanup_status=1
	npx wp-env run cli wp eval-file "$ledger_file" assert-clean || cleanup_status=1
	npx wp-env run cli wp eval-file wp-content/plugins/wc-order-splitter/tests/integration/compat-upgrade-copy-stage.php assert-target-absent || cleanup_status=1
	if npx wp-env run cli wp plugin is-installed "$target_plugin" >/dev/null 2>&1; then cleanup_status=1; fi
	if [[ "$cleanup_status" -ne 0 ]]; then
		echo 'compat-upgrade-runner-error: fail-safe cleanup did not complete' >&2
		return 1
	fi
}

cleanup_stages() {
	local cleanup_status=0
	if [[ -d "$baseline_stage" && "$baseline_stage" == "$repo_root/tests/integration/.wcos-compat-007-baseline-stage" ]]; then rm -rf -- "$baseline_stage" || cleanup_status=1; fi
	if [[ -d "$candidate_stage" && "$candidate_stage" == "$repo_root/tests/integration/.wcos-compat-007-candidate-stage" ]]; then rm -rf -- "$candidate_stage" || cleanup_status=1; fi
	return "$cleanup_status"
}

on_exit() {
	local run_status=$?
	local cleanup_status=0
	trap - EXIT
	set +e
	cleanup_runtime || cleanup_status=1
	cleanup_stages || cleanup_status=1
	if [[ "$cleanup_status" -ne 0 ]]; then exit 1; fi
	exit "$run_status"
}
trap on_exit EXIT

test "$(git -C "$repo_root" rev-parse "$baseline_sha")" = "$baseline_sha"
test "$(git -C "$repo_root" rev-parse "$baseline_sha^{tree}")" = "$baseline_tree"
test "$(git -C "$repo_root" rev-parse HEAD)" = "$candidate_sha"
npx wp-env run cli wp plugin is-active woocommerce
npx wp-env run cli wp plugin deactivate wc-order-splitter >/dev/null 2>&1 || true
cleanup_runtime

prepare_stages() {
	test ! -e "$baseline_stage"
	test ! -e "$candidate_stage"
	mkdir -p "$baseline_stage" "$candidate_stage"
	git -C "$repo_root" archive "$baseline_sha" | tar -x -C "$baseline_stage"
	git -C "$repo_root" archive "$candidate_sha" | tar -x -C "$candidate_stage"
	test "$(sed -n 's/^ \* Version: //p' "$baseline_stage/wc-order-splitter.php" | head -n 1)" = 1.4.11
	test "$(sed -n 's/^ \* Version: //p' "$candidate_stage/wc-order-splitter.php" | head -n 1)" = 1.5.0
}

reset_after_fault() {
	cleanup_runtime
	cleanup_stages
	test ! -e "$baseline_stage"
	test ! -e "$candidate_stage"
	prepare_stages
}

cleanup_stages
prepare_stages

copy_stage() {
	local source_name=$1
	local fault_point=${2:-}
	npx wp-env run cli wp eval-file wp-content/plugins/wc-order-splitter/tests/integration/compat-upgrade-copy-stage.php "$source_name" "$fault_point"
}

activate_stage() {
	local source_name=$1
	copy_stage "$source_name"
	npx wp-env run cli wp plugin activate "$target_plugin"
}

initialize_ledger() {
	npx wp-env run cli wp eval-file "$ledger_file" init
}

create_complete_baseline_fixture() {
	initialize_ledger
	npx wp-env run cli wp eval-file wp-content/plugins/wc-order-splitter/tests/integration/compat-legacy-1-4-11-create.php wos-compat-007
	npx wp-env run cli wp eval-file wp-content/plugins/wc-order-splitter/tests/integration/compat-legacy-1-4-11-seal.php
	npx wp-env run cli wp eval-file wp-content/plugins/wc-order-splitter/tests/integration/compat-upgrade-1-4-11-seed.php
}

replace_with_candidate() {
	remove_target_plugin
	activate_stage .wcos-compat-007-candidate-stage
}

# An early copy failure leaves a directory that WordPress cannot recognize as a plugin.
if copy_stage .wcos-compat-007-baseline-stage partial-target; then
	echo 'compat-upgrade-runner-error: partial-target copy fault unexpectedly succeeded' >&2
	exit 1
fi
if npx wp-env run cli wp plugin is-installed "$target_plugin" >/dev/null 2>&1; then
	echo 'compat-upgrade-runner-error: partial-target copy unexpectedly became an installed plugin' >&2
	exit 1
fi
npx wp-env run cli wp eval-file wp-content/plugins/wc-order-splitter/tests/integration/compat-upgrade-copy-stage.php assert-target-partial
reset_after_fault

# A legacy Split can terminate before its child ID is sealed; cleanup must discover the reciprocal child.
activate_stage .wcos-compat-007-baseline-stage
initialize_ledger
npx wp-env run cli wp eval-file wp-content/plugins/wc-order-splitter/tests/integration/compat-legacy-1-4-11-create.php wos-compat-007
reset_after_fault

# Every baseline setup boundary must leave no task-owned state after an injected exception.
for fault_point in early middle late; do
	activate_stage .wcos-compat-007-baseline-stage
	initialize_ledger
	if npx wp-env run cli wp eval-file wp-content/plugins/wc-order-splitter/tests/integration/compat-upgrade-1-4-11-seed.php "$fault_point"; then
		echo "compat-upgrade-runner-error: seed fault point unexpectedly succeeded: $fault_point" >&2
		exit 1
	fi
	reset_after_fault
done

# Candidate objects and hashed authorities must be durable before later failures.
for fault_point in candidate-user candidate-authority candidate-target; do
	activate_stage .wcos-compat-007-baseline-stage
	create_complete_baseline_fixture
	replace_with_candidate
	if npx wp-env run cli wp eval-file wp-content/plugins/wc-order-splitter/tests/integration/compat-upgrade-acceptance-smoke.php "$candidate_sha" "$storage" "$fault_point"; then
		echo "compat-upgrade-runner-error: candidate fault point unexpectedly succeeded: $fault_point" >&2
		exit 1
	fi
	reset_after_fault
done

activate_stage .wcos-compat-007-baseline-stage
create_complete_baseline_fixture
replace_with_candidate

npx wp-env run cli wp eval-file wp-content/plugins/wc-order-splitter/tests/integration/compat-upgrade-acceptance-smoke.php "$candidate_sha" "$storage"
cleanup_runtime

echo "compat-upgrade-runner-ok baseline=$baseline_sha candidate=$candidate_sha storage=$storage artifacts=0"
