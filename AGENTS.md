# Repository Execution Contract

## Mission

Maintain a WordPress.org-compatible WooCommerce order-splitting plugin without violating stock, financial, tax, privacy, or order-item ownership invariants.

## Source authority

1. Current repository code and tests.
2. WooCommerce public CRUD and order data-store APIs.
3. WordPress.org Plugin Guidelines and Plugin Check.
4. The contracts in `docs/order-mutation-v2-contract.md`.
5. Public product copy only after implementation evidence exists.

Marketing copy, historical changelog statements, and legacy behavior are not architecture authority.

## Non-negotiable gates

- `WC_ORDER_SPLITTER_MUTATIONS_ENABLED` remains `false` until the replacement adapter passes the full WooCommerce integration matrix.
- Never re-enable a legacy mutation handler to make a UI test pass.
- Never copy an existing persisted `WC_Order_Item` object to another order.
- Never identify a commercial line by `product_id` alone.
- Never recalculate historical tax as an implicit side effect of Split, Duplicate, Merge, or Return.
- Never copy `_reduced_stock`; allocate it explicitly and prove conservation.
- Never duplicate shipping, fee, coupon, refund, transaction, or payment ownership without an explicit tested policy.
- Never add outbound requests, telemetry, subscriptions, or data collection without informed opt-in and documented disclosure.
- Never use a global email-recipient filter for mutation scoping.

## Change classification

Use one of these labels in plans and pull requests:

- `P0_RELEASE_SAFETY`: privacy, fatal error, corruption, or fail-closed release work.
- `P1_DOMAIN_CONTRACT`: pure planners, identities, allocators, snapshots, locks, journals, and invariants.
- `P2_WOOCOMMERCE_ADAPTER`: runtime writes, rollback, side effects, HPOS/legacy integration tests, and endpoint reintroduction.
- `P3_PRODUCT_QUALITY`: accessible UI, relation views, documentation, packaging, and release governance.

A pull request may span phases only when P0 remains independently releasable and later-phase code is side-effect-free.

## Required evidence

### Every change

- PHP syntax succeeds on PHP 7.4 and the current supported PHP versions.
- Existing contract tests remain green.
- No removed external endpoint or data collection returns.
- Plugin version and `Stable tag` stay aligned for release changes.

### Mutation-domain changes

- Positive, negative, zero-decimal, and rounding-residual allocation cases.
- Quantity, monetary, per-rate tax, and `_reduced_stock` conservation.
- Stable fingerprint and explicit idempotency behavior.
- Exact line identity cases for variations and configured-product metadata.

### WooCommerce adapter changes

- HPOS enabled, legacy storage, and compatibility/sync mode.
- Managed, unmanaged, variation, parent-managed, backorder, and fractional stock.
- Tax-inclusive and tax-exclusive prices, multiple rates, coupons, fees, and shipping.
- Deleted products, duplicate product lines, refunds, paid orders, and custom metadata.
- Double submission, stale lock, timeout, and injected failure recovery.
- Exact email, webhook, stock-hook, and order-note counts.

## Commands

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/v2/contract-test.php
php tests/v2/identity-contract-test.php
php tests/v2/preflight-contract-test.php
```

The GitHub Actions workflows are the merge authority. Local success is necessary but not sufficient.

## Review rules

- Review the complete diff, not only the newest commit.
- Treat money, tax, stock, refunds, and relation metadata as one transaction boundary.
- Reject hidden fallback behavior. Unsupported input must return a stable error and leave orders unchanged.
- Keep pull requests draft while required checks are absent, queued, or failing.
- Do not merge or publish a WordPress.org ZIP without an independent technical review and human release gate.
