'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const controller = fs.readFileSync(
    path.join(root, 'inc/backend/class-wcos-split-strategy-admin-controller.php'),
    'utf8'
);
const client = fs.readFileSync(path.join(root, 'js/p2-split-strategy-admin.js'), 'utf8');

class ClassList {
    constructor(element) {
        this.element = element;
    }

    add(...tokens) {
        tokens.forEach((token) => this.element.classes.add(token));
    }

    remove(...tokens) {
        tokens.forEach((token) => this.element.classes.delete(token));
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
        this.value = '';
        this.type = '';
        this.name = '';
        this.id = '';
        this.textContent = '';
    }

    get className() {
        return this.classList.toString();
    }

    set className(value) {
        this.classes = new Set(String(value).split(/\s+/).filter(Boolean));
    }

    get firstChild() {
        return this.children.length ? this.children[0] : null;
    }

    appendChild(child) {
        if (child.parentNode) {
            child.parentNode.removeChild(child);
        }
        child.parentNode = this;
        this.children.push(child);
        return child;
    }

    insertBefore(child, reference) {
        if (!reference) {
            return this.appendChild(child);
        }
        if (child.parentNode) {
            child.parentNode.removeChild(child);
        }
        const index = this.children.indexOf(reference);
        assert.notEqual(index, -1, 'insertBefore reference must belong to the parent');
        child.parentNode = this;
        this.children.splice(index, 0, child);
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
        const normalized = String(name);
        const stringValue = String(value);
        this.attributes.set(normalized, stringValue);
        if (normalized === 'class') {
            this.className = stringValue;
        } else if (normalized === 'id') {
            this.id = stringValue;
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

    dispatch(type, event = {}) {
        (this.listeners.get(type) || []).forEach((listener) => listener.call(this, event));
    }

    focus() {
        this.ownerDocument.activeElement = this;
    }

    cloneNode(deep) {
        const clone = new Element(this.tagName, this.ownerDocument);
        this.attributes.forEach((value, name) => clone.setAttribute(name, value));
        clone.className = this.className;
        clone.hidden = this.hidden;
        clone.disabled = this.disabled;
        clone.checked = this.checked;
        clone.value = this.value;
        clone.type = this.type;
        clone.name = this.name;
        clone.id = this.id;
        clone.textContent = this.textContent;
        if (deep) {
            this.children.forEach((child) => clone.appendChild(child.cloneNode(true)));
        }
        return clone;
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
    if (selector === '*') {
        return true;
    }
    if (selector.startsWith('.')) {
        return element.classList.contains(selector.slice(1));
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
        this.activeElement = null;
    }

    createElement(tagName) {
        return new Element(tagName, this);
    }

    getElementById(id) {
        return this.body.querySelectorAll('*').find((element) => element.id === id) || null;
    }

    querySelectorAll(selector) {
        return this.body.querySelectorAll(selector);
    }
}

function element(document, tagName, className, textContent = '') {
    const node = document.createElement(tagName);
    node.className = className || '';
    node.textContent = textContent;
    return node;
}

function feedbackElementFromTemplate(document, name) {
    const pattern = new RegExp('<div class="([^"]*\\bwcos-strategy-' + name + '\\b[^"]*)"([^>]*)><\\/div>');
    const match = controller.match(pattern);
    assert.ok(match, 'Controller template must emit the ' + name + ' feedback region');

    const node = element(document, 'div', match[1]);
    const attributes = match[2];
    for (const attribute of attributes.matchAll(/([a-z-]+)="([^"]*)"/g)) {
        node.setAttribute(attribute[1], attribute[2]);
    }
    node.hidden = /(?:^|\s)hidden(?:\s|$)/.test(attributes);
    return node;
}

function buildSourceDialog(document) {
    const launcher = element(document, 'button', 'wcos-strategy-launcher', 'By category');
    launcher.setAttribute('aria-controls', 'wcos-strategy-dialog-category');

    const dialog = element(document, 'div', 'wcos-strategy-dialog');
    dialog.setAttribute('id', 'wcos-strategy-dialog-category');
    dialog.setAttribute('data-order-id', '101');
    dialog.setAttribute('data-nonce', 'test-nonce');
    dialog.setAttribute('data-strategy', 'category');
    dialog.setAttribute('data-review-action', 'wcos_strategy_review');
    dialog.setAttribute('data-confirm-action', 'wcos_strategy_confirm');
    dialog.setAttribute('data-execute-action', 'wcos_strategy_execute');
    dialog.setAttribute('data-ajax-url', '/wp-admin/admin-ajax.php');

    const form = element(document, 'form', 'wcos-strategy-form');
    form.setAttribute('aria-busy', 'false');

    const reviewControls = element(document, 'div', 'wcos-strategy-review-controls');
    const reviewButton = element(document, 'button', 'wcos-strategy-review-button', 'Review current buckets');
    reviewControls.appendChild(reviewButton);

    const reviewSection = element(document, 'section', 'wcos-strategy-review');
    reviewSection.hidden = true;
    reviewSection.appendChild(element(document, 'p', 'wcos-strategy-review-summary'));
    reviewSection.appendChild(element(document, 'div', 'wcos-strategy-bucket-options'));
    const confirmButton = element(document, 'button', 'button button-secondary wcos-strategy-confirm-button', 'Confirm selected source bucket');
    confirmButton.disabled = true;
    reviewSection.appendChild(confirmButton);

    const confirmationSection = element(document, 'section', 'wcos-strategy-confirmation');
    confirmationSection.hidden = true;
    confirmationSection.appendChild(element(document, 'p', 'wcos-strategy-confirmation-summary'));
    confirmationSection.appendChild(element(document, 'input', 'wcos-strategy-confirm-checkbox'));
    const executeButton = element(document, 'button', 'button button-primary wcos-strategy-execute-button', 'Execute strategy Split');
    executeButton.disabled = true;
    confirmationSection.appendChild(executeButton);

    form.appendChild(reviewControls);
    form.appendChild(reviewSection);
    form.appendChild(confirmationSection);
    form.appendChild(feedbackElementFromTemplate(document, 'status'));
    form.appendChild(feedbackElementFromTemplate(document, 'error'));
    form.appendChild(feedbackElementFromTemplate(document, 'result'));

    const actions = element(document, 'div', 'wcos-strategy-dialog__actions');
    actions.appendChild(element(document, 'button', 'button wcos-strategy-cancel', 'Close'));
    form.appendChild(actions);
    dialog.appendChild(form);
    document.body.appendChild(launcher);
    document.body.appendChild(dialog);

    return { launcher, dialog, form };
}

function applyWordPressNoticeCleanup(form) {
    form.querySelectorAll('.notice').slice().forEach((notice) => {
        if (notice.parentNode) {
            notice.parentNode.removeChild(notice);
        }
    });
}

const document = new Document();
const source = buildSourceDialog(document);

// Negative control: the former source markup loses both hidden WordPress notices.
const legacyForm = source.form.cloneNode(true);
legacyForm.querySelector('.wcos-strategy-error').classList.add('notice', 'notice-error');
legacyForm.querySelector('.wcos-strategy-result').classList.add('notice', 'notice-success');
applyWordPressNoticeCleanup(legacyForm);
assert.equal(legacyForm.querySelectorAll('.wcos-strategy-error').length, 0, 'Harness must reproduce the former error-region loss');
assert.equal(legacyForm.querySelectorAll('.wcos-strategy-result').length, 0, 'Harness must reproduce the former result-region loss');

// Reproduce the lifecycle that removed hidden source notices in the live order-admin DOM.
applyWordPressNoticeCleanup(source.form);
assert.equal(source.form.querySelectorAll('.wcos-strategy-status').length, 1, 'Status must survive admin notice cleanup');
assert.equal(source.form.querySelectorAll('.wcos-strategy-error').length, 1, 'Error must survive admin notice cleanup');
assert.equal(source.form.querySelectorAll('.wcos-strategy-result').length, 1, 'Result must survive admin notice cleanup');

let modal = null;
const window = {
    wcosSplitStrategyStrings: {},
    WCOSBackboneModal: {
        open(options) {
            const rootElement = element(document, 'div', 'wc-backbone-modal');
            const header = element(document, 'div', 'wc-backbone-modal-header');
            header.appendChild(element(document, 'button', 'modal-close', 'Close'));
            const body = element(document, 'div', 'wc-backbone-modal-content');
            const footer = element(document, 'div', 'wc-backbone-modal-footer');
            rootElement.appendChild(header);
            rootElement.appendChild(body);
            rootElement.appendChild(footer);
            document.body.appendChild(rootElement);
            options.build(body, footer, rootElement);
            options.onReady();
            modal = { root: rootElement, body, footer };
            return modal;
        }
    }
};

vm.runInNewContext(client, {
    window,
    document,
    URLSearchParams,
    Number,
    Object,
    Array,
    String,
    Error
}, { filename: 'p2-split-strategy-admin.js' });

source.launcher.dispatch('click');
assert.ok(modal, 'Strategy launcher must open the modal after admin notice cleanup');

const modalForm = modal.body.querySelector('.wcos-strategy-form');
assert.ok(modalForm, 'Modal must contain the cloned strategy form');
assert.equal(modalForm.querySelectorAll('.wcos-strategy-status').length, 1, 'Modal must bind exactly one status region');
assert.equal(modalForm.querySelectorAll('.wcos-strategy-error').length, 1, 'Modal must bind exactly one error region');
assert.equal(modalForm.querySelectorAll('.wcos-strategy-result').length, 1, 'Modal must bind exactly one result region');

const feedback = modalForm.querySelector('.wcos-strategy-feedback');
const status = feedback.querySelector('.wcos-strategy-status');
const error = feedback.querySelector('.wcos-strategy-error');
const result = feedback.querySelector('.wcos-strategy-result');
assert.deepEqual(feedback.children, [status, error, result], 'Feedback regions must be modal-local and deterministic');
assert.equal(status.getAttribute('role'), 'status');
assert.equal(status.getAttribute('aria-live'), 'polite');
assert.equal(status.hidden, true);
assert.equal(error.getAttribute('role'), 'alert');
assert.equal(error.getAttribute('tabindex'), '-1');
assert.equal(error.hidden, true);
assert.equal(error.className, 'wcos-strategy-error notice notice-error inline');
assert.equal(result.hidden, true);
assert.equal(result.className, 'wcos-strategy-result notice notice-success inline');

assert.equal(modal.footer.children.length, 4, 'Footer must contain Close and the three phase actions');
assert.equal(modal.footer.children[0].textContent, 'Close');
assert.equal(modal.footer.children[1].textContent, 'Review current buckets');
assert.equal(modal.footer.children[1].hidden, false);
assert.equal(modal.footer.children[2].hidden, true);
assert.equal(modal.footer.children[3].hidden, true);
assert.equal(document.activeElement, modal.footer.children[1], 'Initial focus must move to Review');

console.log('p2-strategy-modal-feedback-lifecycle-ok');
