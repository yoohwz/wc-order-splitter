(function () {
    'use strict';

    var strings = window.wcosSplitAdminStrings || {};
    var launcher = document.querySelector('.wcos-split-launcher');
    if (!launcher) {
        return;
    }

    var dialogId = launcher.getAttribute('aria-controls');
    var dialog = dialogId ? document.getElementById(dialogId) : null;
    if (!dialog) {
        return;
    }

    var panel = dialog.querySelector('.wcos-split-dialog__panel');
    var form = dialog.querySelector('.wcos-split-form');
    var closeButton = dialog.querySelector('.wcos-split-close');
    var cancelButton = dialog.querySelector('.wcos-split-cancel');
    var reviewButton = dialog.querySelector('.wcos-split-review-button');
    var executeButton = dialog.querySelector('.wcos-split-execute-button');
    var confirmCheckbox = dialog.querySelector('.wcos-split-confirm-checkbox');
    var reviewBox = dialog.querySelector('.wcos-split-review');
    var reviewSummary = dialog.querySelector('.wcos-split-review-summary');
    var statusBox = dialog.querySelector('.wcos-split-status');
    var errorBox = dialog.querySelector('.wcos-split-error');
    var resultBox = dialog.querySelector('.wcos-split-result');
    var state = null;
    var returnFocus = null;
    var busy = false;
    var completed = false;

    function text(key, fallback) {
        return typeof strings[key] === 'string' && strings[key] ? strings[key] : fallback;
    }

    function focusableElements() {
        return Array.prototype.slice.call(dialog.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )).filter(function (element) {
            return !element.hidden && element.offsetParent !== null;
        });
    }

    function openDialog() {
        returnFocus = document.activeElement;
        dialog.hidden = false;
        document.body.classList.add('wcos-split-modal-open');
        window.setTimeout(function () {
            var preferred = completed && !resultBox.hidden
                ? resultBox
                : dialog.querySelector('.wcos-split-quantity:not([disabled])');
            (preferred || panel).focus();
        }, 0);
    }

    function closeDialog() {
        if (busy) {
            return;
        }
        dialog.hidden = true;
        document.body.classList.remove('wcos-split-modal-open');
        if (returnFocus && typeof returnFocus.focus === 'function') {
            returnFocus.focus();
        }
    }

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

    function invalidateReview() {
        if (completed) {
            return;
        }
        state = null;
        reviewBox.hidden = true;
        reviewSummary.textContent = '';
        confirmCheckbox.checked = false;
        executeButton.disabled = true;
        clearResult();
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

    function request(action, data) {
        var body = new URLSearchParams();
        body.set('action', action);
        Object.keys(data).forEach(function (key) {
            body.set(key, String(data[key]));
        });

        return window.fetch(dialog.getAttribute('data-ajax-url'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
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
        });
    }

    function setBusy(nextBusy) {
        busy = !!nextBusy;
        form.setAttribute('aria-busy', busy ? 'true' : 'false');
        reviewButton.disabled = busy || completed;
        confirmCheckbox.disabled = busy || completed;
        executeButton.disabled = busy || completed || !state || !confirmCheckbox.checked;
        cancelButton.disabled = busy;
        closeButton.disabled = busy;
    }

    function reviewPlan() {
        if (busy || completed) {
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
            order_id: dialog.getAttribute('data-order-id'),
            nonce: dialog.getAttribute('data-nonce'),
            plan: JSON.stringify(plan)
        }).then(function (data) {
            state = {
                operationId: data.operation_id,
                token: data.confirmation_token
            };
            var summary = data.summary || {};
            reviewSummary.textContent = text('reviewSummary', 'Reviewed children / affected lines / moved quantity:') + ' ' +
                String(summary.child_count || 0) + ' / ' +
                String(summary.affected_line_count || 0) + ' / ' +
                String(summary.moved_quantity || '0');
            reviewBox.hidden = false;
            confirmCheckbox.checked = false;
            setStatus(text('reviewReady', 'The plan passed server review. Confirm the acknowledgement to execute it.'));
            confirmCheckbox.focus();
        }).catch(function (error) {
            invalidateReview();
            setStatus('');
            showError(error.message);
        }).finally(function () {
            setBusy(false);
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
        reload.addEventListener('click', function () {
            window.location.reload();
        });
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
            order_id: dialog.getAttribute('data-order-id'),
            nonce: dialog.getAttribute('data-nonce'),
            operation_id: state.operationId,
            confirmation_token: state.token
        }).then(function (data) {
            completed = true;
            setStatus(text('completed', 'Split completed successfully.'));
            Array.prototype.forEach.call(dialog.querySelectorAll('input, select'), function (field) {
                field.disabled = true;
            });
            renderSuccess(data);
        }).catch(function (error) {
            if (!error.retryable) {
                invalidateReview();
            }
            setStatus('');
            showError(error.message);
        }).finally(function () {
            setBusy(false);
        });
    }

    launcher.addEventListener('click', openDialog);
    closeButton.addEventListener('click', closeDialog);
    cancelButton.addEventListener('click', closeDialog);
    reviewButton.addEventListener('click', reviewPlan);
    executeButton.addEventListener('click', executePlan);
    confirmCheckbox.addEventListener('change', function () {
        executeButton.disabled = busy || completed || !state || !confirmCheckbox.checked;
    });

    form.addEventListener('input', function (event) {
        if (completed) {
            return;
        }
        if (event.target.classList.contains('wcos-split-quantity')) {
            invalidateReview();
            clearError();
            setStatus('');
        }
    });
    dialog.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeDialog();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }
        var focusable = focusableElements();
        if (!focusable.length) {
            event.preventDefault();
            panel.focus();
            return;
        }
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
})();
