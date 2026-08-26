'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

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
assert.match(client, /root\.addEventListener\('keydown',[\s\S]*trapFocus\(event\);/,
    'Return focus trap must be scoped to the current modal root');
assert.doesNotMatch(client, /document\.addEventListener\('keydown'/,
    'Return focus trap must not install a document-global listener');
assert.equal((client.match(/window\.WCOSBackboneModal\.open/g) || []).length, 1,
    'Return must continue to use exactly one shared modal entry point');

class ClassList {
    constructor(element) {
        this.element = element;
    }

    add(...tokens) {
        tokens.forEach((token) => this.element.classes.add(token));
    }

    contains(token) {
        return this.element.classes.has(token);
    }

    toString() {
        return Array.from(this.element.classes).join(' ');
    }
}

class Element {
    constructor(tagName, ownerDocument) {
        this.tagName = String(tagName).toUpperCase();
        this.ownerDocument = ownerDocument;
        this.parentNode = null;
        this.children = [];
        this.attributes = new Map();
        this.classes = new Set();
        this.classList = new ClassList(this);
        this.listeners = new Map();
        this.hidden = false;
        this.disabled = false;
        this.checked = false;
        this._textContent = '';
    }

    get className() {
        return this.classList.toString();
    }

    set className(value) {
        this.classes = new Set(String(value).split(/\s+/).filter(Boolean));
    }

    get textContent() {
        return this._textContent + this.children.map((child) => child.textContent).join('');
    }

    set textContent(value) {
        this._textContent = String(value);
        this.children.forEach((child) => { child.parentNode = null; });
        this.children = [];
    }

    get isConnected() {
        let current = this;
        while (current) {
            if (current === this.ownerDocument.body) {
                return true;
            }
            current = current.parentNode;
        }
        return false;
    }

    appendChild(child) {
        if (child.parentNode) {
            child.parentNode.removeChild(child);
        }
        child.parentNode = this;
        this.children.push(child);
        return child;
    }

    removeChild(child) {
        const index = this.children.indexOf(child);
        assert.notEqual(index, -1, 'removeChild target must belong to the parent');
        this.children.splice(index, 1);
        child.parentNode = null;
        return child;
    }

    setAttribute(name, value) {
        this.attributes.set(String(name), String(value));
        if (name === 'class') {
            this.className = value;
        }
    }

    getAttribute(name) {
        return this.attributes.has(name) ? this.attributes.get(name) : null;
    }

    removeAttribute(name) {
        this.attributes.delete(name);
    }

    addEventListener(type, listener) {
        if (!this.listeners.has(type)) {
            this.listeners.set(type, []);
        }
        this.listeners.get(type).push(listener);
    }

    dispatchEvent(event) {
        if (!event.target) {
            event.target = this;
        }
        let current = this;
        while (current) {
            event.currentTarget = current;
            const listeners = current.listeners.get(event.type) || [];
            for (const listener of listeners) {
                listener.call(current, event);
                if (event.immediatePropagationStopped) {
                    break;
                }
            }
            if (event.propagationStopped) {
                break;
            }
            current = current.parentNode;
        }
        return !event.defaultPrevented;
    }

    click() {
        if (this.disabled) {
            return;
        }
        this.focus();
        this.dispatchEvent(keyEvent('click'));
    }

    focus() {
        if (!this.disabled) {
            this.ownerDocument.activeElement = this;
        }
    }

    cloneNode(deep) {
        const clone = new Element(this.tagName, this.ownerDocument);
        this.attributes.forEach((value, name) => clone.setAttribute(name, value));
        clone.className = this.className;
        clone.hidden = this.hidden;
        clone.disabled = this.disabled;
        clone.checked = this.checked;
        clone._textContent = this._textContent;
        if (deep) {
            this.children.forEach((child) => clone.appendChild(child.cloneNode(true)));
        }
        return clone;
    }

    contains(candidate) {
        let current = candidate;
        while (current) {
            if (current === this) {
                return true;
            }
            current = current.parentNode;
        }
        return false;
    }

    closest(selector) {
        let current = this;
        while (current) {
            if (matchesSimple(current, selector)) {
                return current;
            }
            current = current.parentNode;
        }
        return null;
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] || null;
    }

    querySelectorAll(selector) {
        const descendants = [];
        const visit = (element) => {
            element.children.forEach((child) => {
                descendants.push(child);
                visit(child);
            });
        };
        visit(this);
        const selectors = selector.split(',').map((value) => value.trim()).filter(Boolean);
        return descendants.filter((element) => selectors.some((candidate) => matchesSelector(element, candidate, this)));
    }
}

function matchesSimple(element, selector) {
    if (selector.startsWith('.')) {
        return element.classList.contains(selector.slice(1));
    }
    const attributeMatch = selector.match(/^([a-z]+)?\[([^=\]]+)(?:="([^"]*)")?\]$/i);
    if (attributeMatch) {
        const tagMatches = !attributeMatch[1] || element.tagName === attributeMatch[1].toUpperCase();
        const attributeValue = element.getAttribute(attributeMatch[2]);
        return tagMatches && attributeValue !== null && (attributeMatch[3] === undefined || attributeValue === attributeMatch[3]);
    }
    return element.tagName === selector.toUpperCase();
}

function matchesSelector(element, selector, boundary) {
    const parts = selector.split(/\s+/).filter(Boolean);
    if (!parts.length || !matchesSimple(element, parts[parts.length - 1])) {
        return false;
    }
    let ancestor = element.parentNode;
    for (let index = parts.length - 2; index >= 0; index -= 1) {
        while (ancestor && ancestor !== boundary.parentNode && !matchesSimple(ancestor, parts[index])) {
            ancestor = ancestor.parentNode;
        }
        if (!ancestor || ancestor === boundary.parentNode) {
            return false;
        }
        ancestor = ancestor.parentNode;
    }
    return true;
}

class Document {
    constructor() {
        this.body = new Element('body', this);
        this.activeElement = this.body;
    }

    createElement(tagName) {
        return new Element(tagName, this);
    }

    getElementById(id) {
        return this.body.querySelectorAll('[id]').find((element) => element.getAttribute('id') === id) || null;
    }

    querySelector(selector) {
        return this.body.querySelector(selector);
    }
}

function element(document, tagName, className, textContent = '') {
    const node = document.createElement(tagName);
    node.className = className || '';
    node._textContent = textContent;
    return node;
}

function keyEvent(type, values = {}) {
    return Object.assign({
        type,
        key: '',
        shiftKey: false,
        defaultPrevented: false,
        propagationStopped: false,
        immediatePropagationStopped: false,
        preventDefault() { this.defaultPrevented = true; },
        stopPropagation() { this.propagationStopped = true; },
        stopImmediatePropagation() {
            this.immediatePropagationStopped = true;
            this.propagationStopped = true;
        }
    }, values);
}

function pressTab(element, shiftKey = false) {
    const event = keyEvent('keydown', { key: 'Tab', shiftKey });
    element.dispatchEvent(event);
    return event;
}

function buildSource(document) {
    const launcher = element(document, 'button', 'wcos-return-launcher', 'Return to original order');
    launcher.setAttribute('aria-controls', 'wcos-return-dialog-test');
    launcher.setAttribute('aria-describedby', 'wcos-return-launcher-description');
    const launcherDescription = element(document, 'span', '', 'Open Return');
    launcherDescription.setAttribute('id', 'wcos-return-launcher-description');

    const sourceDialog = element(document, 'div', 'wcos-return-dialog');
    sourceDialog.setAttribute('id', 'wcos-return-dialog-test');
    sourceDialog.setAttribute('data-child-order-id', '202');
    sourceDialog.setAttribute('data-nonce', 'test-nonce');
    sourceDialog.setAttribute('data-ajax-url', '/wp-admin/admin-ajax.php');
    sourceDialog.setAttribute('data-review-action', 'wcos_return_review');
    sourceDialog.setAttribute('data-confirm-action', 'wcos_return_confirm');
    sourceDialog.setAttribute('data-execute-action', 'wcos_return_execute');

    const panel = element(document, 'div', 'wcos-return-dialog__panel');
    const header = element(document, 'div', 'wcos-return-dialog__header');
    header.appendChild(element(document, 'h2', '', 'Return to original order'));
    header.appendChild(element(document, 'p', '', 'Server-resolved original'));
    panel.appendChild(header);
    panel.appendChild(element(document, 'div', 'wcos-return-policy', 'Return safety policy'));

    const review = element(document, 'div', 'wcos-return-review');
    review.hidden = true;
    review.appendChild(element(document, 'dl', 'wcos-return-review-summary'));
    const label = element(document, 'label', 'wcos-return-confirm-label');
    const checkbox = element(document, 'input', 'wcos-return-confirm-checkbox');
    checkbox.setAttribute('type', 'checkbox');
    label.appendChild(checkbox);
    review.appendChild(label);
    panel.appendChild(review);

    const status = element(document, 'div', 'wcos-return-status');
    status.setAttribute('tabindex', '-1');
    panel.appendChild(status);
    const error = element(document, 'div', 'wcos-return-error');
    error.setAttribute('tabindex', '-1');
    error.hidden = true;
    panel.appendChild(error);
    const result = element(document, 'div', 'wcos-return-result');
    result.setAttribute('tabindex', '-1');
    result.hidden = true;
    panel.appendChild(result);

    const actions = element(document, 'div', 'wcos-return-dialog__actions');
    actions.appendChild(element(document, 'button', 'button wcos-return-cancel', 'Close'));
    actions.appendChild(element(document, 'button', 'button wcos-return-review-button', 'Review return'));
    const confirm = element(document, 'button', 'button wcos-return-confirm-button', 'Confirm return');
    confirm.hidden = true;
    confirm.disabled = true;
    actions.appendChild(confirm);
    const execute = element(document, 'button', 'button wcos-return-execute-button', 'Execute return');
    execute.hidden = true;
    execute.disabled = true;
    actions.appendChild(execute);
    panel.appendChild(actions);
    sourceDialog.appendChild(panel);

    document.body.appendChild(launcherDescription);
    document.body.appendChild(launcher);
    document.body.appendChild(sourceDialog);
    return { launcher };
}

function createHarness() {
    const document = new Document();
    const source = buildSource(document);
    const pending = [];
    let modal = null;

    const window = {
        wcosReturnAdminStrings: {},
        getComputedStyle() {
            return { display: 'block', visibility: 'visible' };
        },
        fetch() {
            return new Promise((resolve, reject) => pending.push({ resolve, reject }));
        },
        WCOSBackboneModal: {
            open(options) {
                const previousFocus = document.activeElement;
                const rootElement = element(document, 'div', 'wc-backbone-modal wcos-admin-backbone-modal ' + options.modalClass);
                const content = element(document, 'div', 'wc-backbone-modal-content');
                content.setAttribute('tabindex', '-1');
                const header = element(document, 'header', 'wc-backbone-modal-header');
                header.appendChild(element(document, 'h1', 'wcos-admin-backbone-modal__title'));
                const close = element(document, 'button', 'modal-close', 'Close modal panel');
                header.appendChild(close);
                const description = element(document, 'p', 'wcos-admin-backbone-modal__description');
                const body = element(document, 'div', 'wcos-admin-backbone-modal__body');
                const footer = element(document, 'div', 'wcos-admin-backbone-modal__footer');
                content.appendChild(header);
                content.appendChild(description);
                content.appendChild(body);
                content.appendChild(footer);
                rootElement.appendChild(content);
                document.body.appendChild(rootElement);

                const handle = {
                    focusContent() { content.focus(); }
                };
                options.build(body, footer, rootElement, handle);
                rootElement.addEventListener('click', function (event) {
                    if (!event.target.closest('.modal-close') || options.isBusy()) {
                        return;
                    }
                    if (rootElement.parentNode) {
                        rootElement.parentNode.removeChild(rootElement);
                    }
                    previousFocus.focus();
                });
                options.onReady(rootElement, handle);
                modal = { root: rootElement, content, body, footer };
                return handle;
            }
        }
    };

    vm.runInNewContext(client, {
        window,
        document,
        URLSearchParams,
        Object,
        Array,
        String,
        Error,
        parseInt
    }, { filename: 'p2-return-admin.js' });

    function controls() {
        return {
            close: modal.root.querySelector('.wc-backbone-modal-header .modal-close'),
            cancel: modal.root.querySelector('.wcos-return-cancel'),
            review: modal.root.querySelector('.wcos-return-review-button'),
            confirm: modal.root.querySelector('.wcos-return-confirm-button'),
            execute: modal.root.querySelector('.wcos-return-execute-button'),
            checkbox: modal.root.querySelector('.wcos-return-confirm-checkbox'),
            error: modal.root.querySelector('.wcos-return-error')
        };
    }

    return {
        document,
        launcher: source.launcher,
        open() {
            source.launcher.focus();
            source.launcher.click();
            return { modal, controls: controls() };
        },
        resolveNext(payload) {
            assert.ok(pending.length, 'A pending Return request must exist');
            pending.shift().resolve({ json: () => Promise.resolve(payload) });
        }
    };
}

async function settle() {
    for (let index = 0; index < 12; index += 1) {
        await Promise.resolve();
    }
}

(async () => {
    const harness = createHarness();
    const opened = harness.open();
    const modal = opened.modal;
    const controls = opened.controls;

    assert.equal(harness.document.activeElement, modal.content, 'Initial modal focus must remain inside the dialog');
    controls.review.focus();
    assert.equal(pressTab(controls.review).defaultPrevented, true, 'Initial forward Tab must wrap at the final control');
    assert.equal(harness.document.activeElement, controls.close, 'Initial forward wrap must focus the first usable control');
    assert.equal(pressTab(controls.close, true).defaultPrevented, true, 'Initial reverse Tab must wrap at the first control');
    assert.equal(harness.document.activeElement, controls.review, 'Initial reverse wrap must focus the final usable control');

    controls.review.click();
    assert.equal(harness.document.activeElement, modal.content, 'Busy Review must focus the dialog fallback');
    assert.equal(controls.close.disabled, true);
    assert.equal(controls.cancel.disabled, true);
    assert.equal(controls.review.disabled, true);
    const busyReviewTab = pressTab(modal.content);
    assert.equal(busyReviewTab.defaultPrevented, true, 'Busy Review Tab must be trapped');
    assert.equal(harness.document.activeElement, modal.content, 'Busy Review Tab must not escape to BODY');
    const busyEscape = keyEvent('keydown', { key: 'Escape' });
    modal.content.dispatchEvent(busyEscape);
    assert.equal(busyEscape.defaultPrevented, true, 'Busy Escape protection must remain active');

    harness.resolveNext({
        success: true,
        data: {
            review_id: 'review-id',
            review_token: 'review-token',
            summary: {
                child: { id: 202, number: '202' },
                original: { id: 101, number: '101' },
                strategy: 'manual_quantity',
                returned_line_count: 1,
                quantity: '1.000000',
                historical_subtotal: '9.92',
                historical_total: '9.92',
                historical_tax: '0.00',
                currency: 'USD',
                retirement: { policy: 'non_force_trash_archive', child_status_after: 'trash' }
            }
        }
    });
    await settle();
    assert.equal(controls.checkbox.disabled, false, 'Review acknowledgement must be enabled after success');
    assert.equal(harness.document.activeElement, controls.checkbox, 'Review success must focus the enabled acknowledgement');
    controls.cancel.focus();
    assert.equal(pressTab(controls.cancel).defaultPrevented, true, 'Reviewed forward Tab must wrap');
    assert.equal(harness.document.activeElement, controls.close, 'Reviewed forward wrap must remain inside the dialog');
    assert.equal(pressTab(controls.close, true).defaultPrevented, true, 'Reviewed reverse Tab must wrap');
    assert.equal(harness.document.activeElement, controls.cancel, 'Reviewed reverse wrap must remain inside the dialog');

    controls.checkbox.checked = true;
    controls.checkbox.dispatchEvent(keyEvent('change'));
    assert.equal(controls.confirm.disabled, false, 'Confirm must enable only after acknowledgement');
    controls.confirm.click();
    assert.equal(harness.document.activeElement, modal.content, 'Busy Confirm must focus the dialog fallback');
    assert.equal(controls.execute.disabled, true, 'Execute must remain disabled while Confirm is in flight');
    assert.equal(pressTab(modal.content).defaultPrevented, true, 'Busy Confirm Tab must be trapped');
    assert.equal(harness.document.activeElement, modal.content, 'Busy Confirm Tab must not escape to BODY');

    harness.resolveNext({
        success: true,
        data: { operation_id: 'operation-id', confirmation_token: 'confirmation-token' }
    });
    await settle();
    assert.equal(controls.execute.disabled, false, 'Execute must enable after Confirm success');
    assert.equal(harness.document.activeElement, controls.execute, 'Confirm success must focus enabled Execute');
    assert.equal(pressTab(controls.execute).defaultPrevented, true, 'Confirmed forward Tab must wrap');
    assert.equal(harness.document.activeElement, controls.close, 'Confirmed forward wrap must focus the first usable control');
    assert.equal(pressTab(controls.close, true).defaultPrevented, true, 'Confirmed reverse Tab must wrap');
    assert.equal(harness.document.activeElement, controls.execute, 'Confirmed reverse wrap must focus the final usable control');

    controls.cancel.click();
    assert.equal(harness.document.activeElement, harness.launcher, 'Closing Return must restore focus to its launcher');
    assert.equal(modal.root.isConnected, false, 'Return-local trap must disappear with its modal instance');

    const failureHarness = createHarness();
    const failed = failureHarness.open();
    failed.controls.review.click();
    failureHarness.resolveNext({ success: false, data: { code: 'review_rejected', message: 'Review rejected', retryable: false } });
    await settle();
    assert.equal(failureHarness.document.activeElement, failed.controls.error, 'Review failure must focus the error surface');
    assert.equal(failed.controls.confirm.disabled, true, 'Review failure must not manufacture Confirm authority');
    failed.controls.cancel.click();
    assert.equal(failureHarness.document.activeElement, failureHarness.launcher, 'Failure close must restore launcher focus');

    console.log('p2-return-focus-lifecycle-ok');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
