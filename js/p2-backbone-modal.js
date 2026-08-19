(function ($) {
    'use strict';

    var templateName = 'wcos-admin-backbone-modal-shell';
    var templateId = 'tmpl-' + templateName;
    var sequence = 0;

    function ensureTemplate() {
        if (document.getElementById(templateId)) {
            return true;
        }
        if (!window.wp || typeof window.wp.template !== 'function' || !$ || !$.fn || typeof $.fn.WCBackboneModal !== 'function') {
            return false;
        }

        var template = document.createElement('script');
        template.type = 'text/template';
        template.id = templateId;
        template.textContent = [
            '<div class="wc-backbone-modal wcos-admin-backbone-modal">',
            '<div class="wc-backbone-modal-content">',
            '<section class="wc-backbone-modal-main" role="main">',
            '<header class="wc-backbone-modal-header">',
            '<h1 class="wcos-admin-backbone-modal__title"></h1>',
            '<button class="modal-close modal-close-link dashicons dashicons-no-alt" type="button">',
            '<span class="screen-reader-text">Close modal panel</span>',
            '</button>',
            '</header>',
            '<article>',
            '<p class="wcos-admin-backbone-modal__description description"></p>',
            '<div class="wcos-admin-backbone-modal__body"></div>',
            '</article>',
            '<footer><div class="inner wcos-admin-backbone-modal__footer"></div></footer>',
            '</section>',
            '</div>',
            '</div>',
            '<div class="wc-backbone-modal-backdrop modal-close"></div>'
        ].join('');
        document.body.appendChild(template);
        return true;
    }

    function currentRoot() {
        return document.getElementById('wc-backbone-modal-dialog');
    }

    function remapClonedIds(container, instanceId) {
        if (!container) {
            return;
        }

        var idMap = {};
        Array.prototype.forEach.call(container.querySelectorAll('[id]'), function (element) {
            var oldId = element.id;
            if (!oldId) {
                return;
            }
            var newId = 'wcos-modal-' + String(instanceId) + '-' + oldId;
            idMap[oldId] = newId;
            element.id = newId;
        });

        function remapTokens(value) {
            return String(value || '').split(/\s+/).filter(Boolean).map(function (token) {
                return idMap[token] || token;
            }).join(' ');
        }

        Array.prototype.forEach.call(container.querySelectorAll('[for]'), function (element) {
            var oldFor = element.getAttribute('for');
            if (oldFor && idMap[oldFor]) {
                element.setAttribute('for', idMap[oldFor]);
            }
        });

        ['aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-owns'].forEach(function (attribute) {
            Array.prototype.forEach.call(container.querySelectorAll('[' + attribute + ']'), function (element) {
                element.setAttribute(attribute, remapTokens(element.getAttribute(attribute)));
            });
        });
    }

    function open(options) {
        options = options || {};
        if (!ensureTemplate()) {
            throw new Error('WooCommerce Backbone modal is unavailable on this screen.');
        }
        if (currentRoot()) {
            throw new Error('Another WooCommerce modal is already open.');
        }

        var previousFocus = document.activeElement;
        var trigger = options.trigger || document.body;
        var instanceId = ++sequence;
        var namespace = '.wcosBackboneModal' + String(instanceId);
        var restoreFocus = options.restoreFocus !== false;
        var removed = false;
        var afterRemoved = null;

        $(trigger).WCBackboneModal({
            template: templateName,
            variable: {}
        });

        var root = currentRoot();
        if (!root) {
            throw new Error('WooCommerce Backbone modal failed to render.');
        }

        var shell = root.querySelector('.wc-backbone-modal');
        var title = root.querySelector('.wcos-admin-backbone-modal__title');
        var description = root.querySelector('.wcos-admin-backbone-modal__description');
        var body = root.querySelector('.wcos-admin-backbone-modal__body');
        var footer = root.querySelector('.wcos-admin-backbone-modal__footer');
        var content = root.querySelector('.wc-backbone-modal-content');

        if (shell && options.modalClass) {
            String(options.modalClass).split(/\s+/).filter(Boolean).forEach(function (className) {
                shell.classList.add(className);
            });
        }
        if (title) {
            title.textContent = options.title || '';
        }
        if (description) {
            description.textContent = options.description || '';
            description.hidden = !options.description;
        }
        if (content && options.label) {
            content.setAttribute('aria-label', options.label);
        }

        var handle = {
            root: root,
            shell: shell,
            body: body,
            footer: footer,
            close: function (shouldRestoreFocus, callback) {
                restoreFocus = shouldRestoreFocus !== false;
                afterRemoved = typeof callback === 'function' ? callback : null;
                var close = root.querySelector('.wc-backbone-modal-header .modal-close') || root.querySelector('.modal-close');
                if (close) {
                    close.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
                }
            },
            focusContent: function () {
                if (content && typeof content.focus === 'function') {
                    content.focus();
                }
            }
        };

        if (typeof options.build === 'function') {
            options.build(body, footer, root, handle);
        }
        remapClonedIds(body, instanceId);

        root.addEventListener('click', function (event) {
            if (!event.target.closest('.modal-close')) {
                return;
            }
            if (typeof options.isBusy === 'function' && options.isBusy()) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
            }
        }, true);

        $(document.body).on('wc_backbone_modal_removed' + namespace, function (event, target) {
            if (target !== templateName || removed) {
                return;
            }
            removed = true;
            $(document.body).off(namespace);
            if (typeof options.onRemoved === 'function') {
                options.onRemoved();
            }
            if (restoreFocus && previousFocus && previousFocus.isConnected && typeof previousFocus.focus === 'function') {
                previousFocus.focus();
            }
            if (typeof afterRemoved === 'function') {
                var callback = afterRemoved;
                afterRemoved = null;
                window.setTimeout(callback, 0);
            }
        });

        /*
         * Call onReady after open() has returned its handle to the workflow.
         * This avoids the synchronous RHS-assignment race that left callers
         * observing a null modal handle while the modal was already visible.
         */
        if (typeof options.onReady === 'function') {
            window.setTimeout(function () {
                if (!removed && root.isConnected) {
                    options.onReady(root, handle);
                }
            }, 0);
        }

        return handle;
    }

    window.WCOSBackboneModal = {
        open: open,
        currentRoot: currentRoot,
        templateName: templateName
    };
})(window.jQuery);
