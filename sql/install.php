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
 * One row per submitted report.
 *
 * No FOREIGN KEY constraints on id_product / id_shop / id_customer: PrestaShop tables are
 * created with _MYSQL_ENGINE_, which is MyISAM on a large share of shops, and MyISAM silently
 * ignores foreign keys. Declaring them would fail or be a no-op depending on the host, so
 * referential integrity is enforced in application code instead — actionProductDelete drops a
 * product's reports, and the admin list degrades gracefully when a product has disappeared.
 *
 * report_type and status are VARCHAR rather than ENUM so adding a value later is a code change
 * rather than an ALTER TABLE on a table that may hold millions of rows. Allowed values are
 * validated against ReportBrokenLinkRepository::REPORT_TYPES / STATUSES on write.
 *
 * ip_hash holds a keyed SHA-256 of the reporter's IP (never the address itself) purely so the
 * rate limiter can count recent submissions. See the GDPR note in Readme.md.
 */
$sql = array();

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . "reportbrokenlink_report` (
    `id_report` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_shop` INT(10) UNSIGNED NOT NULL DEFAULT 1,
    `id_product` INT(10) UNSIGNED NOT NULL,
    `id_customer` INT(10) UNSIGNED NULL DEFAULT NULL,
    `report_type` VARCHAR(32) NOT NULL DEFAULT 'other',
    `customer_email` VARCHAR(255) NOT NULL DEFAULT '',
    `customer_name` VARCHAR(255) NOT NULL DEFAULT '',
    `message` TEXT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'open',
    `admin_response` TEXT NULL,
    `ip_hash` CHAR(64) NOT NULL DEFAULT '',
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id_report`),
    KEY `idx_product_status_created` (`id_product`, `status`, `created_at`),
    KEY `idx_shop_status_created` (`id_shop`, `status`, `created_at`),
    KEY `idx_ratelimit` (`ip_hash`, `id_product`, `created_at`),
    KEY `idx_created` (`created_at`)
) ENGINE=" . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

foreach ($sql as $query) {
    if (!Db::getInstance()->execute($query)) {
        return false;
    }
}

Configuration::updateValue('REPORTBROKENLINK_ENABLED', 1);
Configuration::updateValue('REPORTBROKENLINK_EMAILS', (string) Configuration::get('PS_SHOP_EMAIL'));
Configuration::updateValue('REPORTBROKENLINK_TYPES', 'broken_link,wrong_price,missing_info,other');
Configuration::updateValue('REPORTBROKENLINK_POSITION', 'info');
Configuration::updateValue('REPORTBROKENLINK_GUEST', 1);
Configuration::updateValue('REPORTBROKENLINK_RATE_LIMIT', 1);
Configuration::updateValue('REPORTBROKENLINK_DEDUP', 1);
Configuration::updateValue('REPORTBROKENLINK_NOTIFY_CUSTOMER', 1);
Configuration::updateValue('REPORTBROKENLINK_PER_PAGE', 20);
Configuration::updateValue('REPORTBROKENLINK_RECAPTCHA', 0);
Configuration::updateValue('REPORTBROKENLINK_RECAPTCHA_SITE', '');
Configuration::updateValue('REPORTBROKENLINK_RECAPTCHA_SECRET', '');

return true;
