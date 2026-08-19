# P2 Hardened Duplicate — Production Readiness Contract

## Status

This milestone prepares hardened single-order Duplicate for production while `WCOS_Feature_Gates::DUPLICATE` remains `false`.

Manual quantity Split remains the only production-enabled mutation workflow. Duplicate requires a separate final gate-changing PR and Human Gate after this readiness foundation is accepted.

## Production path

All future production Duplicate writes must use only:

`WCOS_Duplicate_Admin_Controller -> WCOS_Mutation_Gateway -> WCOS_Duplicate_WooCommerce_Adapter -> WCOS_Duplicate_Order_Service`

Legacy `order-duplicate-option.php` and `actions/duplicate-order.php` remain unbootstrapped and excluded from the release package.

## First hardened Duplicate policy

The target order:

- is a fresh WooCommerce order in `pending` status;
- copies core customer, address, currency, payment-method and customer-note context;
- copies historical line, shipping, fee, tax and coupon rows exactly through fresh `WC_Order_Item` objects;
- does not copy the source transaction ID;
- does not copy the source paid state;
- does not copy order-level stock-reduced state;
- does not copy line `_reduced_stock` state;
- does not copy arbitrary custom order-level metadata outside the explicitly copied core fields;
- records only internal Duplicate source/operation relation metadata.

The request itself must not write physical product stock.

## Fail-closed compatibility boundary

The first hardened Duplicate rejects before mutation when it cannot prove compatibility, including:

- refunds or partial refunds;
- unresolved manual-reconciliation stock evidence on the source;
- unclassified private order-item metadata;
- inconsistent private line-item business/identity metadata classification;
- non-canonical business metadata values;
- unsupported fractional quantities when the active WooCommerce quantity integration no longer preserves fractional stock amounts;
- internally inconsistent historical totals/taxes.

Deleted catalog products remain supported because persisted historical order-item state is authoritative. Paid source orders are supported, but their Duplicate target remains pending and never inherits the source transaction ID, `date_paid`, or stock-reduced state.

## Historical item integrity

Aggregate monetary equality is not sufficient for Duplicate. The service verifies exact multisets of cloned item semantics for:

- product/variation identity, quantity, tax class, historical subtotal/total/tax arrays and business metadata;
- shipping method ID, instance ID, title, totals, taxes and business metadata;
- fee amount, tax class/status, totals, taxes and business metadata;
- tax rate ID/label/compound/rate percent/cart/shipping tax totals and business metadata;
- coupon code/discount/discount-tax and business metadata.

Persisted item IDs must be fresh and may never be re-parented from the source order. Configured lines sharing a product ID remain distinct when an explicit metadata adapter classifies their private business metadata consistently for Duplicate and canonical identity.

## Review -> confirmation -> execute

The read-only Review endpoint requires:

- order-specific nonce;
- centralized mutation authorization;
- configured allowed order status;
- PII-free Duplicate preflight.

The server creates the operation UUID and short-lived confirmation token. The stored record contains only the token HMAC plus source/user/signature/price-precision/policy authority.

Source authority is checked across every pre-mutation boundary:

1. the source object used by Review must match the fresh preflight `source_signature`;
2. confirmation creation reloads the source under the reviewed price precision and requires the same signature;
3. Execute confirmation verification reloads and rechecks the source before mutation;
4. for a new operation without a durable journal, confirmation verification publishes only the verified PII-free source hash into request-local authority;
5. the WooCommerce adapter reloads the source again immediately before preflight/service and must still match that verified hash.

The last check closes the narrow confirmation-verify -> adapter/service TOCTOU window. Once a durable journal exists, the journal becomes replay authority and the request-local transient signature is deliberately not used.

Execute also re-verifies the same nonce, authorization, user, token, price precision and Duplicate policy before any mutation. Once a durable journal exists, it becomes replay authority after confirmation expiry.

`DUPLICATE=false` means Execute returns `workflow_disabled` and does not create a journal or target order in this readiness milestone.

The same request-local post-verification source authority was applied to the already production-enabled manual quantity Split path after independent review found the shared boundary issue; the canonical integration matrix protects both workflows.

## Stock / side-effect contract

Acceptance requires:

- no physical-stock write for a normal Duplicate;
- WooCommerce pre-write stock attempts inside the Duplicate request are blocked before persistence;
- confirmed after-write stock evidence enters persistent `manual_reconciliation` and blocks later mutations;
- exactly one WooCommerce new-order event for the target;
- at most one active `order.created` webhook scheduling for the target;
- no implicit target status transition;
- no implicit email;
- exactly one best-effort operation note on source and target for a successful first execution;
- completed retry reuses the same target and does not repeat new-order/webhook/email/note/stock side effects.

## Precision and durable replay

Duplicate executes under request-local `WCOS_Price_Precision_Scope`. The durable journal captures immutable `price_precision` and Duplicate preflight `policy_version`.

`WCOS_Duplicate_Order_Service::POLICY_VERSION` and `WCOS_Duplicate_Preflight::POLICY_VERSION` are one production authority and are locked together by a unit contract so service fingerprint semantics cannot drift from review/confirmation semantics.

Acceptance covers 0-, 2- and 3-decimal historical order values. An interrupted operation must replay using journal precision/policy even if the ambient store precision changes. A changed durable policy fails closed instead of replaying under new semantics.

## Enablement gate

This readiness PR does not authorize `WCOS_Feature_Gates::DUPLICATE = true`.

A later exact gate-changing diff requires:

1. this readiness foundation merged with `DUPLICATE=false`;
2. canonical CI green on legacy / HPOS / HPOS-sync;
3. independent technical/security/accessibility review;
4. production-enabled Duplicate end-to-end acceptance under the real gate state;
5. explicit Human Gate approval.
