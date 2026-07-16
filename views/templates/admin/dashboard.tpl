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
   with nothing to do.

   Styled with the admin theme's own Bootstrap classes (alert / btn / icon), NOT the module's
   admin.css. This template renders on the Dashboard controller, which does not load the
   module's stylesheet — that only loads on the module's own configure page — so any custom
   class here would render unstyled. This mirrors the sibling wecallyouback widget so the two
   sit consistently on the Dashboard.

   The counts stay outside the translated sentences: building them with sprintf() in the
   template needs a PHP call Smarty does not expose here, and it spares translators from having
   to preserve placeholders. *}
<div class="alert alert-info" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <span>
        <i class="icon icon-flag"></i>&nbsp;
        <strong>{$rbl_open_count|intval} {l s='page issue(s) reported by customers are waiting for you' mod='reportbrokenlink'}</strong>
        &nbsp;{$rbl_week_count|intval} {l s='report(s) came in over the last 7 days.' mod='reportbrokenlink'}
    </span>
    <a href="{$rbl_dashboard_link|escape:'html':'UTF-8'}" class="btn btn-primary btn-sm">
        {l s='Review reports' mod='reportbrokenlink'}
    </a>
</div>
