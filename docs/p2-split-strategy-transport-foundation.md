# P2 Split strategy transport foundation

## Classification

`P2_SPLIT_STRATEGY_TRANSPORT_FOUNDATION`

This milestone establishes the server-side Review -> Confirm -> Execute transport contract for future Category and Stock-status Split while keeping both strategy gates hard-off and registering no production route or UI.

## Production state

Mutation gates remain unchanged:

- `SPLIT = true`
- `DUPLICATE = true`
- `MERGE = false`
- `RETURN_ORDER = false`
- `BULK_RETURN = false`

Split strategy gates remain:

- `manual_quantity = true`
- `category = false`
- `stock_status = false`

`WCOS_Split_Strategy_Admin_Controller` is loaded as a class definition only. It has no constructor hook registration and is not instantiated by the plugin bootstrap. No `wp_ajax_*` strategy action, launcher, dialog, JavaScript, option, filter, or other production surface is introduced.

## Transport lifecycle

The future production server contract is:

`Review request -> server Review Store -> Confirm request -> strategy Confirmation Store -> Execute request -> WCOS_Mutation_Gateway -> strategy adapter -> hardened Split adapter/service`

Every controller method enforces:

- global hardened Split gate;
- exact strategy gate;
- order-specific nonce;
- centralized order-mutation authorization;
- configured source-order status policy.

Since Category and Stock-status remain hard-off, direct controller use fails before any Review transient, confirmation, journal, or child order can be created.

## Server-side Review authority

`WCOS_Split_Strategy_Review_Store` is a short-lived server-side authority layer. The Review response may expose its PII-free planner report for display, but Confirm receives only:

- opaque `review_id`;
- opaque `review_token`;
- source order ID;
- semantic strategy;
- selected `source_bucket_key`.

Confirm never trusts a client-supplied planner Review payload. It reloads the authoritative server Review, verifies token/user/order/strategy ownership, expiry, and current source signature, then passes that server record to the accepted strategy confirmation foundation.

## Review -> Confirm concurrency

A server Review is single-use authority.

Two concurrent Confirm requests may both observe the Review before either removes it. The controller therefore creates a candidate confirmation and then requires successful atomic-enough transient consumption of the Review before returning the candidate token. Only the request that successfully consumes the Review keeps its confirmation. A losing request deletes its unexposed candidate confirmation and fails closed with `review_already_consumed`.

This prevents one Review from intentionally producing multiple usable operation IDs through a Confirm race.

## Confirmation and Execute

Execute accepts only an operation ID and confirmation token from the client. It verifies the server confirmation before entering the gateway and additionally requires the confirmed semantic strategy to equal the requested transport strategy.

The gateway then re-applies:

- global Split gate;
- strategy gate;
- centralized authorization;

and delegates only to `WCOS_Split_Strategy_WooCommerce_Adapter::split_confirmed()`.

Client-supplied plan, classification fingerprint, planner evidence, execution policy, price precision, or strategy authority are never Execute authority.

## Frozen classification

Category and Stock-status catalog classification is read during Review only. Confirm and Execute use the frozen server authority created from that Review.

Canonical acceptance changes live taxonomy or stock status after confirmation and proves the frozen reviewed line still moves exactly as confirmed. Execute does not re-run either planner.

## TOCTOU

The transport closes source-order races at both ephemeral boundaries:

1. Review Store creation rechecks the current PII-free source signature against planner Review;
2. Review Store verification rechecks source signature before Confirm;
3. strategy Confirmation verification rechecks source signature before first mutation;
4. `WCOS_Split_WooCommerce_Adapter` rechecks the request-local verified signature immediately before hardened mutation.

The acceptance fixture mutates public line-item business metadata between Review and Confirm. That metadata participates in `WCOS_Order_Contract_Snapshot::source_signature()` while leaving Category classification unchanged, proving the Review -> Confirm race check rather than merely changing planner classification.

## Error contract

The controller exposes structured `WCOS_Split_Transport_Exception` errors with HTTP status and retryability. Foundation coverage includes:

- `workflow_disabled` / `strategy_disabled`;
- `invalid_strategy`;
- `invalid_order` / `order_not_found`;
- `invalid_nonce`;
- `authorization_failed`;
- `status_disabled`;
- Review token/owner/expiry/source-change failures;
- `source_bucket_required`;
- confirmation token/authority/policy failures;
- `confirmation_strategy_mismatch`;
- `review_already_consumed`;
- `operation_busy` / `operation_conflict`;
- preflight and manual-reconciliation failures.

## Acceptance boundary

Canonical legacy, HPOS-only, and HPOS compatibility/sync matrices must prove:

- controller/review classes load but register no production route;
- both strategy gates remain hard-off before and after test-only execution;
- direct hard-off use creates no mutation authority;
- nonce and authorization boundaries hold under a test-only temporary strategy gate;
- Review output is PII-free;
- Confirm ignores client-supplied Review evidence;
- Review ownership and single-use semantics hold;
- source changes between Review and Confirm fail closed;
- invalid confirmation creates no journal;
- Category executes frozen taxonomy authority and durably replays without another child;
- durable Category authority cannot be replayed as Stock-status;
- Stock-status executes frozen status authority after the live catalog status changes;
- all existing manual Split, Duplicate, whole-line, recovery, stock, tax, concurrency, and journal contracts remain green.

## Deferred production work

This foundation is not the sandbox/production UI milestone. Before Category or Stock-status can be enabled, separate work must still provide:

1. production route registration behind an exact strategy gate;
2. server-rendered strategy launcher/dialog and client state machine;
3. accessible bucket Review/selection/confirmation UX;
4. production-enabled E2E acceptance for each strategy;
5. exact-state package/CI assertions;
6. independent technical/security/accessibility review;
7. explicit Human Gate for each `false -> true` strategy enablement.
