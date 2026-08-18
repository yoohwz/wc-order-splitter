# Order Mutation V2 Contract

## Status

The legacy Split, Duplicate, Merge, Return, and Bulk Return handlers remain fail-closed in version 1.4.12. The classes under `inc/mutation-v2/` are the replacement domain foundation; their presence does not enable a production mutation path.

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
9. A per-source-order lock prevents concurrent mutation operations.
10. Relationships are reciprocal and structured; legacy CSV relation metadata is compatibility data only.
11. Failure leaves a durable journal entry and either restores the source snapshot or records a recoverable operation state.
12. Email, webhook, stock, analytics, and third-party side effects are scoped to the committed operation rather than global request filters.

## Current charge policy for the first replacement workflow

The first workflow eligible for reintroduction is manual quantity split into one child order. Its initial policy is intentionally narrow:

- preserve historical product-line amounts and per-rate taxes;
- keep shipping, fee, coupon, refund, and payment transaction ownership on the original order;
- copy customer, address, currency, and non-transactional payment context to the child;
- never copy `transaction_id`;
- reject refunded or partially refunded source orders;
- reject a request that moves every source quantity away from the original order;
- retain the source status only after stock markers and all target items are persisted.

Any broader policy requires a separate contract and tests.

## Implementation gates

### Gate A — Pure domain proof

- deterministic amount allocation for positive, negative, and rounding-residual values;
- quantity, monetary, per-rate tax, and `_reduced_stock` conservation tests;
- stable request fingerprint independent of input order;
- PHP 7.4, 8.1, and 8.3 execution.

### Gate B — WooCommerce adapter proof

- HPOS enabled;
- legacy order storage;
- WooCommerce compatibility/sync mode;
- managed stock, variation stock, unmanaged stock, backorders, and fractional stock;
- prices including and excluding tax;
- multiple tax rates, coupons, fees, and shipping methods;
- deleted products and duplicated product lines with different metadata;
- operation retry, double click, stale lock, and simulated failure after target creation;
- exact email, webhook, and stock-hook counts.

### Gate C — Product acceptance

- server-rendered preflight preview;
- explicit charge policy shown before confirmation;
- accessible labels, keyboard operation, error focus, and status announcement;
- operation record and reciprocal order links visible to administrators;
- migration behavior for existing `yoos_original_order` and `yoos_splitted_order` metadata;
- release ZIP passes Plugin Check and the full CI matrix.

No mutation endpoint may be enabled until Gates A and B are green. Gate C is required before the WordPress.org release is published.
