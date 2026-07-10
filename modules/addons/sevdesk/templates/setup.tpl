{include file="partials/layout_top.tpl" pageTitle="Einrichtung" pageDescription="API-Zugang, Exportregeln und Buchungskonten zentral prüfen."}

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
                        <input type="password" id="sevdesk-api-key" name="sevdesk_api_key" class="form-control" autocomplete="new-password" value="" placeholder="{$settings.sevdesk_api_key_masked|default:'Token unverändert lassen'|escape:'html':'UTF-8'}" aria-describedby="sevdesk-api-key-help">
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

                <div class="sd-field">
                    <label for="eu-b2c-mode">EU-Privatkunden</label>
                    <select id="eu-b2c-mode" name="eu_b2c_mode" class="form-control" data-controls="eu-b2c-confirmation">
                        <option value="blocked"{if !$settings.eu_b2c_mode || $settings.eu_b2c_mode === 'blocked'} selected{/if}>Blockieren und als Klärfall markieren</option>
                        <option value="domestic_confirmed"{if $settings.eu_b2c_mode === 'domestic_confirmed'} selected{/if}>Inländische Besteuerung ausdrücklich bestätigt</option>
                    </select>
                    <small class="sd-help">Ohne dokumentierte steuerliche Entscheidung bleibt EU B2C blockiert. Das Modul trifft keine OSS-Entscheidung selbst.</small>
                </div>
                <div id="eu-b2c-confirmation" class="sd-confirmation" data-visible-when="eu-b2c-mode:domestic_confirmed"{if $settings.eu_b2c_mode !== 'domestic_confirmed'} hidden{/if}>
                    <label class="sd-check-control" for="eu-b2c-confirmed">
                        <input type="checkbox" id="eu-b2c-confirmed" name="eu_b2c_acknowledged" value="1">
                        <span>Ich bestätige, dass für das aktuelle Geschäftsmodell und den betroffenen Zeitraum die inländische Besteuerung von EU-B2C-Umsätzen fachlich geprüft wurde.</span>
                    </label>
                </div>

                <div class="sd-field sd-field--switch">
                    <span class="sd-label">Kleinunternehmerregelung</span>
                    <label class="sd-switch" for="small-business-owner">
                        <input type="checkbox" id="small-business-owner" name="smallBusinessOwner" value="on"{if $settings.smallBusinessOwner === 'on' || $settings.smallBusinessOwner === true || $settings.smallBusinessOwner == 1} checked{/if}>
                        <span class="sd-switch-control" aria-hidden="true"></span>
                        <span>Steuer wird nach § 19 UStG nicht erhoben</span>
                    </label>
                    <small class="sd-help">Nur aktivieren, wenn diese Regel für den gesamten Exportzeitraum gilt.</small>
                </div>
            </section>

            <section class="sd-form-section" aria-labelledby="sd-accounts-heading">
                <div class="sd-form-section-heading">
                    <span class="sd-step">03</span>
                    <div>
                        <h2 id="sd-accounts-heading">Buchungskonten</h2>
                        <p>Gespeichert wird die sevdesk-AccountDatev-ID. Der Systemcheck prüft Konto, Steuerregel und Steuersatz gemeinsam. Leere, nicht benötigte Profile bleiben sicher blockiert.</p>
                    </div>
                </div>

                <div class="sd-account-grid">
                    <div class="sd-field">
                        <label for="account-general">Deutschland</label>
                        <input type="number" id="account-general" name="accountingTypeGeneral" class="form-control" min="1" step="1" list="revenue-account-options" value="{$settings.accountingTypeGeneral|escape:'html':'UTF-8'}">
                        <label class="sd-sublabel" for="tax-rule-general">TaxRule-ID</label>
                        <input type="number" id="tax-rule-general" name="taxRuleGeneral" class="form-control sd-tax-rule-input" min="1" step="1" value="{$settings.taxRuleGeneral|default:1|escape:'html':'UTF-8'}">
                    </div>
                    <div class="sd-field">
                        <label for="account-eu-business">EU-Geschäftskunden</label>
                        <input type="number" id="account-eu-business" name="accountingTypeInterCommunityBusiness" class="form-control" min="1" step="1" list="revenue-account-options" value="{$settings.accountingTypeInterCommunityBusiness|escape:'html':'UTF-8'}">
                        <label class="sd-sublabel" for="tax-rule-eu-business">TaxRule-ID</label>
                        <input type="number" id="tax-rule-eu-business" name="taxRuleInterCommunityBusiness" class="form-control sd-tax-rule-input" min="1" step="1" value="{$settings.taxRuleInterCommunityBusiness|default:3|escape:'html':'UTF-8'}">
                        <label class="sd-check-control sd-check-control--compact" for="confirm-eu-b2b-goods">
                            <input type="checkbox" id="confirm-eu-b2b-goods" name="eu_b2b_goods_confirmed" value="on"{if $settings.eu_b2b_goods_confirmed === 'on' || $settings.eu_b2b_goods_confirmed === true || $settings.eu_b2b_goods_confirmed == 1} checked{/if}>
                            <span>Rule 3 ist für innergemeinschaftliche Warenlieferungen ausdrücklich bestätigt. Hosting, Domains, Lizenzen und andere Dienstleistungen sind von dieser Freigabe ausgeschlossen.</span>
                        </label>
                    </div>
                    <div class="sd-field">
                        <label for="account-eu-consumer">EU-Privatkunden</label>
                        <input type="number" id="account-eu-consumer" name="accountingTypeInterCommunityConsumer" class="form-control" min="1" step="1" list="revenue-account-options" value="{$settings.accountingTypeInterCommunityConsumer|escape:'html':'UTF-8'}">
                        <label class="sd-sublabel" for="tax-rule-eu-consumer">TaxRule-ID</label>
                        <input type="number" id="tax-rule-eu-consumer" name="taxRuleInterCommunityConsumer" class="form-control sd-tax-rule-input" min="1" step="1" value="{$settings.taxRuleInterCommunityConsumer|default:1|escape:'html':'UTF-8'}">
                    </div>
                    <div class="sd-field">
                        <label for="account-third-country">Drittland</label>
                        <input type="number" id="account-third-country" name="accountingTypeThirdPartyCountry" class="form-control" min="1" step="1" list="revenue-account-options" value="{$settings.accountingTypeThirdPartyCountry|escape:'html':'UTF-8'}">
                        <label class="sd-sublabel" for="tax-rule-third-country">TaxRule-ID</label>
                        <input type="number" id="tax-rule-third-country" name="taxRuleThirdPartyCountry" class="form-control sd-tax-rule-input" min="1" step="1" value="{$settings.taxRuleThirdPartyCountry|escape:'html':'UTF-8'}">
                        <label class="sd-check-control sd-check-control--compact" for="confirm-third-country">
                            <input type="checkbox" id="confirm-third-country" name="third_country_confirmed" value="on"{if $settings.third_country_confirmed === 'on' || $settings.third_country_confirmed === true || $settings.third_country_confirmed == 1} checked{/if}>
                            <span>Konto und TaxRule für das konkrete Drittland-Leistungsprofil sind fachlich bestätigt.</span>
                        </label>
                    </div>
                    <div class="sd-field">
                        <label for="account-credit">Guthaben / Add Funds</label>
                        <input type="number" id="account-credit" name="accountingTypeCredit" class="form-control" min="1" step="1" list="revenue-account-options" value="{$settings.accountingTypeCredit|escape:'html':'UTF-8'}">
                        <label class="sd-sublabel" for="tax-rule-credit">TaxRule-ID</label>
                        <input type="number" id="tax-rule-credit" name="taxRuleCredit" class="form-control sd-tax-rule-input" min="1" step="1" value="{$settings.taxRuleCredit|escape:'html':'UTF-8'}">
                        <label class="sd-check-control sd-check-control--compact" for="confirm-credit">
                            <input type="checkbox" id="confirm-credit" name="add_funds_confirmed" value="on"{if $settings.add_funds_confirmed === 'on' || $settings.add_funds_confirmed === true || $settings.add_funds_confirmed == 1} checked{/if}>
                            <span>Guthaben/Add-Funds-Profil und steuerliche Behandlung sind bestätigt.</span>
                        </label>
                    </div>
                    <div class="sd-field">
                        <label for="account-small-business">Kleinunternehmer</label>
                        <input type="number" id="account-small-business" name="accountingTypeSmallBusinessOwner" class="form-control" min="1" step="1" list="revenue-account-options" value="{$settings.accountingTypeSmallBusinessOwner|escape:'html':'UTF-8'}">
                        <label class="sd-sublabel" for="tax-rule-small-business">TaxRule-ID</label>
                        <input type="number" id="tax-rule-small-business" name="taxRuleSmallBusinessOwner" class="form-control sd-tax-rule-input" min="1" step="1" value="{$settings.taxRuleSmallBusinessOwner|default:11|escape:'html':'UTF-8'}">
                        <label class="sd-check-control sd-check-control--compact" for="confirm-small-business">
                            <input type="checkbox" id="confirm-small-business" name="small_business_confirmed" value="on"{if $settings.small_business_confirmed === 'on' || $settings.small_business_confirmed === true || $settings.small_business_confirmed == 1} checked{/if}>
                            <span>§-19-Profil, Konto und TaxRule sind für den Exportzeitraum bestätigt.</span>
                        </label>
                    </div>
                </div>
                {if $accountOptions|@count}
                    <datalist id="revenue-account-options">
                        {foreach from=$accountOptions item=account}
                            <option value="{$account.accountDatevId|default:$account.id|escape:'html':'UTF-8'}">{$account.accountNumber|escape:'html':'UTF-8'} — {$account.name|default:$account.accountName|escape:'html':'UTF-8'}</option>
                        {/foreach}
                    </datalist>
                {/if}
            </section>
        </div>

        <aside class="sd-form-aside">
            <div class="sd-sticky-summary">
                <p class="sd-kicker">Vor dem Speichern</p>
                <h2>Konfiguration prüfen</h2>
                <ul class="sd-plain-list">
                    <li><i class="fas fa-check" aria-hidden="true"></i> API-Token gehört zum richtigen Mandanten</li>
                    <li><i class="fas fa-check" aria-hidden="true"></i> Konten entsprechen dem aktiven Kontenrahmen</li>
                    <li><i class="fas fa-check" aria-hidden="true"></i> EU B2B und EU B2C sind getrennt gewählt</li>
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
