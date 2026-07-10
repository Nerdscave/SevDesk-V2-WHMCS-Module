{include file="partials/layout_top.tpl" pageTitle="Buchungsassistent" pageDescription="Zahlungen anhand von Transaktionsreferenz und Betrag prüfen, bevor sie gebucht werden."}

<div class="sd-inline-note">
    <i class="fas fa-shield-alt" aria-hidden="true"></i>
    <span>Der Assistent bucht nie allein aufgrund eines ähnlichen Betrags. Eindeutige Referenz, Betrag, Währung und Zielbeleg müssen zusammenpassen.</span>
</div>
<form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=bookingAssistant" class="sd-form" data-loading-form>
    <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">

    {if $job}
        <div class="sd-job-created" role="status">
            <span class="sd-panel-icon" aria-hidden="true"><i class="fas fa-money-check-alt"></i></span>
            <div><strong>Buchungsjob #{$job.id|escape:'html':'UTF-8'} wurde angelegt.</strong><p>Die ausgewählten Zahlungen werden nachvollziehbar im Hintergrund verarbeitet.</p></div>
            <a class="btn btn-primary" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobDetail&amp;id={$job.id|escape:'url'}">Fortschritt öffnen</a>
        </div>
    {/if}

    <section class="sd-filter-bar" aria-labelledby="sd-booking-filter-title">
        <div class="sd-filter-intro">
            <p class="sd-kicker">Zahlungsabgleich</p>
            <h2 id="sd-booking-filter-title">Kandidaten suchen</h2>
            <p>Die Vorschau führt noch keine Buchung aus.</p>
        </div>
        <div class="sd-filter-fields">
            <div class="sd-field">
                <label for="booking-date-start">Transaktion von</label>
                <input type="date" id="booking-date-start" name="date_start" class="form-control" value="{$filters.date_start|default:$smarty.post.date_start|escape:'html':'UTF-8'}" required>
            </div>
            <div class="sd-field">
                <label for="booking-date-end">Transaktion bis</label>
                <input type="date" id="booking-date-end" name="date_end" class="form-control" value="{$filters.date_end|default:$smarty.post.date_end|escape:'html':'UTF-8'}" required>
            </div>
            <div class="sd-filter-submit"><button type="submit" name="preview" value="1" class="btn btn-default"><i class="fas fa-search" aria-hidden="true"></i> Vorschau erstellen</button></div>
        </div>
    </section>

    {if $candidates|@count}
        <section class="sd-section" aria-labelledby="sd-candidates-title">
            <div class="sd-section-heading">
                <div><p class="sd-kicker">Prüfschritt</p><h2 id="sd-candidates-title">Gefundene Zuordnungen</h2><p>{$paymentTotal|default:0|escape:'html':'UTF-8'} positive WHMCS-Zahlungen im Zeitraum. Auswahl und Bestätigung gelten für die aktuell angezeigte Seite.</p></div>
                <div class="sd-selection-tools"><span data-selection-count>0 ausgewählt</span><button type="button" class="btn btn-default btn-sm" data-select-all>Alle eindeutigen wählen</button><button type="button" class="btn btn-default btn-sm" data-select-none>Auswahl leeren</button></div>
            </div>

            <div class="sd-table-wrap">
                <table class="table sd-table sd-table--selectable">
                    <thead>
                    <tr>
                        <th scope="col" class="sd-checkbox-cell"><span class="sr-only">Auswahl</span></th>
                        <th scope="col">WHMCS-Rechnung</th>
                        <th scope="col">Transaktion</th>
                        <th scope="col" class="sd-align-right">Betrag</th>
                        <th scope="col">sevdesk-Kandidat</th>
                        <th scope="col">Bewertung</th>
                    </tr>
                    </thead>
                    <tbody>
                    {foreach from=$candidates item=candidate}
                        {assign var="bookable" value=$candidate.bookable|default:false}
                        <tr{if !$bookable} class="is-muted"{/if}>
                            <td class="sd-checkbox-cell"><input type="checkbox" name="candidate_ids[]" value="{$candidate.id|escape:'html':'UTF-8'}" data-export-checkbox aria-label="Zahlungszuordnung für Rechnung {$candidate.invoicenum|default:$candidate.invoice_id|escape:'html':'UTF-8'} auswählen"{if !$bookable} disabled{else} checked{/if}></td>
                            <td><a href="invoices.php?action=edit&amp;id={$candidate.invoice_id|escape:'url'}" target="_blank" rel="noopener">{$candidate.invoicenum|default:'Rechnung'|escape:'html':'UTF-8'}</a><small class="sd-mono">ID {$candidate.invoice_id|escape:'html':'UTF-8'}</small></td>
                            <td><span class="sd-mono">{$candidate.transaction_id|default:'—'|escape:'html':'UTF-8'}</span><small>{$candidate.gateway|default:'—'|escape:'html':'UTF-8'}</small></td>
                            <td class="sd-align-right sd-mono">{$candidate.amount_formatted|default:$candidate.amount|escape:'html':'UTF-8'}</td>
                            <td>{if $candidate.sevdesk_transaction_id}<span class="sd-mono">{$candidate.sevdesk_transaction_id|escape:'html':'UTF-8'}</span><small>Beleg {$candidate.sevdesk_id|default:'—'|escape:'html':'UTF-8'}</small>{else}<span class="sd-muted">Kein Kandidat</span>{/if}</td>
                            <td>
                                {include file="partials/status_badge.tpl" status=$candidate.status|default:'warning'}
                                <small>{$candidate.message|default:'Manuelle Prüfung erforderlich'|escape:'html':'UTF-8'}</small>
                                {if $candidate.reason}<small class="sd-mono">{$candidate.reason|escape:'html':'UTF-8'}</small>{/if}
                            </td>
                        </tr>
                    {/foreach}
                    </tbody>
                </table>
            </div>

            {include file="partials/pagination.tpl"}

            <div class="sd-submit-bar">
                <div><strong>Auswahl verbindlich buchen</strong><p>Dieser Schritt verändert den Buchungsstatus in sevdesk und lässt sich nicht in jedem Fall automatisch zurücknehmen.</p></div>
                <button type="submit" name="import" value="1" class="btn btn-primary" data-requires-selection data-confirm="Ausgewählte Zahlungen jetzt verbindlich in sevdesk buchen? Bitte prüfen Sie Referenzen und Beträge vor der Bestätigung."><i class="fas fa-check" aria-hidden="true"></i> Auswahl buchen</button>
            </div>
        </section>
    {elseif ($filters.submitted || $smarty.post) && !$job}
        {include file="partials/empty_state.tpl" emptyIcon="fa-money-check-alt" emptyTitle="Keine Zahlungskandidaten" emptyText="Für den gewählten Zeitraum wurden keine eindeutigen oder prüfbaren Treffer gefunden."}
    {/if}
</form>

{include file="partials/layout_bottom.tpl"}
