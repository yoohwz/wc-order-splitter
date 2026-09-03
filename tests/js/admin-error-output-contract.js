'use strict';

// WOS-REL-005: internal exception policy never permits raw HTML error sinks.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../..');
const clients = [
    'p2-split-admin.js', 'p2-duplicate-admin.js', 'p2-merge-admin.js',
    'p2-return-admin.js', 'p2-bulk-return-admin.js', 'p2-split-strategy-admin.js'
];

function requireTextOnlySource(source, name) {
    // Include computed literal access and equivalent DOM HTML parsers, not just
    // the spelling `.innerHTML =`. No source-level exception list is allowed.
    assert.doesNotMatch(source,
        /\b(?:innerHTML|outerHTML|insertAdjacentHTML|createContextualFragment|parseFromString|setHTMLUnsafe|setHTML|srcdoc)\b/,
        name + ': raw DOM HTML sink returned');
    assert.doesNotMatch(source, /(?:\.\s*html\b|\[\s*['"]html['"]\s*\])/,
        name + ': jQuery HTML sink returned');
    assert.doesNotMatch(source,
        /(?:\$|jQuery)\s*\([^)]*\)\s*\.\s*(?:append|prepend|before|after|replaceWith|wrap|wrapAll|wrapInner)\s*\(/,
        name + ': jQuery HTML insertion returned');
    assert.doesNotMatch(source, /\bdocument\s*(?:\.\s*(?:write|writeln)|\[\s*['"](?:write|writeln)['"]\s*\])\s*\(/,
        name + ': document HTML sink returned');
}

function exerciseErrorSink(fn, message) {
    const written = { hidden: true, focusCount: 0 };
    const errorBox = new Proxy({ focus() { written.focusCount++; } }, {
        set(target, key, value) {
            assert.ok(key === 'textContent' || key === 'hidden', 'Error UI wrote non-text property: ' + String(key));
            written[key] = value;
            return true;
        }
    });
    // Execute the real production showError function, not a test reimplementation.
    vm.runInNewContext('(' + fn + ')(serverMessage);', {
        errorBox, serverMessage: message, text: (key, fallback) => fallback,
        message: (key, fallback) => fallback
    }, { timeout: 1000 });
    assert.equal(written.textContent, message, 'Server error must stay literal text, without premature HTML encoding');
    assert.equal(written.hidden, false);
    assert.equal(written.focusCount, 1);
}

const attack = '<img src=x onerror="alert(1)"><script>alert(2)</script> & "quoted" \'text\'';
const payload = JSON.parse(JSON.stringify({ success: false, data: { message: attack } }));
for (const name of clients) {
    const source = fs.readFileSync(path.join(root, 'js', name), 'utf8');
    requireTextOnlySource(source, name);
    assert.match(source, /new Error\(failure\.message\s*\|\|/, name + ': JSON error-message transport changed');
    assert.match(source, /showError\(error\.message\)/, name + ': caught server errors no longer reach the tested sink');
    const functions = [...source.matchAll(/function showError\(\w+\)\s*\{[^{}]*\}/g)];
    assert.equal(functions.length, 1, name + ': expected one auditable error presentation boundary');
    exerciseErrorSink(functions[0][0], payload.data.message);
}
requireTextOnlySource(fs.readFileSync(path.join(root, 'js/p2-backbone-modal.js'), 'utf8'), 'shared modal bridge');

// Prove the contract rejects the raw sinks it promises to guard.
for (const unsafe of [
    'errorBox.innerHTML = message;', 'errorBox["innerHTML"] = message;',
    'errorBox.outerHTML = message;', 'errorBox.insertAdjacentHTML("beforeend", message);',
    '$(errorBox).html(message);', '$(errorBox)["html"](message);',
    '$(errorBox).append(message);', 'jQuery(errorBox).replaceWith(message);',
    'range.createContextualFragment(message);', 'parser.parseFromString(message, "text/html");',
    'errorBox.setHTMLUnsafe(message);', 'document.write(message);'
]) {
    assert.throws(() => requireTextOnlySource(unsafe, 'negative fixture'));
}
assert.throws(() => exerciseErrorSink('function showError(value) { errorBox["innerHTML"] = value; }', attack));
assert.throws(() => exerciseErrorSink('function showError(value) { errorBox.textContent = "&lt;encoded&gt;"; }', attack));
console.log('admin-error-output-contract-ok: six JSON/text error boundaries; raw HTML negative fixtures rejected');
