<?php
/**
 * Static regression checks for 4.1.2 security fixes. Plain PHP, no shop.
 *
 *   php tests/SecurityGuardsTest.php
 */
if (PHP_SAPI !== 'cli') {
    exit;
}

$root = dirname(__DIR__);
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

echo "1) Admin mutations are POST-gated\n";
$main = file_get_contents($root . '/reportbrokenlink.php');
ok(strpos($main, 'REQUEST_METHOD') !== false, 'REQUEST_METHOD check present');
ok(strpos($main, '$isPost && Tools::isSubmit') !== false, 'mutators behind $isPost');
ok(strpos($main, "\$isPost && Tools::isSubmit('rbl_export')") !== false, 'export behind POST');

echo "\n2) CSV export is a POST button, not a GET link\n";
$tpl = file_get_contents($root . '/views/templates/admin/reports.tpl');
ok(strpos($tpl, 'name="rbl_export"') !== false, 'rbl_export submit button');
ok(strpos($tpl, 'rbl_export_url') === false, 'no GET export URL in template');

echo "\n3) reCAPTCHA siteverify uses POST body for secret\n";
ok(strpos($main, "'method' => 'POST'") !== false, 'POST context for siteverify');
ok(strpos($main, 'siteverify?') === false, 'secret not in query string');

echo "\n4) Front report controller stays POST-only\n";
$front = file_get_contents($root . '/controllers/front/report.php');
ok(strpos($front, 'REQUEST_METHOD') !== false, 'FO POST gate');

echo "\n5) Version + upgrade stub\n";
ok(strpos($main, "version = '4.1.2'") !== false, 'module version 4.1.2');
ok(is_file($root . '/upgrade/upgrade-4.1.2.php'), 'upgrade-4.1.2.php present');

echo "\n" . ($fail === 0 ? "OK - all passed\n" : "$fail FAILED\n");
exit($fail === 0 ? 0 : 1);
