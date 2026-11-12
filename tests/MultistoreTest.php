<?php
/**
 * reportbrokenlink - multistore tests (4.2.0). Plain PHP, no PrestaShop, no database.
 *
 *     php tests/MultistoreTest.php
 *
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (PHP_SAPI === 'cli' && !defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.1.0'); // standalone run outside PrestaShop
}
if (!defined('_PS_VERSION_')) {
    exit;
}

$fail = 0;
function ok($cond, $label)
{
    global $fail;
    if ($cond) {
        echo "  ok   $label\n";

        return;
    }
    ++$fail;
    echo "  FAIL $label\n";
}

$root = dirname(__DIR__);
require_once $root . '/classes/ReportBrokenLinkRepository.php';

echo "1) shopCondition()\n";
ok(ReportBrokenLinkRepository::shopCondition(1, '`id_shop`') === '`id_shop` = 1', 'single int keeps the old "= N" form');
ok(ReportBrokenLinkRepository::shopCondition(array(2), 'r.`id_shop`') === 'r.`id_shop` = 2', 'one-element list is the same "= N"');
ok(ReportBrokenLinkRepository::shopCondition(array(1, 3, 3, '2'), '`id_shop`') === '`id_shop` IN (1,3,2)', 'list is cast, de-duplicated, IN (...)');
ok(ReportBrokenLinkRepository::shopCondition(array(), '`id_shop`') === '`id_shop` = 0', 'empty list matches no row');
ok(ReportBrokenLinkRepository::shopCondition(0, '`id_shop`') === '`id_shop` = 0', 'zero matches no row (as before)');
ok(ReportBrokenLinkRepository::shopCondition(array('1 OR 1=1', -4), '`id_shop`') === '`id_shop` = 1', 'crafted values are cast, negatives dropped');

echo "\n2) buildWhere() uses the list\n";
$m = new ReflectionMethod('ReportBrokenLinkRepository', 'buildWhere');
$m->setAccessible(true);
ok($m->invoke(null, array('id_shop' => 4)) === 'r.`id_shop` = 4', 'int filter unchanged');
ok($m->invoke(null, array('id_shop' => array(1, 2))) === 'r.`id_shop` IN (1,2)', 'list filter gives IN');
ok($m->invoke(null, array()) === 'r.`id_shop` = 0', 'missing filter still matches nothing');

echo "\n3) Source assertions\n";
$repo = file_get_contents($root . '/classes/ReportBrokenLinkRepository.php');
$main = file_get_contents($root . '/reportbrokenlink.php');
$tpl = file_get_contents($root . '/views/templates/admin/reports.tpl');

ok(strpos($repo, '`id_shop` = \' . (int) $idShop') === false, 'no repository query still hard-casts a single shop id');
foreach (array('updateReport', 'bulkUpdateStatus', 'bulkDelete', 'getById', 'countByStatus', 'countSince', 'getTopReportedProducts') as $fn) {
    $start = strpos($repo, 'function ' . $fn);
    $end = strpos($repo, 'function ', $start + 10);
    $body = substr($repo, $start, $end === false ? strlen($repo) : $end - $start);
    ok(strpos($body, 'self::shopCondition($idShop,') !== false, "$fn takes the shop list");
}
foreach (array('countRecentByIpForProduct', 'countRecentByIp', 'hasRecentDuplicate') as $fn) {
    $start = strpos($repo, 'function ' . $fn);
    $end = strpos($repo, 'function ', $start + 10);
    $body = substr($repo, $start, $end === false ? strlen($repo) : $end - $start);
    ok(strpos($body, 'id_shop') === false, "$fn stays global (anti-spam)");
}
ok(strpos($main, "'id_shop' => \$this->getContextShopIds()") !== false, 'list filters use the context shops');
ok(substr_count($main, '$idShop = $this->getContextShopIds();') === 4, 'dashboard, intro, update and bulk action use the context shops');
ok(strpos($main, '$idShop = (int) $this->context->shop->id;') === false, 'no admin path is left on the browsed shop id');
ok(strpos($main, 'Shop::getContextListShopID()') !== false, 'context list comes from core');
ok(strpos($main, 'function renderShopContextNotice') !== false
    && strpos($main, 'Shop::isFeatureActive() || Shop::getContext() === Shop::CONTEXT_SHOP') !== false, 'context notice gated on multistore + non-shop context');
ok(strpos($main, '. $this->renderShopContextNotice()') !== false, 'notice is rendered on the configuration page');
ok(preg_match('/if \(!Shop::isFeatureActive\(\) \|\| Shop::getContext\(\) === Shop::CONTEXT_SHOP\) \{\s+Configuration::updateValue\(\s+\'REPORTBROKENLINK_ADMIN_LINK\'/', $main) === 1, 'admin link only stored in a single-shop context');
ok(strpos($main, "Configuration::get('PS_SHOP_NAME', null, null, \$idShop)") !== false, 'resolved mail uses the report shop name');
ok(strpos($main, "'shop'") !== false && strpos($main, '$header[] = \'shop\'') !== false, 'CSV gains a shop column only with multistore');
ok(strpos($tpl, '{if $rbl_multistore}<th>') !== false && strpos($tpl, '{if $rbl_multistore}8{else}7{/if}') !== false, 'template: shop column + colspan only with multistore');
ok(strpos($main, 'deleteByProduct($idProduct)') !== false, 'product-delete hook unchanged (core fires it only when the product is gone everywhere)');

echo "\n4) Version\n";
ok(strpos($main, "version = '4.2.0'") !== false, 'module version 4.2.0');
ok(strpos(file_get_contents($root . '/CHANGELOG.md'), '## 4.2.0') !== false, 'CHANGELOG has 4.2.0');

echo "\n" . ($fail === 0 ? "OK - all passed\n" : "$fail FAILED\n");
exit($fail === 0 ? 0 : 1);
