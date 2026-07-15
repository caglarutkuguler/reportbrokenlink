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
 * Back office reports list: expandable rows, bulk selection, delete confirmation.
 *
 * Vanilla JS: the module must not depend on the admin theme's jQuery version, which differs
 * between PrestaShop 1.7, 8 and 9.
 */
(function () {
    'use strict';

    function ready(callback) {
        if (document.readyState !== 'loading') {
            callback();
        } else {
            document.addEventListener('DOMContentLoaded', callback);
        }
    }

    ready(function () {
        var form = document.getElementById('rbl-list-form');
        if (!form) {
            return;
        }

        /* -------------------------------------------------------------- */
        /* Expandable detail rows                                          */
        /* -------------------------------------------------------------- */

        Array.prototype.forEach.call(form.querySelectorAll('[data-rbl-toggle]'), function (button) {
            button.addEventListener('click', function () {
                var row = document.getElementById(button.getAttribute('data-rbl-toggle'));
                if (!row) {
                    return;
                }

                var opening = row.hidden;
                row.hidden = !opening;
                button.setAttribute('aria-expanded', opening ? 'true' : 'false');
                button.classList.toggle('active', opening);

                if (opening) {
                    var field = row.querySelector('select, textarea');
                    if (field) {
                        field.focus();
                    }
                }
            });
        });

        /* -------------------------------------------------------------- */
        /* Bulk selection                                                  */
        /* -------------------------------------------------------------- */

        var bulkBar = form.querySelector('[data-rbl-bulk]');
        var bulkCount = form.querySelector('[data-rbl-bulk-count]');
        var checkAll = form.querySelector('[data-rbl-check-all]');
        var checkboxes = Array.prototype.slice.call(form.querySelectorAll('[data-rbl-check]'));

        function selected() {
            return checkboxes.filter(function (box) {
                return box.checked;
            });
        }

        function refresh() {
            var count = selected().length;

            if (bulkBar) {
                bulkBar.hidden = count === 0;
            }
            if (bulkCount) {
                bulkCount.textContent = count + '';
            }
            if (checkAll) {
                checkAll.checked = count > 0 && count === checkboxes.length;
                // Neither all nor none: show the mixed state rather than lying with a tick.
                checkAll.indeterminate = count > 0 && count < checkboxes.length;
            }
        }

        checkboxes.forEach(function (box) {
            box.addEventListener('change', refresh);
        });

        if (checkAll) {
            checkAll.addEventListener('change', function () {
                checkboxes.forEach(function (box) {
                    box.checked = checkAll.checked;
                });
                refresh();
            });
        }

        refresh();

        /* -------------------------------------------------------------- */
        /* Confirmation on destructive actions                             */
        /* -------------------------------------------------------------- */

        Array.prototype.forEach.call(form.querySelectorAll('[data-rbl-confirm]'), function (button) {
            button.addEventListener('click', function (event) {
                if (!window.confirm(button.getAttribute('data-rbl-confirm'))) {
                    event.preventDefault();
                }
            });
        });
    });
})();
