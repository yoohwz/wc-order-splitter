# P2 Category and Stock-status Split Planner Foundation

## Scope

This milestone introduces deterministic, read-only server planners for two future Split strategies:

- Category Split;
- Stock-status Split.

It does not register a controller, AJAX action, order button, mutation route, or production strategy. `WCOS_Split_Strategy_Gates::CATEGORY` and `WCOS_Split_Strategy_Gates::STOCK_STATUS` remain hard-off.

Manual quantity Split remains the only production-enabled **Split strategy**. Hardened single-order Duplicate is a separate production mutation workflow and is unchanged by this milestone.

## Planner-only architecture

A future strategy flow must be:

`Review planner -> frozen classification evidence -> explicit quantity plan -> confirmation -> hardened strategy adapter/gateway -> whole-line Split service`

Review is read-only. Execute must consume the frozen plan and must never re-run category or stock-status classification against volatile catalog state.

The whole-line Split plan/runtime/recovery foundations are already merged. That dependency is satisfied, but whole-line execution remains internal-only and requires an active request-local stock side-effect guard. Therefore these planners still cannot be connected directly to the mutation service.

## Source-state coherence

Each planner begins from the hardened Split preflight, then rehydrates the source order and requires its PII-free commercial source signature to match the preflight signature before classification begins.

This prevents planner evidence from being built from a stale caller-owned `WC_Order` object while the report claims authority over a newer source state.

Future confirmation must revalidate the source signature again before token issuance/execution.

## Category planner

Category authority uses stable taxonomy **term IDs** rather than names or slugs.

For each source line:

- historical order item ID and quantity are authoritative for the resulting plan;
- the current catalog product must still exist to prove current Category classification at Review time;
- assigned ancestor + descendant categories collapse to the deepest assigned leaf category;
- multiple unrelated leaf categories are ambiguous and fail closed;
- no assigned category becomes an explicit `category-uncategorized` bucket and is never silently dropped;
- deleted catalog products fail closed because current category classification cannot be proven from volatile catalog state.

Bucket reports may contain term slug/name for display, but the classification fingerprint deliberately excludes them. Its category authority is the source signature, planner policy version, stable term ID, and frozen item allocations. Renaming a category or changing its slug therefore does not change classification identity while the term ID and reviewed source remain the same.

The operator must explicitly choose which reviewed bucket remains on the source order. Every other bucket becomes a full-line child allocation plan.

## Stock-status planner

Stock status is explicitly treated as volatile catalog evidence.

Review accepts only WooCommerce statuses:

- `instock`;
- `outofstock`;
- `onbackorder`.

Evidence records source item ID, product/variation IDs, current catalog object ID, current stock owner ID, and reviewed status.

After Review, `build_plan()` uses only the frozen report. A product moving from one stock status to another after Review does not rewrite the already reviewed plan; a new Review observes the new catalog state.

Deleted catalog products fail closed because current stock-status classification cannot be proven.

## Execution policy

Both planners bind their reports to:

`WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER`

`build_plan()` rejects a review whose execution-policy authority has been altered. This planner foundation still does not call the mutation service.

## Future confirmation / transport contract

A future strategy confirmation must bind at least:

- source order signature;
- strategy name and planner policy version;
- classification fingerprint;
- explicit source bucket;
- canonical quantity plan;
- price precision;
- `WCOS_Split_Execution_Policy::ALLOW_WHOLE_LINE_TRANSFER`.

Before mutation, a dedicated hardened strategy adapter must establish `WCOS_Stock_Side_Effect_Guard` scope and preserve the same fail-closed source/confirmation semantics used by the existing production workflows.

Execute must not query Category or stock status again.

## Production gate

Planner classes are safe to package/load while strategy gates remain hard-off because they register no production surface and perform no writes.

Any future Category or Stock-status production enablement requires its own transport/UI, production-enabled integration matrix, independent review, and explicit Human Gate.
