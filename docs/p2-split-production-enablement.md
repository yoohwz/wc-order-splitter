# P2 Manual Quantity Split — Final Production Enablement

## Scope

This change is the final gate-changing diff for the first production-enabled order mutation workflow.

Approved state:

- `WCOS_Feature_Gates::SPLIT = true`
- `WCOS_Feature_Gates::DUPLICATE = false`
- `WCOS_Feature_Gates::MERGE = false`
- `WCOS_Feature_Gates::RETURN_ORDER = false`
- `WCOS_Feature_Gates::BULK_RETURN = false`

No legacy mutation handler may be loaded or restored.

## Runtime authority

Production Split writes must continue to use only:

`WCOS_Split_Admin_Controller -> WCOS_Mutation_Gateway -> WCOS_Split_WooCommerce_Adapter -> WCOS_Split_Order_Service`

The production gate remains internal code. Constants, options, filters, mu-plugins, or `wp-config.php` must not be able to enable another mutation workflow.

`WC_Order_Splitter_Safety_Guard::mutations_enabled()` reflects the internal feature-gate set. It is not a second independently configurable gate.

## Compatibility boundary

The first enabled manual quantity Split intentionally remains fail-closed for unsupported cases already defined by P2 preflight, including:

- coupons;
- refunds / partial refunds;
- negative fees;
- nested Split;
- unclassified or inconsistently classified private line metadata;
- direct database/raw-meta stock mutation integrations without an explicit compatibility adapter.

Manual quantities must align exactly with each line's frozen WooCommerce admin quantity step. Fractional steps are accepted only when the active WooCommerce quantity integration also preserves fractional stock amounts.

## CI transition

The canonical CI contract changes from "all workflows hard-off" to "Split-only approved production state".

CI must prove:

- PHP 7.4 / 8.1 / 8.3 syntax and unit contracts;
- `SPLIT=true` and every other mutation gate remains `false`;
- no externally overrideable mutation gate exists;
- legacy mutation handlers/hooks remain absent;
- the package includes the hardened Split controller, confirmation store, parser, gateway, adapter, service, JS and CSS;
- legacy / HPOS / HPOS-sync all report the Split-only gate state;
- the complete prior hard-off P2 regression suite still passes inside a test-only simulated hard-off scope;
- after restoring the real release gate map, production Review -> Confirm -> Gateway -> Adapter -> Service succeeds end-to-end;
- production retry returns the original child set and does not change physical stock;
- Duplicate, Merge, Return and Bulk Return remain blocked.

## Human Gate

This branch changes production behavior and must not be merged merely because the readiness foundation was previously accepted.

Merge requires, on the exact final head:

1. canonical `Required CI` green;
2. independent review of the gate, safety-guard, CI/package and production transport diff;
3. explicit Human Gate approval to enable manual quantity Split in production.

Version and changelog are intentionally unchanged in this enablement milestone. Release/version bookkeeping remains a later release step.
