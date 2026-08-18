# Repository Execution Contract

## Mission

Maintain a WordPress.org-compatible WooCommerce order-splitting plugin without violating stock, financial, tax, privacy, or order-item ownership invariants.

## Source authority

1. Current repository code and tests.
2. `inc/domain/` as the single replacement mutation-engine source of truth.
3. WooCommerce public CRUD and order data-store APIs.
4. WordPress.org Plugin Guidelines and Plugin Check.
5. The contracts in `docs/order-mutation-v2-contract.md`.
6. Public product copy only after implementation evidence exists.

`inc/mutation-v2/`, duplicate implementations, marketing copy, historical changelog statements, and legacy behavior are not architecture authority.

## Non-negotiable gates

- `WC_Order_Splitter_Safety_Guard::mutations_enabled()` remains `false` and `WCOS_Feature_Gates::any_enabled()` remains `false` until a later workflow release passes its full acceptance matrix and human gate.
- No constant, option, filter, mu-plugin, or external plugin may enable an unfinished mutation workflow.
- Never re-enable or bootstrap a legacy mutation handler to make a UI or integration test pass.
- Future mutation controllers must enter through `WCOS_Mutation_Gateway`; controller code must not instantiate mutation services directly.
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
- `P1_DOMAIN_CONTRACT`: planners, identities, allocators, snapshots, leases, journals, fingerprints, recovery contracts, governance boundaries, and invariants.
- `P2_WOOCOMMERCE_ADAPTER`: runtime mutation controllers, production workflow enablement, side-effect policy, HPOS/legacy adapter validation, and endpoint reintroduction.
- `P3_PRODUCT_QUALITY`: accessible UI, relation views, documentation, packaging, and release governance.

P1 may contain internal adapter/service scaffolding used exclusively by integration tests to prove the domain contracts, but it must remain unreachable from production controllers and all workflow gates must stay hard-off. Any production-reachable write path is P2 and requires a separate acceptance gate.

## Required evidence

### Every change

- PHP syntax succeeds on PHP 7.4 and the current supported PHP versions.
- Existing domain and integration contracts remain green.
- No removed external endpoint or data collection returns.
- Plugin version and `Stable tag` stay aligned for release changes.
- No second mutation engine or competing lock/journal implementation is introduced.

### Mutation-domain changes

- Positive, negative, zero-decimal, and rounding-residual allocation cases.
- Quantity, monetary, per-rate tax, and `_reduced_stock` conservation.
- Stable fingerprint and explicit idempotency behavior.
- Exact line identity cases for variations and configured-product metadata.
- Protected operational metadata cannot be reclassified into commercial identity/copy state.
- Lease takeover, refresh, ownership, and release are concurrency-safe.
- Recovery snapshots are integrity-fingerprinted and exclude customer/payment PII.
- Compensation is resumable across persistence/checkpoint crash windows.

### WooCommerce adapter evidence before any later production enablement

- HPOS enabled, legacy storage, and compatibility/sync mode.
- Managed, unmanaged, variation, parent-managed, backorder, and fractional stock.
- Tax-inclusive and tax-exclusive prices, multiple rates, coupons, fees, and shipping.
- Deleted products, duplicate product lines, refunds, paid orders, and custom metadata.
- Double submission, stale lock, timeout, and injected failure recovery.
- Exact email, webhook, stock-hook, analytics, and order-note counts.
- Persisted invariants are verified after database re-read.

## Commands

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/unit/run.php
```

WooCommerce/WordPress integration contracts run through `.github/workflows/ci.yml` across legacy, HPOS, and HPOS-sync storage. The GitHub Actions workflows are the merge authority. Local success is necessary but not sufficient.

## Review rules

- Review the complete diff, not only the newest commit.
- Treat money, tax, stock, refunds, and relation metadata as one transaction boundary.
- Reject hidden fallback behavior. Unsupported input must return a stable error and leave orders unchanged.
- Keep pull requests draft while required checks are absent, queued, or failing.
- Keep P1 workflow gates hard-off even if internal services pass their tests.
- Do not merge a production mutation controller or publish a WordPress.org ZIP without an independent technical review and human release gate.
