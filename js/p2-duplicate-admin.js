(function () {
    'use strict';

    var strings = window.wcosDuplicateAdminStrings || {};
    var launcher = document.querySelector('.wcos-duplicate-launcher');
    if (!launcher) {
        return;
    }

    var dialogId = launcher.getAttribute('aria-controls');
    var dialog = dialogId ? document.getElementById(dialogId) : null;
    if (!dialog) {
        return;
    }

    var panel = dialog.querySelector('.wcos-duplicate-dialog__panel');
    var closeButton = dialog.querySelector('.wcos-duplicate-close');
    var cancelButton = dialog.querySelector('.wcos-duplicate-cancel');
    var reviewButton = dialog.querySelector('.wcos-duplicate-review-button');
    var executeButton = dialog.querySelector('.wcos-duplicate-execute-button');
    var confirmCheckbox = dialog.querySelector('.wcos-duplicate-confirm-checkbox');
    var reviewBox = dialog.querySelector('.wcos-duplicate-review');
    var reviewSummary = dialog.querySelector('.wcos-duplicate-review-summary');
    var statusBox = dialog.querySelector('.wcos-duplicate-status');
    var errorBox = dialog.querySelector('.wcos-duplicate-error');
    var resultBox = dialog.querySelector('.wcos-duplicate-result');
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
        document.body.classList.add('wcos-duplicate-modal-open');
        window.setTimeout(function () {
            var preferred = completed && !resultBox.hidden ? resultBox : reviewButton;
            (preferred || panel).focus();
        }, 0);
    }

    function closeDialog() {
        if (busy) {
            return;
        }
        dialog.hidden = true;
        document.body.classList.remove('wcos-duplicate-modal-open');
        if (returnFocus && typeof returnFocus.focus === 'function') {
            returnFocus.focus();
        }
    }

    function clearError() {
        errorBox.hidden = true;
        errorBox.textContent = '';
    }

    function showError(message) {
        errorBox.textContent = message || text('requestFailed', 'The Duplicate request could not be completed.');
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
                throw new Error(text('requestFailed', 'The Duplicate request could not be completed.'));
            });
        }).then(function (payload) {
            if (!payload || payload.success !== true) {
                var failure = payload && payload.data ? payload.data : {};
                var error = new Error(failure.message || text('requestFailed', 'The Duplicate request could not be completed.'));
                error.code = failure.code || 'request_failed';
                error.retryable = !!failure.retryable;
                throw error;
            }
            return payload.data || {};
        });
    }

    function setBusy(nextBusy) {
        busy = !!nextBusy;
        dialog.setAttribute('aria-busy', busy ? 'true' : 'false');
        reviewButton.disabled = busy || completed;
        confirmCheckbox.disabled = busy || completed;
        executeButton.disabled = busy || completed || !state || !confirmCheckbox.checked;
        cancelButton.disabled = busy;
        closeButton.disabled = busy;
    }

    function reviewDuplicate() {
        if (busy || completed) {
            return;
        }
        clearError();
        clearResult();
        invalidateReview();
        setBusy(true);
        setStatus(text('reviewing', 'Reviewing Duplicate…'));

        request('wcos_duplicate_review', {
            order_id: dialog.getAttribute('data-order-id'),
            nonce: dialog.getAttribute('data-nonce')
        }).then(function (data) {
            state = {
                operationId: data.operation_id,
                token: data.confirmation_token
            };
            var summary = data.summary || {};
            reviewSummary.textContent = text('reviewSummary', 'Reviewed lines / shipping / fees / coupons:') + ' ' +
                String(summary.line_count || 0) + ' / ' +
                String(summary.shipping_count || 0) + ' / ' +
                String(summary.fee_count || 0) + ' / ' +
                String(summary.coupon_count || 0);
            reviewBox.hidden = false;
            confirmCheckbox.checked = false;
            setStatus(text('reviewReady', 'The order passed server review. Confirm the acknowledgement to duplicate it.'));
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
        message.textContent = text('completed', 'Order duplicated successfully.');
        resultBox.appendChild(message);

        var target = data && data.target ? data.target : null;
        if (target) {
            var detail = document.createElement('p');
            if (target.edit_url) {
                var link = document.createElement('a');
                link.href = target.edit_url;
                link.textContent = text('targetOrder', 'Duplicated order') + ' #' + String(target.number || target.id || '');
                detail.appendChild(link);
            } else {
                detail.textContent = text('targetOrder', 'Duplicated order') + ' #' + String(target.number || target.id || '');
            }
            resultBox.appendChild(detail);
        }
        resultBox.hidden = false;
        resultBox.focus();
    }

    function executeDuplicate() {
        if (busy || completed || !state || !confirmCheckbox.checked) {
            return;
        }
        clearError();
        clearResult();
        setBusy(true);
        setStatus(text('executing', 'Duplicating order…'));

        request('wcos_duplicate_execute', {
            order_id: dialog.getAttribute('data-order-id'),
            nonce: dialog.getAttribute('data-nonce'),
            operation_id: state.operationId,
            confirmation_token: state.token
        }).then(function (data) {
            completed = true;
            setStatus(text('completed', 'Order duplicated successfully.'));
            confirmCheckbox.disabled = true;
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
    reviewButton.addEventListener('click', reviewDuplicate);
    executeButton.addEventListener('click', executeDuplicate);
    confirmCheckbox.addEventListener('change', function () {
        executeButton.disabled = busy || completed || !state || !confirmCheckbox.checked;
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
