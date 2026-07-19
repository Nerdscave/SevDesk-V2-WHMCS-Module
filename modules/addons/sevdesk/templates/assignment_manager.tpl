{include file="partials/layout_top.tpl" pageTitle="Zuordnungen"}

<div class="alert alert-warning" role="status">
    Eine vollständige Zuordnung kann nur aufgehoben werden, wenn Voucher und Invoice unter der gespeicherten ID read-only nachweislich fehlen.
    Legacy-Zuordnungen ohne Typ werden ausschließlich gelesen und erst nach einer zweiten Remote-Prüfung bestätigt.
</div>

<div class="well well-sm">
    <form method="get" action="addonmodules.php" class="form-inline sd-toolbar" aria-label="Zuordnungen filtern">
        <input type="hidden" name="module" value="sevdesk">
        <input type="hidden" name="a" value="assignmentManager">
        <div class="form-group">
            <label for="mapping-query">Suche</label>
            <input type="search" id="mapping-query" name="q" class="form-control input-sm" value="{$filters.q|escape:'html':'UTF-8'}" placeholder="Rechnungsnummer, WHMCS-ID oder sevdesk-ID">
        </div>
        <div class="form-group">
            <label for="mapping-status">Zustand</label>
            <select id="mapping-status" name="status" class="form-control input-sm">
                <option value="">Alle</option>
                <option value="mapped"{if $filters.status === 'mapped'} selected{/if}>Vollständig</option>
                <option value="untyped"{if $filters.status === 'untyped'} selected{/if}>Legacy-Typ ungeklärt</option>
                <option value="incomplete"{if $filters.status === 'incomplete'} selected{/if}>Ohne sevdesk-ID</option>
                <option value="orphan"{if $filters.status === 'orphan'} selected{/if}>Ohne WHMCS-Rechnung</option>
            </select>
        </div>
        <button type="submit" class="btn btn-default btn-sm">Filtern</button>
        {if $filters.q || $filters.status}<a class="btn btn-link btn-sm" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=assignmentManager">Filter zurücksetzen</a>{/if}
    </form>
</div>

{if $legacyBatchIds}
    <div class="panel panel-default">
        <div class="panel-heading"><h3 class="panel-title">Legacy-Typen gesammelt vorprüfen</h3></div>
        <div class="panel-body">
            <p>Die Sammelprüfung liest höchstens 25 sichtbare, vollständige Legacy-Zuordnungen. Nur eindeutige Treffer mit Rewrite-Marker können danach gesammelt bestätigt werden.</p>
            <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=assignmentManager">
                <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
                <input type="hidden" name="batch_mapping_ids" value="{$legacyBatchIds|escape:'html':'UTF-8'}">
                <input type="hidden" name="page" value="{$pagination.page|escape:'html':'UTF-8'}">
                <input type="hidden" name="filter_status" value="{$filters.status|escape:'html':'UTF-8'}">
                <input type="hidden" name="filter_q" value="{$filters.q|escape:'html':'UTF-8'}">
                <button type="submit" name="inspect_legacy_types_batch" value="1" class="btn btn-default btn-sm">Sichtbare Legacy-Typen read-only prüfen</button>
            </form>
        </div>
    </div>
{/if}

{if $batchTypeInspections|@count}
    <div class="panel panel-info">
        <div class="panel-heading"><h3 class="panel-title">Ergebnis der Sammelprüfung</h3></div>
        <div class="panel-body">
            <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=assignmentManager" data-confirm="Alle markerbestätigten Typen nach einer erneuten Remote-Prüfung übernehmen?">
                <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
                <input type="hidden" name="page" value="{$pagination.page|escape:'html':'UTF-8'}">
                <input type="hidden" name="filter_status" value="{$filters.status|escape:'html':'UTF-8'}">
                <input type="hidden" name="filter_q" value="{$filters.q|escape:'html':'UTF-8'}">
                <div class="table-responsive">
                    <table class="table table-condensed">
                        <thead><tr><th scope="col">Mapping</th><th scope="col">WHMCS-Rechnung</th><th scope="col">Vorschlag</th><th scope="col">Nachweis</th></tr></thead>
                        <tbody>
                        {foreach from=$batchTypeInspections item=inspection}
                            <tr>
                                <td class="sd-mono">{$inspection.mappingId|escape:'html':'UTF-8'}</td>
                                <td>{$inspection.invoiceNumber|default:$inspection.invoiceId|escape:'html':'UTF-8'}</td>
                                <td>{if $inspection.suggestedType === 'invoice'}Invoice{elseif $inspection.suggestedType === 'voucher'}Voucher{else}—{/if}</td>
                                <td>
                                    {$inspection.message|escape:'html':'UTF-8'}
                                    {if $inspection.batchEligible}
                                        <input type="hidden" name="batch_confirmations[{$inspection.mappingId|escape:'html':'UTF-8'}]" value="{$inspection.suggestedType|escape:'html':'UTF-8'}">
                                    {/if}
                                </td>
                            </tr>
                        {/foreach}
                        </tbody>
                    </table>
                </div>
                {if $batchTypeEligibleCount > 0}
                    <button type="submit" name="confirm_legacy_types_batch" value="1" class="btn btn-primary btn-sm">Markerbestätigte Typen übernehmen</button>
                {else}
                    <p class="help-block">Kein Treffer ist für eine Sammelbestätigung geeignet. Bitte die angezeigten Einzelfälle getrennt prüfen.</p>
                {/if}
            </form>
        </div>
    </div>
{/if}

{if $mappings|@count}
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
            <tr>
                <th scope="col">WHMCS-Rechnung</th>
                <th scope="col">Rechnungsdatum</th>
                <th scope="col">sevdesk-Beleg</th>
                <th scope="col">Typ / Hoheit / Rule</th>
                <th scope="col">Bereit / Zustellung</th>
                <th scope="col">Zustand</th>
                <th scope="col">Mapping-ID</th>
                <th scope="col" class="sd-table-action"><span class="sr-only">Aktion</span></th>
            </tr>
            </thead>
            <tbody>
            {foreach from=$mappings item=mapping}
                <tr>
                    <td>
                        {if $mapping.invoice_exists !== false && $mapping.invoice_id}
                            <a href="invoices.php?action=edit&amp;id={$mapping.invoice_id|escape:'url'}" target="_blank" rel="noopener">{$mapping.invoicenum|default:'Rechnung'|escape:'html':'UTF-8'}</a>
                            <small class="sd-mono">ID {$mapping.invoice_id|escape:'html':'UTF-8'}</small>
                        {else}
                            <span class="text-muted">Rechnung nicht vorhanden</span>
                            {if $mapping.invoice_id}<small class="sd-mono">ehemalige ID {$mapping.invoice_id|escape:'html':'UTF-8'}</small>{/if}
                        {/if}
                    </td>
                    <td>{$mapping.date|default:'—'|escape:'html':'UTF-8'}</td>
                    <td>
                        {if $mapping.sevdesk_id}
                            <a class="sd-mono" href="https://my.sevdesk.de/#/ex/detail/id/{$mapping.sevdesk_id|escape:'url'}" target="_blank" rel="noopener">{$mapping.sevdesk_id|escape:'html':'UTF-8'} <span class="sr-only">in sevdesk öffnen</span><i class="fas fa-external-link-alt" aria-hidden="true"></i></a>
                        {else}
                            <span class="text-muted">Keine ID gespeichert</span>
                        {/if}
                    </td>
                    <td>
                        {if $mapping.document_type === 'voucher'}
                            <strong>Voucher</strong>
                        {elseif $mapping.document_type === 'invoice'}
                            <strong>Invoice</strong>
                        {elseif $mapping.sevdesk_id}
                            <span class="text-warning"><i class="fas fa-question-circle" aria-hidden="true"></i> ungeklärt</span>
                        {else}
                            <span class="text-muted">—</span>
                        {/if}
                        <small class="sd-mono">{$mapping.document_number|default:'keine Dokumentnummer'|escape:'html':'UTF-8'}</small>
                        <small>Hoheit: {$mapping.document_authority|default:'nicht historisch erfasst'|escape:'html':'UTF-8'} · Rule: {$mapping.tax_rule|default:'—'|escape:'html':'UTF-8'}</small>
                        {if $mapping.document_type === 'invoice'}
                            <small>E-Rechnung: {if $mapping.is_e_invoice == 1}<strong>ja</strong>{if $mapping.xml_sha256} · XML geprüft{/if}{elseif $mapping.is_e_invoice === null}historisch ungeklärt{else}nein{/if}</small>
                        {/if}
                    </td>
                    <td>
                        <small>
                            bereit: {$mapping.document_ready_at|default:'—'|escape:'html':'UTF-8'}<br>
                            zugestellt: {$mapping.delivered_at|default:'—'|escape:'html':'UTF-8'}<br>
                            Zustand: {$mapping.delivery_state|default:'unknown'|escape:'html':'UTF-8'}
                        </small>
                    </td>
                    <td>
                        {if $mapping.invoice_exists === false}
                            {include file="partials/status_badge.tpl" status="warning"}
                            <small>verwaiste Zuordnung</small>
                        {elseif !$mapping.sevdesk_id}
                            {include file="partials/status_badge.tpl" status="ambiguous"}
                            <small>vor erneutem Export abgleichen</small>
                        {elseif !$mapping.document_type}
                            {include file="partials/status_badge.tpl" status="ambiguous"}
                            <small>Belegtyp manuell bestätigen</small>
                        {else}
                            {include file="partials/status_badge.tpl" status="mapped"}
                        {/if}
                    </td>
                    <td><span class="sd-mono">{$mapping.mapping_id|escape:'html':'UTF-8'}</span></td>
                    <td class="sd-table-action">
                        {if $mapping.invoice_exists !== false && $mapping.sevdesk_id && !$mapping.document_type}
                            {if $typeInspection && $typeInspection.mappingId == $mapping.mapping_id}
                                <div class="alert {if $typeInspection.context.markerEvidence}alert-info{else}alert-warning{/if}">
                                    Genau ein Remote-Typ passt: <strong>{if $typeInspection.suggestedType === 'invoice'}Invoice{else}Voucher{/if}</strong>
                                    mit Dokumentnummer <span class="sd-mono">{$typeInspection.documentNumber|escape:'html':'UTF-8'}</span>.<br>
                                    <small>
                                        Nachweis Dokumentnummer: {if $typeInspection.context.numberEvidence}<strong>ja</strong>{else}<strong>nein</strong>{/if} ·
                                        Rewrite-Marker: {if $typeInspection.context.markerEvidence}<strong>ja</strong>{else}<strong>nein</strong>{/if}
                                    </small>
                                    {if !$typeInspection.context.markerEvidence}
                                        <br><strong>Schwächerer Legacy-Vorschlag:</strong>
                                        Das Dokument besitzt keinen Marker des Rewrites. Bitte die historische Zuordnung in WHMCS und sevdesk vor der Bestätigung manuell prüfen.
                                    {/if}
                                </div>
                                <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=assignmentManager" data-confirm="Belegtyp nach erneuter Remote-Prüfung verbindlich bestätigen?">
                                    <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
                                    <input type="hidden" name="mapping_id" value="{$mapping.mapping_id|default:$mapping.id|escape:'html':'UTF-8'}">
                                    <input type="hidden" name="document_type" value="{$typeInspection.suggestedType|escape:'html':'UTF-8'}">
                                    <input type="hidden" name="page" value="{$pagination.page|escape:'html':'UTF-8'}">
                                    <input type="hidden" name="filter_status" value="{$filters.status|escape:'html':'UTF-8'}">
                                    <input type="hidden" name="filter_q" value="{$filters.q|escape:'html':'UTF-8'}">
                                    <button type="submit" name="confirm_legacy_type" value="1" class="btn btn-primary btn-sm">Typ bestätigen</button>
                                </form>
                            {else}
                                <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=assignmentManager">
                                    <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
                                    <input type="hidden" name="mapping_id" value="{$mapping.mapping_id|default:$mapping.id|escape:'html':'UTF-8'}">
                                    <input type="hidden" name="page" value="{$pagination.page|escape:'html':'UTF-8'}">
                                    <input type="hidden" name="filter_status" value="{$filters.status|escape:'html':'UTF-8'}">
                                    <input type="hidden" name="filter_q" value="{$filters.q|escape:'html':'UTF-8'}">
                                    <button type="submit" name="inspect_legacy_type" value="1" class="btn btn-default btn-sm">Typ read-only prüfen</button>
                                </form>
                            {/if}
                        {/if}
                        <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=assignmentManager" data-confirm="Zuordnung wirklich prüfen und nur bei nachgewiesener Remote-Abwesenheit aufheben?">
                            <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
                            <input type="hidden" name="mapping_id" value="{$mapping.mapping_id|default:$mapping.id|escape:'html':'UTF-8'}">
                            <input type="hidden" name="invoiceid" value="{$mapping.invoice_id|escape:'html':'UTF-8'}">
                            <input type="hidden" name="page" value="{$pagination.page|escape:'html':'UTF-8'}">
                            <input type="hidden" name="filter_status" value="{$filters.status|escape:'html':'UTF-8'}">
                            <input type="hidden" name="filter_q" value="{$filters.q|escape:'html':'UTF-8'}">
                            <button type="submit" name="delete" value="1" class="btn btn-default btn-sm">{if $mapping.sevdesk_id}Fehlen prüfen und aufheben{else}Unvollständige Reservierung aufheben{/if}</button>
                        </form>
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    </div>
    {include file="partials/pagination.tpl"}
{else}
    {include file="partials/empty_state.tpl" emptyIcon="fa-link" emptyTitle="Keine Zuordnungen gefunden" emptyText="Für den aktuellen Filter sind keine Zuordnungen vorhanden."}
{/if}

{include file="partials/layout_bottom.tpl"}
