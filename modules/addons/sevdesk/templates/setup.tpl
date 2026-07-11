{include file="partials/layout_top.tpl" pageTitle="Einrichtung"}

<form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=setup" data-loading-form>
    <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
    <input type="hidden" name="save" value="1">

    <div class="panel panel-default">
        <div class="panel-heading"><h3 class="panel-title">sevdesk-Verbindung</h3></div>
        <div class="panel-body">
            <div class="form-group">
                <label class="control-label" for="sevdesk-api-key">API-Token</label>
                <div class="input-group">
                    <input type="password" id="sevdesk-api-key" name="sevdesk_api_key" class="form-control" autocomplete="new-password" value="" placeholder="{$settings.sevdesk_api_key_placeholder|default:'Token unverändert lassen'|escape:'html':'UTF-8'}" aria-describedby="sevdesk-api-key-help">
                    <span class="input-group-btn">
                        <button type="button" class="btn btn-default" data-toggle-password aria-controls="sevdesk-api-key">Anzeigen</button>
                    </span>
                </div>
                <small id="sevdesk-api-key-help" class="help-block">Leer lassen, um den gespeicherten Token beizubehalten. Der Token wird in Protokollen ausgeblendet.</small>
            </div>

            <div class="form-group">
                <label class="control-label" for="custom-field-id">WHMCS-Kundenfeld für die sevdesk-Kontakt-ID</label>
                {if $customFields|@count}
                    <select id="custom-field-id" name="custom_field_id" class="form-control" required aria-describedby="custom-field-id-help">
                        <option value="">Bitte wählen</option>
                        {foreach from=$customFields item=field key=fieldKey}
                            {assign var="fieldId" value=$field.id|default:$fieldKey}
                            <option value="{$fieldId|escape:'html':'UTF-8'}"{if $settings.custom_field_id == $fieldId} selected{/if}>{$field.label|default:$field.fieldname|default:$field|escape:'html':'UTF-8'} (ID {$fieldId|escape:'html':'UTF-8'})</option>
                        {/foreach}
                    </select>
                {else}
                    <input type="number" id="custom-field-id" name="custom_field_id" class="form-control" min="1" step="1" value="{$settings.custom_field_id|escape:'html':'UTF-8'}" required aria-describedby="custom-field-id-help">
                {/if}
                <small id="custom-field-id-help" class="help-block">Ein späterer Wechsel kann zu doppelten Kontakten führen. Vor dem Speichern werden bestehende Werte nicht verschoben.</small>
            </div>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading"><h3 class="panel-title">Exportregeln</h3></div>
        <div class="panel-body">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="control-label" for="import-after">Rechnungen berücksichtigen ab</label>
                        <input type="date" id="import-after" name="import_after" class="form-control" value="{$settings.import_after_iso|default:$settings.import_after|escape:'html':'UTF-8'}" required aria-describedby="import-after-help">
                        <small id="import-after-help" class="help-block">Ältere Rechnungen werden als übersprungen protokolliert.</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="checkbox">
                        <label for="import-only-paid">
                            <input type="checkbox" id="import-only-paid" name="import_only_paid" value="on"{if $settings.import_only_paid === 'on' || $settings.import_only_paid === true || $settings.import_only_paid == 1} checked{/if}>
                            Nur vollständig bezahlte Rechnungen exportieren
                        </label>
                    </div>
                </div>
            </div>

            <div class="checkbox">
                <label for="sync-enabled">
                    <input type="checkbox" id="sync-enabled" name="sync_enabled" value="on"{if $settings.sync_enabled === 'on' || $settings.sync_enabled === true || $settings.sync_enabled == 1} checked{/if}>
                    Neue Rechnungen automatisch als Exportjob einreihen
                </label>
                <small class="help-block"><strong>Standardmäßig ausgeschaltet.</strong> Erst aktivieren, wenn API, Konten und Steuerprofile im Systemcheck bestätigt sind.</small>
            </div>

            <div class="checkbox">
                <label for="debug-logging">
                    <input type="checkbox" id="debug-logging" name="debug_logging" value="on"{if $settings.debug_logging === 'on' || $settings.debug_logging === true || $settings.debug_logging == 1} checked{/if}>
                    Sanitisiertes Diagnoseprotokoll aktivieren
                </label>
                <small class="help-block">Auch im Diagnosemodus werden Token, PDF-Inhalt und ungekürzte Kundendaten niemals protokolliert.</small>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="panel panel-default">
                        <div class="panel-heading clearfix">
                            <span class="pull-right">
                                {if $settings.eu_b2c_mode === 'domestic_confirmed'}<span class="label label-warning">Prüfung bestätigt</span>{else}<span class="label label-default">Standardmäßig blockiert</span>{/if}
                            </span>
                            <h3 class="panel-title">EU-Privatkunden</h3>
                        </div>
                        <div class="panel-body">
                            <div class="form-group">
                                <label class="control-label" for="eu-b2c-mode">Behandlung auswählen</label>
                                <select id="eu-b2c-mode" name="eu_b2c_mode" class="form-control" data-controls="eu-b2c-confirmation" aria-describedby="eu-b2c-mode-help">
                                    <option value="blocked"{if !$settings.eu_b2c_mode || $settings.eu_b2c_mode === 'blocked'} selected{/if}>Blockieren und als Klärfall markieren</option>
                                    <option value="domestic_confirmed"{if $settings.eu_b2c_mode === 'domestic_confirmed'} selected{/if}>Deutsche Besteuerung ist fachlich bestätigt</option>
                                </select>
                                <small id="eu-b2c-mode-help" class="help-block">OSS-Regeln 18–20 sind für Voucher nicht unterstützt. Das Modul entscheidet nicht selbst, ob deutsche Umsatzsteuer angewendet werden darf.</small>
                            </div>
                            <div id="eu-b2c-confirmation" class="checkbox" data-visible-when="eu-b2c-mode:domestic_confirmed"{if $settings.eu_b2c_mode !== 'domestic_confirmed'} hidden{/if}>
                                <label for="eu-b2c-confirmed">
                                    <input type="checkbox" id="eu-b2c-confirmed" name="eu_b2c_acknowledged" value="1">
                                    <strong>Erneute Bestätigung erforderlich:</strong> Für das aktuelle Geschäftsmodell und den betroffenen Zeitraum wurde die inländische Besteuerung von EU-B2C-Umsätzen fachlich geprüft.
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="panel panel-default">
                        <div class="panel-heading clearfix">
                            <span class="pull-right">
                                {if $settings.smallBusinessOwner === 'on' || $settings.smallBusinessOwner === true || $settings.smallBusinessOwner == 1}<span class="label label-warning">Aktiv</span>{else}<span class="label label-default">Nicht aktiv</span>{/if}
                            </span>
                            <h3 class="panel-title">Kleinunternehmerregelung</h3>
                        </div>
                        <div class="panel-body">
                            <div class="checkbox">
                                <label for="small-business-owner">
                                    <input type="checkbox" id="small-business-owner" name="smallBusinessOwner" value="on"{if $settings.smallBusinessOwner === 'on' || $settings.smallBusinessOwner === true || $settings.smallBusinessOwner == 1} checked{/if}>
                                    Steuer wird nach § 19 UStG nicht erhoben
                                </label>
                                <small class="help-block">Nur aktivieren, wenn die Regel für den gesamten Exportzeitraum gilt. Das zugehörige Rule-11-Profil muss unten zusätzlich bestätigt werden.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading"><h3 class="panel-title">Steuerfälle und Buchungskonten</h3></div>
        <div class="panel-body">
            <div class="alert alert-info" role="note">
                Die Kontenliste stammt aus Ihrem sevdesk-Mandanten; eine Kontoauswahl allein gibt keinen Steuerfall frei.
            </div>

            <article class="panel panel-default sd-tax-profile" aria-labelledby="tax-profile-general-title">
                <div class="panel-heading clearfix">
                    <span class="pull-right"><span class="label label-default">Rule 1</span></span>
                    <h3 class="panel-title" id="tax-profile-general-title">Deutschland</h3>
                </div>
                <div class="panel-body">
                    <p class="text-muted">Steuerpflichtige Inlandsumsätze; der Steuersatz kommt aus der WHMCS-Rechnung und muss vom gewählten Konto unterstützt werden.</p>

                    {assign var="generalAccountId" value=$settings.accountingTypeGeneral|default:''}
                    {assign var="generalAccountFound" value=false}
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                {if $accountOptions|@count}
                                    {foreach from=$accountOptions item=account}
                                        {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                        {if $generalAccountId == $accountId}{assign var="generalAccountFound" value=true}{/if}
                                    {/foreach}
                                    <label class="control-label" for="account-general">sevdesk-Erlöskonto</label>
                                    <select id="account-general" name="accountingTypeGeneral" class="form-control" aria-describedby="account-general-help">
                                        <option value=""{if !$generalAccountId} selected{/if}>Kein Konto gewählt – Profil bleibt blockiert</option>
                                        {foreach from=$accountOptions item=account}
                                            {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                            <option value="{$accountId|escape:'html':'UTF-8'}"{if $generalAccountId == $accountId} selected{/if}>{if $account.accountNumber}{$account.accountNumber|escape:'html':'UTF-8'} — {/if}{$account.name|default:$account.accountName|default:'Unbenanntes Konto'|escape:'html':'UTF-8'} (ID {$accountId|escape:'html':'UTF-8'})</option>
                                        {/foreach}
                                        {if $generalAccountId && !$generalAccountFound}
                                            <option value="{$generalAccountId|escape:'html':'UTF-8'}" selected>Gespeicherte ID {$generalAccountId|escape:'html':'UTF-8'} – nicht in aktueller Guidance</option>
                                        {/if}
                                    </select>
                                {else}
                                    <label class="control-label" for="account-general">sevdesk-AccountDatev-ID</label>
                                    <input type="number" id="account-general" name="accountingTypeGeneral" class="form-control" min="1" step="1" value="{$generalAccountId|escape:'html':'UTF-8'}" aria-describedby="account-general-help">
                                {/if}
                                <small id="account-general-help" class="help-block">Eine nicht mehr angebotene gespeicherte ID wird nicht stillschweigend gelöscht.</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="control-label" for="tax-rule-general">TaxRule-ID</label>
                                <details class="sd-info">
                                    <summary class="sd-info-trigger" aria-label="Mehr Informationen zu Tax Rule 1" title="Mehr Informationen zu Tax Rule 1"><i class="fas fa-info-circle" aria-hidden="true"></i></summary>
                                    <div class="sd-info-popover" role="note"><strong>Rule 1</strong><span>Steuerpflichtige Umsätze; das Konto muss den konkreten WHMCS-Steuersatz erlauben.</span></div>
                                </details>
                                <input type="number" id="tax-rule-general" name="taxRuleGeneral" class="form-control" min="1" step="1" value="{$settings.taxRuleGeneral|default:1|escape:'html':'UTF-8'}" aria-describedby="tax-rule-general-help">
                                <small id="tax-rule-general-help" class="help-block">Voreinstellung: Rule 1 für steuerpflichtige Umsätze.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </article>

            <article class="panel panel-default sd-tax-profile" aria-labelledby="tax-profile-eu-business-title">
                <div class="panel-heading clearfix">
                    <span class="pull-right">
                        {if $settings.eu_b2b_goods_confirmed === 'on' || $settings.eu_b2b_goods_confirmed === true || $settings.eu_b2b_goods_confirmed == 1}<span class="label label-warning">Warenprofil bestätigt</span>{else}<span class="label label-default">Blockiert</span>{/if}
                    </span>
                    <h3 class="panel-title" id="tax-profile-eu-business-title">Innergemeinschaftliche Warenlieferung (EU B2B)</h3>
                </div>
                <div class="panel-body">
                    <p class="text-muted">Nur für Organisationen mit USt-ID, WHMCS-<code>taxexempt</code> und bestätigter Warenlieferung. Hosting, Domains, Lizenzen und andere Dienstleistungen bleiben ausgeschlossen.</p>

                    {assign var="euBusinessAccountId" value=$settings.accountingTypeInterCommunityBusiness|default:''}
                    {assign var="euBusinessAccountFound" value=false}
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                {if $accountOptions|@count}
                                    {foreach from=$accountOptions item=account}
                                        {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                        {if $euBusinessAccountId == $accountId}{assign var="euBusinessAccountFound" value=true}{/if}
                                    {/foreach}
                                    <label class="control-label" for="account-eu-business">sevdesk-Erlöskonto</label>
                                    <select id="account-eu-business" name="accountingTypeInterCommunityBusiness" class="form-control" aria-describedby="account-eu-business-help">
                                        <option value=""{if !$euBusinessAccountId} selected{/if}>Kein Konto gewählt – Profil bleibt blockiert</option>
                                        {foreach from=$accountOptions item=account}
                                            {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                            <option value="{$accountId|escape:'html':'UTF-8'}"{if $euBusinessAccountId == $accountId} selected{/if}>{if $account.accountNumber}{$account.accountNumber|escape:'html':'UTF-8'} — {/if}{$account.name|default:$account.accountName|default:'Unbenanntes Konto'|escape:'html':'UTF-8'} (ID {$accountId|escape:'html':'UTF-8'})</option>
                                        {/foreach}
                                        {if $euBusinessAccountId && !$euBusinessAccountFound}
                                            <option value="{$euBusinessAccountId|escape:'html':'UTF-8'}" selected>Gespeicherte ID {$euBusinessAccountId|escape:'html':'UTF-8'} – nicht in aktueller Guidance</option>
                                        {/if}
                                    </select>
                                {else}
                                    <label class="control-label" for="account-eu-business">sevdesk-AccountDatev-ID</label>
                                    <input type="number" id="account-eu-business" name="accountingTypeInterCommunityBusiness" class="form-control" min="1" step="1" value="{$euBusinessAccountId|escape:'html':'UTF-8'}" aria-describedby="account-eu-business-help">
                                {/if}
                                <small id="account-eu-business-help" class="help-block">Die Kontoauswahl aktiviert Rule 3 nicht automatisch. Fehlen Organisation, USt-ID, <code>taxexempt</code> oder Bestätigung, wird die Rechnung zum Klärfall.</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="control-label" for="tax-rule-eu-business">TaxRule-ID</label>
                                <details class="sd-info">
                                    <summary class="sd-info-trigger" aria-label="Mehr Informationen zu Tax Rule 3" title="Mehr Informationen zu Tax Rule 3"><i class="fas fa-info-circle" aria-hidden="true"></i></summary>
                                    <div class="sd-info-popover" role="note"><strong>Rule 3</strong><span>Nur für bestätigte innergemeinschaftliche Warenlieferungen, nicht für Hosting oder andere Dienstleistungen.</span></div>
                                </details>
                                <input type="number" id="tax-rule-eu-business" name="taxRuleInterCommunityBusiness" class="form-control" min="1" step="1" value="{$settings.taxRuleInterCommunityBusiness|default:3|escape:'html':'UTF-8'}" aria-describedby="tax-rule-eu-business-help">
                                <small id="tax-rule-eu-business-help" class="help-block">Voreinstellung: Rule 3. EU-B2B-Dienstleistungen und Reverse-Charge-Fälle sind damit nicht freigegeben.</small>
                            </div>
                        </div>
                    </div>

                    <div class="checkbox">
                        <label for="confirm-eu-b2b-goods">
                            <input type="checkbox" id="confirm-eu-b2b-goods" name="eu_b2b_goods_confirmed" value="on"{if $settings.eu_b2b_goods_confirmed === 'on' || $settings.eu_b2b_goods_confirmed === true || $settings.eu_b2b_goods_confirmed == 1} checked{/if}>
                            Rule 3 ist für innergemeinschaftliche Warenlieferungen ausdrücklich bestätigt. Hosting, Domains, Lizenzen und andere Dienstleistungen sind von dieser Freigabe ausgeschlossen.
                        </label>
                    </div>
                </div>
            </article>

            <article class="panel panel-default sd-tax-profile" aria-labelledby="tax-profile-eu-consumer-title">
                <div class="panel-heading clearfix">
                    <span class="pull-right">
                        {if $settings.eu_b2c_mode === 'domestic_confirmed'}<span class="label label-warning">Deutsche Steuer bestätigt</span>{else}<span class="label label-default">Blockiert</span>{/if}
                    </span>
                    <h3 class="panel-title" id="tax-profile-eu-consumer-title">EU-Privatkunden (EU B2C)</h3>
                </div>
                <div class="panel-body">
                    <p class="text-muted">Wird nur im oben bestätigten Modus für deutsche Besteuerung verwendet; OSS-pflichtige Umsätze bleiben technisch blockiert.</p>

                    {assign var="euConsumerAccountId" value=$settings.accountingTypeInterCommunityConsumer|default:''}
                    {assign var="euConsumerAccountFound" value=false}
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                {if $accountOptions|@count}
                                    {foreach from=$accountOptions item=account}
                                        {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                        {if $euConsumerAccountId == $accountId}{assign var="euConsumerAccountFound" value=true}{/if}
                                    {/foreach}
                                    <label class="control-label" for="account-eu-consumer">sevdesk-Erlöskonto</label>
                                    <select id="account-eu-consumer" name="accountingTypeInterCommunityConsumer" class="form-control" aria-describedby="account-eu-consumer-help">
                                        <option value=""{if !$euConsumerAccountId} selected{/if}>Kein Konto gewählt – Profil bleibt blockiert</option>
                                        {foreach from=$accountOptions item=account}
                                            {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                            <option value="{$accountId|escape:'html':'UTF-8'}"{if $euConsumerAccountId == $accountId} selected{/if}>{if $account.accountNumber}{$account.accountNumber|escape:'html':'UTF-8'} — {/if}{$account.name|default:$account.accountName|default:'Unbenanntes Konto'|escape:'html':'UTF-8'} (ID {$accountId|escape:'html':'UTF-8'})</option>
                                        {/foreach}
                                        {if $euConsumerAccountId && !$euConsumerAccountFound}
                                            <option value="{$euConsumerAccountId|escape:'html':'UTF-8'}" selected>Gespeicherte ID {$euConsumerAccountId|escape:'html':'UTF-8'} – nicht in aktueller Guidance</option>
                                        {/if}
                                    </select>
                                {else}
                                    <label class="control-label" for="account-eu-consumer">sevdesk-AccountDatev-ID</label>
                                    <input type="number" id="account-eu-consumer" name="accountingTypeInterCommunityConsumer" class="form-control" min="1" step="1" value="{$euConsumerAccountId|escape:'html':'UTF-8'}" aria-describedby="account-eu-consumer-help">
                                {/if}
                                <small id="account-eu-consumer-help" class="help-block">Bei der sicheren Standardeinstellung wird dieses Konto nicht verwendet. Erst die ausdrückliche EU-B2C-Bestätigung oben erlaubt die weitere Prüfung.</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="control-label" for="tax-rule-eu-consumer">TaxRule-ID</label>
                                <details class="sd-info">
                                    <summary class="sd-info-trigger" aria-label="Mehr Informationen zur EU-B2C Tax Rule" title="Mehr Informationen zur EU-B2C Tax Rule"><i class="fas fa-info-circle" aria-hidden="true"></i></summary>
                                    <div class="sd-info-popover" role="note"><strong>EU B2C</strong><span>Rule 1 ist nur nach bestätigter deutscher Besteuerung zulässig. OSS-Rules 18–20 sind für Voucher nicht unterstützt.</span></div>
                                </details>
                                <input type="number" id="tax-rule-eu-consumer" name="taxRuleInterCommunityConsumer" class="form-control" min="1" step="1" value="{$settings.taxRuleInterCommunityConsumer|default:1|escape:'html':'UTF-8'}" aria-describedby="tax-rule-eu-consumer-help">
                                <small id="tax-rule-eu-consumer-help" class="help-block">Voreinstellung: Rule 1. Die nicht unterstützten OSS-Rules 18–20 werden vom Modul nicht ersatzweise verwendet.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </article>

            <article class="panel panel-default sd-tax-profile" aria-labelledby="tax-profile-third-country-title">
                <div class="panel-heading clearfix">
                    <span class="pull-right">
                        {if $settings.third_country_confirmed === 'on' || $settings.third_country_confirmed === true || $settings.third_country_confirmed == 1}<span class="label label-warning">Profil bestätigt</span>{else}<span class="label label-default">Blockiert</span>{/if}
                    </span>
                    <h3 class="panel-title" id="tax-profile-third-country-title">Drittland</h3>
                </div>
                <div class="panel-body">
                    <p class="text-muted">Warenexport und nicht im Inland steuerbare Dienstleistungen sind unterschiedliche Fälle; Konto und TaxRule müssen zum konkreten Leistungsprofil passen.</p>

                    {assign var="thirdCountryAccountId" value=$settings.accountingTypeThirdPartyCountry|default:''}
                    {assign var="thirdCountryAccountFound" value=false}
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                {if $accountOptions|@count}
                                    {foreach from=$accountOptions item=account}
                                        {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                        {if $thirdCountryAccountId == $accountId}{assign var="thirdCountryAccountFound" value=true}{/if}
                                    {/foreach}
                                    <label class="control-label" for="account-third-country">sevdesk-Erlöskonto</label>
                                    <select id="account-third-country" name="accountingTypeThirdPartyCountry" class="form-control" aria-describedby="account-third-country-help">
                                        <option value=""{if !$thirdCountryAccountId} selected{/if}>Kein Konto gewählt – Profil bleibt blockiert</option>
                                        {foreach from=$accountOptions item=account}
                                            {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                            <option value="{$accountId|escape:'html':'UTF-8'}"{if $thirdCountryAccountId == $accountId} selected{/if}>{if $account.accountNumber}{$account.accountNumber|escape:'html':'UTF-8'} — {/if}{$account.name|default:$account.accountName|default:'Unbenanntes Konto'|escape:'html':'UTF-8'} (ID {$accountId|escape:'html':'UTF-8'})</option>
                                        {/foreach}
                                        {if $thirdCountryAccountId && !$thirdCountryAccountFound}
                                            <option value="{$thirdCountryAccountId|escape:'html':'UTF-8'}" selected>Gespeicherte ID {$thirdCountryAccountId|escape:'html':'UTF-8'} – nicht in aktueller Guidance</option>
                                        {/if}
                                    </select>
                                {else}
                                    <label class="control-label" for="account-third-country">sevdesk-AccountDatev-ID</label>
                                    <input type="number" id="account-third-country" name="accountingTypeThirdPartyCountry" class="form-control" min="1" step="1" value="{$thirdCountryAccountId|escape:'html':'UTF-8'}" aria-describedby="account-third-country-help">
                                {/if}
                                <small id="account-third-country-help" class="help-block">Wählen Sie nur ein Konto, das für den tatsächlichen Drittland-Fall fachlich freigegeben wurde.</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="control-label" for="tax-rule-third-country">TaxRule-ID</label>
                                <details class="sd-info">
                                    <summary class="sd-info-trigger" aria-label="Mehr Informationen zu Drittland-Steuerregeln" title="Mehr Informationen zu Drittland-Steuerregeln"><i class="fas fa-info-circle" aria-hidden="true"></i></summary>
                                    <div class="sd-info-popover" role="note"><strong>Drittland</strong><span>Warenexport und Dienstleistungen können unterschiedliche Regeln benötigen. Es gibt deshalb keine pauschale Voreinstellung.</span></div>
                                </details>
                                <input type="number" id="tax-rule-third-country" name="taxRuleThirdPartyCountry" class="form-control" min="1" step="1" value="{$settings.taxRuleThirdPartyCountry|escape:'html':'UTF-8'}" aria-describedby="tax-rule-third-country-help">
                                <small id="tax-rule-third-country-help" class="help-block">Keine pauschale Voreinstellung: Nur die für Ihr bestätigtes Profil freigegebene Rule eintragen.</small>
                            </div>
                        </div>
                    </div>

                    <div class="checkbox">
                        <label for="confirm-third-country">
                            <input type="checkbox" id="confirm-third-country" name="third_country_confirmed" value="on"{if $settings.third_country_confirmed === 'on' || $settings.third_country_confirmed === true || $settings.third_country_confirmed == 1} checked{/if}>
                            Konto und TaxRule für das konkrete Drittland-Leistungsprofil sind fachlich bestätigt.
                        </label>
                    </div>
                </div>
            </article>

            <article class="panel panel-default sd-tax-profile" aria-labelledby="tax-profile-credit-title">
                <div class="panel-heading clearfix">
                    <span class="pull-right">
                        {if $settings.add_funds_confirmed === 'on' || $settings.add_funds_confirmed === true || $settings.add_funds_confirmed == 1}<span class="label label-warning">Profil bestätigt</span>{else}<span class="label label-default">Blockiert</span>{/if}
                    </span>
                    <h3 class="panel-title" id="tax-profile-credit-title">Guthaben / Add Funds</h3>
                </div>
                <div class="panel-body">
                    <p class="text-muted">Nur für einen ausdrücklich bestätigten Add-Funds-Fall; angewendetes Kundenguthaben auf normalen Rechnungen bleibt ein eigener Klärfall.</p>

                    {assign var="creditAccountId" value=$settings.accountingTypeCredit|default:''}
                    {assign var="creditAccountFound" value=false}
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                {if $accountOptions|@count}
                                    {foreach from=$accountOptions item=account}
                                        {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                        {if $creditAccountId == $accountId}{assign var="creditAccountFound" value=true}{/if}
                                    {/foreach}
                                    <label class="control-label" for="account-credit">sevdesk-Erlöskonto</label>
                                    <select id="account-credit" name="accountingTypeCredit" class="form-control" aria-describedby="account-credit-help">
                                        <option value=""{if !$creditAccountId} selected{/if}>Kein Konto gewählt – Profil bleibt blockiert</option>
                                        {foreach from=$accountOptions item=account}
                                            {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                            <option value="{$accountId|escape:'html':'UTF-8'}"{if $creditAccountId == $accountId} selected{/if}>{if $account.accountNumber}{$account.accountNumber|escape:'html':'UTF-8'} — {/if}{$account.name|default:$account.accountName|default:'Unbenanntes Konto'|escape:'html':'UTF-8'} (ID {$accountId|escape:'html':'UTF-8'})</option>
                                        {/foreach}
                                        {if $creditAccountId && !$creditAccountFound}
                                            <option value="{$creditAccountId|escape:'html':'UTF-8'}" selected>Gespeicherte ID {$creditAccountId|escape:'html':'UTF-8'} – nicht in aktueller Guidance</option>
                                        {/if}
                                    </select>
                                {else}
                                    <label class="control-label" for="account-credit">sevdesk-AccountDatev-ID</label>
                                    <input type="number" id="account-credit" name="accountingTypeCredit" class="form-control" min="1" step="1" value="{$creditAccountId|escape:'html':'UTF-8'}" aria-describedby="account-credit-help">
                                {/if}
                                <small id="account-credit-help" class="help-block">Ohne fachliche Bestätigung bleibt das Profil unabhängig von der Kontoauswahl blockiert.</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="control-label" for="tax-rule-credit">TaxRule-ID</label>
                                <details class="sd-info">
                                    <summary class="sd-info-trigger" aria-label="Mehr Informationen zur Add-Funds-Steuerregel" title="Mehr Informationen zur Add-Funds-Steuerregel"><i class="fas fa-info-circle" aria-hidden="true"></i></summary>
                                    <div class="sd-info-popover" role="note"><strong>Add Funds</strong><span>Die steuerliche Behandlung muss für den konkreten Anwendungsfall bestätigt sein; es gibt keine allgemeine Rule-Voreinstellung.</span></div>
                                </details>
                                <input type="number" id="tax-rule-credit" name="taxRuleCredit" class="form-control" min="1" step="1" value="{$settings.taxRuleCredit|escape:'html':'UTF-8'}" aria-describedby="tax-rule-credit-help">
                                <small id="tax-rule-credit-help" class="help-block">Keine pauschale Voreinstellung. Konto und Rule müssen gemeinsam geprüft und bestätigt werden.</small>
                            </div>
                        </div>
                    </div>

                    <div class="checkbox">
                        <label for="confirm-credit">
                            <input type="checkbox" id="confirm-credit" name="add_funds_confirmed" value="on"{if $settings.add_funds_confirmed === 'on' || $settings.add_funds_confirmed === true || $settings.add_funds_confirmed == 1} checked{/if}>
                            Guthaben/Add-Funds-Profil und steuerliche Behandlung sind bestätigt.
                        </label>
                    </div>
                </div>
            </article>

            <article class="panel panel-default sd-tax-profile" aria-labelledby="tax-profile-small-business-title">
                <div class="panel-heading clearfix">
                    <span class="pull-right">
                        {if $settings.small_business_confirmed === 'on' || $settings.small_business_confirmed === true || $settings.small_business_confirmed == 1}<span class="label label-warning">Profil bestätigt</span>{else}<span class="label label-default">Blockiert</span>{/if}
                    </span>
                    <h3 class="panel-title" id="tax-profile-small-business-title">Kleinunternehmer (§ 19 UStG)</h3>
                </div>
                <div class="panel-body">
                    <p class="text-muted">Rule 11 mit 0 % Steuer; wird nur verwendet, wenn die Kleinunternehmerregelung oben für den gesamten Exportzeitraum aktiviert ist.</p>

                    {assign var="smallBusinessAccountId" value=$settings.accountingTypeSmallBusinessOwner|default:''}
                    {assign var="smallBusinessAccountFound" value=false}
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                {if $accountOptions|@count}
                                    {foreach from=$accountOptions item=account}
                                        {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                        {if $smallBusinessAccountId == $accountId}{assign var="smallBusinessAccountFound" value=true}{/if}
                                    {/foreach}
                                    <label class="control-label" for="account-small-business">sevdesk-Erlöskonto</label>
                                    <select id="account-small-business" name="accountingTypeSmallBusinessOwner" class="form-control" aria-describedby="account-small-business-help">
                                        <option value=""{if !$smallBusinessAccountId} selected{/if}>Kein Konto gewählt – Profil bleibt blockiert</option>
                                        {foreach from=$accountOptions item=account}
                                            {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                            <option value="{$accountId|escape:'html':'UTF-8'}"{if $smallBusinessAccountId == $accountId} selected{/if}>{if $account.accountNumber}{$account.accountNumber|escape:'html':'UTF-8'} — {/if}{$account.name|default:$account.accountName|default:'Unbenanntes Konto'|escape:'html':'UTF-8'} (ID {$accountId|escape:'html':'UTF-8'})</option>
                                        {/foreach}
                                        {if $smallBusinessAccountId && !$smallBusinessAccountFound}
                                            <option value="{$smallBusinessAccountId|escape:'html':'UTF-8'}" selected>Gespeicherte ID {$smallBusinessAccountId|escape:'html':'UTF-8'} – nicht in aktueller Guidance</option>
                                        {/if}
                                    </select>
                                {else}
                                    <label class="control-label" for="account-small-business">sevdesk-AccountDatev-ID</label>
                                    <input type="number" id="account-small-business" name="accountingTypeSmallBusinessOwner" class="form-control" min="1" step="1" value="{$smallBusinessAccountId|escape:'html':'UTF-8'}" aria-describedby="account-small-business-help">
                                {/if}
                                <small id="account-small-business-help" class="help-block">Das Konto muss Rule 11 und 0 % laut Receipt Guidance ausdrücklich erlauben.</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="control-label" for="tax-rule-small-business">TaxRule-ID</label>
                                <details class="sd-info">
                                    <summary class="sd-info-trigger" aria-label="Mehr Informationen zu Tax Rule 11" title="Mehr Informationen zu Tax Rule 11"><i class="fas fa-info-circle" aria-hidden="true"></i></summary>
                                    <div class="sd-info-popover" role="note"><strong>Rule 11</strong><span>Kleinunternehmer nach § 19 UStG mit 0 % Steuer; globaler Schalter und Profilbestätigung sind zusätzlich erforderlich.</span></div>
                                </details>
                                <input type="number" id="tax-rule-small-business" name="taxRuleSmallBusinessOwner" class="form-control" min="1" step="1" value="{$settings.taxRuleSmallBusinessOwner|default:11|escape:'html':'UTF-8'}" aria-describedby="tax-rule-small-business-help">
                                <small id="tax-rule-small-business-help" class="help-block">Voreinstellung: Rule 11. Die Rechnungspositionen müssen steuerfrei mit 0 % vorliegen.</small>
                            </div>
                        </div>
                    </div>

                    <div class="checkbox">
                        <label for="confirm-small-business">
                            <input type="checkbox" id="confirm-small-business" name="small_business_confirmed" value="on"{if $settings.small_business_confirmed === 'on' || $settings.small_business_confirmed === true || $settings.small_business_confirmed == 1} checked{/if}>
                            §-19-Profil, Konto und TaxRule sind für den Exportzeitraum bestätigt.
                        </label>
                    </div>
                </div>
            </article>
        </div>
        <div class="panel-footer clearfix">
            <span class="pull-right">
                <a class="btn btn-default" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=health">Systemcheck öffnen</a>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Einstellungen speichern</button>
            </span>
            <span class="text-muted small">Das Speichern startet keinen Export.</span>
        </div>
    </div>
</form>

{include file="partials/layout_bottom.tpl"}
