(function () {
    'use strict';

    var strings = window.wcosDuplicateAdminStrings || {};
    var launcher = document.querySelector('.wcos-duplicate-launcher');
    if (!launcher || !window.WCOSBackboneModal) {
        return;
    }

    var sourceDialogId = launcher.getAttribute('aria-controls');
    var sourceDialog = sourceDialogId ? document.getElementById(sourceDialogId) : null;
    if (!sourceDialog) {
        return;
    }

    function text(key, fallback) {
        return typeof strings[key] === 'string' && strings[key] ? strings[key] : fallback;
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
    launcher.removeAttribute('aria-controls');

    function cloneBody(sourcePanel, body) {
        Array.prototype.forEach.call(sourcePanel.children, function (child) {
            if (child.classList.contains('wcos-duplicate-dialog__header') || child.classList.contains('wcos-duplicate-dialog__actions')) {
                return;
            }
            body.appendChild(child.cloneNode(true));
        });
    }

    function cloneFooter(sourceActions, footer) {
        Array.prototype.forEach.call(sourceActions ? sourceActions.children : [], function (sourceButton) {
            var button = sourceButton.cloneNode(true);
            if (button.classList.contains('wcos-duplicate-cancel')) {
                button.classList.add('modal-close');
                button.classList.add('button-large');
            }
            footer.appendChild(button);
        });
    }

    function openDuplicateModal() {
        var busy = false;
        var completed = false;
        var completedPresentation = null;
        var state = null;
        var dialog = null;
        var closeButton = null;
        var cancelButton = null;
        var reviewButton = null;
        var executeButton = null;
        var confirmCheckbox = null;
        var reviewBox = null;
        var reviewSummary = null;
        var statusBox = null;
        var errorBox = null;
        var resultBox = null;

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

            return window.fetch(sourceDialog.getAttribute('data-ajax-url'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
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
            if (!busy && completedPresentation) {
                var presentation = completedPresentation;
                completedPresentation = null;
                // Optional presentation must never change the completed operation.
                try {
                    resultBox.dispatchEvent(new CustomEvent('wcos:operation-completed', { bubbles: true, detail: presentation }));
                } catch (error) {}
            }
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
                order_id: sourceDialog.getAttribute('data-order-id'),
                nonce: sourceDialog.getAttribute('data-nonce')
            }).then(function (data) {
                state = { operationId: data.operation_id, token: data.confirmation_token };
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
            completedPresentation = { action: 'duplicate', operationId: data.operation_id, status: data.status };
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
                order_id: sourceDialog.getAttribute('data-order-id'),
                nonce: sourceDialog.getAttribute('data-nonce'),
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

        window.WCOSBackboneModal.open({
            trigger: launcher,
            title: (sourceDialog.querySelector('.wcos-duplicate-dialog__header h2') || {}).textContent || 'Review order duplicate',
            description: (sourceDialog.querySelector('.wcos-duplicate-dialog__header p') || {}).textContent || launcherDescription,
            modalClass: 'wcos-duplicate-backbone-modal',
            isBusy: function () { return busy; },
            build: function (body, footer, root) {
                var sourcePanel = sourceDialog.querySelector('.wcos-duplicate-dialog__panel');
                var sourceActions = sourceDialog.querySelector('.wcos-duplicate-dialog__actions');
                cloneBody(sourcePanel, body);
                cloneFooter(sourceActions, footer);
                dialog = root;
                closeButton = root.querySelector('.wc-backbone-modal-header .modal-close');
                cancelButton = root.querySelector('.wcos-duplicate-cancel');
                reviewButton = root.querySelector('.wcos-duplicate-review-button');
                executeButton = root.querySelector('.wcos-duplicate-execute-button');
                confirmCheckbox = root.querySelector('.wcos-duplicate-confirm-checkbox');
                reviewBox = root.querySelector('.wcos-duplicate-review');
                reviewSummary = root.querySelector('.wcos-duplicate-review-summary');
                statusBox = root.querySelector('.wcos-duplicate-status');
                errorBox = root.querySelector('.wcos-duplicate-error');
                resultBox = root.querySelector('.wcos-duplicate-result');
            },
            onReady: function () {
                reviewButton.addEventListener('click', reviewDuplicate);
                executeButton.addEventListener('click', executeDuplicate);
                confirmCheckbox.addEventListener('change', function () {
                    executeButton.disabled = busy || completed || !state || !confirmCheckbox.checked;
                });
                reviewButton.focus();
            }
        });
    }

    launcher.addEventListener('click', openDuplicateModal);
})();
