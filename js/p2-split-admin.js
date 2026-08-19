(function () {
    'use strict';

    var strings = window.wcosSplitAdminStrings || {};
    var launcher = document.querySelector('.wcos-split-launcher');
    if (!launcher || !window.WCOSBackboneModal) {
        return;
    }

    var sourceDialogId = launcher.getAttribute('aria-controls');
    var sourceDialog = sourceDialogId ? document.getElementById(sourceDialogId) : null;
    if (!sourceDialog) {
        return;
    }

    var strategyLaunchers = Array.prototype.slice.call(document.querySelectorAll('.wcos-strategy-launcher'));

    function text(key, fallback) {
        return typeof strings[key] === 'string' && strings[key] ? strings[key] : fallback;
    }

    function humanDecimal(value) {
        var normalized = String(value == null ? '' : value).trim();
        if (normalized.indexOf('.') !== -1) {
            normalized = normalized.replace(/0+$/, '').replace(/\.$/, '');
        }
        return normalized === '' ? '0' : normalized;
    }

    function removeExternalDescription(button) {
        var descriptionId = button.getAttribute('aria-describedby');
        var description = descriptionId ? document.getElementById(descriptionId) : null;
        var value = description ? description.textContent.trim() : '';
        if (description && description.parentNode) {
            description.parentNode.removeChild(description);
        }
        button.removeAttribute('aria-describedby');
        return value;
    }

    var launcherDescription = removeExternalDescription(launcher);
    strategyLaunchers.forEach(function (strategyLauncher) {
        strategyLauncher._wcosDescription = removeExternalDescription(strategyLauncher);
        strategyLauncher.hidden = true;
    });
    launcher.removeAttribute('aria-controls');

    function cloneFooterButtons(sourceActions, footer) {
        Array.prototype.forEach.call(sourceActions ? sourceActions.children : [], function (sourceButton) {
            var button = sourceButton.cloneNode(true);
            if (button.classList.contains('wcos-split-cancel')) {
                button.classList.add('modal-close');
                button.classList.add('button-large');
            }
            footer.appendChild(button);
        });
    }

    function openQuantityDialog(trigger) {
        var busy = false;
        var completed = false;
        var state = null;
        var activeChildren = 1;
        var maxChildren = 10;
        var modal = null;
        var dialog = null;
        var form = null;
        var reviewButton = null;
        var executeButton = null;
        var confirmCheckbox = null;
        var reviewBox = null;
        var reviewSummary = null;
        var statusBox = null;
        var errorBox = null;
        var resultBox = null;
        var table = null;
        var tableWrap = null;
        var policyBox = null;
        var addChildButton = null;
        var removeChildButton = null;
        var childCountLabel = null;
        var editButton = null;
        var cancelButton = null;
        var closeButton = null;

        function clearError() {
            errorBox.hidden = true;
            errorBox.textContent = '';
        }

        function showError(message) {
            errorBox.textContent = message || text('requestFailed', 'The Split request could not be completed.');
            errorBox.hidden = false;
            errorBox.focus();
        }

        function setStatus(message) {
            statusBox.textContent = message || '';
        }

        function clearResult() {
            resultBox.hidden = true;
            resultBox.textContent = '';
        }

        function childColumnElements(childIndex) {
            var childKey = 'child-' + String(childIndex);
            var fields = Array.prototype.slice.call(dialog.querySelectorAll('.wcos-split-quantity[data-child-key="' + childKey + '"]'));
            var cells = fields.map(function (field) { return field.closest('td'); }).filter(Boolean);
            var header = table && table.tHead && table.tHead.rows.length
                ? table.tHead.rows[0].cells[childIndex + 1]
                : null;
            return { fields: fields, cells: cells, header: header };
        }

        function setChildVisible(childIndex, visible) {
            var column = childColumnElements(childIndex);
            if (column.header) {
                column.header.hidden = !visible;
            }
            column.cells.forEach(function (cell) {
                cell.hidden = !visible;
            });
        }

        function updateChildToolbar() {
            if (childCountLabel) {
                childCountLabel.textContent = String(activeChildren) + (activeChildren === 1 ? ' child order' : ' child orders');
            }
            if (addChildButton) {
                addChildButton.disabled = busy || completed || !!state || activeChildren >= maxChildren;
            }
            if (removeChildButton) {
                removeChildButton.hidden = activeChildren <= 1;
                removeChildButton.disabled = busy || completed || !!state || activeChildren <= 1;
            }
        }

        function enhanceChildColumns() {
            for (var childIndex = 1; childIndex <= maxChildren; childIndex++) {
                var column = childColumnElements(childIndex);
                column.fields.forEach(function (field) {
                    if (field.value === '0') {
                        field.value = '';
                    }
                    field.placeholder = '0';
                });
                setChildVisible(childIndex, childIndex === 1);
            }

            Array.prototype.forEach.call(table.querySelectorAll('tbody tr[data-item-id]'), function (row) {
                var sourceQuantity = row.getAttribute('data-source-quantity') || '0';
                var currentCell = row.cells.length > 1 ? row.cells[1] : null;
                if (!currentCell) {
                    return;
                }
                currentCell.textContent = humanDecimal(sourceQuantity);
                var remaining = document.createElement('span');
                remaining.className = 'wcos-split-remaining-hint';
                var label = document.createElement('span');
                label.textContent = 'Remaining ';
                var value = document.createElement('strong');
                value.className = 'wcos-split-remaining';
                value.setAttribute('data-item-id', row.getAttribute('data-item-id') || '');
                value.textContent = humanDecimal(sourceQuantity);
                remaining.appendChild(label);
                remaining.appendChild(value);
                currentCell.appendChild(remaining);
            });

            var toolbar = document.createElement('div');
            toolbar.className = 'wcos-split-child-toolbar';
            childCountLabel = document.createElement('strong');
            childCountLabel.className = 'wcos-split-child-count';
            addChildButton = document.createElement('button');
            addChildButton.type = 'button';
            addChildButton.className = 'button button-secondary wcos-split-add-child';
            addChildButton.textContent = 'Add child order';
            removeChildButton = document.createElement('button');
            removeChildButton.type = 'button';
            removeChildButton.className = 'button wcos-split-remove-child';
            removeChildButton.textContent = 'Remove last child';
            toolbar.appendChild(childCountLabel);
            toolbar.appendChild(addChildButton);
            toolbar.appendChild(removeChildButton);
            tableWrap.parentNode.insertBefore(toolbar, tableWrap);

            addChildButton.addEventListener('click', function () {
                if (busy || completed || state || activeChildren >= maxChildren) {
                    return;
                }
                activeChildren += 1;
                setChildVisible(activeChildren, true);
                updateChildToolbar();
                updateRemaining();
                updateReviewAvailability();
                var next = childColumnElements(activeChildren).fields[0];
                if (next) {
                    next.focus();
                }
            });

            removeChildButton.addEventListener('click', function () {
                if (busy || completed || state || activeChildren <= 1) {
                    return;
                }
                var column = childColumnElements(activeChildren);
                column.fields.forEach(function (field) {
                    field.value = '';
                });
                setChildVisible(activeChildren, false);
                activeChildren -= 1;
                updateChildToolbar();
                updateRemaining();
                updateReviewAvailability();
            });

            updateChildToolbar();
            updateRemaining();
        }

        function enhancePolicy() {
            var list = policyBox.querySelector('ul');
            if (!list) {
                return;
            }
            list.hidden = true;
            var summary = document.createElement('p');
            summary.className = 'wcos-split-policy-summary';
            summary.textContent = 'Child orders are created as Pending payment. Shipping stays on the source order, historical prices and taxes are preserved, and this Split does not change physical product stock.';
            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'button-link wcos-split-policy-toggle';
            toggle.setAttribute('aria-expanded', 'false');
            toggle.textContent = 'View safety details';
            policyBox.insertBefore(summary, list);
            policyBox.insertBefore(toggle, list);
            toggle.addEventListener('click', function () {
                var expanded = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                toggle.textContent = expanded ? 'View safety details' : 'Hide safety details';
                list.hidden = expanded;
            });
        }

        function createEditButton() {
            editButton = document.createElement('button');
            editButton.type = 'button';
            editButton.className = 'button button-large wcos-split-edit-button';
            editButton.textContent = 'Edit quantities';
            editButton.hidden = true;
            modal.footer.insertBefore(editButton, reviewButton);
            executeButton.hidden = true;

            editButton.addEventListener('click', function () {
                if (busy || completed) {
                    return;
                }
                state = null;
                reviewBox.hidden = true;
                reviewSummary.textContent = '';
                confirmCheckbox.checked = false;
                executeButton.disabled = true;
                executeButton.hidden = true;
                editButton.hidden = true;
                reviewButton.hidden = false;
                setStatus('');
                clearError();
                setBusy(false);
                updateChildToolbar();
                updateReviewAvailability();
                var first = dialog.querySelector('.wcos-split-quantity:not([disabled])');
                if (first) {
                    first.focus();
                }
            });
        }

        function decimalIsPositiveAndLessThan(value, sourceValue) {
            if (!/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/.test(value)) {
                return false;
            }
            var valueNumber = Number(value);
            var sourceNumber = Number(sourceValue);
            return Number.isFinite(valueNumber) && Number.isFinite(sourceNumber) && valueNumber > 0 && valueNumber < sourceNumber;
        }

        function buildPlan() {
            var plan = {};
            var hasQuantity = false;
            var invalid = false;

            Array.prototype.forEach.call(dialog.querySelectorAll('tbody tr[data-item-id]'), function (row) {
                var itemId = row.getAttribute('data-item-id');
                var sourceQuantity = row.getAttribute('data-source-quantity') || '0';
                var movedForLine = 0;

                Array.prototype.forEach.call(row.querySelectorAll('.wcos-split-quantity[data-child-key]'), function (quantityInput) {
                    var cell = quantityInput.closest('td');
                    if (cell && cell.hidden) {
                        return;
                    }
                    var quantity = quantityInput.value.trim();
                    if (quantity === '' || Number(quantity) === 0) {
                        return;
                    }
                    if (!decimalIsPositiveAndLessThan(quantity, sourceQuantity)) {
                        invalid = true;
                        return;
                    }
                    var childKey = quantityInput.getAttribute('data-child-key') || '';
                    if (!/^child-(?:[1-9]|10)$/.test(childKey)) {
                        invalid = true;
                        return;
                    }
                    if (!plan[childKey]) {
                        plan[childKey] = {};
                    }
                    plan[childKey][itemId] = quantity;
                    movedForLine += Number(quantity);
                    hasQuantity = true;
                });

                if (movedForLine >= Number(sourceQuantity)) {
                    invalid = true;
                }
            });

            Object.keys(plan).forEach(function (childKey) {
                if (!Object.keys(plan[childKey]).length) {
                    delete plan[childKey];
                }
            });

            if (invalid || !hasQuantity) {
                throw new Error(text('invalidPlan', 'Enter at least one quantity and keep a positive residual quantity on every affected source line.'));
            }
            return plan;
        }

        function updateRemaining() {
            Array.prototype.forEach.call(dialog.querySelectorAll('tbody tr[data-item-id]'), function (row) {
                var source = Number(row.getAttribute('data-source-quantity') || 0);
                var moved = 0;
                Array.prototype.forEach.call(row.querySelectorAll('.wcos-split-quantity[data-child-key]'), function (field) {
                    var cell = field.closest('td');
                    if (cell && cell.hidden) {
                        return;
                    }
                    var value = Number(field.value || 0);
                    if (Number.isFinite(value) && value > 0) {
                        moved += value;
                    }
                });
                var remaining = source - moved;
                var output = row.querySelector('.wcos-split-remaining');
                if (output) {
                    output.textContent = humanDecimal(String(Math.max(0, remaining).toFixed(6)));
                    output.classList.toggle('is-invalid', remaining <= 0);
                }
            });
        }

        function canBuildPlan() {
            try {
                buildPlan();
                return true;
            } catch (error) {
                return false;
            }
        }

        function updateReviewAvailability() {
            if (!reviewButton || reviewButton.hidden) {
                return;
            }
            reviewButton.disabled = busy || completed || !!state || !canBuildPlan();
        }

        function request(action, data) {
            var body = new URLSearchParams();
            body.set('action', action);
            Object.keys(data).forEach(function (key) {
                body.set(key, String(data[key]));
            });

            return window.fetch(sourceDialog.getAttribute('data-ajax-url'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function (response) {
                return response.json().catch(function () {
                    throw new Error(text('requestFailed', 'The Split request could not be completed.'));
                });
            }).then(function (payload) {
                if (!payload || payload.success !== true) {
                    var failure = payload && payload.data ? payload.data : {};
                    var error = new Error(failure.message || text('requestFailed', 'The Split request could not be completed.'));
                    error.code = failure.code || 'request_failed';
                    error.retryable = !!failure.retryable;
                    throw error;
                }
                return payload.data || {};
            }).catch(function (error) {
                if (typeof error.retryable !== 'boolean') {
                    error.retryable = true;
                }
                throw error;
            });
        }

        function setBusy(nextBusy) {
            busy = !!nextBusy;
            form.setAttribute('aria-busy', busy ? 'true' : 'false');
            Array.prototype.forEach.call(dialog.querySelectorAll('.wcos-split-quantity'), function (field) {
                field.disabled = busy || completed || !!state;
            });
            reviewButton.disabled = busy || completed || !!state || !canBuildPlan();
            confirmCheckbox.disabled = busy || completed || !state;
            executeButton.disabled = busy || completed || !state || !confirmCheckbox.checked;
            cancelButton.disabled = busy;
            closeButton.disabled = busy;
            if (editButton) {
                editButton.disabled = busy || completed;
            }
            updateChildToolbar();
        }

        function reviewPlan() {
            if (busy || completed || state) {
                return;
            }
            clearError();
            clearResult();
            var plan;
            try {
                plan = buildPlan();
            } catch (error) {
                showError(error.message);
                return;
            }

            setBusy(true);
            setStatus(text('reviewing', 'Reviewing Split plan…'));
            request('wcos_split_review', {
                order_id: sourceDialog.getAttribute('data-order-id'),
                nonce: sourceDialog.getAttribute('data-nonce'),
                plan: JSON.stringify(plan)
            }).then(function (data) {
                state = { operationId: data.operation_id, token: data.confirmation_token };
                var summary = data.summary || {};
                var childCount = Number(summary.child_count || 0);
                var lineCount = Number(summary.affected_line_count || 0);
                reviewSummary.textContent = String(childCount) + (childCount === 1 ? ' child order' : ' child orders') +
                    ' · ' + String(lineCount) + (lineCount === 1 ? ' product line' : ' product lines') +
                    ' · ' + humanDecimal(summary.moved_quantity || '0') + ' quantity moving';
                reviewBox.hidden = false;
                confirmCheckbox.checked = false;
                reviewButton.hidden = true;
                editButton.hidden = false;
                executeButton.hidden = false;
                setStatus(text('reviewReady', 'The plan passed server review. Confirm the acknowledgement to execute it.'));
                confirmCheckbox.focus();
            }).catch(function (error) {
                state = null;
                reviewBox.hidden = true;
                executeButton.hidden = true;
                editButton.hidden = true;
                reviewButton.hidden = false;
                setStatus('');
                showError(error.message);
            }).finally(function () {
                setBusy(false);
                updateReviewAvailability();
            });
        }

        function renderSuccess(data) {
            resultBox.textContent = '';
            var message = document.createElement('p');
            message.textContent = text('completed', 'Split completed successfully.');
            resultBox.appendChild(message);
            var children = Array.isArray(data.children) ? data.children : [];
            if (children.length) {
                var list = document.createElement('ul');
                children.forEach(function (child) {
                    var item = document.createElement('li');
                    if (child.edit_url) {
                        var link = document.createElement('a');
                        link.href = child.edit_url;
                        link.textContent = text('childOrder', 'Child order') + ' #' + String(child.number || child.id || '');
                        item.appendChild(link);
                    } else {
                        item.textContent = text('childOrder', 'Child order') + ' #' + String(child.number || child.id || '');
                    }
                    list.appendChild(item);
                });
                resultBox.appendChild(list);
            }
            var reload = document.createElement('button');
            reload.type = 'button';
            reload.className = 'button';
            reload.textContent = text('reloadOrder', 'Reload source order');
            reload.addEventListener('click', function () { window.location.reload(); });
            resultBox.appendChild(reload);
            resultBox.hidden = false;
            resultBox.focus();
        }

        function executePlan() {
            if (busy || completed || !state || !confirmCheckbox.checked) {
                return;
            }
            clearError();
            clearResult();
            setBusy(true);
            setStatus(text('executing', 'Executing Split…'));
            request('wcos_split_execute', {
                order_id: sourceDialog.getAttribute('data-order-id'),
                nonce: sourceDialog.getAttribute('data-nonce'),
                operation_id: state.operationId,
                confirmation_token: state.token
            }).then(function (data) {
                completed = true;
                setStatus(text('completed', 'Split completed successfully.'));
                Array.prototype.forEach.call(dialog.querySelectorAll('input, select, button'), function (field) {
                    if (!field.classList.contains('wcos-split-cancel') && !field.classList.contains('modal-close')) {
                        field.disabled = true;
                    }
                });
                editButton.hidden = true;
                reviewButton.hidden = true;
                executeButton.hidden = true;
                renderSuccess(data);
            }).catch(function (error) {
                if (!error.retryable) {
                    state = null;
                    reviewBox.hidden = true;
                    executeButton.hidden = true;
                    editButton.hidden = true;
                    reviewButton.hidden = false;
                }
                setStatus('');
                showError(error.message);
            }).finally(function () {
                setBusy(false);
                updateReviewAvailability();
            });
        }

        modal = window.WCOSBackboneModal.open({
            trigger: trigger || launcher,
            title: (sourceDialog.querySelector('.wcos-split-dialog__header h2') || {}).textContent || 'Review quantity split',
            description: (sourceDialog.querySelector('.wcos-split-dialog__header p') || {}).textContent || launcherDescription,
            modalClass: 'wcos-split-backbone-modal',
            isBusy: function () { return busy; },
            build: function (body, footer, root) {
                var sourceForm = sourceDialog.querySelector('.wcos-split-form');
                var sourceActions = sourceDialog.querySelector('.wcos-split-dialog__actions');
                var clonedForm = sourceForm.cloneNode(true);
                var clonedActions = clonedForm.querySelector('.wcos-split-dialog__actions');
                if (clonedActions && clonedActions.parentNode) {
                    clonedActions.parentNode.removeChild(clonedActions);
                }
                body.appendChild(clonedForm);
                cloneFooterButtons(sourceActions, footer);
                dialog = root;
                form = clonedForm;
                reviewButton = root.querySelector('.wcos-split-review-button');
                executeButton = root.querySelector('.wcos-split-execute-button');
                confirmCheckbox = root.querySelector('.wcos-split-confirm-checkbox');
                reviewBox = root.querySelector('.wcos-split-review');
                reviewSummary = root.querySelector('.wcos-split-review-summary');
                statusBox = root.querySelector('.wcos-split-status');
                errorBox = root.querySelector('.wcos-split-error');
                resultBox = root.querySelector('.wcos-split-result');
                table = root.querySelector('.wcos-split-table');
                tableWrap = root.querySelector('.wcos-split-table-wrap');
                policyBox = root.querySelector('.wcos-split-policy');
                cancelButton = root.querySelector('.wcos-split-cancel');
                closeButton = root.querySelector('.wc-backbone-modal-header .modal-close');
            },
            onReady: function () {
                enhanceChildColumns();
                enhancePolicy();
                createEditButton();
                reviewButton.addEventListener('click', reviewPlan);
                executeButton.addEventListener('click', executePlan);
                confirmCheckbox.addEventListener('change', function () {
                    executeButton.disabled = busy || completed || !state || !confirmCheckbox.checked;
                });
                form.addEventListener('input', function (event) {
                    if (completed || !event.target.classList.contains('wcos-split-quantity')) {
                        return;
                    }
                    clearError();
                    setStatus('');
                    updateRemaining();
                    updateReviewAvailability();
                });
                updateReviewAvailability();
                var first = dialog.querySelector('.wcos-split-quantity:not([disabled])');
                if (first) {
                    first.focus();
                }
            }
        });
    }

    function methodLabel(raw) {
        var label = String(raw || '').trim();
        return label.replace(/^Split\s+/i, '').replace(/^by\s+/i, 'By ');
    }

    function createMethodOption(label, description, clickHandler) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'wcos-split-method-option';
        var title = document.createElement('strong');
        title.textContent = label;
        var detail = document.createElement('span');
        detail.textContent = description;
        button.appendChild(title);
        button.appendChild(detail);
        button.addEventListener('click', clickHandler);
        return button;
    }

    function openMethodChooser() {
        var handle = null;
        handle = window.WCOSBackboneModal.open({
            trigger: launcher,
            title: 'Split order',
            description: 'Choose how this order should be split. You will review the result before anything is changed.',
            modalClass: 'wcos-split-method-backbone-modal',
            build: function (body, footer) {
                var options = document.createElement('div');
                options.className = 'wcos-split-method-options';
                options.appendChild(createMethodOption(
                    'By quantity',
                    'Move exact quantities from one or more product lines into child orders.',
                    function () {
                        handle.close(false);
                        window.setTimeout(function () { openQuantityDialog(launcher); }, 0);
                    }
                ));
                strategyLaunchers.forEach(function (strategyLauncher) {
                    options.appendChild(createMethodOption(
                        methodLabel(strategyLauncher.textContent),
                        strategyLauncher._wcosDescription || 'Build child orders from the reviewed product classification.',
                        function () {
                            handle.close(false);
                            window.setTimeout(function () { strategyLauncher.click(); }, 0);
                        }
                    ));
                });
                body.appendChild(options);
                var cancel = document.createElement('button');
                cancel.type = 'button';
                cancel.className = 'button button-large modal-close';
                cancel.textContent = 'Cancel';
                footer.appendChild(cancel);
            }
        });
    }

    launcher.addEventListener('click', function () {
        if (strategyLaunchers.length) {
            openMethodChooser();
        } else {
            openQuantityDialog(launcher);
        }
    });
})();
