# P2 Whole-Line Transfer Foundation

## Purpose

This milestone adds one internal capability required by future server-built Category and Stock-status Split strategies: moving an entire persisted source line to one or more child orders while continuing to use the hardened Split saga.

It does **not** add or enable a Category or Stock-status controller, endpoint, button, strategy gate, or legacy handler.

## Execution policies

`WCOS_Split_Execution_Policy` defines two internal policies:

- `partial_lines_only` — the existing manual quantity Split behavior. Every affected source line must retain a positive quantity.
- `allow_whole_line_transfer` — an explicit internal strategy policy. A fully allocated source line may be removed, but at least one product line must remain on the source order.

The default remains `partial_lines_only`. Existing production manual Split calls therefore retain their previous semantics.

## Durable operation authority

New Split journals record:

- `execution_policy`;
- `fully_moved_item_ids`.

The execution policy also participates in the mutation fingerprint. A durable operation cannot be retried under a different policy. Legacy journals that predate this field may only resume under `partial_lines_only`.

## Whole-line mutation semantics

For a fully allocated line:

1. the historical source line is allocated across child destinations using the same exact amount/tax/reduced-stock allocator as a partial Split;
2. fresh child `WC_Order_Item_Product` objects are created;
3. no source remainder is synthesized;
4. the persisted source item is staged for deletion with WooCommerce CRUD `WC_Order::remove_item()` and deleted on the later source save;
5. source + children must still conserve quantity, subtotal, discount, shipping, fees, historical tax, grand total and `_reduced_stock`;
6. the Split request itself must not write physical product stock.

Shipping and positive fees remain on the source under the existing manual Split financial policy.

## Stock lifecycle

When the source had already reduced stock, the full `_reduced_stock` marker for a moved line follows the child allocation and the child receives the source order-level stock-reduced flag.

Acceptance proves:

- Split itself does not change physical stock;
- cancelling the child restores only the fully moved product stock;
- cancelling the residual source restores only the residual source product stock;
- aggregate restoration happens exactly once.

## Crash and recovery boundary

Before the destructive source-line deletion is persisted, existing compensation/retry behavior remains available.

After a fully moved source line has been deleted from persisted source state:

- if persisted source + operation-owned children form a valid conserved commit, retry may finalize that exact persisted state;
- if the persisted state is ambiguous or fails conservation, the engine must not guess/recreate the deleted line from current catalog data;
- instead the operation enters persistent `manual_reconciliation`, automatic compensation is disabled, and the source is blocked from later mutations until a human proves and records the correct state.

This converts an irreversible/ambiguous crash window into a fail-closed operational incident rather than silent data reconstruction.

## Future strategy boundary

Future Category and Stock-status planners may only produce an explicit, reviewed quantity plan. They must never restore the old mutation handlers.

Execution must still enter the shared hardened Split path and pass `allow_whole_line_transfer` as the immutable operation policy.

No future strategy may recompute volatile classification during Execute. Classification evidence and the resulting plan must be frozen at Review/confirmation time.
