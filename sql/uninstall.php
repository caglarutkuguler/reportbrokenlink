<?php
/**
 * 2019-2026 MEG Venture
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 *
 *  @author    MEG Venture
 *  @copyright 2019-2026 MEG Venture
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

if (!Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'reportbrokenlink_report`')) {
    return false;
}

$settings = array(
    'REPORTBROKENLINK_ENABLED',
    'REPORTBROKENLINK_EMAILS',
    'REPORTBROKENLINK_TYPES',
    'REPORTBROKENLINK_POSITION',
    'REPORTBROKENLINK_GUEST',
    'REPORTBROKENLINK_RATE_LIMIT',
    'REPORTBROKENLINK_DEDUP',
    'REPORTBROKENLINK_NOTIFY_CUSTOMER',
    'REPORTBROKENLINK_PER_PAGE',
    'REPORTBROKENLINK_RECAPTCHA',
    'REPORTBROKENLINK_RECAPTCHA_SITE',
    'REPORTBROKENLINK_RECAPTCHA_SECRET',
    'REPORTBROKENLINK_ADMIN_LINK',
);

foreach ($settings as $setting) {
    Configuration::deleteByName($setting);
}

return true;
