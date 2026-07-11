{include file="partials/layout_top.tpl" pageTitle="Einzelexport"}

<div class="panel panel-default">
    <div class="panel-heading"><h3 class="panel-title">Rechnung auswählen</h3></div>
    <div class="panel-body">
        <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=singleImport" data-loading-form>
            <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
            <div class="form-group">
                <label class="control-label" for="invoice-id">Interne WHMCS-Rechnungs-ID</label>
                <input type="number" id="invoice-id" name="invoiceid" class="form-control" min="1" step="1" inputmode="numeric" value="{$filters.invoiceid|default:$smarty.post.invoiceid|escape:'html':'UTF-8'}" placeholder="Interne Rechnungs-ID" required aria-describedby="invoice-id-help">
                <small id="invoice-id-help" class="help-block">Gemeint ist die numerische ID aus der WHMCS-URL, nicht die sichtbare Rechnungsnummer.</small>
            </div>
            <button type="submit" name="preflight" value="1" class="btn btn-primary">
                <i class="fas fa-search" aria-hidden="true"></i> Vorprüfung anzeigen
            </button>
            <a class="btn btn-default" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobs">Jobverlauf ansehen</a>
        </form>
    </div>
</div>

<div class="alert alert-info" role="status">
    Bei unklarem API-Ausgang wird kein zweiter Beleg erzeugt; der Fall erscheint unter „Klärfälle“.
</div>

{if $preflight}
    <div class="panel panel-default">
        <div class="panel-heading clearfix">
            <span class="pull-right">{if $preflight.exportable}{include file="partials/status_badge.tpl" status="pending"}{else}{include file="partials/status_badge.tpl" status="skipped"}{/if}</span>
            <h3 class="panel-title">Vorprüfung: Rechnung {$preflight.invoicenum|default:$preflight.id|escape:'html':'UTF-8'}</h3>
        </div>
        <div class="panel-body">
            <dl class="dl-horizontal">
                <dt>Brutto</dt><dd>{$preflight.gross_formatted|escape:'html':'UTF-8'}</dd>
                <dt>Guthaben</dt><dd>{$preflight.credit_formatted|escape:'html':'UTF-8'}</dd>
                <dt>Zahlbetrag</dt><dd>{$preflight.payable_formatted|escape:'html':'UTF-8'}</dd>
                <dt>Steuerprofil</dt><dd>{$preflight.tax_profile|default:'—'|escape:'html':'UTF-8'}</dd>
                <dt>TaxRule / Konto</dt><dd>{$preflight.tax_rule|default:'—'|escape:'html':'UTF-8'} / {$preflight.account_datev|default:'—'|escape:'html':'UTF-8'}</dd>
            </dl>
            {if $preflight.exportable}
                <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=singleImport" data-loading-form>
                    <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}"><input type="hidden" name="invoiceid" value="{$preflight.id|escape:'html':'UTF-8'}">
                    <button type="submit" name="confirm_export" value="1" class="btn btn-primary" data-confirm="Diesen vorgeprüften Beleg als Job einreihen?"><i class="fas fa-play" aria-hidden="true"></i> Exportjob verbindlich einreihen</button>
                </form>
            {else}
                <p class="text-danger">{$preflight.reason|escape:'html':'UTF-8'}</p>
                {if $preflight.reason_code === 'credit_applied_requires_review'}
                    <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=singleImport" data-loading-form>
                        <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}"><input type="hidden" name="invoiceid" value="{$preflight.id|escape:'html':'UTF-8'}">
                        <div class="checkbox">
                            <label for="credit-treatment-confirmed">
                                <input type="checkbox" id="credit-treatment-confirmed" name="credit_treatment_confirmed" value="1" required>
                                Ich bestätige für diesen Einzelfall: Der Voucher wird über den vollen Rechnungsbruttobetrag erzeugt. Das eingesetzte WHMCS-Guthaben wird nicht proportional vom Umsatz gekürzt und muss als Zahlung separat geklärt werden.
                            </label>
                        </div>
                        <button type="submit" name="confirm_credit_export" value="1" class="btn btn-primary" data-confirm="Vollen Rechnungsbruttobetrag exportieren und das Guthaben separat klären?"><i class="fas fa-play" aria-hidden="true"></i> Behandlung bestätigen und Job einreihen</button>
                    </form>
                {/if}
            {/if}
        </div>
    </div>
{/if}

{if $job}
    <div class="alert alert-success" role="status">
        <a class="btn btn-primary btn-sm pull-right" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobDetail&amp;id={$job.id|escape:'url'}">Jobdetails öffnen</a>
        Exportjob <strong>#{$job.id|escape:'html':'UTF-8'}</strong> wurde angelegt. {include file="partials/status_badge.tpl" status=$job.status}
    </div>
{/if}

{include file="partials/layout_bottom.tpl"}
