# Order Mutation V2 Contract

## Status

The legacy Split, Duplicate, Merge, Return, and Bulk Return handlers remain fail-closed in version 1.4.12. `inc/domain/` is the single replacement mutation-engine foundation. Foundation services and integration scaffolding do not register production mutation endpoints, and `WCOS_Feature_Gates` remains hard-off for every workflow.

Any duplicate implementation under `inc/mutation-v2/` is superseded and must not be introduced as a second runtime source of truth.

## Mandatory invariants

Every mutation adapter must prove the following before its UI or endpoint can be enabled:

1. Exact line identity uses at least `product_id`, `variation_id`, `tax_class`, and a canonical business-metadata signature. `product_id` alone is never a valid merge key.
2. For each source line, original quantity plus all target quantities equals the pre-operation quantity.
3. For each source line and currency precision, original plus target `subtotal`, `total`, `subtotal_tax`, and `total_tax` equals the pre-operation value in integer minor units.
4. Every per-rate historical tax allocation is conserved. Tax rates must not be recalculated during a mutation.
5. Original plus target `_reduced_stock` equals the pre-operation marker, and the mutation itself does not change physical stock.
6. Shipping, fees, coupons, refunds, and payment ownership follow an explicit policy; no component may be copied implicitly to every target order.
7. A persisted `WC_Order_Item` object is owned by exactly one order. All target items are newly constructed objects.
8. One idempotency token can commit at most once. Reuse with a different fingerprint fails closed.
9. A per-source-order lease prevents concurrent mutation operations; stale takeover, refresh, ownership checks, and release must be concurrency-safe.
10. Relationships are reciprocal and structured; legacy CSV relation metadata is compatibility data only.
11. Failure leaves a durable authoritative journal entry and either restores a fingerprinted source snapshot or records an explicit recoverable/compensating state.
12. Recovery must not overwrite source or relation state changed by another actor after the recorded persistence checkpoint.
13. Recovery snapshots must exclude customer/address/payment plaintext unless a later explicit recovery contract proves that data is necessary.
14. Protected operational metadata such as `_reduced_stock` and `_wcos_*` cannot be promoted into commercial identity or blind-copy state by extension filters.
15. Email, webhook, stock, analytics, and third-party side effects are scoped to the committed operation rather than global request filters.
16. Persisted invariants are checked after database re-read, not only against in-memory objects.

## Current charge policy for the first replacement workflow

The first workflow eligible for later reintroduction is manual quantity split. The foundation may model one or more child allocations, but a production release must explicitly define the supported product surface and its tests.

Current narrow policy:

- preserve historical product-line amounts and per-rate taxes;
- keep shipping, fee, coupon, refund, and payment transaction ownership on the original order;
- copy customer, address, currency, and non-transactional payment context to the child only through the explicit copy-context contract;
- never copy `transaction_id`;
- reject refunded or partially refunded source orders until a refund-allocation policy exists;
- reject a request that moves every source quantity away from the original order;
- persist children in a non-fulfillment `pending` state;
- retain source financial/stock invariants across source + children;
- never touch physical stock merely because an existing order is repartitioned.

Any broader policy requires a separate contract and tests.

## Implementation gates

### Gate A — Domain proof

- deterministic amount allocation for positive, negative, zero-decimal, fractional, and rounding-residual values;
- quantity, monetary, per-rate tax, and `_reduced_stock` conservation tests;
- stable request fingerprint independent of input order;
- exact line identity and explicit metadata classification;
- CAS-safe lease ownership and stale takeover;
- durable journal state transitions and immutable request fingerprint;
- PII-free recovery snapshot integrity fingerprint;
- resumable compensation across crash windows;
- PHP 7.4, 8.1, and 8.3 execution.

### Gate B — WooCommerce adapter proof

- HPOS enabled;
- legacy order storage;
- WooCommerce compatibility/sync mode;
- managed stock, variation stock, parent-managed stock, unmanaged stock, backorders, and fractional stock;
- prices including and excluding tax;
- multiple tax rates, coupons, fees, and shipping methods;
- deleted products and duplicated product lines with different metadata;
- operation retry, double click, stale lease, real concurrent worker contention, and injected failure at each persistence boundary;
- compensation and compensation-resume after partial persistence;
- exact email, webhook, stock-hook, analytics, and order-note counts;
- database re-read verification of all conserved state.

### Gate C — Product acceptance

- server-rendered preflight preview;
- explicit charge policy shown before confirmation;
- accessible labels, keyboard operation, error focus, and status announcement;
- operation record and reciprocal order links visible to administrators;
- migration behavior for existing `yoos_original_order` and `yoos_splitted_order` metadata;
- release ZIP passes Plugin Check and the full CI matrix.

No mutation endpoint may be enabled until Gates A and B are green and an independent technical review finds no release blocker. Gate C and a human release gate are required before a WordPress.org workflow release is published.
