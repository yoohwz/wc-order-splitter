# P2 Quantity Split WooCommerce Adapter

## Classification

`P2_WOOCOMMERCE_ADAPTER`

## Status

Milestone 1 introduces the WooCommerce-facing adapter boundary for manual quantity split while every production mutation gate remains hard-off. No AJAX endpoint, order action, bulk action, UI control, or legacy mutation handler is registered by this milestone.

The production write path is now designed as:

`controller -> WCOS_Mutation_Gateway -> WCOS_Split_WooCommerce_Adapter -> WCOS_Split_Order_Service`

Controllers must not call the service directly.

## Implemented in this milestone

### Request-local physical-stock proof

`WCOS_Stock_Side_Effect_Guard` observes WooCommerce product/variation stock writes only inside the current mutation request. This replaces the production adapter's reliance on comparing catalog stock before and after the whole request, which can be invalidated by a concurrent checkout in another process.

A stock write observed in the current mutation request:

- prevents the mutation conservation contract from being accepted;
- prevents persisted-state recovery from being auto-finalized as a valid order-only mutation;
- leaves the operation journal in `recovery_required`;
- marks `automatic_compensation_allowed=false`, because physical stock is outside the order snapshot and requires explicit reconciliation.

The existing exact before/after product-stock comparison remains available outside the P2 adapter scope for legacy P1 integration contracts.

### Read-only Split preflight

`WCOS_Split_Preflight` returns a PII-free compatibility report and an explicit policy contract. The current narrow policy is:

- shipping: keep on source;
- fees: keep on source;
- coupons: reject until allocation policy exists;
- refunds: reject until allocation policy exists;
- payment transaction: keep on source;
- child status: `pending`;
- tax: preserve historical amounts/rates;
- physical stock: no write;
- nested split: reject.

The report exposes compatibility facts only: order type/status/currency/tax-inclusive mode, line/charge counts, transaction presence, deleted-product line count, fractional quantity line count, managed/unmanaged stock counts, and backorder count. Customer/address/payment plaintext is not returned.

### Durable journal retention boundary

`WCOS_Operation_Journal_Retention` defines bounded cleanup for authoritative mutation records:

- default retention: 90 days;
- configurable between 7 and 365 days;
- only terminal `completed`, `failed`, and `compensated` records are eligible;
- `started`, `committed`, `recovery_required`, and `compensating` records are never purged;
- cleanup scheduling remains dormant while all production workflow gates are hard-off.

## Acceptance evidence added

The P2 integration contract is executed through the existing canonical WooCommerce CI matrix on legacy storage, HPOS-only, and HPOS compatibility/sync mode. It proves:

- Split remains production hard-off;
- fractional-quantity preflight and PII-free reporting;
- safe adapter Split and retry are at-most-once and do not write physical stock;
- coupon-bearing sources fail before journal/child persistence and remain unchanged;
- historical order lines whose catalog product was deleted remain splittable;
- WooCommerce product stock writes are observed inside the request-local guard;
- ambient stock differences do not create false failures while a clean adapter proof is active;
- an actual stock write injected during Split blocks the commit path and disables automatic compensation;
- journal retention stays dormant while production gates are off and never treats recovery-required state as purgeable.

## Explicit Gate B work still required

This milestone does **not** satisfy the complete P2 production-enable gate. Before manual quantity Split can be enabled, the following still require implementation/evidence:

- managed stock, variation-managed stock, parent-managed variation stock, unmanaged stock, backorders, and fractional-stock matrix;
- tax-inclusive and tax-exclusive prices with multiple historical tax rates and rounding combinations;
- fee and shipping package/method matrix under the keep-on-source policy;
- explicit paid-order/payment lifecycle policy;
- refunds remain unsupported until an allocation/inverse policy exists;
- coupons remain unsupported until an allocation policy exists;
- duplicate commercial lines with distinct metadata/adapters for configured products;
- exact email, webhook, analytics, fulfillment, stock-hook, and order-note counts;
- third-party metadata adapters and compatibility policy;
- production controller/nonce/idempotency transport;
- server-rendered preflight/confirmation UX and accessibility;
- independent technical review and human gate before changing `WCOS_Feature_Gates::SPLIT`.

## Non-goal

Duplicate, category split, stock-status split, Merge, Return, and Bulk Return remain outside this milestone and stay hard-off.
