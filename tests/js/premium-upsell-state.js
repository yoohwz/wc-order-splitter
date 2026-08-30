const fs = require('fs');
const vm = require('vm');
const path = require('path');

const source = fs.readFileSync(path.join(__dirname, '../../js/post-action-tip.js'), 'utf8');
const storage = new Map();
const hooks = {};

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
        ajaxSuccess() { return this; },
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

const context = {
    window: {
        localStorage,
        wcosPremiumUpsellTestHooks: hooks,
        wcosPremiumUpsell: {
            productUrl: 'https://yoohw.com/product/woocommerce-advanced-order-actions/',
            thresholds: { split: 3, duplicate: 2, merge: 2 },
            executeActions: {},
            actionTips: {}
        },
        fetch() {
            return Promise.reject(new Error('fetch should not be called by this state test'));
        }
    },
    document: {},
    jQuery: jqueryStub,
    URLSearchParams,
    FormData: typeof FormData === 'undefined' ? function FormData() {} : FormData,
    isFinite,
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

assert(typeof hooks.observePayload === 'function', 'observePayload test hook missing');
assert(typeof hooks.readState === 'function', 'readState test hook missing');

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
state = hooks.readState();
assert(!state.pending.duplicate, 'Duplicate must not qualify on first success');
hooks.observePayload('duplicate', { success: true, data: { operation_id: 'dup-2' } });
state = hooks.readState();
assert(state.usage.duplicate === 2 && state.pending.duplicate === true, 'Duplicate must qualify on second unique success');

hooks.observePayload('merge', { success: true, data: { operation_id: 'merge-1' } });
state = hooks.readState();
assert(!state.pending.merge, 'Merge must not qualify on first success');
hooks.observePayload('merge', { success: true, data: { operation_id: 'merge-2' } });
state = hooks.readState();
assert(state.usage.merge === 2 && state.pending.merge === true, 'Merge must qualify on second unique success');

assert(hooks.nextPendingAction(state) === 'split', 'Pending action order must be deterministic');

console.log('premium-upsell-state: ok');
