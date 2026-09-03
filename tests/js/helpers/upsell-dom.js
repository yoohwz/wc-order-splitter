'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

class Element {
    constructor(tag, ownerDocument) {
        this.tagName = tag.toUpperCase();
        this.ownerDocument = ownerDocument;
        this.children = [];
        this.parentNode = null;
        this.attributes = {};
        this.className = '';
        this.listeners = {};
        this.hidden = false;
        this._text = '';
        this.classList = {
            contains: (name) => this.className.split(/\s+/).includes(name),
            add: (...names) => { this.className += ' ' + names.join(' '); }
        };
    }
    get isConnected() { return this === this.ownerDocument.body || !!(this.parentNode && this.parentNode.isConnected); }
    get firstChild() { return this.children[0] || null; }
    get firstElementChild() { return this.firstChild; }
    get textContent() { return this._text + this.children.map((child) => child.textContent).join(''); }
    set textContent(value) { this._text = value; this.children.forEach((child) => { child.parentNode = null; }); this.children = []; }
    appendChild(child) { child.remove(); child.parentNode = this; this.children.push(child); return child; }
    insertBefore(child, before) { child.remove(); child.parentNode = this; this.children.splice(this.children.indexOf(before), 0, child); }
    remove() {
        if (this.parentNode) {
            this.parentNode.children = this.parentNode.children.filter((child) => child !== this);
            this.parentNode = null;
        }
    }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    getAttribute(name) { return this.attributes[name] === undefined ? null : this.attributes[name]; }
    matches(selector) {
        return selector.split(',').some((part) => {
            part = part.trim();
            if (part.startsWith('.')) return this.classList.contains(part.slice(1));
            if (part.startsWith('#')) return this.getAttribute('id') === part.slice(1);
            if (part.startsWith('[')) {
                const match = part.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/);
                if (!match) throw new Error('Unsupported selector: ' + part);
                if (match[1] === 'hidden') return this.hidden;
                return match[2] === undefined ? this.getAttribute(match[1]) !== null : this.getAttribute(match[1]) === match[2];
            }
            return this.tagName === part.toUpperCase();
        });
    }
    closest(selector) { return this.matches(selector) ? this : this.parentNode && this.parentNode.closest(selector); }
    querySelectorAll(selector) {
        return this.children.flatMap((child) => [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)]);
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    addEventListener(name, listener) { (this.listeners[name] ||= []).push(listener); }
    dispatchEvent(event) {
        event.target ||= this;
        (this.listeners[event.type] || []).forEach((listener) => listener(event));
        if (event.bubbles) {
            if (this.parentNode) this.parentNode.dispatchEvent(event);
            else if (this === this.ownerDocument.body) this.ownerDocument.dispatchEvent(event);
        }
        return true;
    }
    focus() { this.ownerDocument.activeElement = this; }
    click() { this.dispatchEvent({ type: 'click', bubbles: true }); }
}

function harness(options = {}) {
    const storage = options.storage || new Map();
    const document = {
        listeners: {},
        createElement(tag) { return new Element(tag, this); },
        addEventListener(name, listener) { (this.listeners[name] ||= []).push(listener); },
        dispatchEvent(event) { (this.listeners[event.type] || []).forEach((listener) => listener(event)); }
    };
    document.body = document.createElement('body');
    const config = {
        productUrl: 'https://yoohw.com/product/woocommerce-advanced-order-actions/',
        thresholds: { split: 3, duplicate: 2, merge: 2, return: 2 },
        actionTips: { split: 'Split automation', duplicate: 'Duplicate control', merge: 'Merge previews', return: 'Action Logs and guarded rollback' },
        splitHint: 'Product group, tag, attribute and conditional routing. Vendor and bundle routing require compatible integrations.',
        ...options.config
    };
    const window = {
        wcosPremiumUpsell: config,
        localStorage: {
            getItem(key) { if (options.denyRead) throw new Error('Storage blocked'); return storage.get(key) || null; },
            setItem(key, value) { if (options.denyWrite) throw new Error('Quota exceeded'); storage.set(key, value); },
            removeItem(key) { storage.delete(key); }
        },
        fetch() { throw new Error('Promotion must not make network calls'); }
    };
    const originalFetch = window.fetch;
    const context = vm.createContext({ window, document, console });
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../../js/post-action-tip.js'), 'utf8'), context);
    function modal(action, chooser = false) {
        document.body.textContent = '';
        const root = document.body.appendChild(document.createElement('div'));
        root.setAttribute('id', 'wc-backbone-modal-dialog');
        const shell = root.appendChild(document.createElement('div'));
        shell.className = chooser ? 'wcos-split-method-backbone-modal' : 'wcos-' + action + '-backbone-modal';
        const body = shell.appendChild(document.createElement('div'));
        body.className = 'wcos-admin-backbone-modal__body';
        const footer = shell.appendChild(document.createElement('footer'));
        const close = footer.appendChild(document.createElement('button'));
        close.textContent = 'Close';
        const result = body.appendChild(document.createElement('div'));
        result.className = 'wcos-' + action + '-result';
        const outcome = result.appendChild(document.createElement('p'));
        outcome.textContent = 'Operation completed. Operation evidence remains visible.';
        const continuation = result.appendChild(document.createElement('a'));
        continuation.href = '/order';
        continuation.textContent = 'View order';
        return { root, shell, body, footer, close, result, outcome, continuation };
    }
    function emit(action, operationId, status = 'completed', target) {
        target ||= modal(action).result;
        target.dispatchEvent({ type: 'wcos:operation-completed', bubbles: true, detail: { action, operationId, status } });
        return target;
    }
    return { document, window, originalFetch, storage, config, modal, emit, context,
        read: () => JSON.parse(storage.get('wcosPremiumUpsellStateV1') || '{}') };
}

module.exports = { harness, Element };
