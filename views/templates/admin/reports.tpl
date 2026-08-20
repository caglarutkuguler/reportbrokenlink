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

<div class="panel">
    <h3>
        <i class="icon icon-flag"></i> {l s='Reports' mod='reportbrokenlink'}
        <span class="badge">{$rbl_total|intval}</span>
    </h3>

    {* Filters are a GET form so the resulting list can be bookmarked and shared, and so the
       pagination links below can simply carry the same query string. The AdminModules token is
       re-sent as a hidden field: without it PrestaShop rejects the page as an invalid token. *}
    <form action="index.php" method="get" class="rbl-filters" id="rbl-filter-form">
        <input type="hidden" name="controller" value="AdminModules">
        <input type="hidden" name="token" value="{$rbl_admin_token|escape:'html':'UTF-8'}">
        <input type="hidden" name="configure" value="reportbrokenlink">

        <div class="rbl-filters__row">
            <div class="rbl-filter">
                <label for="rbl_status">{l s='Status' mod='reportbrokenlink'}</label>
                <select name="rbl_status" id="rbl_status" class="form-control">
                    <option value="">{l s='Any' mod='reportbrokenlink'}</option>
                    {foreach from=$rbl_status_labels key=value item=label}
                        <option value="{$value|escape:'html':'UTF-8'}"{if $rbl_filters.status == $value} selected="selected"{/if}>
                            {$label|escape:'html':'UTF-8'}
                        </option>
                    {/foreach}
                </select>
            </div>

            <div class="rbl-filter">
                <label for="rbl_type">{l s='Issue type' mod='reportbrokenlink'}</label>
                <select name="rbl_type" id="rbl_type" class="form-control">
                    <option value="">{l s='Any' mod='reportbrokenlink'}</option>
                    {foreach from=$rbl_type_labels key=value item=label}
                        <option value="{$value|escape:'html':'UTF-8'}"{if $rbl_filters.report_type == $value} selected="selected"{/if}>
                            {$label|escape:'html':'UTF-8'}
                        </option>
                    {/foreach}
                </select>
            </div>

            <div class="rbl-filter">
                <label for="rbl_category">{l s='Category' mod='reportbrokenlink'}</label>
                <select name="rbl_category" id="rbl_category" class="form-control">
                    <option value="">{l s='Any' mod='reportbrokenlink'}</option>
                    {foreach from=$rbl_categories item=category}
                        <option value="{$category.id_category|intval}"{if $rbl_filters.id_category == $category.id_category} selected="selected"{/if}>
                            {$category.name|escape:'html':'UTF-8'}
                        </option>
                    {/foreach}
                </select>
            </div>

            <div class="rbl-filter">
                <label for="rbl_from">{l s='From' mod='reportbrokenlink'}</label>
                <input type="date" name="rbl_from" id="rbl_from" class="form-control"
                       value="{$rbl_filters.date_from|escape:'html':'UTF-8'}">
            </div>

            <div class="rbl-filter">
                <label for="rbl_to">{l s='To' mod='reportbrokenlink'}</label>
                <input type="date" name="rbl_to" id="rbl_to" class="form-control"
                       value="{$rbl_filters.date_to|escape:'html':'UTF-8'}">
            </div>

            <div class="rbl-filter rbl-filter--grow">
                <label for="rbl_search">{l s='Search' mod='reportbrokenlink'}</label>
                <input type="text" name="rbl_search" id="rbl_search" class="form-control"
                       placeholder="{l s='Message, name or e-mail' mod='reportbrokenlink'}"
                       value="{$rbl_filters.search|escape:'html':'UTF-8'}">
            </div>

            <div class="rbl-filter rbl-filter--actions">
                <button type="submit" class="btn btn-default">
                    <i class="icon icon-search"></i> {l s='Filter' mod='reportbrokenlink'}
                </button>
                {if $rbl_has_filters}
                    <a class="btn btn-link" href="{$rbl_configure_url|escape:'html':'UTF-8'}">
                        {l s='Reset' mod='reportbrokenlink'}
                    </a>
                {/if}
            </div>
        </div>

        {if $rbl_filters.id_product}
            <p class="rbl-filters__active">
                {l s='Showing reports for one product only.' mod='reportbrokenlink'}
                <input type="hidden" name="rbl_product" value="{$rbl_filters.id_product|intval}">
                <a href="{$rbl_configure_url|escape:'html':'UTF-8'}">{l s='Show all products' mod='reportbrokenlink'}</a>
            </p>
        {/if}
    </form>

    {if $rbl_total > 0}
        <div class="rbl-toolbar">
            <a class="btn btn-default" href="{$rbl_export_url|escape:'html':'UTF-8'}">
                <i class="icon icon-cloud-download"></i> {l s='Export to CSV' mod='reportbrokenlink'}
            </a>
            <span class="rbl-toolbar__hint text-muted">
                {l s='The export contains exactly the reports matching the filters above.' mod='reportbrokenlink'}
            </span>
        </div>

        {* One form for the whole table. Per-row saves identify their row through the value of
           the submit button, so no second <form> has to be nested inside this one. *}
        <form action="{$rbl_configure_url|escape:'html':'UTF-8'}" method="post" id="rbl-list-form">
            <div class="rbl-bulk" data-rbl-bulk hidden>
                <span class="rbl-bulk__count">
                    <span data-rbl-bulk-count>0</span> {l s='selected' mod='reportbrokenlink'}
                </span>
                <button type="submit" name="rbl_bulk_progress" class="btn btn-default">
                    <i class="icon icon-spinner"></i> {l s='Mark in progress' mod='reportbrokenlink'}
                </button>
                <button type="submit" name="rbl_bulk_resolve" class="btn btn-default">
                    <i class="icon icon-check"></i> {l s='Mark resolved' mod='reportbrokenlink'}
                </button>
                <button type="submit" name="rbl_bulk_spam" class="btn btn-default">
                    <i class="icon icon-ban"></i> {l s='Mark as spam' mod='reportbrokenlink'}
                </button>
                <button type="submit" name="rbl_bulk_delete" class="btn btn-danger"
                        data-rbl-confirm="{l s='Delete the selected report(s)? This cannot be undone.' mod='reportbrokenlink'|escape:'html':'UTF-8'}">
                    <i class="icon icon-trash"></i> {l s='Delete' mod='reportbrokenlink'}
                </button>
            </div>

            <table class="table rbl-table">
                <thead>
                <tr>
                    <th class="rbl-col-check">
                        <input type="checkbox" data-rbl-check-all
                               aria-label="{l s='Select all reports on this page' mod='reportbrokenlink'}">
                    </th>
                    <th>{l s='Product' mod='reportbrokenlink'}</th>
                    <th>{l s='Issue' mod='reportbrokenlink'}</th>
                    <th>{l s='Reported by' mod='reportbrokenlink'}</th>
                    <th>{l s='Date' mod='reportbrokenlink'}</th>
                    <th>{l s='Status' mod='reportbrokenlink'}</th>
                    <th class="rbl-col-actions"></th>
                </tr>
                </thead>
                <tbody>
                {foreach from=$rbl_reports item=report}
                    <tr class="rbl-row rbl-row--{$report.status|escape:'html':'UTF-8'}">
                        <td>
                            <input type="checkbox" name="rbl_ids[]" value="{$report.id_report|intval}"
                                   data-rbl-check
                                   aria-label="{l s='Select report' mod='reportbrokenlink'} #{$report.id_report|intval}">
                        </td>
                        <td>
                            {if $report.product_deleted}
                                <span class="text-muted">{$report.product_display|escape:'html':'UTF-8'}</span>
                            {else}
                                <a href="{$report.edit_link|escape:'html':'UTF-8'}">{$report.product_display|escape:'html':'UTF-8'}</a>
                                <a class="rbl-ext" href="{$report.front_link|escape:'html':'UTF-8'}" target="_blank"
                                   rel="noopener noreferrer"
                                   title="{l s='Open the page a visitor sees' mod='reportbrokenlink'}">
                                    <i class="icon icon-external-link"></i>
                                </a>
                            {/if}
                        </td>
                        <td>
                            <span class="rbl-type">{$report.type_label|escape:'html':'UTF-8'}</span>
                            <span class="rbl-excerpt">{$report.excerpt|escape:'html':'UTF-8'}</span>
                        </td>
                        <td>
                            {if $report.customer_link}
                                <a href="{$report.customer_link|escape:'html':'UTF-8'}">{$report.customer_display|escape:'html':'UTF-8'}</a>
                            {else}
                                {$report.customer_display|escape:'html':'UTF-8'}
                            {/if}
                            {if $report.customer_email}
                                <br><a class="rbl-mail" href="mailto:{$report.customer_email|escape:'html':'UTF-8'}">{$report.customer_email|escape:'html':'UTF-8'}</a>
                            {/if}
                        </td>
                        <td class="rbl-date">{$report.created_display|escape:'html':'UTF-8'}</td>
                        <td>
                            <span class="rbl-status rbl-status--{$report.status|escape:'html':'UTF-8'}">
                                {$rbl_status_labels[$report.status]|escape:'html':'UTF-8'}
                            </span>
                        </td>
                        <td class="text-right">
                            <button type="button" class="btn btn-default btn-xs"
                                    data-rbl-toggle="rbl-detail-{$report.id_report|intval}"
                                    aria-expanded="false" aria-controls="rbl-detail-{$report.id_report|intval}">
                                {l s='View' mod='reportbrokenlink'} <i class="icon icon-caret-down"></i>
                            </button>
                        </td>
                    </tr>

                    <tr class="rbl-detail" id="rbl-detail-{$report.id_report|intval}" hidden>
                        <td colspan="7">
                            <div class="rbl-detail__grid">
                                <div class="rbl-detail__main">
                                    <h4>{l s='What the visitor wrote' mod='reportbrokenlink'}</h4>
                                    {* white-space: pre-wrap keeps the reporter's line breaks
                                       without ever putting markup into the page. *}
                                    <div class="rbl-detail__message">{$report.message|escape:'html':'UTF-8'}</div>

                                    <dl class="rbl-detail__meta">
                                        <dt>{l s='Reference' mod='reportbrokenlink'}</dt>
                                        <dd>#{$report.id_report|intval}</dd>
                                        <dt>{l s='Issue type' mod='reportbrokenlink'}</dt>
                                        <dd>{$report.type_label|escape:'html':'UTF-8'}</dd>
                                        <dt>{l s='Received' mod='reportbrokenlink'}</dt>
                                        <dd>{$report.created_display|escape:'html':'UTF-8'}</dd>
                                        <dt>{l s='Last updated' mod='reportbrokenlink'}</dt>
                                        <dd>{$report.updated_display|escape:'html':'UTF-8'}</dd>
                                        <dt>{l s='Reported by' mod='reportbrokenlink'}</dt>
                                        <dd>
                                            {$report.customer_display|escape:'html':'UTF-8'}
                                            {if $report.customer_email}
                                                &lt;{$report.customer_email|escape:'html':'UTF-8'}&gt;
                                            {else}
                                                <span class="text-muted">({l s='no e-mail address left' mod='reportbrokenlink'})</span>
                                            {/if}
                                        </dd>
                                    </dl>
                                </div>

                                <div class="rbl-detail__side">
                                    <div class="form-group">
                                        <label for="rbl_report_status_{$report.id_report|intval}">
                                            {l s='Status' mod='reportbrokenlink'}
                                        </label>
                                        <select class="form-control" id="rbl_report_status_{$report.id_report|intval}"
                                                name="rbl_report_status_{$report.id_report|intval}">
                                            {foreach from=$rbl_status_labels key=value item=label}
                                                <option value="{$value|escape:'html':'UTF-8'}"{if $report.status == $value} selected="selected"{/if}>
                                                    {$label|escape:'html':'UTF-8'}
                                                </option>
                                            {/foreach}
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label for="rbl_admin_response_{$report.id_report|intval}">
                                            {l s='Your notes / reply' mod='reportbrokenlink'}
                                        </label>
                                        <textarea class="form-control" rows="4"
                                                  id="rbl_admin_response_{$report.id_report|intval}"
                                                  name="rbl_admin_response_{$report.id_report|intval}"
                                                  placeholder="{l s='Kept internally unless you tick the box below.' mod='reportbrokenlink'}">{$report.admin_response|escape:'html':'UTF-8'}</textarea>
                                    </div>

                                    {if $rbl_notify_enabled && $report.customer_email && $report.status != 'resolved'}
                                        <div class="checkbox rbl-notify">
                                            <label>
                                                <input type="checkbox" value="1"
                                                       name="rbl_notify_customer_{$report.id_report|intval}">
                                                {l s='E-mail the reporter when I save this as Resolved' mod='reportbrokenlink'}
                                            </label>
                                        </div>
                                    {/if}

                                    <button type="submit" class="btn btn-primary"
                                            name="rbl_save_report" value="{$report.id_report|intval}">
                                        <i class="icon icon-save"></i> {l s='Save' mod='reportbrokenlink'}
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        </form>

        {if $rbl_pages > 1}
            <div class="rbl-pagination">
                {if $rbl_page > 1}
                    <a class="btn btn-default"
                       href="{$rbl_configure_url|escape:'html':'UTF-8'}{$rbl_filter_query|escape:'html':'UTF-8'}&amp;rbl_page={$rbl_page-1}">
                        &laquo; {l s='Previous' mod='reportbrokenlink'}
                    </a>
                {/if}
                <span class="rbl-pagination__label">
                    {l s='Page' mod='reportbrokenlink'} {$rbl_page|intval} / {$rbl_pages|intval}
                </span>
                {if $rbl_page < $rbl_pages}
                    <a class="btn btn-default"
                       href="{$rbl_configure_url|escape:'html':'UTF-8'}{$rbl_filter_query|escape:'html':'UTF-8'}&amp;rbl_page={$rbl_page+1}">
                        {l s='Next' mod='reportbrokenlink'} &raquo;
                    </a>
                {/if}
            </div>
        {/if}
    {else}
        <p class="alert alert-info">
            {if $rbl_has_filters}
                {l s='No report matches these filters.' mod='reportbrokenlink'}
                <a href="{$rbl_configure_url|escape:'html':'UTF-8'}">{l s='Reset them' mod='reportbrokenlink'}</a>
            {else}
                {l s='No reports yet. As soon as a visitor uses the button on a product page, it will show up here.' mod='reportbrokenlink'}
            {/if}
        </p>
    {/if}
</div>
