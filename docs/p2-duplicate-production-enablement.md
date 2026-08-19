# P2 Hardened Duplicate — Production Enablement

## Classification

`P2_FINAL_DUPLICATE_ENABLEMENT`

## Mission

Enable only the already accepted hardened single-order Duplicate workflow after its production-readiness foundation was merged to `main`.

This change does not refactor the Duplicate engine, adapter, transport, confirmation store, UI, stock guard, journal, or recovery implementation accepted in PR #12.

## Approved production gate state

The intended release gate map is:

- `SPLIT = true`
- `DUPLICATE = true`
- `MERGE = false`
- `RETURN_ORDER = false`
- `BULK_RETURN = false`

Gate state remains internal code and cannot be enabled by constants, options, filters, mu-plugins, or `wp-config.php`.

## Production write path

Duplicate writes remain exclusively:

`WCOS_Duplicate_Admin_Controller -> WCOS_Mutation_Gateway -> WCOS_Duplicate_WooCommerce_Adapter -> WCOS_Duplicate_Order_Service`

Legacy Duplicate handlers remain unbootstrapped and excluded from the distributable package.

## Regression preservation

The accepted `DUPLICATE=false` readiness contract is not rewritten to make the gate change pass. Canonical integration keeps that complete hard-off contract inside the existing test-only all-gates-false Reflection scope, restores the real release gate map, and then runs production-enabled acceptance.

The existing manual quantity Split production contract remains enabled and green. It now protects only the workflows that are still unapproved instead of treating the newly approved Duplicate workflow as a failure.

## Production-enabled Duplicate acceptance

Under the real `DUPLICATE=true` gate, canonical acceptance must prove:

- Split remains enabled;
- Merge / Return / Bulk Return remain hard-off;
- Duplicate review and execute AJAX routes remain registered;
- authorized supported orders render the Duplicate launcher and accessible dialog;
- invalid nonce, insufficient capability, and invalid confirmation token fail before mutation;
- Review is read-only and creates no journal or target;
- confirmed Execute reaches Controller -> Gateway -> Adapter -> Service;
- exactly one fresh Pending payment target is created;
- source/operation relations are correct;
- source transaction ID, paid state, order-level stock-reduced state, and line `_reduced_stock` are not copied;
- the source commercial signature remains unchanged;
- physical product stock remains unchanged;
- durable journal reaches `completed` with price-precision and Duplicate policy-version authority;
- completed retry returns the exact original target without a second target or stock write.

## CI and package contract

CI changes from “Split-only approved production state” to “Split + Duplicate approved production state”.

The package contract must contain the hardened Duplicate controller, confirmation store, preflight, adapter, service, JS, and CSS, while legacy Duplicate/other mutation handlers remain excluded.

## Human Gate

This PR intentionally changes production behavior. It must remain unmerged until:

1. canonical CI is green on PHP 7.4 / 8.1 / 8.3;
2. legacy / HPOS / HPOS-sync production-enabled Duplicate acceptance is green;
3. package and architecture gate contracts are green;
4. independent technical/security/accessibility review finds no remaining blocker;
5. an explicit Human Gate approves the exact final head.

Version/changelog release bookkeeping remains a separate post-enable step so this gate-changing diff stays narrowly reviewable.
