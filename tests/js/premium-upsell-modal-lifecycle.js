'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { harness } = require('./helpers/upsell-dom');

// Execute the actual production lifecycle functions, not a second client model.
function extract(source, name) {
    const match = new RegExp('^([ \\t]*)function ' + name + '\\(', 'm').exec(source);
    assert.ok(match, name + ' exists');
    const start = match.index;
    let position = source.indexOf('{', start), depth = 0, quote = '', comment = '';
    for (; position < source.length; position++) {
        const char = source[position], next = source[position + 1];
        if (comment === '//') { if (char === '\n') comment = ''; continue; }
        if (comment === '/*') { if (char === '*' && next === '/') { comment = ''; position++; } continue; }
        if (quote) { if (char === '\\') position++; else if (char === quote) quote = ''; continue; }
        if (char === '"' || char === "'") { quote = char; continue; }
        if (char === '/' && (next === '/' || next === '*')) { comment = char + next; position++; continue; }
        if (char === '{') depth++;
        if (char === '}' && --depth === 0) return source.slice(start, position + 1);
    }
    throw new Error('Unclosed function ' + name);
}

const cases = [
    ['split', 'split', 'executePlan'],
    ['split-strategy', 'split', 'executeStrategy'],
    ['duplicate', 'duplicate', 'executeDuplicate'],
    ['merge', 'merge', 'confirmAndMerge'],
    ['return', 'return', 'executeReturn']
];

async function runCase(fileAction, action, execute, outcome, presentationMode = 'normal') {
    const source = fs.readFileSync(path.join(__dirname, '../../js/p2-' + fileAction + '-admin.js'), 'utf8');
    const h = harness();
    for (let i = 1; i < h.config.thresholds[action]; i++) h.emit(action, 'previous-' + i);
    const m = h.modal(fileAction === 'split-strategy' ? 'strategy' : action);
    m.result.hidden = true;
    m.result.textContent = '';
    const control = () => m.footer.appendChild(h.document.createElement('button'));
    const events = [], requests = [], failures = [];
    h.document.addEventListener('wcos:operation-completed', (event) => {
        assert.equal(h.context.busy, false);
        if (action === 'return') assert.equal(h.context.phase, 'completed');
        else assert.equal(h.context.completed, true);
        assert.ok(m.result.firstElementChild, 'Success result precedes notification');
        assert.equal(m.result.hidden, false);
        assert.deepEqual(Object.keys(event.detail).sort(), ['action', 'operationId', 'status']);
        events.push(event.detail);
    });
    if (presentationMode === 'absent') h.document.listeners['wcos:operation-completed'] = [];
    if (presentationMode === 'throws') m.result.dispatchEvent = () => { throw new Error('Presentation failed'); };
    const authority = { operationId: 'current-operation', token: 'unchanged-token' };
    const attributes = {
        'data-order-id': '10', 'data-source-order-id': '10', 'data-child-order-id': '10',
        'data-nonce': 'unchanged-nonce', 'data-execute-action': 'wcos_' + action + '_execute',
        'data-confirm-action': 'wcos_' + action + '_confirm', 'data-strategy': 'category'
    };
    const context = h.context;
    Object.assign(context, {
        busy: false, completed: false, completedPresentation: null,
        phase: 'confirmed', state: authority, confirmationState: authority,
        confirmationAuthority: authority, reviewState: {}, reviewAuthority: { reviewId: 'review-id', token: 'review-token' },
        selectedBucket: 'bucket', selectedTarget: '20', retryReady: false,
        dialog: m.root, form: m.body, resultBox: m.result,
        sourceDialog: { getAttribute: (key) => attributes[key] || '' },
        reviewButton: control(), confirmButton: control(), executeButton: control(),
        cancelButton: control(), closeButton: control(), editButton: control(), targetSelect: control(),
        confirmCheckbox: control(), reviewBox: control(),
        text: (key, fallback) => fallback,
        canBuildPlan: () => true, updateChildToolbar() {}, updateReviewAvailability() {},
        clearError() {}, clearResult() { m.result.textContent = ''; m.result.hidden = true; },
        showError(message) { failures.push(message); }, setStatus() {}, hideWorkflowActions() {},
        invalidateReview() {}, showExecuteAction() {}, focusableElements: () => [m.close], focusDialogFallback() {},
        $: () => ({ prop() {} }),
        CustomEvent: class { constructor(type, options) { this.type = type; Object.assign(this, options); } },
        request(...args) {
            requests.push({ action: args.at(-2), data: args.at(-1) });
            if (outcome === 'failure' || outcome === 'retry') {
                const error = new Error(outcome);
                error.retryable = outcome === 'retry';
                return Promise.reject(error);
            }
            return Promise.resolve({
                operation_id: 'current-operation', status: outcome,
                target: { id: 20, edit_url: '/target' }, original: { id: 30, edit_url: '/original' },
                children: [{ id: 40, edit_url: '/child' }]
            });
        }
    });
    context.confirmCheckbox.checked = true;
    const names = ['setBusy', 'renderSuccess', execute];
    if (action === 'merge') names.push('executeSameOperation', 'finishExecute', 'handleExecuteFailure');
    if (action === 'return') names.push('executeSameOperation', 'setPhase', 'updateControls');
    vm.runInContext(names.map((name) => extract(source, name)).join('\n'), context);
    vm.runInContext(execute + '()', context);
    assert.equal(m.result.querySelector('.wcos-modal-upsell'), null, 'No ad while executing');
    assert.equal(context.busy, true);
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(context.busy, false);
    assert.equal(requests.length, 1, 'Exactly the existing Execute request is issued');
    assert.equal(requests[0].data.operation_id, authority.operationId);
    assert.equal(requests[0].data.confirmation_token, authority.token);
    assert.equal(requests[0].data.nonce, 'unchanged-nonce');
    assert.equal(m.footer.querySelector('.wcos-modal-upsell'), null);
    assert.equal(context.cancelButton.disabled, false);
    assert.equal(context.closeButton.disabled, false);
    const card = m.result.querySelector('.wcos-completed-upsell');
    if (outcome === 'completed') {
        assert.equal(failures.length, 0, 'Optional presentation must not convert success into failure');
        assert.equal(m.result.hidden, false);
        assert.ok(m.result.textContent.includes('successfully') || m.result.textContent.includes('Return completed'));
        assert.ok(m.result.querySelector('a'), 'Operational order link remains available');
        if (presentationMode === 'normal') {
            assert.equal(events.length, 1);
            assert.ok(card, fileAction + ' integrates the completed campaign');
            assert.equal(h.read().usage[action], h.config.thresholds[action]);
        } else assert.equal(card, null);
    } else {
        assert.equal(card, null, 'Failure/nonterminal state must not advertise');
        assert.equal(h.read().usage[action], h.config.thresholds[action] - 1);
        if (outcome === 'failure' || outcome === 'retry') assert.equal(events.length, 0);
    }
}

(async () => {
    for (const [fileAction, action, execute] of cases) {
        for (const outcome of ['completed', 'failure', 'retry', 'recovery_pending', 'compensated', 'manual_reconciliation']) {
            await runCase(fileAction, action, execute, outcome);
        }
        await runCase(fileAction, action, execute, 'completed', 'absent');
        await runCase(fileAction, action, execute, 'completed', 'throws');
    }
    console.log('premium-upsell-modal-lifecycle: ok (five real clients; completion, failure, recovery, absent/throwing presentation)');
})().catch((error) => { console.error(error); process.exit(1); });
