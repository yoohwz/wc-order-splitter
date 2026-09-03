'use strict';

const assert = require('node:assert/strict');
const { harness } = require('./helpers/upsell-dom');

for (const [action, threshold] of Object.entries({ split: 3, duplicate: 2, merge: 2, return: 2 })) {
    const h = harness();
    for (let i = 1; i < threshold; i++) {
        const result = h.emit(action, 'operation-' + i);
        assert.equal(h.read().usage[action], i);
        assert.equal(result.querySelector('.wcos-modal-upsell'), null, action + ' must not advertise early');
        h.emit(action, 'operation-' + i);
        assert.equal(h.read().usage[action], i, 'Replay must not increment');
    }
    const m = h.modal(action);
    h.emit(action, 'threshold', 'completed', m.result);
    const card = m.result.querySelector('.wcos-completed-upsell');
    assert.ok(card, action + ' must render at its threshold');
    assert.equal(m.result.children.at(-1), card, 'Banner follows all operation evidence and continuation');
    assert.equal(m.result.children[0], m.outcome);
    assert.equal(m.result.children[1], m.continuation);
    assert.equal(m.footer.querySelector('.wcos-modal-upsell'), null, 'No footer CTA');
    assert.equal(m.close.disabled, undefined, 'Close remains independently usable');
    assert.equal(card.querySelector('a').href, h.config.productUrl);
    assert.equal(card.querySelector('a').rel, 'noopener noreferrer');
    assert.equal(card.querySelector('p').textContent, h.config.actionTips[action]);
    assert.equal(h.read().shown[action], true);
    h.emit(action, 'threshold', 'completed', m.result);
    assert.equal(m.result.querySelectorAll('.wcos-modal-upsell').length, 1);
    card.querySelector('button').click();
    assert.equal(h.read().dismissed[action], true);
    assert.equal(m.result.querySelector('.wcos-modal-upsell'), null);
    assert.equal(h.document.activeElement, m.result);
    for (let i = 0; i < 100; i++) h.emit(action, 'later-' + i);
    assert.equal(h.read().usage[action], threshold, 'Usage saturates without replay-ID eviction');
    assert.equal(h.read().seenOperations.length, threshold, 'Retained IDs stay bounded');
    assert.equal(h.emit(action, 'operation-1').querySelector('.wcos-modal-upsell'), null);
    const nextPage = harness({ storage: h.storage });
    assert.equal(nextPage.document.body.children.length, 0, 'No later-page duplicate advertising');
    assert.equal(nextPage.emit(action, 'new-page-success').querySelector('.wcos-modal-upsell'), null);
    assert.equal(h.window.fetch, h.originalFetch, 'Mutation transport must not be wrapped/replaced');
}

for (const action of ['split', 'duplicate', 'merge', 'return']) {
    for (const status of [undefined, null, '', 'reviewed', 'confirmed', 'executing', 'busy', 'failed', 'retry_required', 'replayed', 'recovery_required', 'recovery_pending', 'compensating', 'compensated', 'manual_reconciliation']) {
        const h = harness();
        // Explicit detail allows a missing status (emit has a completed default).
        const m = h.modal(action);
        m.result.dispatchEvent({ type: 'wcos:operation-completed', bubbles: true, detail: { action, operationId: 'rejected', status } });
        assert.equal(h.read().usage[action], 0, action + '/' + status + ' cannot count');
        assert.equal(m.result.querySelector('.wcos-modal-upsell'), null);
    }
    for (const unsafe of ['hidden', 'detached', 'busy', 'footer', 'wrong-result', 'empty-result', 'outside-modal']) {
        const h = harness();
        const m = h.modal(action);
        if (unsafe === 'hidden') m.result.hidden = true;
        if (unsafe === 'detached') m.root.remove();
        if (unsafe === 'busy') m.root.setAttribute('aria-busy', 'true');
        if (unsafe === 'footer') m.footer.appendChild(m.result);
        if (unsafe === 'wrong-result') m.result.className = 'wcos-review';
        if (unsafe === 'empty-result') m.result.textContent = '';
        if (unsafe === 'outside-modal') h.document.body.appendChild(m.result);
        h.emit(action, 'unsafe', 'completed', m.result);
        assert.equal(h.read().usage[action], 0, unsafe + ' cannot count');
        assert.equal(m.result.querySelector('.wcos-modal-upsell'), null);
    }
}
const scoped = harness();
scoped.emit('split', 'shared-id');
scoped.emit('duplicate', 'shared-id');
assert.equal(scoped.read().usage.split, 1);
assert.equal(scoped.read().usage.duplicate, 1, 'Deduplication is action scoped');
scoped.emit('bulk_return', 'excluded');
assert.equal(scoped.read().usage.bulk_return, undefined);
for (const id of ['', 'contains@email.example', 'private data', 'x'.repeat(101), 123, null]) {
    scoped.emit('return', id);
}
assert.equal(scoped.read().usage.return, 0, 'Malformed IDs / PII are not retained');

function chooser(h) {
    const m = h.modal('split', true);
    m.result.remove();
    const options = m.body.appendChild(h.document.createElement('div'));
    options.className = 'wcos-split-method-options';
    const methods = ['By quantity', 'By category', 'By stock status'].map((label) => {
        const button = options.appendChild(h.document.createElement('button'));
        button.className = 'wcos-split-method-option';
        button.textContent = label;
        return button;
    });
    m.body.dispatchEvent({ type: 'wcos:split-method-chooser', bubbles: true });
    return { ...m, methods };
}
const h = harness();
const choice = chooser(h);
choice.body.dispatchEvent({ type: 'wcos:split-method-chooser', bubbles: true });
assert.equal(choice.body.querySelectorAll('.wcos-modal-upsell').length, 1);
assert.equal(choice.footer.querySelector('.wcos-modal-upsell'), null);
assert.equal(choice.body.children[0].className, 'wcos-split-method-options');
choice.methods.forEach((button) => assert.equal(button.disabled, undefined));
choice.body.querySelector('.wcos-modal-upsell-dismiss').click();
assert.equal(h.read().hints.splitRoutingDismissed, true);
assert.equal(h.document.activeElement, choice.methods[0]);
assert.equal(chooser(h).body.querySelector('.wcos-modal-upsell'), null);
assert.equal(chooser(harness({ storage: h.storage })).body.querySelector('.wcos-modal-upsell'), null);

const migrated = new Map([['wcosPremiumUpsellStateV1', JSON.stringify({
    usage: { split: 999, duplicate: 2 }, shown: { duplicate: true }, pending: { split: true },
    seenOperations: ['split:legacy-1', 'private@example.com'], hints: { splitRoutingDismissed: true }, customer: 'must be discarded'
})]]);
const migration = harness({ storage: migrated });
assert.equal(migration.read().usage.split, 3);
assert.equal(migration.read().shown.duplicate, true);
assert.deepEqual(migration.read().seenOperations, ['split:legacy-1']);
assert.equal(migration.read().pending, undefined, 'Obsolete later-page queue is removed');
assert.equal(migration.read().customer, undefined);
assert.equal(migration.document.body.children.length, 0);
assert.equal(chooser(migration).body.querySelector('.wcos-modal-upsell'), null);

for (const options of [{ denyRead: true }, { denyWrite: true }, { config: { productUrl: 'https://yoohw.com/product/woocommerce-advanced-order-actions/?utm_source=bad' } }]) {
    const limited = harness(options);
    assert.equal(chooser(limited).body.querySelector('.wcos-modal-upsell'), null);
    limited.emit('duplicate', 'one');
    assert.equal(limited.emit('duplicate', 'two').querySelector('.wcos-modal-upsell'), null, 'Fail closed without local limits/canonical URL');
}
const malformed = harness({ storage: new Map([['wcosPremiumUpsellStateV1', '{bad json']]) });
assert.equal(malformed.read().usage.split, 0);
const brokenDom = harness();
brokenDom.emit('return', 'one');
const m = brokenDom.modal('return');
const append = m.result.appendChild;
m.result.appendChild = () => { throw new Error('Presentation render failure'); };
assert.doesNotThrow(() => brokenDom.emit('return', 'two', 'completed', m.result));
assert.equal(m.result.children[0], m.outcome);
assert.equal(m.result.children[1], m.continuation);
assert.equal(m.footer.children[0], m.close);
m.result.appendChild = append;

console.log('premium-upsell-state: ok (four campaigns, unsafe states, replay, migration, bounded storage, isolated presentation)');
