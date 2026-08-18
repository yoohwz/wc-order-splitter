# Order Mutation v2 Recovery Design Review

## Status

The mutation-v2 classes on this branch are development-only and register no runtime entry point. `WC_ORDER_SPLITTER_MUTATIONS_ENABLED` remains `false`.

## Recovery authority

A safe interrupted-operation recovery must satisfy all of the following before it can be wired to an administrator action:

1. Acquire the exact per-order execution lease.
2. Read a non-committed operation record and durable recovery context.
3. Verify the journal fingerprint against the stored execution specification.
4. Accept the source only when it matches either:
   - the immutable original snapshot; or
   - the exact planned mutated source specification.
5. Accept a target only when it matches the exact child specification or a verified stock-neutral quarantine state.
6. Restore and semantically verify the source before destructive target cleanup.
7. Neutralize child stock ownership before deleting the child.
8. Retain the recovery context whenever source restoration, quarantine, relation cleanup, or target deletion cannot be verified.
9. Never reverse a committed operation; committed reversal is a separate business workflow.

## Current development implementations

- `WCOS_V2_Snapshot_Comparator`
- `WCOS_V2_Specification_Comparator`
- `WCOS_V2_Child_Quarantine`
- `WCOS_V2_Child_Stock_Ownership`
- `WCOS_V2_Interrupted_Operation_Recovery`

These implementations are not release authority until exception injection and real WooCommerce integration tests prove every phase boundary.

## Required failure matrix before enablement

- failure before child persistence;
- failure after child persistence but before target journaling;
- failure after target journaling but before relation staging;
- failure after relation staging but before source write;
- exception during source item save;
- exception after source persistence but before recovery phase advancement;
- postcondition verification failure;
- relation commit failure;
- journal commit failure;
- recovery-context cleanup failure after commit;
- exception during child stock quarantine;
- relation unlink failure after quarantine;
- target delete failure after quarantine;
- retry of every incomplete state;
- stale lease cleanup race;
- identical committed retry;
- same operation ID with a different request payload;
- source edited by an administrator after interruption;
- child status, totals, stock state, or metadata changed after interruption.

## Release split

The emergency 1.4.12 hotfix must remain isolated from this development branch. Its distribution ZIP should contain only the fail-closed production plugin, not mutation-v2 prototypes or test infrastructure.
