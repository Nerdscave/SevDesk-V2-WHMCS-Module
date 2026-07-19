{include file="partials/layout_top.tpl" pageTitle="Sammelexport"}

<form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=massImport" data-loading-form>
    <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
    <input type="hidden" name="source" value="whmcs">

    {if $job}
        <div class="alert alert-success" role="status">
            <a class="btn btn-primary btn-sm pull-right" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobDetail&amp;id={$job.id|escape:'url'}">Fortschritt öffnen</a>
            Job <strong>#{$job.id|escape:'html':'UTF-8'}</strong> wurde angelegt. Die Verarbeitung läuft unabhängig von dieser Browserseite weiter.
        </div>
    {/if}

    <div class="panel panel-default">
        <div class="panel-heading"><h3 class="panel-title">Zeitraum festlegen</h3></div>
        <div class="panel-body">
            <div class="row">
                <div class="col-sm-4">
                    <div class="form-group">
                        <label class="control-label" for="date-start">Rechnungsdatum von</label>
                        <input type="date" id="date-start" name="date_start" class="form-control" value="{$filters.date_start|default:$smarty.post.date_start|escape:'html':'UTF-8'}" required>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="form-group">
                        <label class="control-label" for="date-end">Rechnungsdatum bis</label>
                        <input type="date" id="date-end" name="date_end" class="form-control" value="{$filters.date_end|default:$smarty.post.date_end|escape:'html':'UTF-8'}" required>
                    </div>
                </div>
            </div>
            <button type="submit" name="search" value="1" class="btn btn-default">
                <i class="fas fa-search" aria-hidden="true"></i> Rechnungen suchen
            </button>
        </div>
    </div>

    {if $invoices|@count}
        <div class="panel panel-default">
            <div class="panel-heading clearfix">
                <span class="pull-right">
                    <span data-selection-count>0 ausgewählt</span>
                    <button type="button" class="btn btn-default btn-sm" data-select-all>Alle zulässigen wählen</button>
                    <button type="button" class="btn btn-default btn-sm" data-select-none>Auswahl leeren</button>
                </span>
                <h3 class="panel-title">Exportumfang prüfen</h3>
            </div>
            <div class="panel-body">
                <p class="text-muted">{$invoices|@count|escape:'html':'UTF-8'} Rechnungen gefunden. Der Altbestandsjob versendet keine E-Mails und erzeugt keine E-Rechnungen. Vor jeder neuen Invoice prüft der Worker zusätzlich mögliche Remote-Dubletten.</p>
            </div>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                    <tr>
                        <th scope="col" class="sd-checkbox-cell"><span class="sr-only">Auswahl</span></th>
                        <th scope="col">Rechnung</th>
                        <th scope="col">Kunde</th>
                        <th scope="col">Datum</th>
                        <th scope="col" class="text-right">Betrag</th>
                        <th scope="col">WHMCS-Status</th>
                        <th scope="col">Exportstatus</th>
                    </tr>
                    </thead>
                    <tbody>
                    {foreach from=$invoices item=invoice}
                        {assign var="canExport" value=true}
                        {if $invoice.mapped || $invoice.eligible === false || $invoice.exportable === false}{assign var="canExport" value=false}{/if}
                        <tr{if !$canExport} class="is-muted"{/if}>
                            <td class="sd-checkbox-cell">
                                <input type="checkbox" name="invoice_ids[]" value="{$invoice.id|default:$invoice.invoice_id|escape:'html':'UTF-8'}" aria-label="Rechnung {$invoice.invoicenum|default:$invoice.id|escape:'html':'UTF-8'} auswählen" data-export-checkbox{if !$canExport} disabled{else} checked{/if}>
                            </td>
                            <td>
                                <a href="invoices.php?action=edit&amp;id={$invoice.id|default:$invoice.invoice_id|escape:'url'}" target="_blank" rel="noopener">{$invoice.invoicenum|default:'Ohne Rechnungsnummer'|escape:'html':'UTF-8'}</a>
                                <small class="sd-mono">ID {$invoice.id|default:$invoice.invoice_id|escape:'html':'UTF-8'}</small>
                            </td>
                            <td>{$invoice.client_name|default:$invoice.companyname|default:'—'|escape:'html':'UTF-8'}{if $invoice.countrycode}<small>{$invoice.countrycode|escape:'html':'UTF-8'}</small>{/if}</td>
                            <td>{$invoice.date|default:'—'|escape:'html':'UTF-8'}{if $invoice.datepaid}<small>bezahlt {$invoice.datepaid|escape:'html':'UTF-8'}</small>{/if}</td>
                            <td class="text-right sd-mono">{$invoice.gross_formatted|default:$invoice.total|default:'—'|escape:'html':'UTF-8'}
                                {if $invoice.credit_formatted && $invoice.credit_formatted != '0,00'}<small>Guthaben {$invoice.credit_formatted|escape:'html':'UTF-8'} · Zahlbetrag {$invoice.payable_formatted|escape:'html':'UTF-8'}</small>{/if}
                            </td>
                            <td>{$invoice.status|default:'—'|escape:'html':'UTF-8'}</td>
                            <td>
                                {if $invoice.mapped}
                                    {include file="partials/status_badge.tpl" status="mapped"}
                                    {if $invoice.sevdesk_id}<small class="sd-mono">sevdesk {$invoice.sevdesk_id|escape:'html':'UTF-8'}</small>{/if}
                                {elseif !$canExport}
                                    {include file="partials/status_badge.tpl" status="skipped"}
                                    {if $invoice.reason}<small>{$invoice.reason|escape:'html':'UTF-8'}</small>{/if}
                                    {if $invoice.help_url}<small><a href="{$invoice.help_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener">sevdesk-Einschränkung öffnen</a></small>{/if}
                                {else}
                                    {include file="partials/status_badge.tpl" status="pending"}
                                    <small>Ziel {$invoice.document_type|default:'—'|escape:'html':'UTF-8'} · Hoheit {$invoice.document_authority|default:'—'|escape:'html':'UTF-8'} · Rule {$invoice.tax_rule|escape:'html':'UTF-8'}{if $invoice.document_type === 'invoice'} · kein frei gewähltes accountDatev{else} · Konto {$invoice.account_datev|escape:'html':'UTF-8'}{/if}</small>
                                    <small>{$invoice.delivery_state|default:'—'|escape:'html':'UTF-8'}</small>
                                {/if}
                            </td>
                        </tr>
                    {/foreach}
                    </tbody>
                </table>
            </div>
            <div class="panel-footer text-right">
                <button type="submit" name="import" value="1" class="btn btn-primary" data-requires-selection data-confirm="Ausgewählte Rechnungen jetzt mailfrei als Altbestandsjob einreihen? Bereits vorhandene vollständige Zuordnungen werden weiterhin übersprungen.">
                    <i class="fas fa-play" aria-hidden="true"></i> Mailfreien Altbestandsjob anlegen
                </button>
            </div>
        </div>
    {elseif $filters.submitted || $smarty.post}
        {include file="partials/empty_state.tpl" emptyIcon="fa-search" emptyTitle="Keine passenden Rechnungen" emptyText="Im gewählten Zeitraum wurden keine Rechnungen gefunden. Prüfen Sie den Zeitraum und die Exportregeln."}
    {/if}
</form>

{include file="partials/layout_bottom.tpl"}
