<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 3.x -> 4.0.0.
 *
 * There is no report data to migrate: 3.x stored nothing at all, it only sent an e-mail (and,
 * from 3.1.0 on, failed to even do that — see AUDIT.md). So this creates the new table, seeds
 * settings that never existed before, fixes up the hook registrations, and deletes the 3.x
 * files that a module upgrade would otherwise leave behind on disk: PrestaShop extracts the new
 * archive *over* the old folder rather than replacing it, so anything renamed or dropped stays
 * where it was until something removes it.
 *
 * The module name, module_key and the displayProductAdditionalInfo registration are all
 * deliberately preserved so existing installations upgrade in place.
 *
 * @param Module $module
 *
 * @return bool
 */
function upgrade_module_4_0_0($module)
{
    $success = true;

    // Creates reportbrokenlink_report and seeds the settings 3.x never had. The recipient
    // defaults to PS_SHOP_EMAIL, which is where 3.x nominally sent reports, so the upgrade
    // preserves the previous behaviour rather than silently pointing mail somewhere new.
    $success = (bool) (include dirname(__FILE__) . '/../sql/install.php') && $success;

    // extraLeft is a 1.4-1.6 hook that 3.x registered on anything up to and including 1.7.0.0
    // (its version check used '>' against '1.7.0.0 ', trailing space and all). It does nothing
    // on a 1.7+ shop, so drop it rather than carry it forward.
    $module->unregisterHook('extraLeft');

    // displayProductAdditionalInfo stays registered from 3.x — do not touch it, that is what
    // keeps the button where merchants already expect it.
    foreach (array('displayFooterProduct', 'actionFrontControllerSetMedia', 'actionProductDelete', 'displayDashboardTop') as $hook) {
        if (!$module->isRegisteredInHook($hook)) {
            $module->registerHook($hook);
        }
    }

    // A 3.x install that was never re-registered may be missing the info hook entirely.
    if (!$module->isRegisteredInHook('displayProductAdditionalInfo')) {
        $module->registerHook('displayProductAdditionalInfo');
    }

    reportbrokenlink_remove_legacy_files();

    return $success;
}

/**
 * Deletes every file 3.x shipped that 4.0.0 no longer uses.
 *
 * Leaving these behind is not cosmetic: views/templates/front/reportbrokenlink-extra.tpl still
 * contains the CDN jQuery include and the old inline form, and controllers/front/ajax.php is
 * still a routable, unauthenticated mail-sending endpoint. Both must actually leave the disk.
 */
function reportbrokenlink_remove_legacy_files()
{
    $base = _PS_MODULE_DIR_ . 'reportbrokenlink/';

    $files = array(
        // The 3.1.0+ open mail relay. Reachable as a front controller for as long as it exists.
        'controllers/front/ajax.php',
        // The 3.0.0 endpoint, which bootstrapped the shop itself via config.inc.php.
        'reportbrokenlink_ajax.php',
        // Button + modal + inline JS + jQuery 1.8.2 from Google's CDN.
        'views/templates/front/reportbrokenlink-extra.tpl',
        'views/css/reportbrokenlink.css',
        'views/img/loading.gif',
        'views/img/broken-link.gif',
        'views/img/reportbrokenlink.png',
        'views/img/index.php',
        // PrestaShop 1.4-era module icon, unused since 1.5.
        'logo.gif',
    );

    foreach ($files as $file) {
        $path = $base . $file;
        if (file_exists($path) && is_file($path)) {
            @unlink($path);
        }
    }

    // views/img/ only ever held the three images above.
    $imgDir = $base . 'views/img';
    if (is_dir($imgDir)) {
        $remaining = @scandir($imgDir);
        if (is_array($remaining) && count(array_diff($remaining, array('.', '..'))) === 0) {
            @rmdir($imgDir);
        }
    }
}
