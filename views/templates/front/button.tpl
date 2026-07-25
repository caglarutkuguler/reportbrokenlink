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

{* The modal is moved to <body> by front.js on load: rendered here it would sit inside the
   theme's add-to-cart <form>, where a nested dialog breaks both layout and submission. *}
<div class="rbl-wrapper">
    <button type="button" class="rbl-open" data-rbl-open aria-haspopup="dialog">
        <svg class="rbl-open__icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">
            <path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                  d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>
        </svg>
        <span>{l s='Report an issue with this page' mod='reportbrokenlink'}</span>
    </button>

    <div class="rbl-modal" data-rbl-modal hidden>
        <div class="rbl-modal__backdrop" data-rbl-dismiss></div>

        <div class="rbl-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="rbl-title">
            <div class="rbl-modal__header">
                <h2 class="rbl-modal__title" id="rbl-title">{l s='Report an issue' mod='reportbrokenlink'}</h2>
                <button type="button" class="rbl-modal__close" data-rbl-dismiss
                        aria-label="{l s='Close' mod='reportbrokenlink'}">&times;</button>
            </div>

            {* Deliberately a <div>, not a <form>. In the "next to product information"
               position this markup is rendered INSIDE the theme's add-to-cart <form>
               (classic product.tpl includes displayProductAdditionalInfo inside
               <form id="add-to-cart-or-refresh">). A <form> nested in a <form> is invalid HTML,
               so the browser's parser silently drops the inner one before any script runs —
               which made front.js fail to find [data-rbl-form] and bail out, so the button did
               nothing. A <div> is never touched by the parser. front.js collects the fields and
               posts them with fetch(), so no real form element is needed. *}
            <div class="rbl-modal__body" data-rbl-form>
                <p class="rbl-intro">{l s='Noticed something wrong on this page? Tell us what you saw and we will fix it.' mod='reportbrokenlink'}</p>

                <div class="rbl-alert rbl-alert--error" data-rbl-error hidden role="alert"></div>

                <div class="rbl-field">
                    <label class="rbl-label" for="rbl-type">
                        {l s='What is wrong?' mod='reportbrokenlink'} <span class="rbl-required" aria-hidden="true">*</span>
                    </label>
                    <select class="rbl-input" id="rbl-type" name="rbl_type" required>
                        <option value="">{l s='Please choose...' mod='reportbrokenlink'}</option>
                        {foreach from=$rbl_types item=type}
                            <option value="{$type.value|escape:'html':'UTF-8'}">{$type.label|escape:'html':'UTF-8'}</option>
                        {/foreach}
                    </select>
                    <span class="rbl-field__error" data-rbl-field-error="type"></span>
                </div>

                <div class="rbl-field">
                    <label class="rbl-label" for="rbl-message">
                        {l s='Describe the issue' mod='reportbrokenlink'} <span class="rbl-required" aria-hidden="true">*</span>
                    </label>
                    <textarea class="rbl-input rbl-textarea" id="rbl-message" name="rbl_message" rows="4"
                              maxlength="{$rbl_message_max|intval}"
                              placeholder="{l s='For example: the size guide link does not open.' mod='reportbrokenlink'}"
                              required aria-describedby="rbl-counter"></textarea>
                    <span class="rbl-counter" id="rbl-counter" data-rbl-counter aria-live="polite"></span>
                    <span class="rbl-field__error" data-rbl-field-error="message"></span>
                </div>

                {if !$rbl_is_logged}
                    <div class="rbl-field">
                        <label class="rbl-label" for="rbl-name">
                            {l s='Your name' mod='reportbrokenlink'}
                            <span class="rbl-optional">{l s='(optional)' mod='reportbrokenlink'}</span>
                        </label>
                        <input class="rbl-input" id="rbl-name" name="rbl_name" type="text" maxlength="255"
                               autocomplete="name">
                    </div>

                    <div class="rbl-field">
                        <label class="rbl-label" for="rbl-email">
                            {l s='Your e-mail' mod='reportbrokenlink'}
                            <span class="rbl-optional">{l s='(optional)' mod='reportbrokenlink'}</span>
                        </label>
                        <input class="rbl-input" id="rbl-email" name="rbl_email" type="email" maxlength="255"
                               autocomplete="email" aria-describedby="rbl-email-help">
                        <span class="rbl-help" id="rbl-email-help">{l s='Only used to let you know once the issue is fixed.' mod='reportbrokenlink'}</span>
                        <span class="rbl-field__error" data-rbl-field-error="email"></span>
                    </div>
                {/if}

                {* Honeypot. Hidden from people, irresistible to bots. Never remove the label:
                   a field with no label is what some bots look for. *}
                <div class="rbl-hp" aria-hidden="true">
                    <label for="rbl-website">{l s='Leave this field empty' mod='reportbrokenlink'}</label>
                    <input id="rbl-website" name="rbl_website" type="text" tabindex="-1" autocomplete="off">
                </div>

                {if $rbl_gdpr_module_id}
                    <div class="rbl-gdpr">
                        {hook h='displayGDPRConsent' id_module=$rbl_gdpr_module_id}
                    </div>
                {/if}

                <input type="hidden" name="rbl_id_product" value="{$rbl_id_product|intval}">
                <input type="hidden" name="rbl_token" value="{$rbl_token|escape:'html':'UTF-8'}">

                <div class="rbl-modal__footer">
                    <button type="button" class="rbl-btn rbl-btn--ghost" data-rbl-dismiss>
                        {l s='Cancel' mod='reportbrokenlink'}
                    </button>
                    <button type="button" class="rbl-btn rbl-btn--primary" data-rbl-submit>
                        <span class="rbl-spinner" data-rbl-spinner hidden aria-hidden="true"></span>
                        <span data-rbl-submit-label>{l s='Send report' mod='reportbrokenlink'}</span>
                    </button>
                </div>
            </div>

            <div class="rbl-success" data-rbl-success hidden>
                <svg class="rbl-success__icon" viewBox="0 0 24 24" width="40" height="40" aria-hidden="true" focusable="false">
                    <path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                          d="m5 13 4 4L19 7"/>
                </svg>
                <p class="rbl-success__text" data-rbl-success-text></p>
                <button type="button" class="rbl-btn rbl-btn--primary" data-rbl-dismiss>
                    {l s='Close' mod='reportbrokenlink'}
                </button>
            </div>
        </div>
    </div>
</div>
