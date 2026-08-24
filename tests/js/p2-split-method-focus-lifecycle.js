'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const client = fs.readFileSync(path.join(root, 'js/p2-split-admin.js'), 'utf8');

class Element {
    constructor(tagName, ownerDocument) {
        this.tagName = String(tagName).toUpperCase();
        this.ownerDocument = ownerDocument;
        this.parentNode = null;
        this.children = [];
        this.attributes = new Map();
        this.listeners = new Map();
        this.hidden = false;
        this.textContent = '';
        this.type = '';
        this.classes = new Set();
    }

    set className(value) {
        this.classes = new Set(String(value).split(/\s+/).filter(Boolean));
    }

    get className() {
        return Array.from(this.classes).join(' ');
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

    click() {
        (this.listeners.get('click') || []).forEach((listener) => listener.call(this, { target: this }));
    }

    focus() {
        this.ownerDocument.activeElement = this;
    }

    querySelector() {
        return null;
    }

    querySelectorAll() {
        return [];
    }
}

class Document {
    constructor() {
        this.body = new Element('body', this);
        this.activeElement = this.body;
        this.elementsById = new Map();
        this.launcher = null;
        this.strategyLaunchers = [];
    }

    createElement(tagName) {
        return new Element(tagName, this);
    }

    getElementById(id) {
        return this.elementsById.get(id) || null;
    }

    querySelector(selector) {
        return selector === '.wcos-split-launcher' ? this.launcher : null;
    }

    querySelectorAll(selector) {
        return selector === '.wcos-strategy-launcher' ? this.strategyLaunchers : [];
    }
}

function appendDescription(document, button, id, text) {
    const description = document.createElement('span');
    description.textContent = text;
    description.setAttribute('id', id);
    document.elementsById.set(id, description);
    document.body.appendChild(description);
    button.setAttribute('aria-describedby', id);
}

function createHarness() {
    const document = new Document();
    const sourceDialog = document.createElement('div');
    document.elementsById.set('wcos-split-source', sourceDialog);
    document.body.appendChild(sourceDialog);

    const launcher = document.createElement('button');
    launcher.className = 'wcos-split-launcher';
    launcher.setAttribute('aria-controls', 'wcos-split-source');
    appendDescription(document, launcher, 'wcos-split-description', 'Choose a Split method.');
    document.launcher = launcher;
    document.body.appendChild(launcher);

    ['Split by category', 'Split by stock status'].forEach((label, index) => {
        const strategy = document.createElement('button');
        strategy.className = 'wcos-strategy-launcher';
        strategy.textContent = label;
        appendDescription(document, strategy, `wcos-strategy-description-${index}`, `${label} description.`);
        document.strategyLaunchers.push(strategy);
        document.body.appendChild(strategy);
    });

    let methodHandle = null;
    let methodOptions = [];
    let stageHandle = null;

    function createModalHandle(previousFocus, onClose) {
        return {
            previousFocus,
            close(restoreFocus = true, callback) {
                document.activeElement = document.body;
                if (restoreFocus && previousFocus) {
                    previousFocus.focus();
                }
                if (onClose) {
                    onClose();
                }
                if (callback) {
                    callback();
                }
            }
        };
    }

    const modal = {
        open(options) {
            const previousFocus = document.activeElement;
            if (options.modalClass === 'wcos-split-method-backbone-modal') {
                const body = document.createElement('div');
                const footer = document.createElement('div');
                methodHandle = createModalHandle(previousFocus);
                options.build(body, footer);
                methodOptions = body.children[0].children;
                return methodHandle;
            }

            stageHandle = createModalHandle(previousFocus);
            return stageHandle;
        }
    };

    document.strategyLaunchers.forEach((strategy) => {
        strategy.addEventListener('click', () => {
            stageHandle = createModalHandle(document.activeElement);
        });
    });

    vm.runInNewContext(client, {
        window: {
            WCOSBackboneModal: modal,
            wcosSplitAdminStrings: {},
            setTimeout(callback) {
                callback();
            }
        },
        document,
        Array,
        String
    });

    return {
        document,
        launcher,
        strategyLaunchers: document.strategyLaunchers,
        openChooser() {
            launcher.focus();
            launcher.click();
            return { handle: methodHandle, options: methodOptions };
        },
        getStageHandle() {
            return stageHandle;
        }
    };
}

{
    const harness = createHarness();
    const chooser = harness.openChooser();
    chooser.options[0].focus();
    chooser.handle.close();
    assert.equal(harness.document.activeElement, harness.launcher, 'direct chooser close must restore the visible launcher');
}

['quantity', 'category', 'stock status'].forEach((method, index) => {
    const harness = createHarness();
    const chooser = harness.openChooser();
    const option = chooser.options[index];
    option.focus();
    option.click();

    const stage = harness.getStageHandle();
    assert.ok(stage, `${method} must open its stage-2 modal`);
    assert.equal(stage.previousFocus, harness.launcher, `${method} must capture the visible launcher as its focus origin`);
    assert.notEqual(stage.previousFocus, harness.strategyLaunchers[index - 1], `${method} must not capture a hidden strategy launcher`);

    stage.close();
    assert.equal(harness.document.activeElement, harness.launcher, `${method} close must restore the visible launcher`);
});

console.log('Split method focus lifecycle regression passed.');
