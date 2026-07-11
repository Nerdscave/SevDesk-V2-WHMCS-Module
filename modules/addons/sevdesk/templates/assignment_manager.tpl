{include file="partials/layout_top.tpl" pageTitle="Zuordnungen"}

<div class="alert alert-warning" role="status">
    Das Aufheben einer Zuordnung löscht keinen sevdesk-Beleg; ein erneuter Export kann ein Duplikat erzeugen.
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
                <option value="incomplete"{if $filters.status === 'incomplete'} selected{/if}>Ohne sevdesk-ID</option>
                <option value="orphan"{if $filters.status === 'orphan'} selected{/if}>Ohne WHMCS-Rechnung</option>
            </select>
        </div>
        <button type="submit" class="btn btn-default btn-sm">Filtern</button>
        {if $filters.q || $filters.status}<a class="btn btn-link btn-sm" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=assignmentManager">Filter zurücksetzen</a>{/if}
    </form>
</div>

{if $mappings|@count}
    <div class="table-responsive">
        <table class="table table-striped">
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
