<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_3_0_1($module)
{
    $errors = [];

    // Ensure all required hooks are registered: Some hooks may have been not
    // registered in 2.x versions, so we need to make sure they are registered in 3.x.
    $requiredHooks = [
        'actionCarrierUpdate',
        'displayAdminProductsExtra',
        'actionProductUpdate',
        'displayHeader',
        'displayAdminOrderMain',
        'actionAdminControllerSetMedia',
        'actionValidateOrder',
        'actionObjectOrderAddBefore',
        'actionObjectOrderUpdateAfter',
        'displayOrderConfirmation',
        'displayOrderDetail',
        'actionEmailSendBefore',
        'displayCarrierExtraContent',
    ];

    foreach ($requiredHooks as $hook) {
        if (!$module->isRegisteredInHook($hook)) {
            if (!$module->registerHook($hook)) {
                $errors[] = 'Failed to register hook: ' . $hook;
            }
        }
    }

    // Add error logging for any issues encountered during the upgrade process
    if (!empty($errors)) {
        PrestaShopLogger::addLog(
            'Omniva module upgrade to 3.0.1 completed with errors: ' . implode('; ', $errors),
            2,
            null,
            'Module',
            $module->id
        );
    }

    return true;
}
