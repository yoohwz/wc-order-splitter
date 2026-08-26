(function () {
	'use strict';

	var strings = window.wcosReturnAdminStrings || {};
	var launcher = document.querySelector('.wcos-return-launcher');
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
			if (child.classList.contains('wcos-return-dialog__header') || child.classList.contains('wcos-return-dialog__actions')) {
				return;
			}
			body.appendChild(child.cloneNode(true));
		});
	}

	function cloneFooter(sourceActions, footer) {
		Array.prototype.forEach.call(sourceActions ? sourceActions.children : [], function (sourceButton) {
			var button = sourceButton.cloneNode(true);
			if (button.classList.contains('wcos-return-cancel')) {
				button.classList.add('modal-close');
				button.classList.add('button-large');
			}
			footer.appendChild(button);
		});
	}

	function openReturnModal() {
		var phase = 'initial';
		var busy = false;
		var reviewAuthority = null;
		var confirmationAuthority = null;
		var retryReady = false;
		var dialog;
		var closeButton;
		var cancelButton;
		var reviewButton;
		var confirmButton;
		var executeButton;
		var confirmCheckbox;
		var reviewBox;
		var reviewSummary;
		var statusBox;
		var errorBox;
		var resultBox;

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
					var parseError = new Error(text('requestFailed', 'The Return request could not be completed.'));
					parseError.retryable = true;
					parseError.transportFailure = true;
					throw parseError;
				});
			}).then(function (payload) {
				if (!payload || payload.success !== true) {
					var failure = payload && payload.data ? payload.data : {};
					var error = new Error(failure.message || text('requestFailed', 'The Return request could not be completed.'));
					error.code = failure.code || 'request_failed';
					error.retryable = !!failure.retryable;
					throw error;
				}
				return payload.data || {};
			}).catch(function (error) {
				if (typeof error.retryable !== 'boolean') {
					error.retryable = true;
					error.transportFailure = true;
				}
				throw error;
			});
		}

		function clearError() {
			errorBox.hidden = true;
			errorBox.textContent = '';
		}

		function showError(message) {
			errorBox.textContent = message || text('requestFailed', 'The Return request could not be completed.');
			errorBox.hidden = false;
			errorBox.focus();
		}

		function setStatus(message) {
			statusBox.textContent = message || '';
		}

		function addSummary(label, value) {
			var term = document.createElement('dt');
			var detail = document.createElement('dd');
			term.textContent = label;
			detail.textContent = value;
			reviewSummary.appendChild(term);
			reviewSummary.appendChild(detail);
		}

		function renderReview(summary) {
			var child = summary.child || {};
			var original = summary.original || {};
			var retirement = summary.retirement || {};
			var currency = String(summary.currency || '');
			reviewSummary.textContent = '';
			addSummary(text('childOrder', 'Current child'), '#' + String(child.number || child.id || ''));
			addSummary(text('originalOrder', 'Server-resolved original'), '#' + String(original.number || original.id || ''));
			addSummary(text('strategy', 'Split strategy'), String(summary.strategy || ''));
			addSummary(text('linesQuantity', 'Returned lines / quantity'), String(summary.returned_line_count || 0) + ' / ' + String(summary.quantity || '0'));
			addSummary(text('historicalValues', 'Historical subtotal / total / tax'), String(summary.historical_subtotal || '0') + ' / ' + String(summary.historical_total || '0') + ' / ' + String(summary.historical_tax || '0') + ' ' + currency);
			addSummary(text('retirement', 'Child retirement'), String(retirement.policy || 'non_force_trash_archive') + ' / ' + String(retirement.child_status_after || 'trash'));
			reviewBox.hidden = false;
		}

		function clearResult() {
			resultBox.hidden = true;
			resultBox.textContent = '';
		}

		function setPhase(nextPhase) {
			phase = nextPhase;
			reviewButton.hidden = 'initial' !== phase;
			confirmButton.hidden = 'reviewed' !== phase;
			executeButton.hidden = 'confirmed' !== phase && 'executing' !== phase;
			if ('reviewed' === phase) {
				confirmCheckbox.disabled = busy;
			} else {
				confirmCheckbox.disabled = true;
			}
		}

		function updateControls() {
			dialog.setAttribute('aria-busy', busy ? 'true' : 'false');
			cancelButton.disabled = busy;
			closeButton.disabled = busy;
			reviewButton.disabled = busy || 'initial' !== phase;
			confirmButton.disabled = busy || 'reviewed' !== phase || !reviewAuthority || !confirmCheckbox.checked;
			executeButton.disabled = busy || ('confirmed' !== phase && 'executing' !== phase) || !confirmationAuthority;
			confirmCheckbox.disabled = busy || 'reviewed' !== phase;
		}

		function setBusy(nextBusy) {
			busy = !!nextBusy;
			updateControls();
		}

		function resetForExplicitReview() {
			reviewAuthority = null;
			confirmationAuthority = null;
			retryReady = false;
			reviewBox.hidden = true;
			reviewSummary.textContent = '';
			confirmCheckbox.checked = false;
			executeButton.textContent = text('executeReturn', 'Execute return');
			clearResult();
			setPhase('initial');
			updateControls();
		}

		function reviewReturn() {
			if (busy || 'initial' !== phase) {
				return;
			}
			clearError();
			clearResult();
			setBusy(true);
			setStatus(text('reviewing', 'Reviewing Return authority…'));
			request(sourceDialog.getAttribute('data-review-action'), {
				child_order_id: sourceDialog.getAttribute('data-child-order-id'),
				nonce: sourceDialog.getAttribute('data-nonce')
			}).then(function (data) {
				reviewAuthority = { reviewId: data.review_id, token: data.review_token };
				renderReview(data.summary || {});
				confirmCheckbox.checked = false;
				setPhase('reviewed');
				setStatus(text('reviewReady', 'The child passed server review. Acknowledge the immutable summary to confirm Return.'));
				confirmCheckbox.focus();
			}).catch(function (error) {
				resetForExplicitReview();
				setStatus('');
				showError(error.message);
			}).finally(function () {
				setBusy(false);
			});
		}

		function confirmReturn() {
			if (busy || 'reviewed' !== phase || !reviewAuthority || !confirmCheckbox.checked) {
				return;
			}
			clearError();
			setBusy(true);
			setStatus(text('confirming', 'Confirming reviewed Return authority…'));
			request(sourceDialog.getAttribute('data-confirm-action'), {
				child_order_id: sourceDialog.getAttribute('data-child-order-id'),
				nonce: sourceDialog.getAttribute('data-nonce'),
				review_id: reviewAuthority.reviewId,
				review_token: reviewAuthority.token
			}).then(function (data) {
				confirmationAuthority = { operationId: data.operation_id, token: data.confirmation_token };
				reviewAuthority = null;
				retryReady = false;
				setPhase('confirmed');
				setStatus(text('confirmReady', 'Return is confirmed. Execute this exact operation when ready.'));
				executeButton.focus();
			}).catch(function (error) {
				reviewAuthority = null;
				confirmationAuthority = null;
				retryReady = false;
				confirmCheckbox.disabled = true;
				setPhase('closed');
				setStatus(text('newReviewRequired', 'This Review is no longer valid. Close or explicitly review the child again.'));
				showError(error.message);
			}).finally(function () {
				setBusy(false);
			});
		}

		function executeSameOperation() {
			return request(sourceDialog.getAttribute('data-execute-action'), {
				child_order_id: sourceDialog.getAttribute('data-child-order-id'),
				nonce: sourceDialog.getAttribute('data-nonce'),
				operation_id: confirmationAuthority.operationId,
				confirmation_token: confirmationAuthority.token
			});
		}

		function renderSuccess(data) {
			if (!data || data.status !== 'completed') {
				var terminalError = new Error(text('closedOperation', 'This Return operation did not complete.'));
				terminalError.retryable = false;
				throw terminalError;
			}
			resultBox.textContent = '';
			var message = document.createElement('p');
			message.textContent = text('completed', 'Return completed. The child is retired and the original is active.');
			resultBox.appendChild(message);
			var original = data.original || null;
			if (original) {
				var detail = document.createElement('p');
				if (original.edit_url) {
					var link = document.createElement('a');
					link.href = original.edit_url;
					link.textContent = text('originalOrder', 'Active original order') + ' #' + String(original.number || original.id || '');
					detail.appendChild(link);
				} else {
					detail.textContent = text('originalOrder', 'Active original order') + ' #' + String(original.number || original.id || '');
				}
				resultBox.appendChild(detail);
			}
			resultBox.hidden = false;
			resultBox.focus();
		}

		function executeReturn() {
			if (busy || 'confirmed' !== phase || !confirmationAuthority) {
				return;
			}
			clearError();
			setPhase('executing');
			setBusy(true);
			setStatus(retryReady ? text('retrying', 'Retrying the same Return operation…') : text('executing', 'Returning operational ownership to the original order…'));
			executeSameOperation().then(function (data) {
				renderSuccess(data);
				confirmationAuthority = null;
				retryReady = false;
				setPhase('completed');
				setStatus('');
			}).catch(function (error) {
				if (error.retryable && confirmationAuthority) {
					retryReady = true;
					executeButton.textContent = text('retryReturn', 'Retry same return');
					setPhase('confirmed');
					setStatus(text('retrying', 'Retry the same Return operation; Review and Confirm will not be repeated.'));
				} else {
					confirmationAuthority = null;
					retryReady = false;
					setPhase('closed');
					setStatus(text('closedOperation', 'This Return operation did not complete and cannot be restarted from this modal.'));
				}
				showError(error.message);
			}).finally(function () {
				setBusy(false);
			});
		}

		var sourcePanel = sourceDialog.querySelector('.wcos-return-dialog__panel');
		var sourceActions = sourceDialog.querySelector('.wcos-return-dialog__actions');
		var modal = window.WCOSBackboneModal.open({
			trigger: launcher,
			title: (sourceDialog.querySelector('.wcos-return-dialog__header h2') || {}).textContent || launcher.textContent.trim(),
			description: (sourceDialog.querySelector('.wcos-return-dialog__header p') || {}).textContent || launcherDescription,
			modalClass: 'wcos-return-backbone-modal',
			isBusy: function () { return busy; },
			build: function (body, footer, root, handle) {
				cloneBody(sourcePanel, body);
				cloneFooter(sourceActions, footer);
				dialog = root;
				closeButton = root.querySelector('.wc-backbone-modal-header .modal-close');
				cancelButton = root.querySelector('.wcos-return-cancel');
				reviewButton = root.querySelector('.wcos-return-review-button');
				confirmButton = root.querySelector('.wcos-return-confirm-button');
				executeButton = root.querySelector('.wcos-return-execute-button');
				confirmCheckbox = root.querySelector('.wcos-return-confirm-checkbox');
				reviewBox = root.querySelector('.wcos-return-review');
				reviewSummary = root.querySelector('.wcos-return-review-summary');
				statusBox = root.querySelector('.wcos-return-status');
				errorBox = root.querySelector('.wcos-return-error');
				resultBox = root.querySelector('.wcos-return-result');
				reviewButton.addEventListener('click', reviewReturn);
				confirmButton.addEventListener('click', confirmReturn);
				executeButton.addEventListener('click', executeReturn);
				confirmCheckbox.addEventListener('change', updateControls);
				root.addEventListener('keydown', function (event) {
					if ('Escape' === event.key && busy) {
						event.preventDefault();
						event.stopImmediatePropagation();
					}
				}, true);
				setPhase('initial');
				updateControls();
				handle.focusContent();
			},
			onReady: function (root) {
				var content = root.querySelector('.wc-backbone-modal-content');
				var title = root.querySelector('.wcos-admin-backbone-modal__title');
				var description = root.querySelector('.wcos-admin-backbone-modal__description');
				var identity = String(sourceDialog.getAttribute('data-child-order-id') || 'return');
				if (title && description && content) {
					title.id = 'wcos-return-modal-title-' + identity;
					description.id = 'wcos-return-modal-description-' + identity;
					content.setAttribute('role', 'dialog');
					content.setAttribute('aria-modal', 'true');
					content.setAttribute('aria-labelledby', title.id);
					content.setAttribute('aria-describedby', description.id);
					content.setAttribute('tabindex', '-1');
					content.focus();
				}
			}
		});

		return modal;
	}

	launcher.addEventListener('click', openReturnModal);
})();
