{*
* 2019-2026 MEG Venture
*
* NOTICE OF LICENSE
*
* This source file is subject to the MIT License
* that is bundled with this package in the file LICENSE.
* It is also available through the world-wide-web at this URL:
* https://opensource.org/licenses/MIT
*
*  @author    MEG Venture
*  @copyright 2019-2026 MEG Venture & Consulting Ltd.
*  @license   https://opensource.org/licenses/MIT MIT License
*}
<style>
{literal}
/* Back-office icons. These used to be FontAwesome 4, which the back office shipped up to
   PrestaShop 8; PrestaShop 9 replaced it with Material Symbols Outlined. An icon-font class
   selects a private-use code point, so the moment the font is not there the browser has
   nothing to fall back to and draws an empty box. These now come from the set the core loads
   for its own interface, which is ligature-based: the icon name is the element's text.
   Sized down from the 24px default and set back to inheriting the text colour, so they sit
   where the FontAwesome ones did. */
.material-icons.mv-ico{font-size:18px;line-height:1;vertical-align:middle;margin-right:4px;}
.material-icons.mv-ico,.material-icons.mv-ico:hover{color:inherit;}
h3 .material-icons.mv-ico,
.panel-heading .material-icons.mv-ico{font-size:20px;}
.btn .material-icons.mv-ico{font-size:16px;margin-right:3px;}
{/literal}
</style>

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
        <i class="material-icons mv-ico">flag</i>&nbsp;
        <strong>{$rbl_open_count|intval} {l s='page issue(s) reported by customers are waiting for you' mod='reportbrokenlink'}</strong>
        &nbsp;{$rbl_week_count|intval} {l s='report(s) came in over the last 7 days.' mod='reportbrokenlink'}
    </span>
    <a href="{$rbl_dashboard_link|escape:'html':'UTF-8'}" class="btn btn-primary btn-sm">
        {l s='Review reports' mod='reportbrokenlink'}
    </a>
</div>
