# P2 Split strategy adapter foundation

## Classification

`P2_SPLIT_STRATEGY_ADAPTER_FOUNDATION`

This milestone connects already-reviewed server-built strategy plans to the single hardened Split adapter/service without enabling a Category or Stock-status production surface.

## Production boundary

The approved strategy gate state remains:

- `manual_quantity = true`
- `category = false`
- `stock_status = false`

No Category/Stock-status controller, AJAX action, launcher, option/filter, confirmation endpoint, or UI is introduced here.

`WCOS_Mutation_Gateway::split_strategy()` exists as the future mandatory production mutation boundary, but it rejects both server-built strategies while their code-only gates remain hard-off.

## Execution architecture

The execution path is:

`frozen planner evidence -> explicit whole-line quantity plan -> WCOS_Split_Strategy_WooCommerce_Adapter -> WCOS_Split_WooCommerce_Adapter -> WCOS_Split_Order_Service`

There is still one Split mutation service and one WooCommerce side-effect boundary.

`WCOS_Split_WooCommerce_Adapter::split()` keeps `partial_lines_only` as its default argument, so the existing manual quantity Split controller/gateway has unchanged semantics. The dedicated strategy adapter is the only new caller that supplies `ALLOW_WHOLE_LINE_TRANSFER`.

## Strategy-plan invariants

The strategy adapter accepts only `category` and `stock_status`.

Before a new operation starts it requires:

- every plan allocation to reference a source line;
- every assigned source line to be transferred completely;
- every assigned source line to belong to exactly one child bucket;
- at least one product line to remain on source, inherited from the whole-line plan contract.

A partial allocation or a source line spread across multiple strategy child buckets is rejected before a mutation journal is created.

## Frozen classification

Execute never calls the Category or Stock-status planner. Planner Review/build-plan is separate from mutation execution.

Changing taxonomy assignments or product stock status after Review does not change the explicit frozen plan supplied to the strategy adapter. This prevents live catalog reclassification during Execute.

## Retry and recovery

A whole-line mutation changes the source order by deleting fully moved items, so a retry cannot revalidate the original plan against current source quantities.

For a new operation, strategy-plan semantics are proven against the current source before journal creation.

Once a durable Split journal exists, retry/recovery validates against journal authority instead:

- recorded execution policy must be `ALLOW_WHOLE_LINE_TRANSFER`;
- requested canonical plan must exactly match the recorded plan;
- every recorded assigned item must appear exactly once;
- recorded assigned item IDs must exactly match `fully_moved_item_ids`.

The hardened Split service remains responsible for fingerprint/idempotency/recovery state transitions.

## Deliberately deferred transport authority

This foundation does **not** yet durably bind the semantic strategy identity (`category` versus `stock_status`) to confirmation/journal authority. That binding is not needed while both production strategy gates are hard-off, but it is mandatory before either strategy can be enabled.

The next confirmation/transport milestone must bind, at minimum:

- strategy identity;
- planner policy version;
- source signature;
- classification fingerprint;
- selected source bucket;
- canonical explicit plan;
- `ALLOW_WHOLE_LINE_TRANSFER` execution policy;
- confirmed price precision;
- current Split preflight policy version;
- operation ID / user / token / expiry or durable replay authority.

Execute must consume that frozen confirmation and must not re-run live classification.

## Acceptance

Canonical integration acceptance must prove across legacy, HPOS-only, and HPOS compatibility/sync storage:

- Category/Stock-status production strategy gates remain hard-off;
- gateway mutation attempts are rejected before journal/child creation;
- direct internal Category adapter execution consumes a frozen plan after taxonomy changes;
- direct internal Stock-status execution consumes a frozen plan after live stock-status changes;
- quantity/money/tax/_reduced_stock conservation remains intact;
- physical stock does not change during Split execution;
- durable operation context records whole-line policy and the exact fully moved source-item set;
- exact retry returns the same child set;
- partial and multi-child-per-line plans fail before journal creation;
- manual quantity Split continues to use `PARTIAL_LINES_ONLY` by default.
