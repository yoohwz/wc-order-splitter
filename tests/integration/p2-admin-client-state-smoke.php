<?php

if (!defined('ABSPATH')) {
    exit(1);
}

$root = dirname(__DIR__, 2);
$js_path = $root . '/js/p2-split-admin.js';
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
    "removeChildButton.className = 'button wcos-split-remove-child';",
    "removeChildButton.textContent = 'Remove last child';",
    "value.className = 'wcos-split-remaining';",
    "toggle.textContent = 'View safety details';",
    "title: 'Split order'",
    "modalClass: 'wcos-split-backbone-modal'",
    "modalClass: 'wcos-split-method-backbone-modal'",
    'window.WCOSBackboneModal.open',
    'strategyLauncher.click();',
    'completed = true;',
    "typeof error.retryable !== 'boolean'",
    'error.retryable = true;',
    'removeExternalDescription',
) as $needle) {
    wcos_p2_adapter_assert(
        false !== strpos($js, $needle),
        'Split admin client is missing streamlined Backbone-modal/terminal UX protection: ' . $needle
    );
}

wcos_p2_adapter_assert(
    false !== strpos($js, 'if (!error.retryable) {'),
    'Non-retryable execute failures do not invalidate the reviewed confirmation state.'
);
wcos_p2_adapter_assert(false === strpos($js, 'window.alert'), 'Split admin client reintroduced blocking alert UI.');
wcos_p2_adapter_assert(false === strpos($js, '.innerHTML'), 'Split admin client reintroduced innerHTML rendering.');

$bridge = file_get_contents($root . '/js/p2-backbone-modal.js');
wcos_p2_adapter_assert(is_string($bridge) && '' !== $bridge, 'WooCommerce Backbone modal bridge is missing.');
foreach (array(
    '$.fn.WCBackboneModal',
    '.WCBackboneModal({',
    'wc-backbone-modal',
    'wc-backbone-modal-content',
    'wc-backbone-modal-main',
    'wc-backbone-modal-header',
    'wc-backbone-modal-backdrop modal-close',
    'wcos-admin-backbone-modal__body',
    'wcos-admin-backbone-modal__footer',
    'wc_backbone_modal_removed',
) as $needle) {
    wcos_p2_adapter_assert(false !== strpos($bridge, $needle), 'Shared modal bridge is missing WooCommerce Backbone contract: ' . $needle);
}
wcos_p2_adapter_assert(false === strpos($bridge, '.innerHTML'), 'Shared Backbone modal bridge uses innerHTML.');

$css = file_get_contents($root . '/css/p2-split-admin.css');
wcos_p2_adapter_assert(is_string($css) && false !== strpos($css, '.wcos-split-backbone-modal .wc-backbone-modal-content'), 'Split CSS does not target the WooCommerce Backbone shell.');
wcos_p2_adapter_assert(false !== strpos($css, '.wcos-split-method-backbone-modal .wc-backbone-modal-content'), 'Split method chooser does not use the WooCommerce Backbone shell.');
wcos_p2_adapter_assert(false !== strpos($css, '.wcos-split-remove-child'), 'Remove-last-child button styling is missing.');
wcos_p2_adapter_assert(false !== strpos($css, 'color: #b32d2e;'), 'Remove-last-child destructive text color is missing.');

echo "p2-admin-client-terminal-state-ok\n";
