(function () {
	'use strict';

	var config = window.wcosBulkReturnAdmin || {};
	var source = document.getElementById('wcos-bulk-return-dialog');
	var activeBatch = null;
	if (!source || !window.WCOSBackboneModal) { return; }

	function message(key, fallback) { return typeof config[key] === 'string' && config[key] ? config[key] : fallback; }
	function statusLabel(status) {
		var labels = {
			completed: message('completedLabel', 'Completed'),
			in_progress: message('inProgressLabel', 'In progress'),
			blocked: message('blockedLabel', 'Blocked'),
			manual_reconciliation: message('manualLabel', 'Manual reconciliation'),
			not_run_blocked: message('notRunLabel', 'Not run')
		};
		return labels[status] || status || '';
	}

	function selectedIds() {
		return Array.prototype.map.call(document.querySelectorAll('input[name="post[]"]:checked, input[name="id[]"]:checked'), function (input) {
			return input.value;
		});
	}

	function actionForButton(button) {
		var select = button && button.id === 'doaction2' ? document.getElementById('bulk-action-selector-bottom') : document.getElementById('bulk-action-selector-top');
		return select ? select.value : '';
	}

	function request(action, data) {
		var body = new URLSearchParams();
		body.set('action', action);
		Object.keys(data).forEach(function (key) {
			if (Array.isArray(data[key])) {
				data[key].forEach(function (value) { body.append(key + '[]', String(value)); });
			} else {
				body.set(key, String(data[key]));
			}
		});
		return window.fetch(source.getAttribute('data-ajax-url'), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (response) {
			return response.json().catch(function () { throw new Error(message('failed', 'The Bulk Return request could not be completed.')); });
		}).then(function (payload) {
			if (!payload || payload.success !== true) {
				var failure = payload && payload.data ? payload.data : {};
				var error = new Error(failure.message || message('failed', 'The Bulk Return request could not be completed.'));
				error.retryable = !!failure.retryable;
				throw error;
			}
			return payload.data || {};
		});
	}

	function open(trigger, ids) {
		var busy = false;
		var phase = activeBatch ? 'resuming' : 'reviewing';
		var reviewAuthority = null;
		var reviewEligible = false;
		var root, closeButton, closeFooter, reviewButton, confirmButton, executeButton, acknowledge, reviewBox, countsBox, groupsBox, rowsBox, statusBox, errorBox, resultsBox;

		function setStatus(value) { statusBox.textContent = value || ''; }
		function clearError() { errorBox.hidden = true; errorBox.textContent = ''; }
		function showError(value) { errorBox.textContent = value || message('failed', 'The Bulk Return request could not be completed.'); errorBox.hidden = false; errorBox.focus(); }
		function setBusy(value) { busy = !!value; updateControls(); }
		function updateControls() {
			root.setAttribute('aria-busy', busy ? 'true' : 'false');
			closeButton.disabled = busy;
			closeFooter.disabled = busy;
			reviewButton.hidden = phase !== 'initial';
			reviewButton.disabled = busy || phase !== 'initial';
			confirmButton.hidden = phase !== 'reviewed';
			confirmButton.disabled = busy || phase !== 'reviewed' || !reviewEligible || !reviewAuthority || !acknowledge.checked;
			executeButton.hidden = ['confirmed', 'in_progress', 'retry'].indexOf(phase) === -1;
			executeButton.disabled = busy || !activeBatch || ['confirmed', 'in_progress', 'retry'].indexOf(phase) === -1;
			acknowledge.disabled = busy || phase !== 'reviewed' || !reviewEligible;
		}

		function renderReview(summary) {
			reviewBox.hidden = false;
			countsBox.textContent = message('selectedLabel', 'Selected') + ': ' + String(summary.selected_count || 0) + ' · ' + message('canonicalLabel', 'Canonical') + ': ' + String(summary.canonical_count || 0) + ' · ' + message('duplicatesLabel', 'Duplicates') + ': ' + String(summary.duplicate_count || 0) + ' · ' + message('maximumLabel', 'Maximum') + ': ' + String(summary.max_children || 20);
			groupsBox.textContent = '';
			Object.keys(summary.groups || {}).forEach(function (originalId) {
				var group = document.createElement('p');
				group.textContent = message('originalLabel', 'Original') + ' #' + originalId + ': ' + String((summary.groups[originalId] || []).length) + ' ' + message('childrenLabel', 'children');
				groupsBox.appendChild(group);
			});
			rowsBox.textContent = '';
			(summary.rows || []).forEach(function (row) {
				var item = document.createElement('article');
				item.className = 'wcos-bulk-return-row ' + (row.eligible ? 'is-eligible' : 'is-ineligible');
				var heading = document.createElement('h4');
				var child = row.child || {};
				var original = row.original || {};
				heading.textContent = message('childLabel', 'Child') + ' #' + String(child.number || child.id || '') + (original.id ? ' → ' + message('originalLabel', 'Original') + ' #' + String(original.number || original.id) : '');
				var detail = document.createElement('p');
				detail.textContent = row.eligible
					? String(row.strategy || '') + ' · ' + String(row.line_count || 0) + ' ' + message('linesLabel', 'lines') + ' / ' + String(row.quantity || '0') + ' · ' + String(row.historical_total || '0') + ' ' + String(row.currency || '')
					: String(row.message || row.reason || message('ineligibleLabel', 'Ineligible'));
				item.appendChild(heading); item.appendChild(detail); rowsBox.appendChild(item);
			});
			reviewEligible = !!summary.all_eligible;
			if (!reviewEligible) { setStatus(message('mixedBlocked', 'Every selected row must be eligible.')); }
		}

		function renderProgress(summary) {
			resultsBox.textContent = '';
			var heading = document.createElement('p');
			heading.textContent = String(summary.cursor || 0) + ' / ' + String(summary.total || 0) + ' · ' + statusLabel(summary.status || 'in_progress');
			resultsBox.appendChild(heading);
			var aggregate = document.createElement('p');
			var counts = summary.counts || {};
			aggregate.textContent = message('completedLabel', 'Completed') + ': ' + String(counts.completed || 0) + ' · ' + message('blockedLabel', 'Blocked') + ': ' + String(counts.blocked || 0) + ' · ' + message('manualLabel', 'Manual reconciliation') + ': ' + String(counts.manual_reconciliation || 0) + ' · ' + message('notRunLabel', 'Not run') + ': ' + String(counts.not_run_blocked || 0);
			resultsBox.appendChild(aggregate);
			(summary.results || []).forEach(function (result) {
				var line = document.createElement('p');
				line.textContent = message('childLabel', 'Child') + ' #' + String(result.child_order_id || '') + ': ' + statusLabel(result.status) + (result.message ? ' — ' + String(result.message) : '');
				resultsBox.appendChild(line);
			});
			if (summary.status === 'completed' || summary.status === 'blocked') {
				phase = 'terminal';
				activeBatch = null;
				setStatus(summary.status === 'completed' ? message('completedStatus', 'Bulk Return completed.') : message('stoppedStatus', 'Bulk Return stopped. Remaining rows were not run.'));
			} else {
				phase = 'in_progress';
				setStatus(message('readyPrefix', 'Ready to execute child') + ' ' + String((summary.cursor || 0) + 1) + ' ' + message('ofLabel', 'of') + ' ' + String(summary.total || 0) + '.');
			}
			updateControls();
		}

		function review() {
			clearError(); setBusy(true); setStatus(message('reviewing', 'Reviewing the selected Return children…'));
			request(source.getAttribute('data-review-action'), { nonce: source.getAttribute('data-nonce'), child_order_ids: ids }).then(function (data) {
				reviewAuthority = { id: data.review_id, token: data.review_token };
				renderReview(data.summary || {});
				phase = 'reviewed';
			}).catch(function (error) { phase = 'initial'; setStatus(''); showError(error.message); }).finally(function () { setBusy(false); if (phase === 'reviewed' && reviewEligible) { acknowledge.focus(); } });
		}

		function confirm() {
			if (busy || !reviewAuthority || !reviewEligible || !acknowledge.checked) { return; }
			clearError(); setBusy(true); setStatus(message('confirming', 'Confirming the exact reviewed batch…'));
			request(source.getAttribute('data-confirm-action'), { nonce: source.getAttribute('data-nonce'), review_id: reviewAuthority.id, review_token: reviewAuthority.token }).then(function (data) {
				activeBatch = { id: data.batch_id, token: data.batch_token, anchor: data.anchor_child_id, cursor: 0 };
				reviewAuthority = null; phase = 'confirmed'; renderProgress(data.summary || {});
			}).catch(function (error) { reviewAuthority = null; phase = 'terminal'; showError(error.message); setStatus(message('reviewAgain', 'Review again after closing this dialog.')); }).finally(function () { setBusy(false); });
		}

		function execute() {
			if (busy || !activeBatch) { return; }
			clearError(); setBusy(true); setStatus(message('executing', 'Executing one child Return…'));
			request(source.getAttribute('data-execute-action'), { nonce: source.getAttribute('data-nonce'), batch_id: activeBatch.id, batch_token: activeBatch.token, anchor_child_id: activeBatch.anchor, cursor: activeBatch.cursor }).then(function (data) {
				if (activeBatch) { activeBatch.cursor = Number(data.cursor || 0); }
				renderProgress(data);
			}).catch(function (error) { phase = error.retryable ? 'retry' : 'terminal'; setStatus(error.retryable ? message('retryCurrent', 'Retry the same durable current row.') : message('cannotContinue', 'The batch cannot continue automatically.')); showError(error.message); }).finally(function () { setBusy(false); });
		}

		function resume() {
			clearError(); setBusy(true); setStatus(message('readingProgress', 'Reading durable Bulk Return progress…'));
			request(source.getAttribute('data-resume-action'), { nonce: source.getAttribute('data-nonce'), batch_id: activeBatch.id, batch_token: activeBatch.token, anchor_child_id: activeBatch.anchor }).then(function (data) {
				if (activeBatch) { activeBatch.cursor = Number(data.cursor || 0); }
				renderProgress(data);
			}).catch(function (error) { phase = 'terminal'; showError(error.message); }).finally(function () { setBusy(false); });
		}

		function usable(element) { return element && !element.disabled && !element.hidden && element.offsetParent !== null && element.getAttribute('tabindex') !== '-1'; }
		function trap(event) {
			if (event.key !== 'Tab') { if (event.key === 'Escape' && busy) { event.preventDefault(); event.stopImmediatePropagation(); } return; }
			var items = Array.prototype.filter.call(root.querySelectorAll('a[href], input, button, [tabindex]'), usable);
			if (!items.length) { event.preventDefault(); return; }
			var first = items[0], last = items[items.length - 1];
			if (event.shiftKey && (document.activeElement === first || !root.contains(document.activeElement))) { event.preventDefault(); last.focus(); }
			else if (!event.shiftKey && (document.activeElement === last || !root.contains(document.activeElement))) { event.preventDefault(); first.focus(); }
		}

		var panel = source.querySelector('.wcos-bulk-return-dialog__panel');
		window.WCOSBackboneModal.open({
			trigger: trigger,
			title: (panel.querySelector('h2') || {}).textContent || message('title', 'Bulk Return'),
			description: (panel.querySelector('header p') || {}).textContent || '',
			modalClass: 'wcos-bulk-return-backbone-modal',
			isBusy: function () { return busy; },
			build: function (body, footer, modalRoot, handle) {
				Array.prototype.forEach.call(panel.children, function (child) { if (child.tagName !== 'HEADER' && child.tagName !== 'FOOTER') { body.appendChild(child.cloneNode(true)); } });
				Array.prototype.forEach.call(panel.querySelectorAll('footer > button'), function (button) { footer.appendChild(button.cloneNode(true)); });
				root = modalRoot; closeButton = root.querySelector('.wc-backbone-modal-header .modal-close'); closeFooter = root.querySelector('.wcos-bulk-return-close'); reviewButton = root.querySelector('.wcos-bulk-return-review-button'); confirmButton = root.querySelector('.wcos-bulk-return-confirm-button'); executeButton = root.querySelector('.wcos-bulk-return-execute-button'); acknowledge = root.querySelector('.wcos-bulk-return-acknowledge'); reviewBox = root.querySelector('.wcos-bulk-return-review'); countsBox = root.querySelector('.wcos-bulk-return-counts'); groupsBox = root.querySelector('.wcos-bulk-return-groups'); rowsBox = root.querySelector('.wcos-bulk-return-rows'); statusBox = root.querySelector('.wcos-bulk-return-status'); errorBox = root.querySelector('.wcos-bulk-return-error'); resultsBox = root.querySelector('.wcos-bulk-return-results');
				reviewButton.addEventListener('click', review); confirmButton.addEventListener('click', confirm); executeButton.addEventListener('click', execute); acknowledge.addEventListener('change', updateControls); root.addEventListener('keydown', trap, true); updateControls(); handle.focusContent();
			},
			onReady: function (modalRoot) {
				var content = modalRoot.querySelector('.wc-backbone-modal-content');
				var title = modalRoot.querySelector('.wcos-admin-backbone-modal__title');
				var description = modalRoot.querySelector('.wcos-admin-backbone-modal__description');
				if (content && title && description) { title.id = 'wcos-bulk-return-modal-title'; description.id = 'wcos-bulk-return-modal-description'; content.setAttribute('role', 'dialog'); content.setAttribute('aria-modal', 'true'); content.setAttribute('aria-labelledby', title.id); content.setAttribute('aria-describedby', description.id); content.setAttribute('tabindex', '-1'); content.focus(); }
				if (activeBatch) { resume(); } else { review(); }
			}
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('#doaction, #doaction2');
		if (!button || actionForButton(button) !== String(config.bulkAction || 'wcos_bulk_return')) { return; }
		event.preventDefault(); event.stopPropagation();
		open(button, selectedIds());
	}, true);

	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!form || !form.querySelector) { return; }
		var top = form.querySelector('#bulk-action-selector-top');
		var bottom = form.querySelector('#bulk-action-selector-bottom');
		var launcher = top && top.value === String(config.bulkAction || 'wcos_bulk_return') ? top : (bottom && bottom.value === String(config.bulkAction || 'wcos_bulk_return') ? bottom : null);
		if (!launcher) { return; }
		event.preventDefault(); event.stopPropagation();
		open(launcher, selectedIds());
	}, true);
})();
