{*
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
*}

{* Only rendered when at least one report is waiting, so the Dashboard stays quiet on a shop
   with nothing to do. The counts are kept outside the translated sentences: building them with
   sprintf() in the template would need a PHP call Smarty does not expose here, and it saves
   translators from having to preserve placeholders. *}
<div class="rbl-widget">
    <i class="icon icon-flag rbl-widget__icon"></i>
    <div class="rbl-widget__text">
        <strong>
            {$rbl_open_count|intval} {l s='page issue(s) reported by customers are waiting for you' mod='reportbrokenlink'}
        </strong>
        <span class="rbl-widget__hint">
            {$rbl_week_count|intval} {l s='report(s) came in over the last 7 days.' mod='reportbrokenlink'}
        </span>
    </div>
    <a class="btn btn-default" href="{$rbl_dashboard_link|escape:'html':'UTF-8'}">
        {l s='Review reports' mod='reportbrokenlink'}
    </a>
</div>
