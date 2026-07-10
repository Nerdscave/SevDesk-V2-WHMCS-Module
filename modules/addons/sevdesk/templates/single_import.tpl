{include file="partials/layout_top.tpl" pageTitle="Einzelexport" pageDescription="Eine WHMCS-Rechnung gezielt prüfen und als eigenen Job übertragen."}

<div class="sd-split-view">
    <section class="sd-panel sd-panel--form" aria-labelledby="sd-single-export-title">
        <div class="sd-panel-heading">
            <div>
                <p class="sd-kicker">Gezielter Export</p>
                <h2 id="sd-single-export-title">Rechnung auswählen</h2>
            </div>
            <span class="sd-panel-icon" aria-hidden="true"><i class="fas fa-file-invoice"></i></span>
        </div>

        <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=singleImport" data-loading-form>
            <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
            <div class="sd-field">
                <label for="invoice-id">Interne WHMCS-Rechnungs-ID</label>
                <input type="number" id="invoice-id" name="invoiceid" class="form-control input-lg" min="1" step="1" inputmode="numeric" value="{$filters.invoiceid|default:$smarty.post.invoiceid|escape:'html':'UTF-8'}" placeholder="Interne Rechnungs-ID" required aria-describedby="invoice-id-help">
                <small id="invoice-id-help" class="sd-help">Gemeint ist die numerische ID aus der WHMCS-URL, nicht die sichtbare Rechnungsnummer.</small>
            </div>
            <div class="sd-action-row">
                <button type="submit" name="preflight" value="1" class="btn btn-primary">
                    <i class="fas fa-search" aria-hidden="true"></i> Vorprüfung anzeigen
                </button>
                <a class="btn btn-default" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobs">Jobverlauf ansehen</a>
            </div>
        </form>
    </section>

    <aside class="sd-guidance" aria-labelledby="sd-single-guidance-title">
        <h2 id="sd-single-guidance-title">Was anschließend passiert</h2>
        <ol class="sd-timeline">
            <li><span>1</span><div><strong>Vorbedingungen</strong><p>Status, Stichtag und bestehende Zuordnung werden geprüft.</p></div></li>
            <li><span>2</span><div><strong>Kontakt und Steuerfall</strong><p>Kontakt-ID, Land, Kundentyp, Konto und TaxRule werden validiert.</p></div></li>
            <li><span>3</span><div><strong>Nachvollziehbarer Export</strong><p>PDF und Belegdaten werden übertragen; das Ergebnis bleibt im Job sichtbar.</p></div></li>
        </ol>
        <div class="sd-inline-note sd-inline-note--warning">
            <i class="fas fa-info-circle" aria-hidden="true"></i>
            <span>Bei unklarem API-Ausgang wird nicht automatisch ein zweiter Beleg erzeugt. Der Fall landet zur Prüfung unter „Klärfälle“.</span>
        </div>
    </aside>
</div>

{if $preflight}
    <section class="sd-section" aria-labelledby="sd-single-preflight-title">
        <div class="sd-section-heading">
            <div><p class="sd-kicker">Read-only Vorprüfung</p><h2 id="sd-single-preflight-title">Rechnung {$preflight.invoicenum|default:$preflight.id|escape:'html':'UTF-8'}</h2></div>
            {if $preflight.exportable}{include file="partials/status_badge.tpl" status="pending"}{else}{include file="partials/status_badge.tpl" status="skipped"}{/if}
        </div>
        <dl class="sd-meta-list sd-meta-list--inline">
            <div><dt>Brutto</dt><dd>{$preflight.gross_formatted|escape:'html':'UTF-8'}</dd></div>
            <div><dt>Guthaben</dt><dd>{$preflight.credit_formatted|escape:'html':'UTF-8'}</dd></div>
            <div><dt>Zahlbetrag</dt><dd>{$preflight.payable_formatted|escape:'html':'UTF-8'}</dd></div>
            <div><dt>Steuerprofil</dt><dd>{$preflight.tax_profile|default:'—'|escape:'html':'UTF-8'}</dd></div>
            <div><dt>TaxRule / Konto</dt><dd>{$preflight.tax_rule|default:'—'|escape:'html':'UTF-8'} / {$preflight.account_datev|default:'—'|escape:'html':'UTF-8'}</dd></div>
        </dl>
        {if $preflight.exportable}
            <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=singleImport" data-loading-form>
                <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}"><input type="hidden" name="invoiceid" value="{$preflight.id|escape:'html':'UTF-8'}">
                <button type="submit" name="confirm_export" value="1" class="btn btn-primary" data-confirm="Diesen vorgeprüften Beleg als Job einreihen?"><i class="fas fa-play" aria-hidden="true"></i> Exportjob verbindlich einreihen</button>
            </form>
        {else}
            <p class="sd-error-message">{$preflight.reason|escape:'html':'UTF-8'}</p>
            {if $preflight.reason_code === 'credit_applied_requires_review'}
                <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=singleImport" data-loading-form>
                    <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}"><input type="hidden" name="invoiceid" value="{$preflight.id|escape:'html':'UTF-8'}">
                    <label class="sd-check-control" for="credit-treatment-confirmed">
                        <input type="checkbox" id="credit-treatment-confirmed" name="credit_treatment_confirmed" value="1" required>
                        <span>Ich bestätige für diesen Einzelfall: Der Voucher wird über den vollen Rechnungsbruttobetrag erzeugt. Das eingesetzte WHMCS-Guthaben wird nicht proportional vom Umsatz gekürzt und muss als Zahlung separat geklärt werden.</span>
                    </label>
                    <button type="submit" name="confirm_credit_export" value="1" class="btn btn-primary" data-confirm="Vollen Rechnungsbruttobetrag exportieren und das Guthaben separat klären?"><i class="fas fa-play" aria-hidden="true"></i> Behandlung bestätigen und Job einreihen</button>
                </form>
            {/if}
        {/if}
    </section>
{/if}

{if $job}
    <section class="sd-section" aria-labelledby="sd-created-job-title">
        <div class="sd-section-heading">
            <div>
                <p class="sd-kicker">Angelegt</p>
                <h2 id="sd-created-job-title">Exportjob #{$job.id|escape:'html':'UTF-8'}</h2>
            </div>
            {include file="partials/status_badge.tpl" status=$job.status}
        </div>
        <div class="sd-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{$job.progress_percent|default:0|escape:'html':'UTF-8'}">
            <span style="width: {$job.progress_percent|default:0|escape:'html':'UTF-8'}%"></span>
        </div>
        <a class="btn btn-default btn-sm" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobDetail&amp;id={$job.id|escape:'url'}">Jobdetails öffnen</a>
    </section>
{/if}

{include file="partials/layout_bottom.tpl"}
