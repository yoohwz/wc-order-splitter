# P2 Split strategy confirmation foundation

## Classification

`P2_SPLIT_STRATEGY_CONFIRMATION_FOUNDATION`

This milestone binds future Category/Stock-status Split confirmation semantics to the existing hardened Split journal while keeping both production strategy gates hard-off.

## Production state

The approved mutation gates remain unchanged:

- `SPLIT = true`
- `DUPLICATE = true`
- `MERGE = false`
- `RETURN_ORDER = false`
- `BULK_RETURN = false`

Split strategy gates remain:

- `manual_quantity = true`
- `category = false`
- `stock_status = false`

No Category/Stock-status controller, AJAX action, launcher, UI, option, filter, or production strategy route is introduced here.

## Authority lifecycle

Before the first mutation write:

`planner Review -> source bucket choice -> short-lived strategy confirmation -> verify`

The temporary confirmation binds:

- source order ID;
- confirming user ID;
- semantic strategy (`category` or `stock_status`);
- planner policy version;
- reviewed source signature;
- classification fingerprint;
- selected source bucket;
- canonical explicit quantity plan;
- `ALLOW_WHOLE_LINE_TRANSFER` execution policy;
- price precision;
- current hardened Split preflight policy version.

After the Split journal starts, the journal becomes the single durable replay authority. No second persistent strategy-operation store is introduced.

## Durable strategy authority

The confirmed semantic fields are normalized into `strategy_authority`:

- `strategy`;
- `planner_policy_version`;
- `review_source_signature`;
- `classification_fingerprint`;
- `source_bucket_key`.

`strategy_authority` participates in the Split mutation fingerprint and is persisted in the existing `WCOS_Operation_Journal` context. Journal mutation treats it as immutable alongside `execution_policy` and `fully_moved_item_ids`.

This prevents one operation ID from being replayed with a different strategy identity, classification review, or source bucket even when the explicit quantity plan happens to be identical.

## TOCTOU boundary

Before the first journal exists, confirmation verification reloads the source order and requires its PII-free source signature to still equal the reviewed signature.

`WCOS_Split_WooCommerce_Adapter` rechecks the verified strategy confirmation signature immediately before hardened Split preflight/mutation. This closes the verify -> mutation source-order race without making live catalog Category/Stock-status state part of Execute.

Catalog taxonomy or stock-status changes after Review/confirmation do not rewrite the frozen explicit plan. Execute never reruns a planner.

## Replay

If the temporary confirmation expires or is deleted after a journal exists, verification reconstructs authority from the existing Split journal only.

Durable replay requires:

- matching Split source/order identity;
- an open/replayable journal state;
- a complete `strategy_authority`;
- exact recorded plan;
- whole-line execution policy;
- recorded price precision;
- current compatible hardened Split policy version.

A raw transient record is not executable authority. `split_confirmed()` requires the record returned by successful verification or durable journal replay.

## Gateway boundary

`WCOS_Mutation_Gateway::split_strategy()` now delegates to `split_confirmed()` after the global Split gate, strategy gate, and centralized authorization checks.

Because Category and Stock-status strategy gates remain false, this path is still production-unreachable. A later transport milestone must verify the strategy confirmation and pass the returned authority record to the gateway; it must not pass client-supplied authority fields directly.

## Acceptance

Canonical integration acceptance must prove:

- strategy confirmation classes load without registering a production route;
- Category/Stock-status gateway calls remain blocked before journal/child creation;
- confirmation records contain no customer PII;
- user/order ownership is checked before first journal creation;
- source changes after Review/confirmation fail before journal creation;
- unverified transient records cannot execute;
- live taxonomy/status changes do not rewrite a frozen confirmed plan;
- semantic strategy authority is present in the one durable Split journal;
- `strategy_authority` cannot be overwritten by later journal checkpoints;
- transient deletion/expiry can replay from the same journal;
- replay under another strategy or source bucket fails closed;
- existing manual Split, Duplicate, recovery, stock, tax, HPOS, and legacy-storage contracts remain green.
