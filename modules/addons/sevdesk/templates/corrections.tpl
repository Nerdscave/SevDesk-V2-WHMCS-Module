{include file="partials/layout_top.tpl" pageTitle="Klärfälle"}

{if $createdJob}
    <div class="alert alert-success" role="status">
        <a class="btn btn-primary btn-sm pull-right" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobDetail&amp;id={$createdJob.id|escape:'url'}">Fortschritt öffnen</a>
        Korrekturjob <strong>#{$createdJob.id|escape:'html':'UTF-8'}</strong> wurde angelegt. Der negative Korrektur-Voucher wird im Hintergrund verarbeitet.
    </div>
{/if}

<div class="panel panel-default">
    <div class="panel-heading"><h3 class="panel-title">Negativen Korrektur-Voucher anlegen</h3></div>
    <div class="panel-body">
        <div class="alert alert-warning" role="note" aria-label="Wirkung der Korrektur">
            Erzeugt einen separaten negativen Korrektur-Voucher für eine bereits erstattete WHMCS-Zahlung – keine Gutschrift, keine Festschreibung, kein Löschen in sevdesk.
        </div>

        <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=corrections" data-loading-form>
            <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="control-label" for="correction-invoice-id">WHMCS-Rechnungs-ID</label>
                        <input type="number" id="correction-invoice-id" name="correction_invoice_id" class="form-control" min="1" step="1" inputmode="numeric" value="{$smarty.post.correction_invoice_id|escape:'html':'UTF-8'}" aria-describedby="correction-invoice-help" data-correction-invoice required>
                        <small id="correction-invoice-help" class="help-block">Interne ID aus <code>tblinvoices.id</code>, nicht die sichtbare Rechnungsnummer.</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="control-label" for="correction-refund-account-id">Erstattete WHMCS-Zahlung</label>
                        {if $refundTransactions|@count}
                            <select id="correction-refund-account-id" name="refund_account_id" class="form-control" aria-describedby="correction-refund-help" data-refund-transaction required>
                                <option value="">Erstattung auswählen</option>
                                {foreach from=$refundTransactions item=transaction}
                                    <option value="{$transaction.id|escape:'html':'UTF-8'}" data-invoice-id="{$transaction.invoice_id|escape:'html':'UTF-8'}"{if $smarty.post.refund_account_id == $transaction.id} selected{/if}>ID {$transaction.id|escape:'html':'UTF-8'} · Rechnung {$transaction.invoicenum|default:$transaction.invoice_id|escape:'html':'UTF-8'} · {$transaction.date|default:'ohne Datum'|escape:'html':'UTF-8'} · {$transaction.amount|default:'Betrag unbekannt'|escape:'html':'UTF-8'}{if $transaction.transid_short} · Ref. {$transaction.transid_short|escape:'html':'UTF-8'}{/if}</option>
                                {/foreach}
                            </select>
                            <small id="correction-refund-help" class="help-block">Nur mit einer ursprünglichen WHMCS-Zahlung verknüpfte Erstattungen werden angeboten. Chargebacks und sonstige negative Buchungen bleiben ausgeschlossen.</small>
                        {else}
                            <input type="number" id="correction-refund-account-id" name="refund_account_id" class="form-control" min="1" step="1" inputmode="numeric" value="{$smarty.post.refund_account_id|escape:'html':'UTF-8'}" aria-describedby="correction-refund-help" required>
                            <small id="correction-refund-help" class="help-block">Interne ID aus <code>tblaccounts.id</code>, nicht Gateway-Referenz oder <code>transid</code>. Die Zahlung muss zur Rechnung gehören und einen Erstattungsbetrag enthalten.</small>
                        {/if}
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label" for="correction-positions">Abweichende Korrekturpositionen <span class="text-muted">(optional)</span></label>
                <textarea id="correction-positions" name="correction_positions_json" class="form-control sd-code-input" rows="5" spellcheck="false" aria-describedby="correction-positions-help" placeholder="&#91;&#123;&quot;description&quot;:&quot;...&quot;,&quot;amount&quot;:&quot;10.00&quot;,&quot;taxRate&quot;:&quot;19&quot;,&quot;net&quot;:false&#125;&#93;">{$smarty.post.correction_positions_json|escape:'html':'UTF-8'}</textarea>
                <small id="correction-positions-help" class="help-block">JSON-Liste mit 1 bis 50 Positionen. Beträge positiv eingeben; das Modul erzeugt daraus die negativen Positionen. Ohne Angabe muss die Ursprungsrechnung genau einen Steuersatz haben.</small>
            </div>

            <div class="checkbox">
                <label for="correction-confirmed">
                    <input type="checkbox" id="correction-confirmed" name="correction_confirmed" value="1" required>
                    Ich habe Rechnung, Erstattung und gegebenenfalls die Steuerpositionen geprüft. Ich bestätige ausdrücklich, dass für diesen Einzelfall ein negativer Korrektur-Voucher angelegt werden soll.
                </label>
            </div>

            <div class="checkbox">
                <label for="correction-refund-confirmed">
                    <input type="checkbox" id="correction-refund-confirmed" name="correction_refund_confirmed" value="1" required>
                    Ich bestätige, dass dies eine echte Kundenerstattung ist, keine Rücklastschrift, kein Chargeback und keine sonstige negative Kontobewegung.
                </label>
            </div>

            <button type="submit" name="create_correction" value="1" class="btn btn-primary" data-confirm="Negativen Korrektur-Voucher für diesen Einzelfall als Job anlegen? Rechnung, Erstattung und Steuerpositionen müssen zuvor geprüft sein.">
                <i class="fas fa-plus" aria-hidden="true"></i> Korrekturjob anlegen
            </button>
        </form>
    </div>
</div>

<div class="row sd-stat-row" aria-label="Klärfälle nach Art">
    <div class="col-sm-3">
        <div class="panel panel-default">
            <div class="panel-body"><span class="sd-stat-value">{$stats.retryable|default:0|escape:'html':'UTF-8'}</span><span class="text-muted small">Wiederholbar</span></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="panel panel-default">
            <div class="panel-body"><span class="sd-stat-value">{$stats.failed|default:0|escape:'html':'UTF-8'}</span><span class="text-muted small">Dauerhaft fehlgeschlagen</span></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="panel panel-default">
            <div class="panel-body"><span class="sd-stat-value">{$stats.ambiguous|default:0|escape:'html':'UTF-8'}</span><span class="text-muted small">Unklarer API-Ausgang</span></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="panel panel-default">
            <div class="panel-body"><span class="sd-stat-value">{$stats.incomplete|default:0|escape:'html':'UTF-8'}</span><span class="text-muted small">Unvollständige Altzuordnung</span></div>
        </div>
    </div>
</div>

<div class="well well-sm">
    <form method="get" action="addonmodules.php" class="form-inline sd-toolbar" aria-label="Klärfälle filtern">
        <input type="hidden" name="module" value="sevdesk">
        <input type="hidden" name="a" value="corrections">
        {if $filters.job_id}<input type="hidden" name="job_id" value="{$filters.job_id|escape:'html':'UTF-8'}">{/if}
        <div class="form-group">
            <label for="correction-status">Art</label>
            <select id="correction-status" name="status" class="form-control input-sm">
                <option value="">Alle Klärfälle</option>
                <option value="permanent_failed"{if $filters.status === 'permanent_failed'} selected{/if}>Nach Korrektur wiederholbar</option>
                <option value="ambiguous"{if $filters.status === 'ambiguous'} selected{/if}>Unklar</option>
            </select>
        </div>
        <div class="form-group">
            <label for="correction-query">Suche</label>
            <input type="search" id="correction-query" name="q" class="form-control input-sm" value="{$filters.q|escape:'html':'UTF-8'}" placeholder="Rechnungsnummer oder Fehlermeldung">
        </div>
        <button type="submit" class="btn btn-default btn-sm">Filtern</button>
    </form>
</div>

{if $items|@count}
    {foreach from=$items item=item}
        <div class="panel panel-default">
            <div class="panel-heading clearfix">
                <span class="pull-right">{include file="partials/status_badge.tpl" status=$item.status}</span>
                <h3 class="panel-title">
                    <a href="invoices.php?action=edit&amp;id={$item.invoice_id|escape:'url'}" target="_blank" rel="noopener">{$item.invoicenum|default:'WHMCS-Rechnung'|escape:'html':'UTF-8'}</a>
                    <span class="sd-mono">ID {$item.invoice_id|escape:'html':'UTF-8'}</span>
                </h3>
            </div>
            <div class="panel-body">
                <p class="text-danger">{$item.message|default:'Für diesen Eintrag liegt keine nähere Fehlermeldung vor.'|escape:'html':'UTF-8'}</p>
                {if $item.error_code === 'unsupported_oss'}<p><a href="https://api.sevdesk.de/" target="_blank" rel="noopener">sevdesk-Einschränkung für OSS-Voucher öffnen</a></p>{/if}
                <dl class="dl-horizontal">
                    <dt>Fehlercode</dt><dd class="sd-mono">{$item.error_code|default:'—'|escape:'html':'UTF-8'}</dd>
                    <dt>Versuche</dt><dd>{$item.attempts|default:0|escape:'html':'UTF-8'}</dd>
                    <dt>Letzter Versuch</dt><dd>{$item.updated_at|default:'—'|escape:'html':'UTF-8'}</dd>
                    <dt>sevdesk-ID</dt><dd class="sd-mono">{$item.sevdesk_id|default:'—'|escape:'html':'UTF-8'}</dd>
                </dl>
                {if $item.recommendation}<p class="text-muted"><i class="fas fa-lightbulb" aria-hidden="true"></i> {$item.recommendation|escape:'html':'UTF-8'}</p>{/if}
            </div>
            <div class="panel-footer sd-actions">
                {if $item.can_retry}
                    <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=corrections" data-loading-form>
                        <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}"><input type="hidden" name="item_id" value="{$item.id|escape:'html':'UTF-8'}">
                        <button type="submit" name="retry" value="1" class="btn btn-primary btn-sm"><i class="fas fa-redo-alt" aria-hidden="true"></i> Erneut versuchen</button>
                    </form>
                {/if}
                {if $item.can_requeue_current_mode}
                    <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=corrections" data-confirm="Neuen mailfreien Export im aktuell bestätigten Modus anlegen? Der alte Job bleibt unverändert erhalten.">
                        <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}"><input type="hidden" name="item_id" value="{$item.id|escape:'html':'UTF-8'}">
                        <div class="checkbox"><label><input type="checkbox" name="confirm_mail_free_requeue" value="yes" required> Ich habe die Übergangsinventur geprüft. Dieser neue Job darf keine Mail und keine historische E-Rechnung erzeugen.</label></div>
                        <button type="submit" name="requeue_current_mode" value="1" class="btn btn-primary btn-sm"><i class="fas fa-random" aria-hidden="true"></i> Im aktuellen Modus neu einreihen</button>
                    </form>
                {/if}
                {if $item.status === 'ambiguous' || $item.can_reconcile}
                    {if $item.can_confirm_email_retry}
                        <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=corrections" data-confirm="Der frühere Versand kann bereits erfolgt sein. Wirklich eine mögliche Doppelmail riskieren?">
                            <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}"><input type="hidden" name="item_id" value="{$item.id|escape:'html':'UTF-8'}">
                            <div class="checkbox"><label><input type="checkbox" name="confirm_duplicate_delivery_risk" value="yes" required> Ich bestätige ausdrücklich das Risiko eines Doppelversands.</label></div>
                            <button type="submit" name="confirm_email_retry" value="1" class="btn btn-danger btn-sm"><i class="fas fa-envelope" aria-hidden="true"></i> Mail erneut übergeben</button>
                        </form>
                    {else}
                    <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=corrections" data-loading-form>
                        <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}"><input type="hidden" name="item_id" value="{$item.id|escape:'html':'UTF-8'}">
                        <button type="submit" name="reconcile" value="1" class="btn btn-default btn-sm"><i class="fas fa-search" aria-hidden="true"></i> In sevdesk abgleichen</button>
                    </form>
                    {/if}
                {/if}
                {if $item.sevdesk_id}<a class="btn btn-default btn-sm" href="https://my.sevdesk.de/#/ex/detail/id/{$item.sevdesk_id|escape:'url'}" target="_blank" rel="noopener">Beleg öffnen</a>{/if}
                <a class="btn btn-link btn-sm" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobDetail&amp;id={$item.job_id|escape:'url'}">Jobdetails</a>
            </div>
        </div>
    {/foreach}
    {include file="partials/pagination.tpl"}
{else}
    {include file="partials/empty_state.tpl" emptyIcon="fa-check-circle" emptyTitle="Keine offenen Klärfälle" emptyText="Aktuell warten keine fehlgeschlagenen oder unklaren Exporte auf eine Entscheidung."}
{/if}

{include file="partials/layout_bottom.tpl"}
