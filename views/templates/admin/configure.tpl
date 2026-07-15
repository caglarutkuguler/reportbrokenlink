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

<link href="{$rbl_this_path|escape:'html':'UTF-8'}views/css/admin.css" rel="stylesheet" type="text/css" media="all">
<script type="text/javascript" src="{$rbl_this_path|escape:'html':'UTF-8'}views/js/admin.js"></script>

<div class="panel">
    <h3><i class="icon icon-question-circle"></i> {l s='How it works' mod='reportbrokenlink'}</h3>

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
    <h3><i class="icon icon-stethoscope"></i> {l s='Status' mod='reportbrokenlink'}</h3>

    <ul class="rbl-checks">
        {foreach from=$rbl_checks item=check}
            <li class="rbl-check rbl-check--{$check.status|escape:'html':'UTF-8'}">
                {if $check.status == 'ok'}
                    <i class="icon icon-check-circle"></i>
                {elseif $check.status == 'warning'}
                    <i class="icon icon-exclamation-triangle"></i>
                {else}
                    <i class="icon icon-times-circle"></i>
                {/if}
                {$check.label|escape:'html':'UTF-8'}
            </li>
        {/foreach}
    </ul>
</div>

<div class="panel">
    <h3><i class="icon icon-bar-chart"></i> {l s='At a glance' mod='reportbrokenlink'}</h3>

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
