(function ($) {
	'use strict';

	var strings = window.wcosMergeAdminStrings || {};
	var launcher = document.querySelector('.wcos-merge-launcher');
	if (!launcher || !window.WCOSBackboneModal || !$.fn.selectWoo) {
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
			if (child.classList.contains('wcos-merge-dialog__header') || child.classList.contains('wcos-merge-dialog__actions')) {
				return;
			}
			body.appendChild(child.cloneNode(true));
		});
	}

	function cloneFooter(sourceActions, footer) {
		Array.prototype.forEach.call(sourceActions ? sourceActions.children : [], function (sourceButton) {
			var button = sourceButton.cloneNode(true);
			if (button.classList.contains('wcos-merge-cancel')) {
				button.classList.add('modal-close');
				button.classList.add('button-large');
			}
			footer.appendChild(button);
		});
	}

	function openMergeModal() {
		var busy = false;
		var completed = false;
		var selectedTarget = '';
		var reviewAuthority = null;
		var confirmationAuthority = null;
		var retryReady = false;
		var dialog;
		var closeButton;
		var cancelButton;
		var reviewButton;
		var executeButton;
		var confirmCheckbox;
		var targetSelect;
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
					throw new Error(text('requestFailed', 'The Merge request could not be completed.'));
				});
			}).then(function (payload) {
				if (!payload || payload.success !== true) {
					var failure = payload && payload.data ? payload.data : {};
					var error = new Error(failure.message || text('requestFailed', 'The Merge request could not be completed.'));
					error.code = failure.code || 'request_failed';
					error.retryable = !!failure.retryable;
					throw error;
				}
				return payload.data || {};
			});
		}

		function clearError() {
			errorBox.hidden = true;
			errorBox.textContent = '';
		}

		function showError(message) {
			errorBox.textContent = message || text('requestFailed', 'The Merge request could not be completed.');
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

		function addSummary(label, value) {
			var term = document.createElement('dt');
			var detail = document.createElement('dd');
			term.textContent = label;
			detail.textContent = value;
			reviewSummary.appendChild(term);
			reviewSummary.appendChild(detail);
		}

		function renderReview(summary) {
			var source = summary.source || {};
			var target = summary.target || {};
			reviewSummary.textContent = '';
			addSummary('Source', '#' + String(source.number || source.id || '') + ' · ' + String(source.status || '') + ' · ' + String(source.line_count || 0) + ' lines · ' + String(source.total || '0') + ' ' + String(summary.currency || ''));
			addSummary('Target', '#' + String(target.number || target.id || '') + ' · ' + String(target.status || '') + ' · ' + String(target.line_count || 0) + ' lines · ' + String(target.total || '0') + ' ' + String(summary.currency || ''));
			addSummary('Transferable lines', String(summary.transferable_line_count || 0));
			addSummary('Line actions', String(summary.coalesced_line_count || 0) + ' coalesce · ' + String(summary.fresh_line_count || 0) + ' fresh');
			addSummary('Projected active-target historical aggregate', String(summary.projected_active_target_total || '0') + ' ' + String(summary.currency || ''));
			addSummary('Source commercial history', (summary.source_shipping_retained ? 'shipping; ' : '') + (summary.source_fees_retained ? 'fees; ' : '') + (summary.source_coupons_retained ? 'coupons; ' : '') + 'retained on archived source');
			addSummary('Target authority', 'Status, customer/address/payment context, shipping, fees and coupons stay with target');
			if (summary.target_financial_history_retained) {
				addSummary('Financial-history boundary', String(summary.settlement_neutral_line_count || 0) + ' settlement-neutral source lines · fresh target lines only');
				addSummary('Target settlement/refund authority', 'Transaction, paid date, payment context, status, refunds, payable total and actual tax stay unchanged');
				addSummary('Payment/refund operations', 'No payment or refund API will run');
			}
			addSummary('Price precision', String(summary.price_precision || 0));
			addSummary('Retirement policy', String(summary.retirement_policy || 'non_force_trash_archive'));
			reviewBox.hidden = false;
		}

		function invalidateReview() {
			if (completed) {
				return;
			}
			reviewAuthority = null;
			confirmationAuthority = null;
			retryReady = false;
			reviewBox.hidden = true;
			reviewSummary.textContent = '';
			confirmCheckbox.checked = false;
			confirmCheckbox.disabled = false;
			$(targetSelect).prop('disabled', false);
			executeButton.textContent = text('confirmMerge', 'Confirm and merge');
			clearResult();
		}

		function setBusy(nextBusy) {
			busy = !!nextBusy;
			dialog.setAttribute('aria-busy', busy ? 'true' : 'false');
			$(targetSelect).prop('disabled', busy || completed || !!confirmationAuthority);
			reviewButton.disabled = busy || completed || !selectedTarget || !!confirmationAuthority;
			confirmCheckbox.disabled = busy || completed || !!confirmationAuthority;
			executeButton.disabled = busy || completed || (!retryReady && (!reviewAuthority || !confirmCheckbox.checked));
			cancelButton.disabled = busy;
			closeButton.disabled = busy;
		}

		function reviewMerge() {
			if (busy || completed || !selectedTarget || confirmationAuthority) {
				return;
			}
			clearError();
			invalidateReview();
			setBusy(true);
			setStatus(text('reviewing', 'Reviewing Merge pair…'));
			request(sourceDialog.getAttribute('data-review-action'), {
				source_order_id: sourceDialog.getAttribute('data-source-order-id'),
				target_order_id: selectedTarget,
				nonce: sourceDialog.getAttribute('data-nonce')
			}).then(function (data) {
				reviewAuthority = { reviewId: data.review_id, token: data.review_token };
				renderReview(data.summary || {});
				setStatus(text('reviewReady', 'The pair passed server review. Confirm the acknowledgement to merge.'));
				confirmCheckbox.focus();
			}).catch(function (error) {
				invalidateReview();
				setStatus('');
				showError(error.message);
			}).finally(function () {
				setBusy(false);
			});
		}

		function executeSameOperation() {
			return request(sourceDialog.getAttribute('data-execute-action'), {
				source_order_id: sourceDialog.getAttribute('data-source-order-id'),
				target_order_id: selectedTarget,
				nonce: sourceDialog.getAttribute('data-nonce'),
				operation_id: confirmationAuthority.operationId,
				confirmation_token: confirmationAuthority.token
			});
		}

		function renderSuccess(data) {
			resultBox.textContent = '';
			var message = document.createElement('p');
			message.textContent = text('completed', 'Orders merged successfully. The source order was retired under the approved policy.');
			resultBox.appendChild(message);
			var target = data && data.target ? data.target : null;
			if (target) {
				var detail = document.createElement('p');
				if (target.edit_url) {
					var link = document.createElement('a');
					link.href = target.edit_url;
					link.textContent = text('targetOrder', 'Active target order') + ' #' + String(target.number || target.id || '');
					detail.appendChild(link);
				} else {
					detail.textContent = text('targetOrder', 'Active target order') + ' #' + String(target.number || target.id || '');
				}
				resultBox.appendChild(detail);
			}
			resultBox.hidden = false;
			resultBox.focus();
		}

		function handleExecuteFailure(error) {
			if (!error.retryable || !confirmationAuthority) {
				invalidateReview();
			} else {
				retryReady = true;
				executeButton.textContent = text('retryMerge', 'Retry merge');
			}
			setStatus('');
			showError(error.message);
		}

		function finishExecute(data) {
			if (!data || data.status !== 'completed') {
				var closed = new Error(text('closedOperation', 'The Merge operation closed without a completed Merge. Review the orders before trying again.'));
				closed.retryable = !!data && ['recovery_required', 'recovery_pending', 'retry_required'].indexOf(data.status) !== -1;
				throw closed;
			}
			completed = true;
			retryReady = false;
			setStatus(text('completed', 'Orders merged successfully.'));
			renderSuccess(data);
		}

		function confirmAndMerge() {
			if (busy || completed || (!confirmationAuthority && (!reviewAuthority || !confirmCheckbox.checked))) {
				return;
			}
			clearError();
			clearResult();
			setBusy(true);

			if (confirmationAuthority) {
				setStatus(text('retrying', 'Retrying the same Merge operation…'));
				executeSameOperation().then(finishExecute).catch(handleExecuteFailure).finally(function () { setBusy(false); });
				return;
			}

			setStatus(text('confirming', 'Confirming reviewed Merge authority…'));
			request(sourceDialog.getAttribute('data-confirm-action'), {
				source_order_id: sourceDialog.getAttribute('data-source-order-id'),
				target_order_id: selectedTarget,
				nonce: sourceDialog.getAttribute('data-nonce'),
				review_id: reviewAuthority.reviewId,
				review_token: reviewAuthority.token
			}).then(function (data) {
				confirmationAuthority = { operationId: data.operation_id, token: data.confirmation_token };
				reviewAuthority = null;
				$(targetSelect).prop('disabled', true);
				confirmCheckbox.disabled = true;
				setStatus(text('executing', 'Merging orders…'));
				return executeSameOperation();
			}).then(finishExecute).catch(handleExecuteFailure).finally(function () {
				setBusy(false);
			});
		}

		window.WCOSBackboneModal.open({
			trigger: launcher,
			title: (sourceDialog.querySelector('.wcos-merge-dialog__header h2') || {}).textContent || 'Merge into another order',
			description: (sourceDialog.querySelector('.wcos-merge-dialog__header p') || {}).textContent || launcherDescription,
			modalClass: 'wcos-merge-backbone-modal',
			isBusy: function () { return busy; },
			build: function (body, footer, root) {
				var sourcePanel = sourceDialog.querySelector('.wcos-merge-dialog__panel');
				var sourceActions = sourceDialog.querySelector('.wcos-merge-dialog__actions');
				cloneBody(sourcePanel, body);
				cloneFooter(sourceActions, footer);
				dialog = root;
				closeButton = root.querySelector('.wc-backbone-modal-header .modal-close');
				cancelButton = root.querySelector('.wcos-merge-cancel');
				reviewButton = root.querySelector('.wcos-merge-review-button');
				executeButton = root.querySelector('.wcos-merge-execute-button');
				confirmCheckbox = root.querySelector('.wcos-merge-confirm-checkbox');
				targetSelect = root.querySelector('.wcos-merge-target-select');
				reviewBox = root.querySelector('.wcos-merge-review');
				reviewSummary = root.querySelector('.wcos-merge-review-summary');
				statusBox = root.querySelector('.wcos-merge-status');
				errorBox = root.querySelector('.wcos-merge-error');
				resultBox = root.querySelector('.wcos-merge-result');
			},
			onReady: function () {
				$(targetSelect).selectWoo({
					width: '100%',
					placeholder: targetSelect.getAttribute('data-placeholder') || '',
					allowClear: true,
					minimumInputLength: 0,
					ajax: {
						url: sourceDialog.getAttribute('data-ajax-url'),
						type: 'POST',
						dataType: 'json',
						delay: 250,
						data: function (params) {
							return {
								action: sourceDialog.getAttribute('data-search-action'),
								source_order_id: sourceDialog.getAttribute('data-source-order-id'),
								nonce: sourceDialog.getAttribute('data-nonce'),
								term: params.term || '',
								page: params.page || 1
							};
						},
						processResults: function (payload) {
							var data = payload && payload.success === true ? payload.data : {};
							return {
								results: (data.results || []).map(function (order) {
									return { id: String(order.id), text: '#' + String(order.number || order.id) + ' · ' + String(order.status || '') + ' · ' + String(order.currency || '') };
								}),
								pagination: { more: !!data.more }
							};
						}
					}
				});
				$(targetSelect).on('change', function () {
					if (completed || confirmationAuthority) {
						return;
					}
					selectedTarget = String($(targetSelect).val() || '');
					invalidateReview();
					clearError();
					setStatus(selectedTarget ? '' : text('selectTarget', 'Select a target order first.'));
					setBusy(false);
				});
				reviewButton.addEventListener('click', reviewMerge);
				executeButton.addEventListener('click', confirmAndMerge);
				confirmCheckbox.addEventListener('change', function () { setBusy(false); });
				$(targetSelect).selectWoo('open');
			}
		});
	}

	launcher.addEventListener('click', openMergeModal);
})(jQuery);
