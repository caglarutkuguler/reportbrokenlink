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

<link href="{$rbl_this_path|escape:'html':'UTF-8'}views/css/admin.css" rel="stylesheet" type="text/css" media="all">
<script type="text/javascript" src="{$rbl_this_path|escape:'html':'UTF-8'}views/js/admin.js"></script>

<div class="panel">
    <h3><i class="material-icons mv-ico">help</i> {l s='How it works' mod='reportbrokenlink'}</h3>

    <div class="rbl-steps">
        <div class="rbl-step">
            <span class="rbl-step__num">1</span>
            <div>
                <strong>{l s='A visitor spots a problem' mod='reportbrokenlink'}</strong>
                <p>{l s='Every product page gets a discreet "Report an issue" button. Anyone can open it - customers and guests alike - pick what is wrong, and describe it in a sentence.' mod='reportbrokenlink'}</p>
            </div>
        </div>
        <div class="rbl-step">
            <span class="rbl-step__num">2</span>
            <div>
                <strong>{l s='The report is saved and you are e-mailed' mod='reportbrokenlink'}</strong>
                <p>{l s='Reports are stored in your shop first and e-mailed second, so nothing is ever lost if your mail server has a bad day. Spam is filtered out before it reaches you.' mod='reportbrokenlink'}</p>
            </div>
        </div>
        <div class="rbl-step">
            <span class="rbl-step__num">3</span>
            <div>
                <strong>{l s='You fix it and close the loop' mod='reportbrokenlink'}</strong>
                <p>{l s='Work through the list below, set each report to Resolved, and - if the visitor left an address - let them know with one tick box.' mod='reportbrokenlink'}</p>
            </div>
        </div>
    </div>
</div>

<div class="panel">
    <h3><i class="material-icons mv-ico">stethoscope</i> {l s='Status' mod='reportbrokenlink'}</h3>

    <ul class="rbl-checks">
        {foreach from=$rbl_checks item=check}
            <li class="rbl-check rbl-check--{$check.status|escape:'html':'UTF-8'}">
                {if $check.status == 'ok'}
                    <i class="material-icons mv-ico">check_circle</i>
                {elseif $check.status == 'warning'}
                    <i class="material-icons mv-ico">warning</i>
                {else}
                    <i class="material-icons mv-ico">cancel</i>
                {/if}
                {$check.label|escape:'html':'UTF-8'}
            </li>
        {/foreach}
    </ul>
</div>

<div class="panel">
    <h3><i class="material-icons mv-ico">bar_chart</i> {l s='At a glance' mod='reportbrokenlink'}</h3>

    <div class="rbl-stats">
        <a class="rbl-stat rbl-stat--open" href="{$rbl_configure_url|escape:'html':'UTF-8'}&amp;rbl_status=open">
            <span class="rbl-stat__value">{$rbl_open_count|intval}</span>
            <span class="rbl-stat__label">{l s='Waiting for you' mod='reportbrokenlink'}</span>
        </a>
        <div class="rbl-stat">
            <span class="rbl-stat__value">{$rbl_week_count|intval}</span>
            <span class="rbl-stat__label">{l s='Reported this week' mod='reportbrokenlink'}</span>
        </div>
        <div class="rbl-stat">
            <span class="rbl-stat__value">{$rbl_month_count|intval}</span>
            <span class="rbl-stat__label">{l s='Reported this month' mod='reportbrokenlink'}</span>
        </div>
        <a class="rbl-stat" href="{$rbl_configure_url|escape:'html':'UTF-8'}&amp;rbl_status=resolved">
            <span class="rbl-stat__value">{$rbl_counts.resolved|intval}</span>
            <span class="rbl-stat__label">{l s='Resolved so far' mod='reportbrokenlink'}</span>
        </a>
        <a class="rbl-stat" href="{$rbl_configure_url|escape:'html':'UTF-8'}&amp;rbl_status=spam">
            <span class="rbl-stat__value">{$rbl_counts.spam|intval}</span>
            <span class="rbl-stat__label">{l s='Marked as spam' mod='reportbrokenlink'}</span>
        </a>
    </div>

    {if $rbl_top_products}
        <h4 class="rbl-subhead">{l s='Most reported products' mod='reportbrokenlink'}</h4>
        <p class="text-muted rbl-subhead__hint">{l s='Spam and duplicates are excluded. A product near the top of this list usually has one underlying problem worth fixing properly.' mod='reportbrokenlink'}</p>
        <table class="table rbl-top">
            <tbody>
            {foreach from=$rbl_top_products item=product}
                <tr>
                    <td>
                        <a href="{$rbl_configure_url|escape:'html':'UTF-8'}&amp;rbl_product={$product.id_product|intval}">
                            {if $product.product_name}
                                {$product.product_name|escape:'html':'UTF-8'}
                            {else}
                                {l s='Deleted product' mod='reportbrokenlink'} #{$product.id_product|intval}
                            {/if}
                        </a>
                    </td>
                    <td class="text-right">
                        <span class="badge">{$product.total|intval}</span>
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    {/if}
</div>
