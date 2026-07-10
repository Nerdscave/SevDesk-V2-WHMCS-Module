{include file="partials/layout_top.tpl" pageTitle="Sammelexport" pageDescription="Rechnungen erst eingrenzen und prüfen, dann als fortsetzbaren Job übergeben."}

<form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=massImport" class="sd-form" data-loading-form>
    <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
    <input type="hidden" name="source" value="whmcs">

    {if $job}
        <div class="sd-job-created" role="status">
            <span class="sd-panel-icon" aria-hidden="true"><i class="fas fa-tasks"></i></span>
            <div><strong>Job #{$job.id|escape:'html':'UTF-8'} wurde angelegt.</strong><p>Die Verarbeitung läuft unabhängig von dieser Browserseite weiter.</p></div>
            <a class="btn btn-primary" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobDetail&amp;id={$job.id|escape:'url'}">Fortschritt öffnen</a>
        </div>
    {/if}

    <section class="sd-filter-bar" aria-labelledby="sd-filter-heading">
        <div class="sd-filter-intro">
            <p class="sd-kicker">Schritt 1</p>
            <h2 id="sd-filter-heading">Zeitraum festlegen</h2>
            <p>Die Suche verändert noch keine Daten in sevdesk.</p>
        </div>
        <div class="sd-filter-fields">
            <div class="sd-field">
                <label for="date-start">Rechnungsdatum von</label>
                <input type="date" id="date-start" name="date_start" class="form-control" value="{$filters.date_start|default:$smarty.post.date_start|escape:'html':'UTF-8'}" required>
            </div>
            <div class="sd-field">
                <label for="date-end">Rechnungsdatum bis</label>
                <input type="date" id="date-end" name="date_end" class="form-control" value="{$filters.date_end|default:$smarty.post.date_end|escape:'html':'UTF-8'}" required>
            </div>
            <div class="sd-filter-submit">
                <button type="submit" name="search" value="1" class="btn btn-default">
                    <i class="fas fa-search" aria-hidden="true"></i> Rechnungen suchen
                </button>
            </div>
        </div>
    </section>

    {if $invoices|@count}
        <section class="sd-section" aria-labelledby="sd-selection-heading">
            <div class="sd-section-heading">
                <div>
                    <p class="sd-kicker">Schritt 2</p>
                    <h2 id="sd-selection-heading">Exportumfang prüfen</h2>
                    <p>{$invoices|@count|escape:'html':'UTF-8'} Rechnungen gefunden. Bereits zugeordnete oder nicht zulässige Einträge werden nicht erneut exportiert.</p>
                </div>
                <div class="sd-selection-tools">
                    <span data-selection-count>0 ausgewählt</span>
                    <button type="button" class="btn btn-default btn-sm" data-select-all>Alle zulässigen wählen</button>
                    <button type="button" class="btn btn-default btn-sm" data-select-none>Auswahl leeren</button>
                </div>
            </div>

            <div class="sd-table-wrap">
                <table class="table sd-table sd-table--selectable">
                    <thead>
                    <tr>
                        <th scope="col" class="sd-checkbox-cell"><span class="sr-only">Auswahl</span></th>
                        <th scope="col">Rechnung</th>
                        <th scope="col">Kunde</th>
                        <th scope="col">Datum</th>
                        <th scope="col" class="sd-align-right">Betrag</th>
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
                            <td class="sd-align-right sd-mono">{$invoice.gross_formatted|default:$invoice.total|default:'—'|escape:'html':'UTF-8'}
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
                                    <small>Profil {$invoice.tax_profile|escape:'html':'UTF-8'} · Rule {$invoice.tax_rule|escape:'html':'UTF-8'} · Konto {$invoice.account_datev|escape:'html':'UTF-8'}</small>
                                {/if}
                            </td>
                        </tr>
                    {/foreach}
                    </tbody>
                </table>
            </div>

            <div class="sd-submit-bar">
                <div>
                    <strong>Ein Job, einzelne Ergebnisse</strong>
                    <p>Fehler werden pro Rechnung gespeichert. Erfolgreiche Einträge laufen weiter.</p>
                </div>
                <button type="submit" name="import" value="1" class="btn btn-primary" data-requires-selection data-confirm="Ausgewählte Rechnungen jetzt als Exportjob einreihen? Bereits vorhandene vollständige Zuordnungen werden weiterhin übersprungen.">
                    <i class="fas fa-play" aria-hidden="true"></i> Ausgewählte Rechnungen einreihen
                </button>
            </div>
        </section>
    {elseif $filters.submitted || $smarty.post}
        {include file="partials/empty_state.tpl" emptyIcon="fa-search" emptyTitle="Keine passenden Rechnungen" emptyText="Im gewählten Zeitraum wurden keine Rechnungen gefunden. Prüfen Sie den Zeitraum und die Exportregeln."}
    {else}
        <section class="sd-preflight" aria-label="Hinweise zum Sammelexport">
            <div><i class="fas fa-stopwatch" aria-hidden="true"></i><strong>Kurze Arbeitsschritte</strong><span>Der Runner arbeitet in begrenzten Batches und umgeht so Proxy-Timeouts.</span></div>
            <div><i class="fas fa-redo-alt" aria-hidden="true"></i><strong>Fortsetzbar</strong><span>Offene Einträge bleiben gespeichert und können später weiterlaufen.</span></div>
            <div><i class="fas fa-list-ul" aria-hidden="true"></i><strong>Einzeln nachvollziehbar</strong><span>Jede Rechnung erhält Status, Versuchszahl und eine verständliche Meldung.</span></div>
        </section>
    {/if}
</form>

{include file="partials/layout_bottom.tpl"}
