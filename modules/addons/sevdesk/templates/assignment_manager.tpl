{include file="partials/layout_top.tpl" pageTitle="Zuordnungen" pageDescription="Bestehende Verknüpfungen zwischen WHMCS-Rechnungen und sevdesk-Belegen prüfen."}

<div class="sd-inline-note sd-inline-note--warning">
    <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
    <span><strong>Eine Zuordnung aufheben löscht keinen Beleg in sevdesk.</strong> Ein späterer Export kann einen zweiten Beleg erzeugen. Nutzen Sie diese Aktion nur nach einer Prüfung im Zielsystem.</span>
</div>

<form method="get" action="addonmodules.php" class="sd-toolbar" aria-label="Zuordnungen filtern">
    <input type="hidden" name="module" value="sevdesk">
    <input type="hidden" name="a" value="assignmentManager">
    <div class="sd-field sd-field--inline sd-field--grow">
        <label for="mapping-query">Suche</label>
        <input type="search" id="mapping-query" name="q" class="form-control input-sm" value="{$filters.q|escape:'html':'UTF-8'}" placeholder="Rechnungsnummer, WHMCS-ID oder sevdesk-ID">
    </div>
    <div class="sd-field sd-field--inline">
        <label for="mapping-status">Zustand</label>
        <select id="mapping-status" name="status" class="form-control input-sm">
            <option value="">Alle</option>
            <option value="mapped"{if $filters.status === 'mapped'} selected{/if}>Vollständig</option>
            <option value="incomplete"{if $filters.status === 'incomplete'} selected{/if}>Ohne sevdesk-ID</option>
            <option value="orphan"{if $filters.status === 'orphan'} selected{/if}>Ohne WHMCS-Rechnung</option>
        </select>
    </div>
    <button type="submit" class="btn btn-default btn-sm">Filtern</button>
    {if $filters.q || $filters.status}<a class="btn btn-link btn-sm" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=assignmentManager">Filter zurücksetzen</a>{/if}
</form>

{if $mappings|@count}
    <div class="sd-table-wrap">
        <table class="table sd-table">
            <thead>
            <tr>
                <th scope="col">WHMCS-Rechnung</th>
                <th scope="col">Rechnungsdatum</th>
                <th scope="col">sevdesk-Beleg</th>
                <th scope="col">Zustand</th>
                <th scope="col">Zuordnung</th>
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
                            <span class="sd-muted">Rechnung nicht vorhanden</span>
                            {if $mapping.invoice_id}<small class="sd-mono">ehemalige ID {$mapping.invoice_id|escape:'html':'UTF-8'}</small>{/if}
                        {/if}
                    </td>
                    <td>{$mapping.date|default:'—'|escape:'html':'UTF-8'}</td>
                    <td>
                        {if $mapping.sevdesk_id}
                            <a class="sd-mono" href="https://my.sevdesk.de/#/ex/detail/id/{$mapping.sevdesk_id|escape:'url'}" target="_blank" rel="noopener">{$mapping.sevdesk_id|escape:'html':'UTF-8'} <span class="sr-only">in sevdesk öffnen</span><i class="fas fa-external-link-alt" aria-hidden="true"></i></a>
                        {else}
                            <span class="sd-muted">Keine ID gespeichert</span>
                        {/if}
                    </td>
                    <td>
                        {if $mapping.invoice_exists === false}
                            {include file="partials/status_badge.tpl" status="warning"}
                            <small>verwaiste Zuordnung</small>
                        {elseif !$mapping.sevdesk_id}
                            {include file="partials/status_badge.tpl" status="ambiguous"}
                            <small>vor erneutem Export abgleichen</small>
                        {else}
                            {include file="partials/status_badge.tpl" status="mapped"}
                        {/if}
                    </td>
                    <td>{$mapping.created_at|default:$mapping.id|default:'Legacy-Datensatz'|escape:'html':'UTF-8'}</td>
                    <td class="sd-table-action">
                        <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=assignmentManager" data-confirm="Zuordnung wirklich aufheben? Der Beleg in sevdesk bleibt bestehen und ein späterer Export kann ein Duplikat anlegen.">
                            <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
                            <input type="hidden" name="mapping_id" value="{$mapping.mapping_id|default:$mapping.id|escape:'html':'UTF-8'}">
                            <input type="hidden" name="invoiceid" value="{$mapping.invoice_id|escape:'html':'UTF-8'}">
                            <button type="submit" name="delete" value="1" class="btn btn-default btn-sm">Aufheben</button>
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
