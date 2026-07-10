{include file="partials/layout_top.tpl" pageTitle="Klärfälle" pageDescription="Fehlgeschlagene und unklare Exporte einzeln beurteilen, ohne Duplikate zu riskieren."}

{if $createdJob}
    <div class="sd-job-created" role="status">
        <span class="sd-panel-icon" aria-hidden="true"><i class="fas fa-file-invoice-dollar"></i></span>
        <div>
            <strong>Korrekturjob #{$createdJob.id|escape:'html':'UTF-8'} wurde angelegt.</strong>
            <p>Der negative Korrektur-Voucher wird nachvollziehbar im Hintergrund verarbeitet.</p>
        </div>
        <a class="btn btn-primary" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobDetail&amp;id={$createdJob.id|escape:'url'}">Fortschritt öffnen</a>
    </div>
{/if}

<section class="sd-correction-create" aria-labelledby="sd-correction-create-title">
    <div class="sd-correction-create-intro">
        <p class="sd-kicker">Sicherer Einzelfall</p>
        <h2 id="sd-correction-create-title">Negativen Korrektur-Voucher anlegen</h2>
        <p>Nur für eine bereits erstattete WHMCS-Zahlung verwenden. Rechnung, Zahlung und Positionen werden vor dem Anlegen erneut geprüft.</p>
        <div class="sd-correction-safety" role="note" aria-label="Wirkung der Korrektur">
            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
            <div>
                <strong>Negativer Korrektur-Voucher, keine Gutschrift/Credit Note, keine Festschreibung</strong>
                <p>Der Vorgang löscht keinen sevdesk-Beleg und schreibt ihn nicht fest. Er erzeugt einen separaten, negativen Erlösbeleg für diesen bestätigten Einzelfall.</p>
            </div>
        </div>
    </div>

    <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=corrections" class="sd-correction-create-form" data-loading-form>
        <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">

        <div class="sd-field-grid">
            <div class="sd-field">
                <label for="correction-invoice-id">WHMCS-Rechnungs-ID</label>
                <input type="number" id="correction-invoice-id" name="correction_invoice_id" class="form-control" min="1" step="1" inputmode="numeric" value="{$smarty.post.correction_invoice_id|escape:'html':'UTF-8'}" aria-describedby="correction-invoice-help" data-correction-invoice required>
                <small id="correction-invoice-help" class="sd-help">Interne ID aus <code>tblinvoices.id</code>, nicht die sichtbare Rechnungsnummer.</small>
            </div>

            <div class="sd-field">
                <label for="correction-refund-account-id">Erstattete WHMCS-Zahlung</label>
                {if $refundTransactions|@count}
                    <select id="correction-refund-account-id" name="refund_account_id" class="form-control" aria-describedby="correction-refund-help" data-refund-transaction required>
                        <option value="">Erstattung auswählen</option>
                        {foreach from=$refundTransactions item=transaction}
                            <option value="{$transaction.id|escape:'html':'UTF-8'}" data-invoice-id="{$transaction.invoice_id|escape:'html':'UTF-8'}"{if $smarty.post.refund_account_id == $transaction.id} selected{/if}>ID {$transaction.id|escape:'html':'UTF-8'} · Rechnung {$transaction.invoicenum|default:$transaction.invoice_id|escape:'html':'UTF-8'} · {$transaction.date|default:'ohne Datum'|escape:'html':'UTF-8'} · {$transaction.amount|default:'Betrag unbekannt'|escape:'html':'UTF-8'}{if $transaction.transid_short} · Ref. {$transaction.transid_short|escape:'html':'UTF-8'}{/if}</option>
                        {/foreach}
                    </select>
                    <small id="correction-refund-help" class="sd-help">Nur mit einer ursprünglichen WHMCS-Zahlung verknüpfte Erstattungen werden angeboten. Chargebacks und sonstige negative Buchungen bleiben ausgeschlossen.</small>
                {else}
                    <input type="number" id="correction-refund-account-id" name="refund_account_id" class="form-control" min="1" step="1" inputmode="numeric" value="{$smarty.post.refund_account_id|escape:'html':'UTF-8'}" aria-describedby="correction-refund-help" required>
                    <small id="correction-refund-help" class="sd-help">Interne ID aus <code>tblaccounts.id</code>, nicht Gateway-Referenz oder <code>transid</code>. Die Zahlung muss zur Rechnung gehören und einen Erstattungsbetrag enthalten.</small>
                {/if}
            </div>
        </div>

        <div class="sd-field">
            <label for="correction-positions">Abweichende Korrekturpositionen <span class="sd-optional">optional</span></label>
            <textarea id="correction-positions" name="correction_positions_json" class="form-control sd-code-input" rows="5" spellcheck="false" aria-describedby="correction-positions-help" placeholder="&#91;&#123;&quot;description&quot;:&quot;...&quot;,&quot;amount&quot;:&quot;10.00&quot;,&quot;taxRate&quot;:&quot;19&quot;,&quot;net&quot;:false&#125;&#93;">{$smarty.post.correction_positions_json|escape:'html':'UTF-8'}</textarea>
            <small id="correction-positions-help" class="sd-help">JSON-Liste mit 1 bis 50 Positionen. Beträge positiv eingeben; das Modul erzeugt daraus die negativen Positionen. Ohne Angabe muss die Ursprungsrechnung genau einen Steuersatz haben.</small>
        </div>

        <label class="sd-check-control sd-correction-confirmation" for="correction-confirmed">
            <input type="checkbox" id="correction-confirmed" name="correction_confirmed" value="1" required>
            <span>Ich habe Rechnung, Erstattung und gegebenenfalls die Steuerpositionen geprüft. Ich bestätige ausdrücklich, dass für diesen Einzelfall ein negativer Korrektur-Voucher angelegt werden soll.</span>
        </label>

        <label class="sd-check-control sd-correction-confirmation" for="correction-refund-confirmed">
            <input type="checkbox" id="correction-refund-confirmed" name="correction_refund_confirmed" value="1" required>
            <span>Ich bestätige, dass dies eine echte Kundenerstattung ist, keine Rücklastschrift, kein Chargeback und keine sonstige negative Kontobewegung.</span>
        </label>

        <div class="sd-correction-create-actions">
            <span><i class="fas fa-lock" aria-hidden="true"></i> Die Bestätigung gilt nur für diesen Auftrag.</span>
            <button type="submit" name="create_correction" value="1" class="btn btn-primary" data-confirm="Negativen Korrektur-Voucher für diesen Einzelfall als Job anlegen? Rechnung, Erstattung und Steuerpositionen müssen zuvor geprüft sein.">
                <i class="fas fa-plus" aria-hidden="true"></i> Korrekturjob anlegen
            </button>
        </div>
    </form>
</section>

<section class="sd-summary-strip sd-summary-strip--compact" aria-label="Klärfälle nach Art">
    <div class="sd-summary-item"><span>Wiederholbar</span><strong>{$stats.retryable|default:0|escape:'html':'UTF-8'}</strong></div>
    <div class="sd-summary-item"><span>Dauerhaft fehlgeschlagen</span><strong>{$stats.failed|default:0|escape:'html':'UTF-8'}</strong></div>
    <div class="sd-summary-item"><span>Unklarer API-Ausgang</span><strong>{$stats.ambiguous|default:0|escape:'html':'UTF-8'}</strong></div>
    <div class="sd-summary-item"><span>Unvollständige Altzuordnung</span><strong>{$stats.incomplete|default:0|escape:'html':'UTF-8'}</strong></div>
</section>

<form method="get" action="addonmodules.php" class="sd-toolbar" aria-label="Klärfälle filtern">
    <input type="hidden" name="module" value="sevdesk">
    <input type="hidden" name="a" value="corrections">
    {if $filters.job_id}<input type="hidden" name="job_id" value="{$filters.job_id|escape:'html':'UTF-8'}">{/if}
    <div class="sd-field sd-field--inline">
        <label for="correction-status">Art</label>
        <select id="correction-status" name="status" class="form-control input-sm">
            <option value="">Alle Klärfälle</option>
            <option value="permanent_failed"{if $filters.status === 'permanent_failed'} selected{/if}>Nach Korrektur wiederholbar</option>
            <option value="ambiguous"{if $filters.status === 'ambiguous'} selected{/if}>Unklar</option>
        </select>
    </div>
    <div class="sd-field sd-field--inline sd-field--grow"><label for="correction-query">Suche</label><input type="search" id="correction-query" name="q" class="form-control input-sm" value="{$filters.q|escape:'html':'UTF-8'}" placeholder="Rechnungsnummer oder Fehlermeldung"></div>
    <button type="submit" class="btn btn-default btn-sm">Filtern</button>
</form>

{if $items|@count}
    <div class="sd-correction-list">
        {foreach from=$items item=item}
            <article class="sd-correction-item">
                <div class="sd-correction-main">
                    <div class="sd-correction-heading">
                        <div>
                            <a href="invoices.php?action=edit&amp;id={$item.invoice_id|escape:'url'}" target="_blank" rel="noopener">{$item.invoicenum|default:'WHMCS-Rechnung'|escape:'html':'UTF-8'}</a>
                            <span class="sd-mono">ID {$item.invoice_id|escape:'html':'UTF-8'}</span>
                        </div>
                        {include file="partials/status_badge.tpl" status=$item.status}
                    </div>
                    <p class="sd-error-message">{$item.message|default:'Für diesen Eintrag liegt keine nähere Fehlermeldung vor.'|escape:'html':'UTF-8'}</p>
                    {if $item.error_code === 'unsupported_oss'}<p><a href="https://api.sevdesk.de/" target="_blank" rel="noopener">sevdesk-Einschränkung für OSS-Voucher öffnen</a></p>{/if}
                    <dl class="sd-meta-list sd-meta-list--inline">
                        <div><dt>Fehlercode</dt><dd class="sd-mono">{$item.error_code|default:'—'|escape:'html':'UTF-8'}</dd></div>
                        <div><dt>Versuche</dt><dd>{$item.attempts|default:0|escape:'html':'UTF-8'}</dd></div>
                        <div><dt>Letzter Versuch</dt><dd>{$item.updated_at|default:'—'|escape:'html':'UTF-8'}</dd></div>
                        <div><dt>sevdesk-ID</dt><dd class="sd-mono">{$item.sevdesk_id|default:'—'|escape:'html':'UTF-8'}</dd></div>
                    </dl>
                    {if $item.recommendation}<p class="sd-recommendation"><i class="fas fa-lightbulb" aria-hidden="true"></i> {$item.recommendation|escape:'html':'UTF-8'}</p>{/if}
                </div>
                <div class="sd-correction-actions">
                    {if $item.can_retry}
                        <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=corrections" data-loading-form>
                            <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}"><input type="hidden" name="item_id" value="{$item.id|escape:'html':'UTF-8'}">
                            <button type="submit" name="retry" value="1" class="btn btn-primary btn-sm"><i class="fas fa-redo-alt" aria-hidden="true"></i> Erneut versuchen</button>
                        </form>
                    {/if}
                    {if $item.status === 'ambiguous' || $item.can_reconcile}
                        <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=corrections" data-loading-form>
                            <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}"><input type="hidden" name="item_id" value="{$item.id|escape:'html':'UTF-8'}">
                            <button type="submit" name="reconcile" value="1" class="btn btn-default btn-sm"><i class="fas fa-search" aria-hidden="true"></i> In sevdesk abgleichen</button>
                        </form>
                    {/if}
                    {if $item.sevdesk_id}<a class="btn btn-default btn-sm" href="https://my.sevdesk.de/#/ex/detail/id/{$item.sevdesk_id|escape:'url'}" target="_blank" rel="noopener">Beleg öffnen</a>{/if}
                    <a class="btn btn-link btn-sm" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobDetail&amp;id={$item.job_id|escape:'url'}">Jobdetails</a>
                </div>
            </article>
        {/foreach}
    </div>
    {include file="partials/pagination.tpl"}
{else}
    {include file="partials/empty_state.tpl" emptyIcon="fa-check-circle" emptyTitle="Keine offenen Klärfälle" emptyText="Aktuell warten keine fehlgeschlagenen oder unklaren Exporte auf eine Entscheidung."}
{/if}

{include file="partials/layout_bottom.tpl"}
