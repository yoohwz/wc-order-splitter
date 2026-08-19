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
    'field.disabled = busy || completed || !!state;',
    'reviewButton.disabled = busy || completed || !!state || !canBuildPlan();',
    'confirmCheckbox.disabled = busy || completed || !state;',
    'executeButton.disabled = busy || completed || !state || !confirmCheckbox.checked;',
    'executeButton.hidden = true;',
    'editButton.hidden = true;',
    'activeChildren = 1;',
    'setChildVisible(childIndex, childIndex === 1);',
    "addChildButton.textContent = 'Add child order';",
    "removeChildButton.textContent = 'Remove last child';",
    "value.className = 'wcos-split-remaining';",
    "toggle.textContent = 'View safety details';",
    "heading.textContent = 'Split order';",
    "'By quantity'",
    'strategyLauncher.click();',
    'completed = true;',
    'completed && !resultBox.hidden',
    "typeof error.retryable !== 'boolean'",
    'error.retryable = true;',
) as $needle) {
    wcos_p2_adapter_assert(
        false !== strpos($js, $needle),
        'Split admin client is missing streamlined terminal/UX protection: ' . $needle
    );
}

wcos_p2_adapter_assert(
    false !== strpos($js, 'if (!error.retryable) {'),
    'Non-retryable execute failures do not invalidate the reviewed confirmation state.'
);
wcos_p2_adapter_assert(
    false === strpos($js, 'window.alert'),
    'Split admin client reintroduced blocking alert UI.'
);

echo "p2-admin-client-terminal-state-ok\n";
