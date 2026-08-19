<?php

if (!defined('ABSPATH')) {
	exit(1);
}

$root = dirname(__DIR__, 2);
$js = file_get_contents($root . '/js/p2-split-strategy-admin.js');
wcos_p2_adapter_assert(is_string($js) && '' !== $js, 'Unable to read strategy admin client for modal feedback acceptance.');

foreach (array(
	'function showReviewAction()',
	'function showConfirmAction()',
	'function showExecuteAction()',
	'function hideWorkflowActions()',
	"feedbackBox.className = 'wcos-strategy-feedback';",
	'clonedForm.insertBefore(feedbackBox, clonedForm.firstChild);',
	'feedbackBox.appendChild(statusBox);',
	'feedbackBox.appendChild(errorBox);',
	'feedbackBox.appendChild(resultBox);',
	"errorBox.classList.add('inline');",
	"resultBox.classList.add('inline');",
	'footer.appendChild(reviewButton);',
	'footer.appendChild(confirmButton);',
	'footer.appendChild(executeButton);',
	"reviewButton.classList.add('button-primary', 'button-large');",
	"confirmButton.classList.add('button-primary', 'button-large');",
	"executeButton.classList.add('button-large');",
	'reviewButton.hidden = false;',
	'confirmButton.hidden = true;',
	'executeButton.hidden = true;',
	'showConfirmAction();',
	'showExecuteAction();',
	'showError(error.message);',
) as $needle) {
	wcos_p2_adapter_assert(false !== strpos($js, $needle), 'Strategy modal footer/feedback contract is missing: ' . $needle);
}

wcos_p2_adapter_assert(
	false === strpos($js, "reviewButton = requireRef(footer.querySelector('.wcos-strategy-review-button')"),
	'Strategy Review button unexpectedly depends on server footer markup rather than the cloned workflow control.'
);
wcos_p2_adapter_assert(false === strpos($js, '.innerHTML'), 'Strategy modal feedback reintroduced innerHTML rendering.');

$css = file_get_contents($root . '/css/p2-split-strategy-admin.css');
wcos_p2_adapter_assert(is_string($css) && '' !== $css, 'Unable to read strategy admin CSS for modal feedback acceptance.');
foreach (array(
	'.wcos-strategy-feedback {',
	'.wcos-strategy-feedback .wcos-strategy-status {',
	'.wcos-strategy-feedback .notice {',
	'.wcos-strategy-review-controls:empty {',
) as $needle) {
	wcos_p2_adapter_assert(false !== strpos($css, $needle), 'Strategy modal feedback CSS contract is missing: ' . $needle);
}

$shared_css = file_get_contents($root . '/css/p2-backbone-modal.css');
wcos_p2_adapter_assert(
	is_string($shared_css) && false !== strpos($shared_css, 'justify-content: flex-end !important;'),
	'Strategy modal footer no longer inherits the shared right-aligned WooCommerce action group.'
);

echo "p2-strategy-modal-feedback-ok\n";
