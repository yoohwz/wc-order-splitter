# P2 Split strategy UI readiness

## Classification

`P2_SPLIT_STRATEGY_UI_READINESS`

This milestone adds the gate-aware admin routes and accessible Review -> source-bucket selection -> Confirm -> Execute UI required for future Category and Stock-status Split. It does not change either strategy gate.

## Production state

Mutation gates remain:

- `SPLIT = true`
- `DUPLICATE = true`
- `MERGE = false`
- `RETURN_ORDER = false`
- `BULK_RETURN = false`

Split strategy gates remain:

- `manual_quantity = true`
- `category = false`
- `stock_status = false`

With this exact state, `WCOS_Split_Strategy_Admin_Controller::bootstrap()` returns without registering strategy AJAX routes, launchers, dialogs, scripts, or styles.

## Gate-aware bootstrap

The plugin loads the strategy controller class and calls `bootstrap()` on every request. Hooks are registered only when:

1. the global hardened Split workflow is enabled; and
2. at least one of `category` or `stock_status` is enabled.

Every Review, Confirm, and Execute request then rechecks the global gate, exact strategy gate, nonce, centralized authorization, and configured order-status policy. Hook registration is therefore not mutation authority.

This architecture lets a later sandbox/production candidate enable a strategy by changing the internal strategy gate without introducing a second bootstrap path.

## Admin launchers

For each enabled strategy, authorized supported order screens receive a separate action:

- **Split by category**;
- **Split by stock status**.

Each launcher is bound to a dedicated dialog through `aria-controls` and `aria-haspopup="dialog"`.

## Server-authoritative UI flow

The browser never authors a Split quantity plan.

The flow is:

1. **Review current buckets** — server planner classifies the current order and stores Review authority server-side;
2. **Choose one source bucket** — the browser submits only the opaque Review ID/token and selected bucket key;
3. **Confirm selected source bucket** — server Confirmation Store freezes the resulting explicit plan and semantic authority;
4. **Acknowledge** the full-line movement policy;
5. **Execute strategy Split** — browser submits only order/strategy/operation/token; server verification reconstructs all mutation authority.

Client requests never send `classification_fingerprint`, execution policy, price precision, planner evidence, or a client-authored mutation plan.

## Frozen classification UX

The Review display lists each server bucket with:

- server-provided label;
- product-line count;
- total reviewed quantity.

The operator chooses exactly one bucket to remain on source. Every other reviewed bucket becomes one pending child order and its lines move completely.

After Confirm, bucket selection is frozen. Category or Stock-status changes in the live catalog do not rewrite the already confirmed plan.

## Accessibility

Each strategy dialog provides:

- `role="dialog"` and `aria-modal="true"`;
- `aria-labelledby` / `aria-describedby`;
- semantic `fieldset` / `legend` for source-bucket selection;
- live status region with `role="status"` and `aria-live="polite"`;
- focusable `role="alert"` error region;
- explicit execution acknowledgement;
- keyboard focus trap;
- Escape close while not busy;
- focus return to the launcher;
- result focus after successful execution;
- responsive controls with 44px mobile targets.

Server-provided labels and results are rendered with DOM `textContent` / `createElement`; the client does not use `innerHTML` or blocking `window.alert()`.

## Client-state safety

The client maintains separate Review and Confirmation state.

- async Review/Confirm/Execute requests freeze relevant controls;
- selecting another bucket invalidates any pre-existing confirmation state;
- successful Confirm consumes Review authority and locks bucket selection;
- non-retryable Confirm errors require a new Review;
- successful Execute enters a terminal state and cannot be re-enabled by `finally` cleanup;
- non-retryable Execute errors discard confirmation state and require a new Review/Confirm sequence;
- completed durable replay remains server-owned through the existing journal authority.

## Acceptance

Canonical acceptance runs under the real hard-off release state, then temporarily enables both strategy gates with test-only Reflection and restores them in `finally`.

It proves:

- real release bootstrap registers no strategy routes;
- test-only enabled bootstrap registers Review/Confirm/Execute and order-screen hooks;
- both Category and Stock-status launchers are rendered;
- launcher/dialog ARIA relationships are correct;
- dialog bucket selection is semantic and accessible;
- live status, alert, acknowledgement, and result regions exist;
- client code has busy/completed terminal state, focus trap, Escape and focus return;
- server display data is rendered without `innerHTML`/`alert()`;
- client code does not construct/send a mutation plan or classification fingerprint;
- responsive admin styles are packaged;
- hooks and exact release strategy gates are restored after test scope;
- the previously accepted server transport E2E remains green across legacy, HPOS-only, and HPOS compatibility/sync storage.

## Remaining sandbox-candidate work

After this foundation is accepted, sandbox readiness requires a narrow candidate/enablement milestone that:

1. proves a real two-worker Confirm race has only one successful confirmation authority;
2. changes the intended sandbox strategy gates to `true` on the candidate build;
3. runs production-enabled route/UI E2E under the real candidate gate state rather than Reflection;
4. validates the distributable ZIP contains all planner/confirmation/transport/UI assets and no legacy mutation path;
5. passes independent final technical/security/accessibility review.

That candidate can be installed on the sandbox for hands-on testing before any production release Human Gate is granted.
