<?php

if (!defined('ABSPATH')) {
    exit(1);
}

$js_path = dirname(__DIR__, 2) . '/js/p2-split-admin.js';
$js = file_get_contents($js_path);
wcos_p2_adapter_assert(is_string($js) && '' !== $js, 'Unable to read the Split admin client script for terminal-state verification.');

foreach (array(
    'var completed = false;',
    "dialog.querySelectorAll('.wcos-split-quantity')",
    'field.disabled = busy || completed;',
    'reviewButton.disabled = busy || completed;',
    'confirmCheckbox.disabled = busy || completed;',
    'executeButton.disabled = busy || completed || !state || !confirmCheckbox.checked;',
    'if (busy || completed) {',
    'completed = true;',
    'completed && !resultBox.hidden',
) as $needle) {
    wcos_p2_adapter_assert(
        false !== strpos($js, $needle),
        'Split admin client is missing terminal/async-boundary protection: ' . $needle
    );
}

wcos_p2_adapter_assert(
    false !== strpos($js, 'if (!error.retryable) {'),
    'Non-retryable execute failures do not invalidate the reviewed confirmation state.'
);

echo "p2-admin-client-terminal-state-ok\n";
