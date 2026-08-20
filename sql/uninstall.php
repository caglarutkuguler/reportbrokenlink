<?php
/**
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
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
