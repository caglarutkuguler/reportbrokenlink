/**
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
 */

/**
 * The report form.
 *
 * No jQuery and no Bootstrap. 3.x pulled jQuery 1.8.2 from Google's CDN on every product page,
 * which sent every visitor's IP to Google before any consent, shipped a 2012 library with known
 * XSS advisories, and replaced the theme's own jQuery for anything that ran afterwards.
 *
 * Configuration arrives in window.reportBrokenLinkConfig via Media::addJsDef.
 */
(function () {
    'use strict';

    var config = window.reportBrokenLinkConfig;
    if (!config || !config.url) {
        return;
    }

    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]),' +
        ' textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    var wrapper = document.querySelector('.rbl-wrapper');
    if (!wrapper) {
        return;
    }

    var openButton = wrapper.querySelector('[data-rbl-open]');
    var modal = wrapper.querySelector('[data-rbl-modal]');
    var form = wrapper.querySelector('[data-rbl-form]');
    if (!openButton || !modal || !form) {
        return;
    }

    var dialog = modal.querySelector('.rbl-modal__dialog');
    var globalError = modal.querySelector('[data-rbl-error]');
    var successPanel = modal.querySelector('[data-rbl-success]');
    var successText = modal.querySelector('[data-rbl-success-text]');
    var submitButton = modal.querySelector('[data-rbl-submit]');
    var submitLabel = modal.querySelector('[data-rbl-submit-label]');
    var spinner = modal.querySelector('[data-rbl-spinner]');
    var messageField = form.querySelector('#rbl-message');
    var counter = modal.querySelector('[data-rbl-counter]');

    var lastFocused = null;
    var submitting = false;

    /* ------------------------------------------------------------------ */
    /* Modal plumbing                                                       */
    /* ------------------------------------------------------------------ */

    // Rendered inside the product page the dialog would sit within the theme's add-to-cart
    // <form>; a nested dialog breaks layout and can submit the wrong form. Move it to <body>.
    document.body.appendChild(modal);

    function lockScroll() {
        // Compensate for the scrollbar we are about to remove, otherwise the whole page shifts
        // sideways as the modal opens.
        var scrollbar = window.innerWidth - document.documentElement.clientWidth;
        document.body.dataset.rblPaddingRight = document.body.style.paddingRight || '';
        document.body.dataset.rblOverflow = document.body.style.overflow || '';
        if (scrollbar > 0) {
            document.body.style.paddingRight = scrollbar + 'px';
        }
        document.body.style.overflow = 'hidden';
    }

    function unlockScroll() {
        document.body.style.paddingRight = document.body.dataset.rblPaddingRight || '';
        document.body.style.overflow = document.body.dataset.rblOverflow || '';
        delete document.body.dataset.rblPaddingRight;
        delete document.body.dataset.rblOverflow;
    }

    function open() {
        lastFocused = document.activeElement;
        modal.hidden = false;
        lockScroll();

        var first = dialog.querySelector(FOCUSABLE);
        if (first) {
            first.focus();
        }

        document.addEventListener('keydown', onKeydown);
    }

    function close() {
        modal.hidden = true;
        unlockScroll();
        document.removeEventListener('keydown', onKeydown);

        // Returning focus to the button is what makes the dialog usable with a keyboard or a
        // screen reader; without it focus falls back to the top of the document.
        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    }

    function onKeydown(event) {
        if (event.key === 'Escape' || event.key === 'Esc') {
            close();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        // Trap Tab inside the dialog while it is open.
        var focusable = Array.prototype.filter.call(
            dialog.querySelectorAll(FOCUSABLE),
            function (element) {
                return element.offsetParent !== null;
            }
        );

        if (!focusable.length) {
            return;
        }

        var first = focusable[0];
        var last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    openButton.addEventListener('click', open);

    Array.prototype.forEach.call(modal.querySelectorAll('[data-rbl-dismiss]'), function (element) {
        element.addEventListener('click', close);
    });

    /* ------------------------------------------------------------------ */
    /* Character counter                                                    */
    /* ------------------------------------------------------------------ */

    function updateCounter() {
        if (!counter || !messageField) {
            return;
        }

        var length = messageField.value.length;
        counter.textContent = length + ' / ' + config.messageMax;
        counter.classList.toggle('rbl-counter--limit', length >= config.messageMax);
    }

    if (messageField) {
        messageField.addEventListener('input', updateCounter);
        updateCounter();
    }

    /* ------------------------------------------------------------------ */
    /* Errors                                                               */
    /* ------------------------------------------------------------------ */

    function clearErrors() {
        globalError.hidden = true;
        globalError.textContent = '';

        Array.prototype.forEach.call(form.querySelectorAll('[data-rbl-field-error]'), function (element) {
            element.textContent = '';
            element.classList.remove('rbl-field__error--visible');
        });

        Array.prototype.forEach.call(form.querySelectorAll('.rbl-input--invalid'), function (element) {
            element.classList.remove('rbl-input--invalid');
            element.removeAttribute('aria-invalid');
        });
    }

    function showFieldError(field, message) {
        var slot = form.querySelector('[data-rbl-field-error="' + field + '"]');
        if (slot) {
            slot.textContent = message;
            slot.classList.add('rbl-field__error--visible');
        }

        var input = form.querySelector('[name="rbl_' + field + '"]');
        if (input) {
            input.classList.add('rbl-input--invalid');
            input.setAttribute('aria-invalid', 'true');
        }
    }

    function showGlobalError(message) {
        globalError.textContent = message;
        globalError.hidden = false;
    }

    /* ------------------------------------------------------------------ */
    /* Validation                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Mirrors the server's rules so the visitor gets instant feedback. The server re-checks
     * every one of these: this is a convenience, never a control.
     *
     * @returns {boolean} true when the form looks submittable
     */
    function validate() {
        clearErrors();

        var valid = true;
        var type = form.querySelector('[name="rbl_type"]');
        var email = form.querySelector('[name="rbl_email"]');
        var message = messageField ? messageField.value.trim() : '';

        if (!type || !type.value) {
            showFieldError('type', config.i18n.typeRequired);
            valid = false;
        }

        if (!message) {
            showFieldError('message', config.i18n.messageRequired);
            valid = false;
        } else if (message.length < config.messageMin) {
            showFieldError('message', config.i18n.messageTooShort);
            valid = false;
        }

        if (email && email.value.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
            showFieldError('email', config.i18n.emailInvalid);
            valid = false;
        }

        if (!valid) {
            var firstInvalid = form.querySelector('.rbl-input--invalid');
            if (firstInvalid) {
                firstInvalid.focus();
            }
        }

        return valid;
    }

    /* ------------------------------------------------------------------ */
    /* Submission                                                           */
    /* ------------------------------------------------------------------ */

    function setLoading(state) {
        submitting = state;
        submitButton.disabled = state;
        spinner.hidden = !state;
        if (state) {
            submitLabel.dataset.rblIdle = submitLabel.textContent;
            submitLabel.textContent = config.i18n.sending;
        } else if (submitLabel.dataset.rblIdle) {
            submitLabel.textContent = submitLabel.dataset.rblIdle;
        }
    }

    /**
     * Resolves with a reCAPTCHA v3 token, or with an empty string when reCAPTCHA is off or its
     * script failed to load. A CAPTCHA that cannot load must not block a real report.
     *
     * @returns {Promise<string>}
     */
    function getRecaptchaToken() {
        if (!config.recaptchaSite || typeof window.grecaptcha === 'undefined') {
            return Promise.resolve('');
        }

        return new Promise(function (resolve) {
            window.grecaptcha.ready(function () {
                window.grecaptcha
                    .execute(config.recaptchaSite, { action: 'reportbrokenlink' })
                    .then(resolve)
                    .catch(function () {
                        resolve('');
                    });
            });
        });
    }

    function showSuccess(message) {
        form.hidden = true;
        successText.textContent = message;
        successPanel.hidden = false;

        var closeButton = successPanel.querySelector('[data-rbl-dismiss]');
        if (closeButton) {
            closeButton.focus();
        }
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        if (submitting || !validate()) {
            return;
        }

        setLoading(true);

        getRecaptchaToken().then(function (token) {
            var data = new FormData(form);
            if (token) {
                data.append('g-recaptcha-response', token);
            }
            // Belt and braces: the controller sets $this->ajax itself, but PrestaShop also
            // derives that flag from this parameter, so sending it keeps the JSON response
            // working regardless of which version's plumbing is underneath.
            data.append('ajax', '1');

            return fetch(config.url, {
                method: 'POST',
                body: data,
                // Send the shop's cookies so a signed-in customer is recognised server-side.
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        }).then(function (response) {
            // Never trust the status alone: 3.x treated any 200 as success, which is how it
            // told four versions' worth of customers their report had been sent while the
            // server was answering with a failure.
            return response.json().catch(function () {
                throw new Error('invalid-json');
            });
        }).then(function (result) {
            setLoading(false);

            if (result && result.success) {
                showSuccess(result.message);
                return;
            }

            if (result && result.errors) {
                Object.keys(result.errors).forEach(function (field) {
                    showFieldError(field, result.errors[field]);
                });
                return;
            }

            showGlobalError((result && result.message) || config.i18n.networkError);
        }).catch(function () {
            setLoading(false);
            showGlobalError(config.i18n.networkError);
        });
    });
})();
