<?php
/**
 * Upgrade to 1.1.0 - SigNoz observability
 *
 * @author    Flutterwave Developers
 * @copyright 2024 Flutterwave Developers
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_0($module)
{
    // App registration itself happens on the next back-office page load
    // (hookDisplayBackOfficeHeader), after the response is sent.
    return $module->installSignozDatabase()
        && $module->registerHook('displayBackOfficeHeader');
}
