(function () {
    'use strict';

    var strings = window.wcosSplitStrategyStrings || {};

    function text(key, fallback) {
        return typeof strings[key] === 'string' && strings[key] ? strings[key] : fallback;
    }

    function request(dialog, action, data) {
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
            /*
             * An unclassified fetch/JSON transport failure may happen after the
             * server accepted Execute. Preserve operation/token state so the
             * same idempotent operation can be retried. Structured server errors
             * already carry an explicit retryable boolean and are not changed.
             */
            if (typeof error.retryable !== 'boolean') {
                error.retryable = true;
            }
            throw error;
        });
    }

    function setupLauncher(launcher) {
        var dialogId = launcher.getAttribute('aria-controls');
        var dialog = dialogId ? document.getElementById(dialogId) : null;
        if (!dialog) {
            return;
        }

        var panel = dialog.querySelector('.wcos-strategy-dialog__panel');
        var form = dialog.querySelector('.wcos-strategy-form');
        var closeButton = dialog.querySelector('.wcos-strategy-close');
        var cancelButton = dialog.querySelector('.wcos-strategy-cancel');
        var reviewButton = dialog.querySelector('.wcos-strategy-review-button');
        var reviewSection = dialog.querySelector('.wcos-strategy-review');
        var reviewSummary = dialog.querySelector('.wcos-strategy-review-summary');
        var bucketOptions = dialog.querySelector('.wcos-strategy-bucket-options');
        var confirmButton = dialog.querySelector('.wcos-strategy-confirm-button');
        var confirmationSection = dialog.querySelector('.wcos-strategy-confirmation');
        var confirmationSummary = dialog.querySelector('.wcos-strategy-confirmation-summary');
        var confirmCheckbox = dialog.querySelector('.wcos-strategy-confirm-checkbox');
        var executeButton = dialog.querySelector('.wcos-strategy-execute-button');
        var statusBox = dialog.querySelector('.wcos-strategy-status');
        var errorBox = dialog.querySelector('.wcos-strategy-error');
        var resultBox = dialog.querySelector('.wcos-strategy-result');
        var reviewState = null;
        var confirmationState = null;
        var selectedBucket = '';
        var busy = false;
        var completed = false;
        var returnFocus = null;

        function focusableElements() {
            return Array.prototype.slice.call(dialog.querySelectorAll(
                'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            )).filter(function (element) {
                return !element.hidden && element.offsetParent !== null;
            });
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

        function openDialog() {
            returnFocus = document.activeElement;
            dialog.hidden = false;
            document.body.classList.add('wcos-strategy-modal-open');
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
            document.body.classList.remove('wcos-strategy-modal-open');
            if (returnFocus && typeof returnFocus.focus === 'function') {
                returnFocus.focus();
            }
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
                radio.name = dialogId + '-source-bucket';
                radio.value = bucketKey;
                radio.className = 'wcos-strategy-bucket-radio';
                radio.id = dialogId + '-bucket-' + String(index + 1);

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

            request(dialog, dialog.getAttribute('data-review-action'), {
                order_id: dialog.getAttribute('data-order-id'),
                nonce: dialog.getAttribute('data-nonce'),
                strategy: dialog.getAttribute('data-strategy')
            }).then(function (data) {
                reviewState = {
                    reviewId: data.review_id,
                    token: data.review_token
                };
                renderBuckets(data.review || {});
                reviewSummary.textContent = data.review && data.review.message ? String(data.review.message) : '';
                reviewSection.hidden = false;
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

            request(dialog, dialog.getAttribute('data-confirm-action'), {
                order_id: dialog.getAttribute('data-order-id'),
                nonce: dialog.getAttribute('data-nonce'),
                strategy: dialog.getAttribute('data-strategy'),
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
                setStatus(text('confirmationReady', 'The plan is frozen. Acknowledge the policy and execute when ready.'));
                confirmCheckbox.focus();
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
            reload.addEventListener('click', function () {
                window.location.reload();
            });
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

            request(dialog, dialog.getAttribute('data-execute-action'), {
                order_id: dialog.getAttribute('data-order-id'),
                nonce: dialog.getAttribute('data-nonce'),
                strategy: dialog.getAttribute('data-strategy'),
                operation_id: confirmationState.operationId,
                confirmation_token: confirmationState.token
            }).then(function (data) {
                completed = true;
                setStatus(text('completed', 'Strategy Split completed successfully.'));
                Array.prototype.forEach.call(dialog.querySelectorAll('input, button'), function (field) {
                    if (!field.classList.contains('wcos-strategy-cancel') && !field.classList.contains('wcos-strategy-close')) {
                        field.disabled = true;
                    }
                });
                renderSuccess(data);
            }).catch(function (error) {
                if (!error.retryable) {
                    confirmationState = null;
                    confirmationSection.hidden = true;
                    confirmCheckbox.checked = false;
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
    }

    Array.prototype.forEach.call(document.querySelectorAll('.wcos-strategy-launcher'), setupLauncher);
})();
