const fs = require('fs');
const vm = require('vm');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '../../js/post-action-tip.js'), 'utf8');
const storage = new Map();
const hooks = {};
let fetchCalls = 0;
let fetchResponse;

const localStorage = {
    getItem(key) {
        return storage.has(key) ? storage.get(key) : null;
    },
    setItem(key, value) {
        storage.set(key, String(value));
    },
    removeItem(key) {
        storage.delete(key);
    }
};

function jqueryStub(arg) {
    if (typeof arg === 'function') {
        return undefined;
    }

    return {
        length: 0,
        first() { return this; },
        last() { return this; },
        after() { return this; },
        before() { return this; },
        append() { return this; },
        appendTo() { return this; },
        on() { return this; },
        remove() { return this; }
    };
}

const response = {
    clone() {
        return {
            json() {
                return Promise.resolve({ success: true, data: { operation_id: 'fetch-split-1' } });
            }
        };
    }
};
fetchResponse = response;

const context = {
    window: {
        localStorage,
        wcosPremiumUpsellTestHooks: hooks,
        wcosPremiumUpsell: {
            productUrl: 'https://yoohw.com/product/woocommerce-advanced-order-actions/',
            thresholds: { split: 3, duplicate: 2, merge: 2 },
            executeActions: { wcos_split_execute: 'split' },
            actionTips: {}
        },
        fetch() {
            fetchCalls += 1;
            return Promise.resolve(fetchResponse);
        }
    },
    document: {},
    jQuery: jqueryStub,
    URLSearchParams,
    FormData: typeof FormData === 'undefined' ? function FormData() {} : FormData,
    isFinite,
    Math,
    JSON,
    Array,
    String,
    Object,
    parseInt,
    decodeURIComponent,
    console
};
context.window.window = context.window;

vm.createContext(context);
vm.runInContext(source, context, { filename: 'post-action-tip.js' });

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

async function run() {
    assert(typeof hooks.observePayload === 'function', 'observePayload test hook missing');
    assert(typeof hooks.readState === 'function', 'readState test hook missing');
    assert(typeof hooks.dismissSplitHint === 'function', 'dismissSplitHint test hook missing');

    assert(hooks.readState().hints.splitRoutingDismissed === false, 'Split hint must be visible until it is dismissed');
    hooks.dismissSplitHint();
    assert(hooks.readState().hints.splitRoutingDismissed === true, 'Split hint dismissal must persist in local state');

    hooks.observePayload('split', { success: true, data: { operation_id: 'split-1' } });
    hooks.observePayload('split', { success: true, data: { operation_id: 'split-2' } });

    let state = hooks.readState();
    assert(state.usage.split === 2, 'Split should not qualify before the third unique success');
    assert(!state.pending.split, 'Split promotion must not be pending before threshold');

    hooks.observePayload('split', { success: false, data: { operation_id: 'split-failed' } });
    state = hooks.readState();
    assert(state.usage.split === 2, 'Failed Split must not increment usage');

    hooks.observePayload('split', { success: true, data: { operation_id: 'split-3' } });
    state = hooks.readState();
    assert(state.usage.split === 3, 'Third unique successful Split must reach threshold');
    assert(state.pending.split === true, 'Third unique successful Split must queue later-page promotion');
    assert(state.shown.split === false, 'Promotion must not be marked shown during the completing operation');

    hooks.observePayload('split', { success: true, data: { operation_id: 'split-3' } });
    state = hooks.readState();
    assert(state.usage.split === 3, 'Replay of an operation ID must not increment usage');

    hooks.observePayload('duplicate', { success: true, data: { operation_id: 'dup-1' } });
    assert(!hooks.readState().pending.duplicate, 'Duplicate must not qualify on first success');
    hooks.observePayload('duplicate', { success: true, data: { operation_id: 'dup-2' } });
    state = hooks.readState();
    assert(state.usage.duplicate === 2 && state.pending.duplicate === true, 'Duplicate must qualify on second unique success');

    hooks.observePayload('merge', { success: true, data: { operation_id: 'merge-1' } });
    assert(!hooks.readState().pending.merge, 'Merge must not qualify on first success');
    hooks.observePayload('merge', { success: true, data: { operation_id: 'merge-2' } });
    state = hooks.readState();
    assert(state.usage.merge === 2 && state.pending.merge === true, 'Merge must qualify on second unique success');
    assert(hooks.nextPendingAction(state) === 'split', 'Pending action order must be deterministic');

    hooks.markCampaignShown(state, 'split');
    assert(state.shown.split === true && state.pending.split === false, 'Rendering must consume the pending Split campaign exactly once');
    assert(hooks.nextPendingAction(state) === 'duplicate', 'A shown campaign must not become eligible again');

    hooks.dismissCampaign('merge');
    state = hooks.readState();
    assert(state.dismissed.merge === true && state.pending.merge === false, 'Dismissal must persist in local campaign state');

    const originalResponse = await context.window.fetch('/admin-ajax.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'wcos_split_execute' })
    });
    await Promise.resolve();
    await Promise.resolve();

    assert(fetchCalls === 1, 'Fetch observer must issue exactly the original request once');
    assert(originalResponse === response, 'Fetch observer must return the original response');
    assert(hooks.readState().usage.split === 4, 'Successful observed fetch must record one unique operation');

    const cloneFailureResponse = {
        clone() {
            throw new Error('clone unavailable');
        }
    };
    fetchResponse = cloneFailureResponse;
    const cloneFailureResult = await context.window.fetch('/admin-ajax.php', {
        method: 'POST',
        body: new URLSearchParams({ action: 'wcos_split_execute' })
    });
    assert(cloneFailureResult === cloneFailureResponse, 'Clone failures must not reject or replace the mutation response');
    assert(fetchCalls === 2, 'Clone failure path must still issue exactly one original request');

    console.log('premium-upsell-state: ok');
}

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
