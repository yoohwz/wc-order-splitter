# P2 Manual Quantity Split — Production Enablement Contract

## Status

This milestone completes the production-facing transport and safety gates for manual quantity Split while `WCOS_Feature_Gates::SPLIT` remains hard-off. The code in this milestone must not make Split executable until a separate Human Gate changes the internal feature gate.

## Production path

All production writes must follow:

`WCOS_Split_Admin_Controller -> WCOS_Mutation_Gateway -> WCOS_Split_WooCommerce_Adapter -> WCOS_Split_Order_Service`

The controller must never instantiate the mutation service directly.

## Persistent manual reconciliation

A confirmed physical-stock write observed after WooCommerce's normal pre-write guard is outside the order-only mutation snapshot. Such an incident is fail-closed and must block every subsequent Split on that source until a human has reconciled stock.

### Two-phase fail-closed persistence

`WCOS_Manual_Reconciliation_Blocker` is persisted **before** the authoritative journal transitions to `manual_reconciliation`.

This ordering is intentional. The blocker is a non-autoloaded, PII-free source-order record containing only operation identifiers, timestamps, and the journal revision observed when the stock incident was recorded. If the PHP process dies after the blocker write but before the journal transition, preflight still rejects the source. The crash window therefore produces a safe false-positive block rather than a false-negative that could permit another Split.

After the journal transition succeeds:

- `completed_at` is cleared;
- automatic compensation is disabled;
- the journal is not retention-purgeable;
- the existing order-meta operation-ID index remains available for audit visibility;
- preflight reports the unresolved operation ID(s) directly in its PII-free message;
- retry and every new Split on the same source are rejected;
- the operation cannot auto-resume or auto-finalize.

A blocker is considered resolved only when the authoritative journal reaches `manual_reconciled` at a **newer journal revision** than the revision recorded by that stock incident. This prevents an older reconciliation from accidentally clearing a later incident on the same operation.

### Manual reconciliation operating procedure

The first production-enabled quantity Split must never automatically modify physical stock to resolve this state and must not expose a one-click "resolve" action.

Before an operation may be marked `manual_reconciled`, an authorized operator/developer must:

1. identify the operation ID reported by Split preflight;
2. inspect the authoritative journal and its `stock_side_effects` evidence;
3. identify the actual stock owner for every affected product/variation, including parent-managed variations;
4. compare current physical stock with the intended post-order stock state;
5. inspect the source and child `_reduced_stock` markers and order-level stock-reduced flags;
6. correct stock externally only when the reconciliation evidence proves a correction is required;
7. record who performed the reconciliation and a meaningful audit note;
8. only then perform the explicit `WCOS_Operation_Journal::mark_manual_reconciled()` transition.

The transition records resolution; it does **not** perform or infer a stock correction. If the operator cannot prove the correct physical-stock state, the source remains blocked and the operation must be escalated for manual support investigation.

## Price precision

WooCommerce order-item hydration can invoke tax rounding through `wc_get_price_decimals`. Each Split therefore captures its price precision in the durable journal and enters a request-local `WCOS_Price_Precision_Scope` before reloading the order.

Precision authority is:

1. existing durable operation journal;
2. server confirmation record created by the review step;
3. current store setting for a new review.

A retry continues under the captured precision even if the ambient store setting changes after an interruption. Acceptance covers zero-decimal and three-decimal monetary/tax conservation as well as the default two-decimal case.

## Safety-policy version authority

A reviewed Split confirmation is bound to `WCOS_Split_Preflight::POLICY_VERSION`. When the first durable journal is created, that policy version is persisted alongside `price_precision` and becomes immutable journal context.

Consequences:

- a live confirmation must match the durable journal's policy version once a journal exists;
- journal checkpoint/state transitions cannot rewrite the captured policy version;
- durable replay after the confirmation TTL requires a stored policy version;
- a durable operation whose recorded policy version differs from current code fails closed with `policy_changed` rather than resuming under new semantics.

This prevents an interrupted mutation reviewed under one safety policy from silently continuing after deployment of a different policy.

## Stock lifecycle

The P2 acceptance matrix covers:

- simple managed stock;
- unmanaged stock;
- variation-owned managed stock;
- parent-managed variation stock;
- backorders;
- fractional managed stock when an integration explicitly enables fractional WooCommerce stock amounts;
- processing -> Split -> child cancellation -> residual source cancellation, with exact `_reduced_stock` redistribution and exact restoration to the correct stock owner.

The Split request itself must not change physical stock.

### Direct database/meta stock mutation

The request-local guard proves stock safety only for integrations that participate in WooCommerce stock APIs/hooks or provide equivalent explicit evidence. An extension that mutates product stock directly in the database or raw metadata, bypassing WooCommerce stock hooks, is unsupported by the first production-enabled quantity Split unless a compatibility adapter provides an equivalent fail-closed contract.

## Fractional quantities

WooCommerce uses integer stock amounts by default. Fractional transport quantities are rejected unless the active `woocommerce_stock_amount` integration actually preserves fractional values. Preflight exposes whether fractional quantity support is active and the server parser enforces the same policy.

## Review -> confirmation -> execute transport

### Review

The review endpoint is read-only. It requires:

- signed-in user;
- order-specific nonce;
- centralized mutation authorization;
- configured allowed order status;
- strict JSON plan parser;
- PII-free Split preflight.

The server generates the operation UUID and a short-lived confirmation token. Client-supplied operation IDs are never accepted for a new operation.

### Confirmation record

The temporary confirmation record contains no customer/address plaintext. It binds:

- operation ID;
- HMAC of the confirmation token;
- source order ID;
- current user ID;
- source signature;
- canonical plan;
- reviewed price precision;
- Split policy version;
- expiry.

Before mutation begins, execution rejects changed source state, changed policy, invalid/expired tokens, user/order mismatch, or precision mismatch. Once a durable journal exists, that journal is authoritative for idempotent retry of the same operation and carries the immutable replay plan, price precision, and safety-policy version.

### Execute

Execution verifies the same nonce/authorization/status boundary, verifies the confirmation record, then enters `WCOS_Mutation_Gateway`. Errors are returned as structured codes with HTTP status and retryability. The execute path remains `workflow_disabled` while the feature gate is false.

## Plan grammar

The production parser is intentionally narrow:

- JSON object only;
- `child-1` through `child-10` only;
- maximum 10 children;
- maximum 500 line assignments;
- maximum request size 64 KiB;
- positive integer item IDs belonging to the source order;
- quantities must be decimal strings, never JSON numeric values;
- maximum six quantity decimals;
- no scientific notation;
- fractional quantities require active fractional WooCommerce quantity support;
- aggregate child allocation for every source line must leave a positive residual on the source;
- numeric overflow is a validation error, not a server error.

## Accessible admin UI

The server-rendered dialog provides a line-by-child allocation matrix so one source line can be distributed among multiple children. It includes:

- semantic table headers and row headers;
- explicit labels for every quantity field;
- `role="dialog"`, `aria-modal`, labelled/described dialog;
- review-before-execute flow;
- explicit acknowledgement checkbox;
- `role="status"` live region and `role="alert"` error region;
- focus trap, Escape close, and focus return;
- no blocking `alert()`;
- no `innerHTML` rendering of server data;
- result links built with DOM APIs and text nodes;
- server-side policy disclosure, including intentionally unsupported cases.

### Client state authority

The client is never authoritative for quantities or financial validation, but its review state must accurately represent what the human confirmed.

Therefore:

- all quantity fields are frozen while an asynchronous review or execute request is in flight, preventing the visible matrix from diverging from the plan the server reviewed;
- quantity edits invalidate the previous confirmation;
- successful execution enters a terminal client state;
- `finally`/busy-state cleanup cannot re-enable Review, acknowledgement, Execute, or quantity inputs after success;
- reopening the dialog after success focuses the result region instead of a disabled quantity field;
- non-retryable execute errors invalidate the reviewed confirmation state.

The launcher and assets remain hidden while `SPLIT` is hard-off.

## Intentionally unsupported in first quantity-Split release

The following may remain unsupported at first enablement because preflight rejects them before mutation and the UI discloses the policy:

- coupons;
- refunds/partial refunds;
- negative fees;
- nested Split;
- unclassified private line-item metadata;
- private metadata whose business/identity classification is inconsistent;
- direct DB/meta stock integrations without a compatibility adapter.

## Enablement gate

Changing `WCOS_Feature_Gates::SPLIT` to `true` requires all of the following on the final enablement diff:

- PHP 7.4 / 8.1 / 8.3 checks green;
- architecture/hard-off contract green before the gate-changing PR;
- package safety green;
- legacy order storage green;
- HPOS-only green;
- HPOS compatibility/sync green;
- all P1 recovery/concurrency/failure contracts green;
- all P2 adapter, manual-reconciliation, precision, stock lifecycle, tax/charge/payment, side-effect, metadata, retention, transport and accessibility contracts green;
- fail-closed manual-reconciliation crash-window contract green;
- durable replay policy-version binding contract green;
- client terminal-state and review/quantity TOCTOU contracts green;
- independent technical/security/accessibility review;
- explicit Human Gate approving production enablement.

This milestone does not itself authorize the gate change.
