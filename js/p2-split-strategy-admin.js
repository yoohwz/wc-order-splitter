(function () {
    'use strict';

    var strings = window.wcosSplitStrategyStrings || {};
    if (!window.WCOSBackboneModal) {
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

    function requireRef(element, label) {
        if (!element) {
            throw new Error('Strategy Split modal failed to bind ' + label + '.');
        }
        return element;
    }

    function request(sourceDialog, action, data) {
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
                throw new Error(text('requestFailed', 'The strategy Split request could not be completed.'));
            });
        }).then(function (payload) {
            if (!payload || payload.success !== true) {
                var failure = payload && payload.data ? payload.data : {};
                var error = new Error(failure.message || text('requestFailed', 'The strategy Split request could not be completed.'));
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

    function cloneFooter(sourceActions, footer) {
        Array.prototype.forEach.call(sourceActions ? sourceActions.children : [], function (sourceButton) {
            var button = sourceButton.cloneNode(true);
            if (button.classList.contains('wcos-strategy-cancel')) {
                button.classList.add('modal-close');
                button.classList.add('button-large');
            }
            footer.appendChild(button);
        });
    }

    function setupLauncher(launcher) {
        var sourceDialogId = launcher.getAttribute('aria-controls');
        var sourceDialog = sourceDialogId ? document.getElementById(sourceDialogId) : null;
        if (!sourceDialog) {
            return;
        }

        if (!launcher._wcosDescription) {
            launcher._wcosDescription = removeExternalDescription(launcher);
        } else {
            removeExternalDescription(launcher);
        }
        launcher.hidden = true;

        function openStrategyModal() {
            var reviewState = null;
            var confirmationState = null;
            var selectedBucket = '';
            var busy = false;
            var completed = false;
            var dialog = null;
            var form = null;
            var closeButton = null;
            var cancelButton = null;
            var reviewButton = null;
            var reviewSection = null;
            var reviewSummary = null;
            var bucketOptions = null;
            var confirmButton = null;
            var confirmationSection = null;
            var confirmationSummary = null;
            var confirmCheckbox = null;
            var executeButton = null;
            var statusBox = null;
            var errorBox = null;
            var resultBox = null;
            var feedbackBox = null;

            function showReviewAction() {
                reviewButton.hidden = false;
                confirmButton.hidden = true;
                executeButton.hidden = true;
            }

            function showConfirmAction() {
                reviewButton.hidden = true;
                confirmButton.hidden = false;
                executeButton.hidden = true;
            }

            function showExecuteAction() {
                reviewButton.hidden = true;
                confirmButton.hidden = true;
                executeButton.hidden = false;
            }

            function hideWorkflowActions() {
                reviewButton.hidden = true;
                confirmButton.hidden = true;
                executeButton.hidden = true;
            }

            function clearError() {
                errorBox.hidden = true;
                errorBox.textContent = '';
            }

            function showError(message) {
                errorBox.textContent = message || text('requestFailed', 'The strategy Split request could not be completed.');
                errorBox.hidden = false;
                errorBox.focus();
            }

            function setStatus(message) {
                statusBox.textContent = message || '';
                statusBox.hidden = !message;
            }

            function clearResult() {
                resultBox.hidden = true;
                resultBox.textContent = '';
            }

            function clearBucketOptions() {
                bucketOptions.textContent = '';
                selectedBucket = '';
            }

            function invalidateConfirmation() {
                if (completed) {
                    return;
                }
                confirmationState = null;
                confirmationSection.hidden = true;
                confirmationSummary.textContent = '';
                confirmCheckbox.checked = false;
                executeButton.disabled = true;
                executeButton.hidden = true;
                if (reviewState) {
                    showConfirmAction();
                }
            }

            function invalidateReview() {
                if (completed) {
                    return;
                }
                reviewState = null;
                invalidateConfirmation();
                reviewSection.hidden = true;
                reviewSummary.textContent = '';
                clearBucketOptions();
                confirmButton.disabled = true;
                showReviewAction();
            }

            function setBusy(nextBusy) {
                busy = !!nextBusy;
                form.setAttribute('aria-busy', busy ? 'true' : 'false');
                reviewButton.disabled = busy || completed || !!confirmationState;
                Array.prototype.forEach.call(dialog.querySelectorAll('.wcos-strategy-bucket-radio'), function (field) {
                    field.disabled = busy || completed || !!confirmationState;
                });
                confirmButton.disabled = busy || completed || !!confirmationState || !reviewState || !selectedBucket;
                confirmCheckbox.disabled = busy || completed || !confirmationState;
                executeButton.disabled = busy || completed || !confirmationState || !confirmCheckbox.checked;
                closeButton.disabled = busy;
                cancelButton.disabled = busy;
            }

            function bucketQuantity(items) {
                var total = 0;
                Object.keys(items || {}).forEach(function (itemId) {
                    var value = Number(items[itemId]);
                    if (Number.isFinite(value)) {
                        total += value;
                    }
                });
                return total;
            }

            function renderBuckets(review) {
                clearBucketOptions();
                var buckets = review && review.buckets ? review.buckets : {};
                var keys = Object.keys(buckets).sort();
                if (keys.length < 2) {
                    throw new Error(text('requestFailed', 'The reviewed strategy did not return enough buckets to split.'));
                }

                keys.forEach(function (bucketKey, index) {
                    var bucket = buckets[bucketKey] || {};
                    var items = bucket.items || {};
                    var option = document.createElement('label');
                    option.className = 'wcos-strategy-bucket-option';
                    var radio = document.createElement('input');
                    radio.type = 'radio';
                    radio.name = sourceDialogId + '-source-bucket';
                    radio.value = bucketKey;
                    radio.className = 'wcos-strategy-bucket-radio';
                    radio.id = sourceDialogId + '-bucket-' + String(index + 1);
                    var content = document.createElement('span');
                    content.className = 'wcos-strategy-bucket-content';
                    var title = document.createElement('strong');
                    title.textContent = String(bucket.label || bucketKey);
                    var detail = document.createElement('span');
                    detail.className = 'description';
                    detail.textContent = String(Object.keys(items).length) + ' ' + text('bucketLines', 'product lines') +
                        ' · ' + String(bucketQuantity(items)) + ' ' + text('bucketQuantity', 'total quantity');
                    content.appendChild(title);
                    content.appendChild(detail);
                    option.appendChild(radio);
                    option.appendChild(content);
                    bucketOptions.appendChild(option);
                });
            }

            function reviewStrategy() {
                if (busy || completed || confirmationState) {
                    return;
                }
                clearError();
                clearResult();
                invalidateReview();
                setBusy(true);
                setStatus(text('reviewing', 'Reviewing current strategy buckets…'));

                request(sourceDialog, sourceDialog.getAttribute('data-review-action'), {
                    order_id: sourceDialog.getAttribute('data-order-id'),
                    nonce: sourceDialog.getAttribute('data-nonce'),
                    strategy: sourceDialog.getAttribute('data-strategy')
                }).then(function (data) {
                    reviewState = { reviewId: data.review_id, token: data.review_token };
                    renderBuckets(data.review || {});
                    reviewSummary.textContent = data.review && data.review.message ? String(data.review.message) : '';
                    reviewSection.hidden = false;
                    showConfirmAction();
                    setStatus(text('reviewReady', 'Choose the one bucket that must remain on the source order.'));
                    var firstRadio = bucketOptions.querySelector('.wcos-strategy-bucket-radio');
                    (firstRadio || reviewSection).focus();
                }).catch(function (error) {
                    invalidateReview();
                    setStatus('');
                    showError(error.message);
                }).finally(function () {
                    setBusy(false);
                });
            }

            function confirmStrategy() {
                if (busy || completed || !reviewState || !selectedBucket || confirmationState) {
                    if (!selectedBucket) {
                        showError(text('chooseBucket', 'Choose a source bucket before confirming.'));
                    }
                    return;
                }
                clearError();
                setBusy(true);
                setStatus(text('confirming', 'Confirming the frozen strategy plan…'));

                request(sourceDialog, sourceDialog.getAttribute('data-confirm-action'), {
                    order_id: sourceDialog.getAttribute('data-order-id'),
                    nonce: sourceDialog.getAttribute('data-nonce'),
                    strategy: sourceDialog.getAttribute('data-strategy'),
                    review_id: reviewState.reviewId,
                    review_token: reviewState.token,
                    source_bucket_key: selectedBucket
                }).then(function (data) {
                    confirmationState = {
                        operationId: data.operation_id,
                        token: data.confirmation_token,
                        sourceBucket: data.source_bucket_key
                    };
                    reviewState = null;
                    confirmationSummary.textContent = text('confirmationReady', 'The plan is frozen. Acknowledge the policy and execute when ready.');
                    confirmationSection.hidden = false;
                    confirmCheckbox.checked = false;
                    showExecuteAction();
                    setStatus(text('confirmationReady', 'The plan is frozen. Acknowledge the policy and execute when ready.'));
                    confirmCheckbox.focus();
                }).catch(function (error) {
                    if (!error.retryable) {
                        invalidateReview();
                    } else {
                        showConfirmAction();
                    }
                    setStatus('');
                    showError(error.message);
                }).finally(function () {
                    setBusy(false);
                });
            }

            function renderSuccess(data) {
                resultBox.textContent = '';
                var message = document.createElement('p');
                message.textContent = text('completed', 'Strategy Split completed successfully.');
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

            function executeStrategy() {
                if (busy || completed || !confirmationState || !confirmCheckbox.checked) {
                    return;
                }
                clearError();
                clearResult();
                setBusy(true);
                setStatus(text('executing', 'Executing strategy Split…'));

                request(sourceDialog, sourceDialog.getAttribute('data-execute-action'), {
                    order_id: sourceDialog.getAttribute('data-order-id'),
                    nonce: sourceDialog.getAttribute('data-nonce'),
                    strategy: sourceDialog.getAttribute('data-strategy'),
                    operation_id: confirmationState.operationId,
                    confirmation_token: confirmationState.token
                }).then(function (data) {
                    completed = true;
                    hideWorkflowActions();
                    setStatus(text('completed', 'Strategy Split completed successfully.'));
                    Array.prototype.forEach.call(dialog.querySelectorAll('input, button'), function (field) {
                        if (!field.classList.contains('wcos-strategy-cancel') && !field.classList.contains('modal-close')) {
                            field.disabled = true;
                        }
                    });
                    renderSuccess(data);
                }).catch(function (error) {
                    if (!error.retryable) {
                        invalidateReview();
                    } else {
                        showExecuteAction();
                    }
                    setStatus('');
                    showError(error.message);
                }).finally(function () {
                    setBusy(false);
                });
            }

            window.WCOSBackboneModal.open({
                trigger: launcher,
                title: (sourceDialog.querySelector('.wcos-strategy-dialog__header h2') || {}).textContent || launcher.textContent.trim(),
                description: (sourceDialog.querySelector('.wcos-strategy-dialog__header p') || {}).textContent || launcher._wcosDescription,
                modalClass: 'wcos-strategy-backbone-modal',
                isBusy: function () { return busy; },
                build: function (body, footer, root) {
                    var sourceForm = sourceDialog.querySelector('.wcos-strategy-form');
                    var sourceActions = sourceDialog.querySelector('.wcos-strategy-dialog__actions');
                    var clonedForm = requireRef(sourceForm, 'source form').cloneNode(true);
                    var clonedActions = clonedForm.querySelector('.wcos-strategy-dialog__actions');
                    if (clonedActions && clonedActions.parentNode) {
                        clonedActions.parentNode.removeChild(clonedActions);
                    }
                    body.appendChild(clonedForm);
                    cloneFooter(sourceActions, footer);

                    dialog = root;
                    form = clonedForm;
                    closeButton = requireRef(root.querySelector('.wc-backbone-modal-header .modal-close'), 'close button');
                    cancelButton = requireRef(footer.querySelector('.wcos-strategy-cancel'), 'cancel button');
                    reviewButton = requireRef(clonedForm.querySelector('.wcos-strategy-review-button'), 'Review button');
                    reviewSection = requireRef(clonedForm.querySelector('.wcos-strategy-review'), 'Review section');
                    reviewSummary = requireRef(clonedForm.querySelector('.wcos-strategy-review-summary'), 'Review summary');
                    bucketOptions = requireRef(clonedForm.querySelector('.wcos-strategy-bucket-options'), 'bucket options');
                    confirmButton = requireRef(clonedForm.querySelector('.wcos-strategy-confirm-button'), 'Confirm button');
                    confirmationSection = requireRef(clonedForm.querySelector('.wcos-strategy-confirmation'), 'confirmation section');
                    confirmationSummary = requireRef(clonedForm.querySelector('.wcos-strategy-confirmation-summary'), 'confirmation summary');
                    confirmCheckbox = requireRef(clonedForm.querySelector('.wcos-strategy-confirm-checkbox'), 'confirmation checkbox');
                    executeButton = requireRef(clonedForm.querySelector('.wcos-strategy-execute-button'), 'Execute button');
                    statusBox = requireRef(clonedForm.querySelector('.wcos-strategy-status'), 'status region');
                    errorBox = requireRef(clonedForm.querySelector('.wcos-strategy-error'), 'error region');
                    resultBox = requireRef(clonedForm.querySelector('.wcos-strategy-result'), 'result region');

                    feedbackBox = document.createElement('div');
                    feedbackBox.className = 'wcos-strategy-feedback';
                    clonedForm.insertBefore(feedbackBox, clonedForm.firstChild);
                    feedbackBox.appendChild(statusBox);
                    feedbackBox.appendChild(errorBox);
                    feedbackBox.appendChild(resultBox);
                    statusBox.hidden = true;
                    errorBox.classList.add('notice', 'notice-error', 'inline');
                    resultBox.classList.add('notice', 'notice-success', 'inline');

                    footer.appendChild(reviewButton);
                    footer.appendChild(confirmButton);
                    footer.appendChild(executeButton);
                    reviewButton.classList.add('button-primary', 'button-large');
                    confirmButton.classList.remove('button-secondary');
                    confirmButton.classList.add('button-primary', 'button-large');
                    executeButton.classList.add('button-large');
                    showReviewAction();

                    var reviewControls = clonedForm.querySelector('.wcos-strategy-review-controls');
                    if (reviewControls && !reviewControls.children.length && reviewControls.parentNode) {
                        reviewControls.parentNode.removeChild(reviewControls);
                    }
                },
                onReady: function () {
                    reviewButton.addEventListener('click', reviewStrategy);
                    confirmButton.addEventListener('click', confirmStrategy);
                    executeButton.addEventListener('click', executeStrategy);
                    confirmCheckbox.addEventListener('change', function () {
                        executeButton.disabled = busy || completed || !confirmationState || !confirmCheckbox.checked;
                    });
                    bucketOptions.addEventListener('change', function (event) {
                        if (completed || confirmationState || !event.target.classList.contains('wcos-strategy-bucket-radio')) {
                            return;
                        }
                        selectedBucket = event.target.value || '';
                        invalidateConfirmation();
                        clearError();
                        confirmButton.disabled = busy || !reviewState || !selectedBucket;
                    });
                    reviewButton.focus();
                }
            });
        }

        launcher.addEventListener('click', openStrategyModal);
    }

    Array.prototype.forEach.call(document.querySelectorAll('.wcos-strategy-launcher'), setupLauncher);
})();
