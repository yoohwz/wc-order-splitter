'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const client = fs.readFileSync(path.join(root, 'js/p2-return-admin.js'), 'utf8');

function functionBody(name, nextName) {
    const start = client.indexOf('function ' + name + '(');
    const end = client.indexOf('function ' + nextName + '(', start);
    assert.notEqual(start, -1, name + ' must exist');
    assert.notEqual(end, -1, nextName + ' must follow ' + name);
    return client.slice(start, end);
}

function assertOrdered(source, needles, message) {
    let offset = -1;
    needles.forEach((needle) => {
        const next = source.indexOf(needle, offset + 1);
        assert.notEqual(next, -1, message + ': missing ' + needle);
        assert.ok(next > offset, message + ': wrong order for ' + needle);
        offset = next;
    });
}

const controls = functionBody('updateControls', 'setBusy');
assert.match(controls, /confirmCheckbox\.disabled = busy \|\| 'reviewed' !== phase;/,
    'Review acknowledgement must remain disabled while a request is in flight');
assert.match(controls, /executeButton\.disabled = busy \|\| \('confirmed' !== phase && 'executing' !== phase\) \|\| !confirmationAuthority;/,
    'Execute must remain disabled while a request is in flight');
assert.match(controls, /cancelButton\.disabled = busy;/,
    'Cancel must remain disabled while a request is in flight');
assert.match(controls, /closeButton\.disabled = busy;/,
    'Header close must remain disabled while a request is in flight');

const review = functionBody('reviewReturn', 'confirmReturn');
assertOrdered(review, [
    'setBusy(true);',
    'request(',
    "setPhase('reviewed');",
    '}).finally(function () {',
    'setBusy(false);',
    "if ('reviewed' === phase && reviewAuthority) {",
    'confirmCheckbox.focus();'
], 'Successful Review must clear busy before focusing the enabled acknowledgement');
assert.equal((review.match(/confirmCheckbox\.focus\(\);/g) || []).length, 1,
    'Review must have exactly one success-focus site');
assertOrdered(review, [
    '}).catch(function (error) {',
    'resetForExplicitReview();',
    'showError(error.message);',
    '}).finally(function () {'
], 'Review failure must reset authority and focus the error surface before cleanup');

const confirm = functionBody('confirmReturn', 'executeSameOperation');
assertOrdered(confirm, [
    'setBusy(true);',
    'request(',
    'confirmationAuthority = { operationId: data.operation_id, token: data.confirmation_token };',
    "setPhase('confirmed');",
    '}).finally(function () {',
    'setBusy(false);',
    "if ('confirmed' === phase && confirmationAuthority) {",
    'executeButton.focus();'
], 'Successful Confirm must clear busy before focusing enabled Execute');
assert.equal((confirm.match(/executeButton\.focus\(\);/g) || []).length, 1,
    'Confirm must have exactly one success-focus site');
assertOrdered(confirm, [
    '}).catch(function (error) {',
    'reviewAuthority = null;',
    'confirmationAuthority = null;',
    "setPhase('closed');",
    'showError(error.message);',
    '}).finally(function () {'
], 'Confirm failure must close authority and focus the error surface before cleanup');

assert.match(client, /function showError\(message\)[\s\S]*errorBox\.focus\(\);/,
    'Failure paths must focus the modal-local error surface');
assert.match(client, /if \('Escape' === event\.key && busy\) \{[\s\S]*event\.preventDefault\(\);[\s\S]*event\.stopImmediatePropagation\(\);/,
    'Busy Escape protection must remain intact');

console.log('p2-return-focus-lifecycle-ok');
