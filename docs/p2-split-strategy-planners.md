# P2 Split strategy planner foundation

## Classification

`P2_SPLIT_STRATEGY_PLANNERS`

This foundation adds deterministic, read-only Review planners for future Category and Stock-status Split workflows. It does **not** add a production controller, AJAX action, UI launcher, option, filter, or executable mutation route for either strategy.

## Strategy gates

The strategy map is code-only:

- `manual_quantity = true`
- `category = false`
- `stock_status = false`

`WCOS_Feature_Gates::SPLIT` remains the global hardened Split workflow gate. `WCOS_Split_Strategy_Gates` is a separate authority boundary for future strategy surfaces.

## Required lifecycle

Future strategy execution must preserve this boundary:

`Review planner -> frozen evidence -> explicit quantity plan -> confirmation -> hardened adapter/gateway/service`

Review may read current catalog classification. Execute must never recompute Category or Stock-status classification from live catalog state. Confirmation must bind the reviewed source signature, classification fingerprint, execution policy, chosen source bucket, and explicit plan before a mutation is allowed.

## Whole-line dependency

Category and Stock-status planners produce full-line allocation plans. Their reports therefore carry `WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER` as explicit execution authority.

The guarded whole-line runtime foundation is already merged, but production strategy transport remains unavailable until a separate milestone hardens confirmation, request-local stock guarding, recovery behavior, authorization, and UI/transport wiring.

## Category policy

Category Review uses stable taxonomy term IDs as classification authority.

- names and slugs are display metadata only and do not participate in the classification fingerprint;
- if both an assigned ancestor and descendant are present, the deepest assigned leaf is the bucket authority;
- multiple unrelated assigned leaf categories are ambiguous and fail closed;
- an item with no category is placed in the explicit `category-uncategorized` bucket;
- a deleted catalog product fails closed because current category classification cannot be proven;
- at least two deterministic buckets are required;
- the operator must later choose one reviewed bucket to remain on the source order;
- `build_plan()` consumes only frozen Review evidence and never queries taxonomy.

## Stock-status policy

Stock-status Review accepts only WooCommerce `instock`, `outofstock`, and `onbackorder`.

For each historical order item it freezes:

- source order-item ID and full historical quantity;
- parent product ID;
- variation ID;
- catalog object ID used for status classification;
- effective stock-owner ID;
- reviewed stock status.

A catalog status change after Review does not rewrite the frozen plan. A new Review observes the new catalog state. A deleted catalog product fails closed.

## Review authority

Both planners require a supported Review with:

- matching planner policy version;
- `ALLOW_WHOLE_LINE_TRANSFER` execution policy;
- non-empty source signature;
- non-empty classification fingerprint.

`build_plan()` rejects downgraded or incomplete Review authority. This is structural validation only; future production confirmation must provide durable anti-tamper binding before Execute is enabled.

## Acceptance boundary

Canonical integration acceptance must prove:

- planner classes are loaded by the plugin bootstrap;
- Category and Stock-status strategy gates remain hard-off;
- Review reports contain no billing PII;
- Category ancestor/descendant collapse is deterministic;
- taxonomy display metadata does not change stable category authority;
- unrelated category leaves fail closed;
- uncategorized and deleted-product cases are explicit;
- Stock-status plans remain frozen after live catalog changes;
- variation identity and parent-managed stock-owner evidence are preserved;
- Review/build-plan do not mutate the source order.

No production strategy may be enabled by this planner-only milestone.
