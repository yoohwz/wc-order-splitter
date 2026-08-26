# P2 Quantity Split WooCommerce Adapter

## Classification

`P2_WOOCOMMERCE_ADAPTER`

## Status

The first P2 foundation milestone is technically implemented and exercised while every production mutation gate remains hard-off. No AJAX endpoint, order action, bulk action, UI control, or legacy mutation handler is registered by this milestone.

The intended production write path is:

`controller -> WCOS_Mutation_Gateway -> WCOS_Split_WooCommerce_Adapter -> WCOS_Split_Order_Service`

Controllers must not instantiate or call the Split service directly.

## Implemented foundations

### Request-local physical-stock proof

`WCOS_Stock_Side_Effect_Guard` is active only inside the current mutation request. Normal WooCommerce product/variation stock writes are intercepted at the `before_set_stock` hooks before the product data store changes. Matching after-write hooks remain observed as fallback evidence for integrations that report a stock write after persistence.

Consequences:

- concurrent checkout stock changes in another request do not create false Split failures;
- an in-request stock-write attempt is blocked before the normal WooCommerce data-store write;
- a blocked attempt dirties the conservation proof and leaves the same idempotency operation safely retriable;
- a confirmed after-write fallback is outside the order-only snapshot and must not be auto-compensated without explicit stock reconciliation.

The exact P1 before/after stock comparison remains available outside a P2 adapter scope.

### Read-only Split preflight

`WCOS_Split_Preflight` returns a PII-free compatibility report and a versioned policy contract.

Current policy:

- shipping: keep on source;
- positive fees: keep on source;
- negative fees: reject;
- coupons: reject;
- refunds: reject;
- payment/transaction ownership: source-only;
- child status: `pending`;
- tax: preserve historical values/rates;
- physical stock: no write;
- nested Split: reject;
- unknown private line metadata: reject;
- context-inconsistent private metadata classification: reject.

The report exposes compatibility facts only: order type/status/currency, `prices_include_tax`, paid state, line/charge/refund counts, transaction presence, deleted-product lines, fractional quantity lines, managed/unmanaged/backorder lines, and unclassified/inconsistent private metadata keys. Customer/address/payment plaintext is not returned.

### Stock lifecycle matrix

The canonical WooCommerce integration matrix proves:

- managed stock: an already-reduced order redistributes `_reduced_stock` exactly without changing physical stock;
- cancelling a child restores exactly the child share;
- cancelling the residual source restores the remaining share exactly once;
- unmanaged stock does not receive synthetic `_reduced_stock`;
- variation-owned stock preserves variation identity and does not move physical stock;
- parent-managed variation stock resolves the real stock owner and remains unchanged;
- backorder lines are identified and remain safe under the no-write Split policy;
- native WooCommerce integer quantity behavior is respected through per-line step `1` authority;
- fractional quantity behavior is proven only when an explicit integration both replaces WooCommerce's default integer stock-amount filter and exposes the applicable WooCommerce admin quantity step.

### Historical tax, charge, payment, and rounding contracts

Acceptance evidence covers:

- tax-inclusive and tax-exclusive order contexts;
- multiple historical tax rates;
- two shipping packages retained on source;
- positive taxable fee retained on source;
- child receives only its historical line tax rows;
- source retains historical fee/shipping tax rows;
- aggregate financial conservation;
- paid source keeps transaction ID and paid timestamp;
- child keeps non-transaction payment context, remains `pending`, and receives no transaction ID or paid timestamp;
- negative fees fail closed before journal or child persistence;
- deterministic multi-child one-cent and tax-cent allocation;
- idempotent retry returns the same child set.

### Production side-effect contract

A real active WooCommerce `order.created` webhook is exercised in CI with delivery scheduling intercepted so no external network call occurs.

The contract proves:

- exactly one `woocommerce_new_order` lifecycle event per child;
- exactly one `order.created` webhook schedule per child;
- completed retry emits neither event again;
- no implicit child status transition;
- no `wp_mail` attempt;
- no product/variation stock-write hook on a safe Split;
- exactly one operation note on source;
- exactly one operation note on each child;
- completed retry does not duplicate operation notes.

### Metadata compatibility boundary

Public line metadata is business configuration by default. Protected stock/refund/mutation metadata is always operational and cannot be promoted by filters.

Private line metadata is fail-closed unless an integration explicitly declares it as either immutable business metadata, which is copied and participates in canonical line identity, or known operational metadata, which is not copied. Classification must be consistent between Split-copy and identity contexts. The integration matrix proves that two same-product lines with different adapted business configuration remain distinct and do not collapse.

### Durable journal retention

`WCOS_Operation_Journal_Retention` defines bounded authoritative-journal cleanup:

- default retention: 90 days;
- configurable between 7 and 365 days;
- only `completed`, `failed`, and `compensated` terminal records are eligible;
- active/recovery/compensating/manual-reconciliation states are never eligible;
- scheduling remains dormant while every production workflow gate is hard-off;
- cleanup runs in bounded option-ID high-water cycles: each cycle snapshots the current maximum matching option ID, scans only through that finite boundary, then resets so previously recent records are reconsidered in later cycles even on continuously active stores.

Regression evidence covers both multi-batch starvation and re-evaluation of a journal that was recent in one cycle but later aged past retention while newer journal records continued to arrive.

## Current acceptance evidence

The canonical CI executes the P2 contracts on legacy order storage, HPOS-only, and HPOS compatibility/sync mode, with PHP 7.4/8.1/8.3 static/unit gates plus package and architecture/hard-off checks.

The adapter foundation remains intentionally non-runnable from production controllers.

## Remaining production-enable blockers

This foundation is safe to merge while production gates remain hard-off, but it is **not** sufficient to enable manual quantity Split yet.

Before changing `WCOS_Feature_Gates::SPLIT`, the next P2 milestone must still provide:

1. Persistent manual-reconciliation state for the extreme fallback where an integration bypasses the normal pre-write stock guard and only reports physical stock mutation after persistence, including behavior if the journal had already reached `completed`.
2. Currency/price-precision evidence beyond the current two-decimal matrices, including zero-decimal and three-decimal precision or an equivalent extension-filtered precision contract.
3. Production transport/controller with nonce/CSRF protection, centralized authorization, strict plan parsing, normalized source item IDs/quantities, an idempotency/operation-ID contract, and explicit error/retry responses.
4. Server-rendered accessible preflight/confirmation UI with policy disclosure, warnings, focus behavior, and operation result/audit feedback.
5. Concrete compatibility guidance/adapters for explicitly supported third-party configured-product ecosystems beyond the generic metadata adapter mechanism.
6. Independent technical review and explicit human gate before production enablement.

Coupons, refunds, and negative fees may remain intentionally unsupported for the first production release if the product policy keeps them fail-closed and the UI communicates that limitation before mutation.

## Non-goals of this milestone

Duplicate, category split, stock-status split, Merge, Return, and Bulk Return remain outside this milestone and stay hard-off.
