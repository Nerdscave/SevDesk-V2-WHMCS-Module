{include file="partials/layout_top.tpl" pageTitle="Einrichtung"}

<form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=setup" data-loading-form>
    <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
    <input type="hidden" name="save" value="1">
    <input type="hidden" name="runtime_quarantine_token" value="{$settings.runtime_quarantine_token|default:''|escape:'html':'UTF-8'}">
    <input type="hidden" name="transition_inventory_fingerprint" value="{$transitionInventory.fingerprint|default:''|escape:'html':'UTF-8'}">

    {if $settings.runtime_review_required === 'on' || $settings.runtime_review_required === true || $settings.runtime_review_required == 1}
        <div class="alert alert-danger" role="alert">
            <h4>Übernommener Modulbestand ist gesperrt</h4>
            <p>Automatische Hooks, Runner, neue Jobs sowie Fortsetzen und Wiederholen bestehender Jobs bleiben gesperrt. Prüfen Sie vor der Freigabe insbesondere Mandant/API-Token, Kontaktfeld und vorhandene Kontakt-IDs, Konten, Steuerprofile, Dokumentmodus sowie alle offenen und unklaren Jobs. Pausieren oder Abbrechen bleibt möglich.</p>
            <div class="checkbox">
                <label for="runtime-review-confirmed">
                    <input type="checkbox" id="runtime-review-confirmed" name="runtime_review_confirmed" value="1" required>
                    Ich habe den übernommenen Bestand und den verbundenen sevdesk-Mandanten geprüft und gebe die konfigurierte Laufzeit ausdrücklich frei.
                </label>
            </div>
        </div>
    {/if}

    <div class="panel panel-warning">
        <div class="panel-heading"><h3 class="panel-title">Übergangsinventur für Dokumentänderungen</h3></div>
        <div class="panel-body">
            <p>Diese Bestandsaufnahme ist rein lesend. Sie zeigt, welche Zuordnungen und Jobs vor einer Änderung von Exportmodus, Dokumenthoheit, OSS-, E-Rechnungs-, Rule-11-Invoice- oder Kleinunternehmerprofil zu prüfen sind.</p>
            <div class="table-responsive">
                <table class="table table-condensed">
                    <tbody>
                    <tr><th scope="row">Typisierte Voucher</th><td>{$transitionInventory.typed_vouchers|default:0|escape:'html':'UTF-8'}</td><th scope="row">Typisierte Invoices</th><td>{$transitionInventory.typed_invoices|default:0|escape:'html':'UTF-8'}</td></tr>
                    <tr><th scope="row">Vollständig, Typ ungeklärt</th><td>{$transitionInventory.untyped_complete|default:0|escape:'html':'UTF-8'}</td><th scope="row">Ohne sevdesk-ID</th><td>{$transitionInventory.null_remote_mappings|default:0|escape:'html':'UTF-8'}</td></tr>
                    <tr><th scope="row">Verwaiste Zuordnungen</th><td>{$transitionInventory.orphan_mappings|default:0|escape:'html':'UTF-8'}</td><th scope="row">Bezahlte, ungemappte Rechnungen ab Stichtag</th><td>{$transitionInventory.paid_unmapped|default:0|escape:'html':'UTF-8'}</td></tr>
                    <tr><th scope="row">Aktive Exportjobs</th><td>{$transitionInventory.active_export_jobs|default:0|escape:'html':'UTF-8'}</td><th scope="row">Unklare Exportjobs</th><td>{$transitionInventory.ambiguous_export_jobs|default:0|escape:'html':'UTF-8'}</td></tr>
                    <tr><th scope="row">Alte fehlgeschlagene Exportjobs</th><td>{$transitionInventory.failed_export_jobs|default:0|escape:'html':'UTF-8'}</td><th scope="row">Lokale Hinweise auf mögliche Remote-Dubletten</th><td>{$transitionInventory.possible_remote_duplicates|default:0|escape:'html':'UTF-8'}</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="help-block">Ein Moduswechsel verändert keine bestehende Zuordnung und startet keinen Nachlauf. Mögliche Remote-Dubletten müssen vor einer Neuanlage in sevdesk geprüft werden.</p>
            <div class="checkbox">
                <label for="transition-inventory-confirmed">
                    <input type="checkbox" id="transition-inventory-confirmed" name="transition_inventory_confirmed" value="1">
                    Ich habe diese Übergangsinventur geprüft. Geplante Änderungen gelten nur für neue, noch nicht begonnene Dokumententscheidungen.
                </label>
            </div>
        </div>
    </div>

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

            <div class="checkbox">
                <label for="customer-number-contact-creation-confirmed">
                    <input type="checkbox" id="customer-number-contact-creation-confirmed" name="customer_number_contact_creation_confirmed" value="on"{if $settings.customer_number_contact_creation_confirmed === 'on' || $settings.customer_number_contact_creation_confirmed === true || $settings.customer_number_contact_creation_confirmed == 1} checked{/if}>
                    Ich bestätige, dass neue sevdesk-Kontakte mit <code>customerNumber=&lt;interne WHMCS-Client-ID&gt;</code> angelegt werden dürfen.
                </label>
                <small class="help-block">Ohne Bestätigung bleiben vorhandene Kontakt-IDs und exakt passende Kundennummerntreffer nutzbar; bei keinem Treffer wird kein neuer Kontakt angelegt.</small>
            </div>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading"><h3 class="panel-title">Dokumentziel und Dokumenthoheit</h3></div>
        <div class="panel-body">
            <div class="alert alert-info" role="note">
                Bestehende Installationen bleiben bei <strong>WHMCS + Voucher only</strong>. Ein Moduswechsel exportiert vorhandene Zuordnungen nicht erneut. Invoice-Ziele werden ausschließlich nach vollständiger Zahlung und mit finaler WHMCS-Rechnungsnummer geschrieben.
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="control-label" for="export-mode">Exportmodus</label>
                        <select id="export-mode" name="export_mode" class="form-control" required>
                            <option value="voucher_only"{if $settings.export_mode === 'voucher_only'} selected{/if}>Voucher only</option>
                            <option value="invoice_for_oss"{if $settings.export_mode === 'invoice_for_oss'} selected{/if}>Invoice nur für bestätigtes OSS / Rule 19</option>
                            <option value="invoice_only"{if $settings.export_mode === 'invoice_only'} selected{/if}>Invoice only</option>
                        </select>
                        <small class="help-block"><strong>Invoice only:</strong> sevdesk-Invoice-Positionen übernehmen kein benutzerdefiniertes <code>accountDatev</code>.</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="control-label" for="document-authority">Kundenseitig maßgebliche Endrechnung</label>
                        <select id="document-authority" name="document_authority" class="form-control" required>
                            <option value="whmcs"{if $settings.document_authority === 'whmcs'} selected{/if}>WHMCS</option>
                            <option value="sevdesk"{if $settings.document_authority === 'sevdesk'} selected{/if}>sevdesk (nur Invoice only)</option>
                        </select>
                        <small class="help-block">WHMCS bleibt in beiden Fällen Billing-, Proforma- und Zahlungsplattform. sevdesk-Dokumenthoheit benötigt die automatische Einreihung neuer Rechnungen.</small>
                    </div>
                </div>
            </div>

            <div class="panel panel-warning">
                <div class="panel-heading"><h4 class="panel-title">Invoice-API-Canary</h4></div>
                <div class="panel-body">
                    <p>Invoice-Modi bleiben fail-closed, bis der dokumentierte Testmandanten-Canary vollständig durchgeführt wurde. Dazu gehören Rule 19, effektive WHMCS-Rechnungsnummer, Marker, Pflichtreferenzen, Open/PDF/Versand/Booking und die Prüfung möglicher ID-Kollisionen.</p>
                    <div class="checkbox">
                        <label for="invoice-canary-confirmed">
                            <input type="checkbox" id="invoice-canary-confirmed" name="invoice_canary_confirmed" value="on"{if $settings.invoice_canary_confirmed === 'on' || $settings.invoice_canary_confirmed === true || $settings.invoice_canary_confirmed == 1} checked{/if}>
                            Ich bestätige, dass der Canary im aktuell konfigurierten sevdesk-Testmandanten vollständig bestanden wurde.
                        </label>
                    </div>
                    <div class="checkbox">
                        <label for="small-business-invoice-canary-confirmed">
                            <input type="checkbox" id="small-business-invoice-canary-confirmed" name="small_business_invoice_canary_confirmed" value="on"{if $settings.small_business_invoice_canary_confirmed === 'on' || $settings.small_business_invoice_canary_confirmed === true || $settings.small_business_invoice_canary_confirmed == 1} checked{/if}>
                            Der separate Canary für normale Kleinunternehmer-Invoices mit Rule 11 und 0&nbsp;% wurde im aktuell verbundenen sevdesk-Mandanten vollständig bestanden.
                        </label>
                        <small class="help-block">Diese Freigabe gilt für alle Rule-11-Invoices. Beim Speichern prüft das Modul zusätzlich, ob Receipt Guidance aktuell mindestens ein <code>REVENUE</code>-Konto mit Rule 11 und 0&nbsp;% anbietet. Ein Live-Test zeigte, dass sevdesk einen Rule-11-Entwurf zwar anlegen, das anschließende Öffnen aber wegen des automatisch gewählten Kontenbereichs ablehnen kann. Invoice-Positionen erlauben kein eigenes <code>accountDatev</code>.</small>
                    </div>
                    <div class="checkbox">
                        <label for="invoice-discount-canary-confirmed">
                            <input type="checkbox" id="invoice-discount-canary-confirmed" name="invoice_discount_canary_confirmed" value="on"{if $settings.invoice_discount_canary_confirmed === 'on' || $settings.invoice_discount_canary_confirmed === true || $settings.invoice_discount_canary_confirmed == 1} checked{/if}>
                            Der zusätzliche Canary für einen festen <code>PromoHosting</code>-Rabatt mit Rule 11 und 0&nbsp;% wurde bestanden.
                        </label>
                        <small class="help-block">Die Rabattfreigabe setzt den allgemeinen Rule-11-Invoice-Canary zusätzlich voraus. Sie gilt nur für eindeutig zugeordnete Hosting-Rabatte aus dem Kleinunternehmerzeitraum. Andere negative Positionen bleiben gesperrt.</small>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="control-label" for="invoice-sev-user">SevUser für Invoices</label>
                        {if $sevUsers|@count}
                            <select id="invoice-sev-user" name="invoice_sev_user_id" class="form-control">
                                <option value="">Bitte wählen</option>
                                {foreach from=$sevUsers item=reference}
                                    <option value="{$reference.id|escape:'html':'UTF-8'}"{if $settings.invoice_sev_user_id == $reference.id} selected{/if}>{$reference.name|escape:'html':'UTF-8'} (ID {$reference.id|escape:'html':'UTF-8'})</option>
                                {/foreach}
                            </select>
                        {else}
                            <input type="number" id="invoice-sev-user" name="invoice_sev_user_id" class="form-control" min="1" step="1" value="{$settings.invoice_sev_user_id|escape:'html':'UTF-8'}">
                        {/if}
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label class="control-label" for="invoice-unity">Standard-Unity</label>
                        {if $unities|@count}
                            <select id="invoice-unity" name="invoice_unity_id" class="form-control">
                                <option value="">Bitte wählen</option>
                                {foreach from=$unities item=reference}
                                    <option value="{$reference.id|escape:'html':'UTF-8'}"{if $settings.invoice_unity_id == $reference.id} selected{/if}>{$reference.name|escape:'html':'UTF-8'} (ID {$reference.id|escape:'html':'UTF-8'})</option>
                                {/foreach}
                            </select>
                        {else}
                            <input type="number" id="invoice-unity" name="invoice_unity_id" class="form-control" min="1" step="1" value="{$settings.invoice_unity_id|escape:'html':'UTF-8'}">
                        {/if}
                        <small class="help-block">Invoice-v1 verwendet je WHMCS-Position zunächst Menge 1.</small>
                    </div>
                </div>
            </div>

            <div class="panel panel-warning">
                <div class="panel-heading"><h4 class="panel-title">Native ZUGFeRD-E-Rechnungen</h4></div>
                <div class="panel-body">
                    <p>ZUGFeRD wird von sevdesk als Eigenschaft einer normalen Invoice erzeugt. Der Modus ist nur mit <strong>Invoice only</strong>, sevdesk-Dokumenthoheit, einem separaten Canary und einem ausdrücklich gesetzten Kunden-Tickbox-Feld zulässig. Fehlen nach dem Opt-in Pflichtdaten, wird die Rechnung blockiert; es gibt keinen stillen Rückfall auf eine normale PDF-Invoice.</p>
                    <p class="text-warning"><strong>Derzeitige Grenze:</strong> Rechnungen mit angewendetem WHMCS-Guthaben werden nicht als ZUGFeRD erzeugt. Das gilt auch für eindeutig erkannte Sammelzahlungen. Der Worker blockiert den Fall, statt eine normale PDF-Invoice zu erzeugen.</p>
                    <div class="form-group">
                        <label class="control-label" for="e-invoice-mode">E-Rechnungsmodus</label>
                        <select id="e-invoice-mode" name="e_invoice_mode" class="form-control">
                            <option value="off"{if $settings.e_invoice_mode === 'off'} selected{/if}>Aus</option>
                            <option value="zugferd_domestic_b2b"{if $settings.e_invoice_mode === 'zugferd_domestic_b2b'} selected{/if}>ZUGFeRD für bestätigte deutsche B2B-Kunden</option>
                        </select>
                        <small class="help-block">Rule 19 bleibt immer eine normale Invoice. OSS Rules 18–20, XRechnung und Behördenfälle sind in diesem Profil ausgeschlossen.</small>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="control-label" for="e-invoice-client-field">Admin-Tickbox für das Kunden-Opt-in</label>
                                {if $eInvoiceClientFields|@count}
                                    <select id="e-invoice-client-field" name="e_invoice_client_field_id" class="form-control">
                                        <option value="">Bitte wählen</option>
                                        {foreach from=$eInvoiceClientFields item=field}
                                            <option value="{$field.id|escape:'html':'UTF-8'}"{if $settings.e_invoice_client_field_id == $field.id} selected{/if}>{$field.label|escape:'html':'UTF-8'} (ID {$field.id|escape:'html':'UTF-8'})</option>
                                        {/foreach}
                                    </select>
                                {else}
                                    <input type="number" id="e-invoice-client-field" name="e_invoice_client_field_id" class="form-control" min="1" step="1" value="{$settings.e_invoice_client_field_id|escape:'html':'UTF-8'}">
                                {/if}
                                <small class="help-block">Es werden nur vorhandene WHMCS-Kundenfelder vom Typ „Tickbox“ akzeptiert, die ausschließlich Administratoren sehen. Das Modul legt kein Feld an.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="control-label" for="e-invoice-payment-method">sevdesk-Standard-Zahlungsmethode</label>
                                {if $paymentMethods|@count}
                                    <select id="e-invoice-payment-method" name="e_invoice_payment_method_id" class="form-control">
                                        <option value="">Bitte wählen</option>
                                        {foreach from=$paymentMethods item=reference}
                                            <option value="{$reference.id|escape:'html':'UTF-8'}"{if $settings.e_invoice_payment_method_id == $reference.id} selected{/if}>{$reference.name|escape:'html':'UTF-8'} (ID {$reference.id|escape:'html':'UTF-8'})</option>
                                        {/foreach}
                                    </select>
                                {else}
                                    <input type="number" id="e-invoice-payment-method" name="e_invoice_payment_method_id" class="form-control" min="1" step="1" value="{$settings.e_invoice_payment_method_id|escape:'html':'UTF-8'}">
                                {/if}
                                <small class="help-block">Die Referenz wird beim Speichern read-only im aktuellen sevdesk-Mandanten geprüft.</small>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="control-label" for="e-invoice-active-from">E-Rechnungen aktiv ab</label>
                        <input type="date" id="e-invoice-active-from" name="e_invoice_active_from" class="form-control" value="{$settings.e_invoice_active_from_iso|default:''|escape:'html':'UTF-8'}">
                        <small class="help-block">Bestehende Dokumente und historische Backfills werden nie nachträglich zu E-Rechnungen.</small>
                    </div>
                    <div class="checkbox">
                        <label for="e-invoice-canary-confirmed">
                            <input type="checkbox" id="e-invoice-canary-confirmed" name="e_invoice_canary_confirmed" value="on"{if $settings.e_invoice_canary_confirmed === 'on' || $settings.e_invoice_canary_confirmed === true || $settings.e_invoice_canary_confirmed == 1} checked{/if}>
                            Der separate ZUGFeRD-Canary mit Create, Readback, XML, PDF, Versand und Kundendownload wurde vollständig bestanden.
                        </label>
                    </div>
                    <div class="checkbox">
                        <label for="e-invoice-profile-acknowledged">
                            <input type="checkbox" id="e-invoice-profile-acknowledged" name="e_invoice_profile_acknowledged" value="1">
                            <strong>Bei aktiviertem Profil erneut bestätigen:</strong> ZUGFeRD wird nur für deutsche Organisationen mit Rule 1 und gesetztem Admin-Opt-in verwendet; Behörden- und OSS-Fälle bleiben ausgeschlossen.
                        </label>
                    </div>
                </div>
            </div>

            <div class="panel panel-default">
                <div class="panel-heading"><h4 class="panel-title">OSS-v1: elektronische Leistungen / Rule 19</h4></div>
                <div class="panel-body">
                    <div class="form-group">
                        <label class="control-label" for="oss-profile">OSS-Profil</label>
                        <select id="oss-profile" name="oss_profile" class="form-control">
                            <option value="blocked"{if $settings.oss_profile === 'blocked'} selected{/if}>Blockiert</option>
                            <option value="rule19_digital_services_confirmed"{if $settings.oss_profile === 'rule19_digital_services_confirmed'} selected{/if}>Rule 19 für ausschließlich elektronische/digitale Leistungen bestätigt</option>
                        </select>
                        <small class="help-block">Positionsbeschreibungen werden nicht heuristisch ausgewertet. Rules 18 und 20 sowie gemischte oder unklare Leistungsarten bleiben blockiert. Das Rule-19-Profil ist nur zulässig, wenn „EU-Privatkunden“ unten auf „Blockieren“ steht; die bisherige deutsche Besteuerung und OSS dürfen nicht gleichzeitig aktiv sein.</small>
                    </div>
                    <div class="checkbox">
                        <label for="oss-profile-acknowledged">
                            <input type="checkbox" id="oss-profile-acknowledged" name="oss_profile_acknowledged" value="1">
                            <strong>Bei Freigabe oder erneutem Speichern bestätigen:</strong> Alle betroffenen EU-B2C-Rechnungspositionen sind elektronische/digitale Leistungen und dürfen nach Rule 19 behandelt werden.
                        </label>
                    </div>
                </div>
            </div>

            <div class="panel panel-default">
                <div class="panel-heading"><h4 class="panel-title">sevdesk-Dokumenthoheit und Versand</h4></div>
                <div class="panel-body">
                    <p>{if $proformaEnabled}<span class="label label-success">WHMCS-Proforma aktiv</span>{else}<span class="label label-danger">WHMCS-Proforma nicht aktiv</span>{/if} {if $themeAdapterInstalled}<span class="label label-success">Adapter-Manifest im aktiven Theme erkannt</span>{else}<span class="label label-danger">Adapter-Manifest im aktiven Theme fehlt</span>{/if} Der Modus lässt sich nur mit aktivem Proforma-Modus und installiertem Theme-Adapter einschalten.</p>
                    <div class="checkbox">
                        <label for="theme-adapter-confirmed">
                            <input type="checkbox" id="theme-adapter-confirmed" name="theme_adapter_confirmed" value="on"{if $settings.theme_adapter_confirmed === 'on' || $settings.theme_adapter_confirmed === true || $settings.theme_adapter_confirmed == 1} checked{/if}>
                            Der gebündelte Twenty-One-Adapter beziehungsweise ein kompatibler Custom-Theme-Adapter ist installiert und geprüft.
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="control-label" for="invoice-delivery-channel">Versandkanal</label>
                        <select id="invoice-delivery-channel" name="invoice_delivery_channel" class="form-control">
                            <option value="sevdesk"{if $settings.invoice_delivery_channel === 'sevdesk'} selected{/if}>sevdesk sendViaEmail</option>
                            <option value="whmcs_template"{if $settings.invoice_delivery_channel === 'whmcs_template'} selected{elseif !$whmcsTemplateDeliverySupported} disabled{/if}>WHMCS-Mailvorlage mit sevdesk-PDF{if !$whmcsTemplateDeliverySupported} – unter WHMCS 8.13 nicht unterstützt{/if}</option>
                        </select>
                        {if !$whmcsTemplateDeliverySupported}
                            <small class="help-block">WHMCS 8.13 führt <code>EmailPreSend</code> aus, übernimmt daraus aber keine Binäranhänge. Eine bestehende Auswahl bleibt sichtbar und muss bewusst auf <strong>sevdesk sendViaEmail</strong> umgestellt werden; das Modul fällt nicht still auf einen anderen Kanal zurück.</small>
                        {/if}
                    </div>
                    <div class="form-group">
                        <label class="control-label" for="whmcs-invoice-email-template">Aktive benutzerdefinierte Invoice-Mailvorlage</label>
                        <select id="whmcs-invoice-email-template" name="whmcs_invoice_email_template" class="form-control">
                            <option value="">Bitte wählen</option>
                            {foreach from=$emailTemplates item=templateName}
                                <option value="{$templateName|escape:'html':'UTF-8'}"{if $settings.whmcs_invoice_email_template === $templateName} selected{/if}>{$templateName|escape:'html':'UTF-8'}</option>
                            {/foreach}
                        </select>
                        <small class="help-block">Das Modul legt keine Vorlagen an und verändert keine vorhandene Vorlage.</small>
                    </div>
                    <div class="form-group">
                        <label class="control-label" for="sevdesk-email-subject">sevdesk-Betreff</label>
                        <input type="text" id="sevdesk-email-subject" name="sevdesk_email_subject" class="form-control" maxlength="200" value="{$settings.sevdesk_email_subject|escape:'html':'UTF-8'}">
                    </div>
                    <div class="form-group">
                        <label class="control-label" for="sevdesk-email-body">sevdesk-Nachricht</label>
                        <textarea id="sevdesk-email-body" name="sevdesk_email_body" class="form-control" rows="5" maxlength="5000">{$settings.sevdesk_email_body|escape:'html':'UTF-8'}</textarea>
                        <small class="help-block">Erlaubte Platzhalter: <code>{literal}{invoice_number}{/literal}</code> und <code>{literal}{company_name}{/literal}</code>.</small>
                    </div>
                </div>
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
                <small class="help-block"><strong>Standardmäßig ausgeschaltet.</strong> Erst aktivieren, wenn API, Konten und Steuerprofile im Systemcheck bestätigt sind. Für sevdesk-Dokumenthoheit ist dieser Schalter verpflichtend.</small>
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
                                <small class="help-block">Das zugehörige Rule-11-Profil muss unten zusätzlich bestätigt werden.</small>
                            </div>
                            <div class="form-group">
                                <label class="control-label" for="small-business-until">Gültig bis einschließlich</label>
                                <input type="date" id="small-business-until" name="small_business_until" class="form-control" value="{$settings.small_business_until_iso|default:''|escape:'html':'UTF-8'}" aria-describedby="small-business-until-help">
                                <small id="small-business-until-help" class="help-block">Optional. Ein gesetzter Stichtag wendet Rule 11 nur auf Rechnungen bis zu diesem Datum an. Leer bleibt aus Gründen der Rückwärtskompatibilität der bisherige unbegrenzte Modus aktiv.</small>
                                {if $settings.small_business_until_invalid|default:false}
                                    <p class="text-danger">Der gespeicherte Stichtag ist ungültig. Bis zur Korrektur bleibt der Export fail-closed.</p>
                                {/if}
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
                    <p class="text-muted">Rule 11 mit 0 % Steuer; wird nur verwendet, wenn die Kleinunternehmerregelung oben für das jeweilige Rechnungsdatum gilt.</p>

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
