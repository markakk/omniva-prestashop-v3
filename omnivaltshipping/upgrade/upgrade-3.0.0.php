<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_3_0_0($module)
{
    $errors = [];

    // Unregister hooks that are no longer used
    $oldHooks = [
        'displayBeforeCarrier',
        'header',
        'displayBackOfficeHeader',
    ];

    foreach ($oldHooks as $hook) {
        $module->unregisterHook($hook);
    }

    // Register new hooks that may not have been registered before
    $newHooks = [
        'displayCarrierExtraContent',
        'actionObjectOrderUpdateAfter',
        'actionEmailSendBefore',
        'displayAdminOrderMain',
    ];

    foreach ($newHooks as $hook) {
        if (!$module->isRegisteredInHook($hook)) {
            if (!$module->registerHook($hook)) {
                $errors[] = 'Failed to register hook: ' . $hook;
            }
        }
    }

    // Replace old hook with new one
    $module->unregisterHook('displayAdminOrder');

    // Fix non-numeric manifest values before altering column type
    Db::getInstance()->execute(
        'UPDATE `' . _DB_PREFIX_ . 'omniva_order_history` SET `manifest` = \'-2\' WHERE `manifest` NOT REGEXP \'^-?[0-9]+$\''
    );

    // Change manifest column type from varchar to int
    $alterResult = Db::getInstance()->execute(
        'ALTER TABLE `' . _DB_PREFIX_ . 'omniva_order_history` MODIFY `manifest` int(10) NOT NULL DEFAULT 0'
    );
    if (!$alterResult) {
        $errors[] = 'Failed to alter manifest column type in omniva_order_history table';
    }

    // Remove orphaned files from previous version
    _omniva_upgrade_cleanup_old_files($module);

    // Log errors if any
    if (!empty($errors)) {
        PrestaShopLogger::addLog(
            'Omniva module upgrade to 3.0.0 completed with errors: ' . implode('; ', $errors),
            2,
            null,
            'Module',
            $module->id
        );
        return false;
    }

    return true;
}

function _omniva_upgrade_cleanup_old_files($module)
{
    $modulePath = _PS_MODULE_DIR_ . $module->name . '/';

    $filesToRemove = [
        'changelog.txt',
        'composer.lock',
        'controllers/front/index.php',
        'sql/.htaccess',
        'translations/en.php',
        'translations/et.php',
        'translations/lt.php',
        'translations/lv.php',
        'upgrade/upgrade-2.0.1.php',
        'upgrade/upgrade-2.0.10.php',
        'upgrade/upgrade-2.0.11.php',
        'upgrade/upgrade-2.0.14.php',
        'upgrade/upgrade-2.0.16.php',
        'upgrade/upgrade-2.1.0.php',
        'upgrade/upgrade-2.1.1.php',
        'upgrade/upgrade-2.2.0.php',
        'upgrade/upgrade-2.2.5.php',
        'upgrade/upgrade-2.3.0.php',
        'views/index.php',
        'views/img/omnivalt-logo.jpg',
        'views/js/index.php',
        'views/js/omniva-admin-order-177.js',
        'views/templates/index.php',
        'views/templates/admin/productTab-1.7.tpl',
        'views/templates/hook/index.php',
        'views/templates/hook/displayBeforeCarrier.tpl',
        'views/templates/hook/blockinorder_1_7_7.tpl',
    ];

    foreach ($filesToRemove as $file) {
        $fullPath = $modulePath . $file;
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
    }
}
