{include file="partials/layout_top.tpl" pageTitle="Einrichtung" pageDescription="API-Zugang, Exportregeln und Steuerprofile sicher einrichten."}

<form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=setup" class="sd-form" data-loading-form>
    <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
    <input type="hidden" name="save" value="1">

    <div class="sd-form-layout">
        <div class="sd-form-main">
            <section class="sd-form-section" aria-labelledby="sd-api-heading">
                <div class="sd-form-section-heading">
                    <span class="sd-step">01</span>
                    <div>
                        <h2 id="sd-api-heading">sevdesk-Verbindung</h2>
                        <p>Der API-Token wird nur für direkte Anfragen an sevdesk verwendet und in Protokollen ausgeblendet.</p>
                    </div>
                </div>

                <div class="sd-field">
                    <label for="sevdesk-api-key">API-Token</label>
                    <div class="sd-input-with-action">
                        <input type="password" id="sevdesk-api-key" name="sevdesk_api_key" class="form-control" autocomplete="new-password" value="" placeholder="{$settings.sevdesk_api_key_placeholder|default:'Token unverändert lassen'|escape:'html':'UTF-8'}" aria-describedby="sevdesk-api-key-help">
                        <button type="button" class="btn btn-default" data-toggle-password aria-controls="sevdesk-api-key">Anzeigen</button>
                    </div>
                    <small id="sevdesk-api-key-help" class="sd-help">Leer lassen, um den gespeicherten Token beizubehalten.</small>
                </div>

                <div class="sd-field">
                    <label for="custom-field-id">WHMCS-Kundenfeld für die sevdesk-Kontakt-ID</label>
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
                    <small id="custom-field-id-help" class="sd-help">Ein späterer Wechsel kann zu doppelten Kontakten führen. Vor dem Speichern werden bestehende Werte nicht verschoben.</small>
                </div>
            </section>

            <section class="sd-form-section" aria-labelledby="sd-export-rules-heading">
                <div class="sd-form-section-heading">
                    <span class="sd-step">02</span>
                    <div>
                        <h2 id="sd-export-rules-heading">Exportregeln</h2>
                        <p>Diese Regeln gelten für automatische und manuell gestartete Exporte.</p>
                    </div>
                </div>

                <div class="sd-field-grid">
                    <div class="sd-field">
                        <label for="import-after">Rechnungen berücksichtigen ab</label>
                        <input type="date" id="import-after" name="import_after" class="form-control" value="{$settings.import_after_iso|default:$settings.import_after|escape:'html':'UTF-8'}" required>
                        <small class="sd-help">Ältere Rechnungen werden als übersprungen protokolliert.</small>
                    </div>
                    <div class="sd-field sd-field--switch">
                        <span class="sd-label">Zahlungsstatus</span>
                        <label class="sd-switch" for="import-only-paid">
                            <input type="checkbox" id="import-only-paid" name="import_only_paid" value="on"{if $settings.import_only_paid === 'on' || $settings.import_only_paid === true || $settings.import_only_paid == 1} checked{/if}>
                            <span class="sd-switch-control" aria-hidden="true"></span>
                            <span>Nur vollständig bezahlte Rechnungen exportieren</span>
                        </label>
                    </div>
                </div>

                <div class="sd-field sd-field--switch sd-field--danger-zone">
                    <span class="sd-label">Automatische Synchronisierung</span>
                    <label class="sd-switch" for="sync-enabled">
                        <input type="checkbox" id="sync-enabled" name="sync_enabled" value="on"{if $settings.sync_enabled === 'on' || $settings.sync_enabled === true || $settings.sync_enabled == 1} checked{/if}>
                        <span class="sd-switch-control" aria-hidden="true"></span>
                        <span>Neue Rechnungen automatisch als Exportjob einreihen</span>
                    </label>
                    <small class="sd-help"><strong>Standardmäßig ausgeschaltet.</strong> Erst aktivieren, wenn API, Konten und Steuerprofile im Systemcheck bestätigt sind.</small>
                </div>

                <div class="sd-field sd-field--switch">
                    <span class="sd-label">Sanitisiertes Diagnoseprotokoll</span>
                    <label class="sd-switch" for="debug-logging">
                        <input type="checkbox" id="debug-logging" name="debug_logging" value="on"{if $settings.debug_logging === 'on' || $settings.debug_logging === true || $settings.debug_logging == 1} checked{/if}>
                        <span class="sd-switch-control" aria-hidden="true"></span>
                        <span>Zusätzliche technische Statusereignisse zulassen</span>
                    </label>
                    <small class="sd-help">Standardmäßig aus. Auch im Diagnosemodus werden Token, PDF-Inhalt und ungekürzte Kundendaten niemals protokolliert.</small>
                </div>

                <div class="sd-section-heading sd-section-heading--setup">
                    <div>
                        <p class="sd-kicker">Steuerliche Leitplanken</p>
                        <h3>Sonderfälle bewusst freigeben</h3>
                        <p>Unsichere Steuerfälle bleiben blockiert, bis die erforderliche fachliche Bestätigung vorliegt.</p>
                    </div>
                </div>

                <div class="sd-field-grid sd-field-grid--policies">
                    <div class="sd-field sd-field--switch sd-policy-card">
                        <div class="sd-policy-card__heading">
                            <span class="sd-label">EU-Privatkunden</span>
                            <span class="sd-status {if $settings.eu_b2c_mode === 'domestic_confirmed'}sd-status--warning{else}sd-status--blocked{/if}">
                                <span class="sd-status-dot" aria-hidden="true"></span>
                                {if $settings.eu_b2c_mode === 'domestic_confirmed'}Prüfung bestätigt{else}Standardmäßig blockiert{/if}
                            </span>
                        </div>
                        <label for="eu-b2c-mode">Behandlung auswählen</label>
                        <select id="eu-b2c-mode" name="eu_b2c_mode" class="form-control" data-controls="eu-b2c-confirmation" aria-describedby="eu-b2c-mode-help">
                            <option value="blocked"{if !$settings.eu_b2c_mode || $settings.eu_b2c_mode === 'blocked'} selected{/if}>Blockieren und als Klärfall markieren</option>
                            <option value="domestic_confirmed"{if $settings.eu_b2c_mode === 'domestic_confirmed'} selected{/if}>Deutsche Besteuerung ist fachlich bestätigt</option>
                        </select>
                        <small id="eu-b2c-mode-help" class="sd-help">
                            <i class="fas fa-info-circle" aria-hidden="true"></i>
                            OSS-Regeln 18–20 sind für Voucher nicht unterstützt. Das Modul entscheidet nicht selbst, ob deutsche Umsatzsteuer angewendet werden darf.
                        </small>
                    </div>

                    <div class="sd-field sd-field--switch sd-policy-card">
                        <div class="sd-policy-card__heading">
                            <span class="sd-label">Kleinunternehmerregelung</span>
                            <span class="sd-status {if $settings.smallBusinessOwner === 'on' || $settings.smallBusinessOwner === true || $settings.smallBusinessOwner == 1}sd-status--warning{else}sd-status--pending{/if}">
                                <span class="sd-status-dot" aria-hidden="true"></span>
                                {if $settings.smallBusinessOwner === 'on' || $settings.smallBusinessOwner === true || $settings.smallBusinessOwner == 1}Aktiv{else}Nicht aktiv{/if}
                            </span>
                        </div>
                        <label class="sd-switch" for="small-business-owner">
                            <input type="checkbox" id="small-business-owner" name="smallBusinessOwner" value="on"{if $settings.smallBusinessOwner === 'on' || $settings.smallBusinessOwner === true || $settings.smallBusinessOwner == 1} checked{/if}>
                            <span class="sd-switch-control" aria-hidden="true"></span>
                            <span>Steuer wird nach § 19 UStG nicht erhoben</span>
                        </label>
                        <small class="sd-help">
                            <i class="fas fa-info-circle" aria-hidden="true"></i>
                            Nur aktivieren, wenn die Regel für den gesamten Exportzeitraum gilt. Das zugehörige Rule-11-Profil muss unten zusätzlich bestätigt werden.
                        </small>
                    </div>
                </div>

                <div id="eu-b2c-confirmation" class="sd-confirmation" data-visible-when="eu-b2c-mode:domestic_confirmed"{if $settings.eu_b2c_mode !== 'domestic_confirmed'} hidden{/if}>
                    <label class="sd-check-control" for="eu-b2c-confirmed">
                        <input type="checkbox" id="eu-b2c-confirmed" name="eu_b2c_acknowledged" value="1">
                        <span><strong>Erneute Bestätigung erforderlich:</strong> Für das aktuelle Geschäftsmodell und den betroffenen Zeitraum wurde die inländische Besteuerung von EU-B2C-Umsätzen fachlich geprüft.</span>
                    </label>
                </div>
            </section>

            <section class="sd-form-section" aria-labelledby="sd-accounts-heading">
                <div class="sd-form-section-heading">
                    <span class="sd-step">03</span>
                    <div>
                        <h2 id="sd-accounts-heading">Steuerfälle und Buchungskonten</h2>
                        <p>Wählen Sie pro Steuerfall das passende sevdesk-Erlöskonto. TaxRule, Konto und Steuersatz werden vor jedem Export gemeinsam geprüft.</p>
                    </div>
                </div>

                <div class="sd-alert" role="note">
                    <i class="fas fa-info-circle" aria-hidden="true"></i>
                    <div>
                        <strong>Die Kontenliste kommt direkt aus Ihrem sevdesk-Mandanten.</strong>
                        <p>Eine Auswahl allein gibt keinen Steuerfall frei. Nicht benötigte Profile bleiben leer; fachlich sensible Profile benötigen zusätzlich die jeweilige Bestätigung.</p>
                    </div>
                </div>

                <div class="sd-account-grid">
                    <article class="sd-field sd-tax-profile" aria-labelledby="tax-profile-general-title">
                        <div class="sd-tax-profile__header">
                            <div>
                                <p class="sd-kicker">Inland</p>
                                <h3 id="tax-profile-general-title">Deutschland</h3>
                            </div>
                            <span class="sd-status sd-status--pending"><span class="sd-status-dot" aria-hidden="true"></span>Rule 1</span>
                        </div>
                        <p class="sd-tax-profile__description">Steuerpflichtige Inlandsumsätze. Der Steuersatz kommt aus der WHMCS-Rechnung und muss vom gewählten Konto unterstützt werden.</p>

                        {assign var="generalAccountId" value=$settings.accountingTypeGeneral|default:''}
                        {assign var="generalAccountFound" value=false}
                        {if $accountOptions|@count}
                            {foreach from=$accountOptions item=account}
                                {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                {if $generalAccountId == $accountId}{assign var="generalAccountFound" value=true}{/if}
                            {/foreach}
                            <label for="account-general">sevdesk-Erlöskonto</label>
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
                            <label for="account-general">sevdesk-AccountDatev-ID</label>
                            <input type="number" id="account-general" name="accountingTypeGeneral" class="form-control" min="1" step="1" value="{$generalAccountId|escape:'html':'UTF-8'}" aria-describedby="account-general-help">
                        {/if}
                        <small id="account-general-help" class="sd-help">Wählen Sie das Erlöskonto für deutsche steuerpflichtige Umsätze. Eine nicht mehr angebotene gespeicherte ID wird nicht stillschweigend gelöscht.</small>

                        <div class="sd-tax-rule-heading">
                            <label class="sd-sublabel" for="tax-rule-general">TaxRule-ID</label>
                            <details class="sd-info">
                                <summary class="sd-info-trigger" aria-label="Mehr Informationen zu Tax Rule 1" title="Mehr Informationen zu Tax Rule 1"><i class="fas fa-info-circle" aria-hidden="true"></i></summary>
                                <div class="sd-info-popover" role="note"><strong>Rule 1</strong><span>Steuerpflichtige Umsätze; das Konto muss den konkreten WHMCS-Steuersatz erlauben.</span></div>
                            </details>
                        </div>
                        <input type="number" id="tax-rule-general" name="taxRuleGeneral" class="form-control sd-tax-rule-input" min="1" step="1" value="{$settings.taxRuleGeneral|default:1|escape:'html':'UTF-8'}" aria-describedby="tax-rule-general-help">
                        <small id="tax-rule-general-help" class="sd-help">Voreinstellung: Rule 1 für steuerpflichtige Umsätze. Die Receipt Guidance muss Konto, Rule und konkreten Steuersatz gemeinsam erlauben.</small>
                    </article>

                    <article class="sd-field sd-tax-profile" aria-labelledby="tax-profile-eu-business-title">
                        <div class="sd-tax-profile__header">
                            <div>
                                <p class="sd-kicker">EU B2B</p>
                                <h3 id="tax-profile-eu-business-title">Innergemeinschaftliche Warenlieferung</h3>
                            </div>
                            <span class="sd-status {if $settings.eu_b2b_goods_confirmed === 'on' || $settings.eu_b2b_goods_confirmed === true || $settings.eu_b2b_goods_confirmed == 1}sd-status--warning{else}sd-status--blocked{/if}">
                                <span class="sd-status-dot" aria-hidden="true"></span>
                                {if $settings.eu_b2b_goods_confirmed === 'on' || $settings.eu_b2b_goods_confirmed === true || $settings.eu_b2b_goods_confirmed == 1}Warenprofil bestätigt{else}Blockiert{/if}
                            </span>
                        </div>
                        <p class="sd-tax-profile__description">Nur für Organisationen mit USt-ID, WHMCS-<code>taxexempt</code> und bestätigter Warenlieferung. Hosting, Domains, Lizenzen und andere Dienstleistungen bleiben ausgeschlossen.</p>

                        {assign var="euBusinessAccountId" value=$settings.accountingTypeInterCommunityBusiness|default:''}
                        {assign var="euBusinessAccountFound" value=false}
                        {if $accountOptions|@count}
                            {foreach from=$accountOptions item=account}
                                {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                {if $euBusinessAccountId == $accountId}{assign var="euBusinessAccountFound" value=true}{/if}
                            {/foreach}
                            <label for="account-eu-business">sevdesk-Erlöskonto</label>
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
                            <label for="account-eu-business">sevdesk-AccountDatev-ID</label>
                            <input type="number" id="account-eu-business" name="accountingTypeInterCommunityBusiness" class="form-control" min="1" step="1" value="{$euBusinessAccountId|escape:'html':'UTF-8'}" aria-describedby="account-eu-business-help">
                        {/if}
                        <small id="account-eu-business-help" class="sd-help">Die Kontoauswahl aktiviert Rule 3 nicht automatisch. Fehlen Organisation, USt-ID, <code>taxexempt</code> oder Bestätigung, wird die Rechnung zum Klärfall.</small>

                        <div class="sd-tax-rule-heading">
                            <label class="sd-sublabel" for="tax-rule-eu-business">TaxRule-ID</label>
                            <details class="sd-info">
                                <summary class="sd-info-trigger" aria-label="Mehr Informationen zu Tax Rule 3" title="Mehr Informationen zu Tax Rule 3"><i class="fas fa-info-circle" aria-hidden="true"></i></summary>
                                <div class="sd-info-popover" role="note"><strong>Rule 3</strong><span>Nur für bestätigte innergemeinschaftliche Warenlieferungen, nicht für Hosting oder andere Dienstleistungen.</span></div>
                            </details>
                        </div>
                        <input type="number" id="tax-rule-eu-business" name="taxRuleInterCommunityBusiness" class="form-control sd-tax-rule-input" min="1" step="1" value="{$settings.taxRuleInterCommunityBusiness|default:3|escape:'html':'UTF-8'}" aria-describedby="tax-rule-eu-business-help">
                        <small id="tax-rule-eu-business-help" class="sd-help">Voreinstellung: Rule 3. EU-B2B-Dienstleistungen und Reverse-Charge-Fälle sind damit nicht freigegeben.</small>

                        <label class="sd-check-control sd-check-control--compact" for="confirm-eu-b2b-goods">
                            <input type="checkbox" id="confirm-eu-b2b-goods" name="eu_b2b_goods_confirmed" value="on"{if $settings.eu_b2b_goods_confirmed === 'on' || $settings.eu_b2b_goods_confirmed === true || $settings.eu_b2b_goods_confirmed == 1} checked{/if}>
                            <span>Rule 3 ist für innergemeinschaftliche Warenlieferungen ausdrücklich bestätigt. Hosting, Domains, Lizenzen und andere Dienstleistungen sind von dieser Freigabe ausgeschlossen.</span>
                        </label>
                    </article>

                    <article class="sd-field sd-tax-profile" aria-labelledby="tax-profile-eu-consumer-title">
                        <div class="sd-tax-profile__header">
                            <div>
                                <p class="sd-kicker">EU B2C</p>
                                <h3 id="tax-profile-eu-consumer-title">EU-Privatkunden</h3>
                            </div>
                            <span class="sd-status {if $settings.eu_b2c_mode === 'domestic_confirmed'}sd-status--warning{else}sd-status--blocked{/if}">
                                <span class="sd-status-dot" aria-hidden="true"></span>
                                {if $settings.eu_b2c_mode === 'domestic_confirmed'}Deutsche Steuer bestätigt{else}Blockiert{/if}
                            </span>
                        </div>
                        <p class="sd-tax-profile__description">Dieses Profil wird nur im oben bestätigten Modus für deutsche Besteuerung verwendet. OSS-pflichtige Umsätze bleiben technisch blockiert.</p>

                        {assign var="euConsumerAccountId" value=$settings.accountingTypeInterCommunityConsumer|default:''}
                        {assign var="euConsumerAccountFound" value=false}
                        {if $accountOptions|@count}
                            {foreach from=$accountOptions item=account}
                                {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                {if $euConsumerAccountId == $accountId}{assign var="euConsumerAccountFound" value=true}{/if}
                            {/foreach}
                            <label for="account-eu-consumer">sevdesk-Erlöskonto</label>
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
                            <label for="account-eu-consumer">sevdesk-AccountDatev-ID</label>
                            <input type="number" id="account-eu-consumer" name="accountingTypeInterCommunityConsumer" class="form-control" min="1" step="1" value="{$euConsumerAccountId|escape:'html':'UTF-8'}" aria-describedby="account-eu-consumer-help">
                        {/if}
                        <small id="account-eu-consumer-help" class="sd-help">Bei der sicheren Standardeinstellung wird dieses Konto nicht verwendet. Erst die ausdrückliche EU-B2C-Bestätigung oben erlaubt die weitere Prüfung.</small>

                        <div class="sd-tax-rule-heading">
                            <label class="sd-sublabel" for="tax-rule-eu-consumer">TaxRule-ID</label>
                            <details class="sd-info">
                                <summary class="sd-info-trigger" aria-label="Mehr Informationen zur EU-B2C Tax Rule" title="Mehr Informationen zur EU-B2C Tax Rule"><i class="fas fa-info-circle" aria-hidden="true"></i></summary>
                                <div class="sd-info-popover" role="note"><strong>EU B2C</strong><span>Rule 1 ist nur nach bestätigter deutscher Besteuerung zulässig. OSS-Rules 18–20 sind für Voucher nicht unterstützt.</span></div>
                            </details>
                        </div>
                        <input type="number" id="tax-rule-eu-consumer" name="taxRuleInterCommunityConsumer" class="form-control sd-tax-rule-input" min="1" step="1" value="{$settings.taxRuleInterCommunityConsumer|default:1|escape:'html':'UTF-8'}" aria-describedby="tax-rule-eu-consumer-help">
                        <small id="tax-rule-eu-consumer-help" class="sd-help">Voreinstellung: Rule 1. Die nicht unterstützten OSS-Rules 18–20 werden vom Modul nicht ersatzweise verwendet.</small>
                    </article>

                    <article class="sd-field sd-tax-profile" aria-labelledby="tax-profile-third-country-title">
                        <div class="sd-tax-profile__header">
                            <div>
                                <p class="sd-kicker">Außerhalb der EU</p>
                                <h3 id="tax-profile-third-country-title">Drittland</h3>
                            </div>
                            <span class="sd-status {if $settings.third_country_confirmed === 'on' || $settings.third_country_confirmed === true || $settings.third_country_confirmed == 1}sd-status--warning{else}sd-status--blocked{/if}">
                                <span class="sd-status-dot" aria-hidden="true"></span>
                                {if $settings.third_country_confirmed === 'on' || $settings.third_country_confirmed === true || $settings.third_country_confirmed == 1}Profil bestätigt{else}Blockiert{/if}
                            </span>
                        </div>
                        <p class="sd-tax-profile__description">Warenexport und nicht im Inland steuerbare Dienstleistungen sind unterschiedliche Fälle. Konto und TaxRule müssen zum konkreten Leistungsprofil passen.</p>

                        {assign var="thirdCountryAccountId" value=$settings.accountingTypeThirdPartyCountry|default:''}
                        {assign var="thirdCountryAccountFound" value=false}
                        {if $accountOptions|@count}
                            {foreach from=$accountOptions item=account}
                                {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                {if $thirdCountryAccountId == $accountId}{assign var="thirdCountryAccountFound" value=true}{/if}
                            {/foreach}
                            <label for="account-third-country">sevdesk-Erlöskonto</label>
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
                            <label for="account-third-country">sevdesk-AccountDatev-ID</label>
                            <input type="number" id="account-third-country" name="accountingTypeThirdPartyCountry" class="form-control" min="1" step="1" value="{$thirdCountryAccountId|escape:'html':'UTF-8'}" aria-describedby="account-third-country-help">
                        {/if}
                        <small id="account-third-country-help" class="sd-help">Wählen Sie nur ein Konto, das für den tatsächlichen Drittland-Fall fachlich freigegeben wurde.</small>

                        <div class="sd-tax-rule-heading">
                            <label class="sd-sublabel" for="tax-rule-third-country">TaxRule-ID</label>
                            <details class="sd-info">
                                <summary class="sd-info-trigger" aria-label="Mehr Informationen zu Drittland-Steuerregeln" title="Mehr Informationen zu Drittland-Steuerregeln"><i class="fas fa-info-circle" aria-hidden="true"></i></summary>
                                <div class="sd-info-popover" role="note"><strong>Drittland</strong><span>Warenexport und Dienstleistungen können unterschiedliche Regeln benötigen. Es gibt deshalb keine pauschale Voreinstellung.</span></div>
                            </details>
                        </div>
                        <input type="number" id="tax-rule-third-country" name="taxRuleThirdPartyCountry" class="form-control sd-tax-rule-input" min="1" step="1" value="{$settings.taxRuleThirdPartyCountry|escape:'html':'UTF-8'}" aria-describedby="tax-rule-third-country-help">
                        <small id="tax-rule-third-country-help" class="sd-help">Keine pauschale Voreinstellung: Tragen Sie nur die für Ihr bestätigtes Waren- oder Leistungsprofil freigegebene Rule ein.</small>

                        <label class="sd-check-control sd-check-control--compact" for="confirm-third-country">
                            <input type="checkbox" id="confirm-third-country" name="third_country_confirmed" value="on"{if $settings.third_country_confirmed === 'on' || $settings.third_country_confirmed === true || $settings.third_country_confirmed == 1} checked{/if}>
                            <span>Konto und TaxRule für das konkrete Drittland-Leistungsprofil sind fachlich bestätigt.</span>
                        </label>
                    </article>

                    <article class="sd-field sd-tax-profile" aria-labelledby="tax-profile-credit-title">
                        <div class="sd-tax-profile__header">
                            <div>
                                <p class="sd-kicker">Sonderfall</p>
                                <h3 id="tax-profile-credit-title">Guthaben / Add Funds</h3>
                            </div>
                            <span class="sd-status {if $settings.add_funds_confirmed === 'on' || $settings.add_funds_confirmed === true || $settings.add_funds_confirmed == 1}sd-status--warning{else}sd-status--blocked{/if}">
                                <span class="sd-status-dot" aria-hidden="true"></span>
                                {if $settings.add_funds_confirmed === 'on' || $settings.add_funds_confirmed === true || $settings.add_funds_confirmed == 1}Profil bestätigt{else}Blockiert{/if}
                            </span>
                        </div>
                        <p class="sd-tax-profile__description">Nur für einen ausdrücklich bestätigten Add-Funds-Fall. Angewendetes Kundenguthaben auf normalen Rechnungen bleibt ein eigener Klärfall.</p>

                        {assign var="creditAccountId" value=$settings.accountingTypeCredit|default:''}
                        {assign var="creditAccountFound" value=false}
                        {if $accountOptions|@count}
                            {foreach from=$accountOptions item=account}
                                {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                {if $creditAccountId == $accountId}{assign var="creditAccountFound" value=true}{/if}
                            {/foreach}
                            <label for="account-credit">sevdesk-Erlöskonto</label>
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
                            <label for="account-credit">sevdesk-AccountDatev-ID</label>
                            <input type="number" id="account-credit" name="accountingTypeCredit" class="form-control" min="1" step="1" value="{$creditAccountId|escape:'html':'UTF-8'}" aria-describedby="account-credit-help">
                        {/if}
                        <small id="account-credit-help" class="sd-help">Ohne fachliche Bestätigung bleibt das Profil unabhängig von der Kontoauswahl blockiert.</small>

                        <div class="sd-tax-rule-heading">
                            <label class="sd-sublabel" for="tax-rule-credit">TaxRule-ID</label>
                            <details class="sd-info">
                                <summary class="sd-info-trigger" aria-label="Mehr Informationen zur Add-Funds-Steuerregel" title="Mehr Informationen zur Add-Funds-Steuerregel"><i class="fas fa-info-circle" aria-hidden="true"></i></summary>
                                <div class="sd-info-popover" role="note"><strong>Add Funds</strong><span>Die steuerliche Behandlung muss für den konkreten Anwendungsfall bestätigt sein; es gibt keine allgemeine Rule-Voreinstellung.</span></div>
                            </details>
                        </div>
                        <input type="number" id="tax-rule-credit" name="taxRuleCredit" class="form-control sd-tax-rule-input" min="1" step="1" value="{$settings.taxRuleCredit|escape:'html':'UTF-8'}" aria-describedby="tax-rule-credit-help">
                        <small id="tax-rule-credit-help" class="sd-help">Keine pauschale Voreinstellung. Konto und Rule müssen gemeinsam geprüft und bestätigt werden.</small>

                        <label class="sd-check-control sd-check-control--compact" for="confirm-credit">
                            <input type="checkbox" id="confirm-credit" name="add_funds_confirmed" value="on"{if $settings.add_funds_confirmed === 'on' || $settings.add_funds_confirmed === true || $settings.add_funds_confirmed == 1} checked{/if}>
                            <span>Guthaben/Add-Funds-Profil und steuerliche Behandlung sind bestätigt.</span>
                        </label>
                    </article>

                    <article class="sd-field sd-tax-profile" aria-labelledby="tax-profile-small-business-title">
                        <div class="sd-tax-profile__header">
                            <div>
                                <p class="sd-kicker">§ 19 UStG</p>
                                <h3 id="tax-profile-small-business-title">Kleinunternehmer</h3>
                            </div>
                            <span class="sd-status {if $settings.small_business_confirmed === 'on' || $settings.small_business_confirmed === true || $settings.small_business_confirmed == 1}sd-status--warning{else}sd-status--blocked{/if}">
                                <span class="sd-status-dot" aria-hidden="true"></span>
                                {if $settings.small_business_confirmed === 'on' || $settings.small_business_confirmed === true || $settings.small_business_confirmed == 1}Profil bestätigt{else}Blockiert{/if}
                            </span>
                        </div>
                        <p class="sd-tax-profile__description">Rule 11 mit 0 % Steuer. Das Profil wird nur verwendet, wenn die Kleinunternehmerregelung oben für den gesamten Exportzeitraum aktiviert ist.</p>

                        {assign var="smallBusinessAccountId" value=$settings.accountingTypeSmallBusinessOwner|default:''}
                        {assign var="smallBusinessAccountFound" value=false}
                        {if $accountOptions|@count}
                            {foreach from=$accountOptions item=account}
                                {assign var="accountId" value=$account.accountDatevId|default:$account.id}
                                {if $smallBusinessAccountId == $accountId}{assign var="smallBusinessAccountFound" value=true}{/if}
                            {/foreach}
                            <label for="account-small-business">sevdesk-Erlöskonto</label>
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
                            <label for="account-small-business">sevdesk-AccountDatev-ID</label>
                            <input type="number" id="account-small-business" name="accountingTypeSmallBusinessOwner" class="form-control" min="1" step="1" value="{$smallBusinessAccountId|escape:'html':'UTF-8'}" aria-describedby="account-small-business-help">
                        {/if}
                        <small id="account-small-business-help" class="sd-help">Das Konto muss Rule 11 und 0 % laut Receipt Guidance ausdrücklich erlauben.</small>

                        <div class="sd-tax-rule-heading">
                            <label class="sd-sublabel" for="tax-rule-small-business">TaxRule-ID</label>
                            <details class="sd-info">
                                <summary class="sd-info-trigger" aria-label="Mehr Informationen zu Tax Rule 11" title="Mehr Informationen zu Tax Rule 11"><i class="fas fa-info-circle" aria-hidden="true"></i></summary>
                                <div class="sd-info-popover" role="note"><strong>Rule 11</strong><span>Kleinunternehmer nach § 19 UStG mit 0 % Steuer; globaler Schalter und Profilbestätigung sind zusätzlich erforderlich.</span></div>
                            </details>
                        </div>
                        <input type="number" id="tax-rule-small-business" name="taxRuleSmallBusinessOwner" class="form-control sd-tax-rule-input" min="1" step="1" value="{$settings.taxRuleSmallBusinessOwner|default:11|escape:'html':'UTF-8'}" aria-describedby="tax-rule-small-business-help">
                        <small id="tax-rule-small-business-help" class="sd-help">Voreinstellung: Rule 11. Die Rechnungspositionen müssen steuerfrei mit 0 % vorliegen.</small>

                        <label class="sd-check-control sd-check-control--compact" for="confirm-small-business">
                            <input type="checkbox" id="confirm-small-business" name="small_business_confirmed" value="on"{if $settings.small_business_confirmed === 'on' || $settings.small_business_confirmed === true || $settings.small_business_confirmed == 1} checked{/if}>
                            <span>§-19-Profil, Konto und TaxRule sind für den Exportzeitraum bestätigt.</span>
                        </label>
                    </article>
                </div>
            </section>
        </div>

        <aside class="sd-form-aside">
            <div class="sd-sticky-summary">
                <p class="sd-kicker">Vor dem Speichern</p>
                <h2>Konfiguration prüfen</h2>
                <ul class="sd-plain-list">
                    <li><i class="fas fa-check" aria-hidden="true"></i> API-Token gehört zum richtigen Mandanten</li>
                    <li><i class="fas fa-check" aria-hidden="true"></i> Konten entsprechen dem aktiven Kontenrahmen</li>
                    <li><i class="fas fa-check" aria-hidden="true"></i> EU B2B und EU B2C sind fachlich getrennt</li>
                    <li><i class="fas fa-check" aria-hidden="true"></i> Nicht benötigte Profile bleiben leer und unbestätigt</li>
                </ul>
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-save" aria-hidden="true"></i> Einstellungen speichern
                </button>
                <a class="btn btn-default btn-block" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=health">Systemcheck öffnen</a>
                <p class="sd-fine-print">Das Speichern startet keinen Export.</p>
            </div>
        </aside>
    </div>
</form>

{include file="partials/layout_bottom.tpl"}
