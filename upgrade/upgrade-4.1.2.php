<?php
/**
 * 4.1.1 -> 4.1.2: admin POST gate + reCAPTCHA verify POST (no schema change).
 *
 * @author    MEG Venture <info@megventure.com>
 * @copyright 2019-2026 MEG Venture & Consulting Ltd.
 * @license   https://opensource.org/licenses/MIT MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param ReportBrokenLink $module
 *
 * @return bool
 */
function upgrade_module_4_1_2($module)
{
    unset($module);

    return true;
}
