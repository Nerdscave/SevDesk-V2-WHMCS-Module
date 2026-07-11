{include file="partials/layout_top.tpl" pageTitle="Systemcheck"}

<p>
    <strong>{$stats.healthy|default:0|escape:'html':'UTF-8'} von {$healthChecks|@count|escape:'html':'UTF-8'} Prüfungen in Ordnung.</strong>
    {include file="partials/status_badge.tpl" status=$stats.health_status|default:'warning'}
</p>

{if $healthChecks|@count}
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th scope="col">Status</th>
                    <th scope="col">Prüfung</th>
                    <th scope="col">Details</th>
                </tr>
            </thead>
            <tbody>
            {foreach from=$healthChecks item=check}
                {assign var="checkStatus" value=$check.status}
                {if !$checkStatus}
                    {if $check.ok}{assign var="checkStatus" value="healthy"}{else}{assign var="checkStatus" value="failed"}{/if}
                {/if}
                <tr>
                    <td>{include file="partials/status_badge.tpl" status=$checkStatus}</td>
                    <td><strong>{$check.name|escape:'html':'UTF-8'}</strong></td>
                    <td>
                        {$check.message|default:$check.description|escape:'html':'UTF-8'}
                        {if $check.details}<pre class="sd-health-detail">{$check.details|escape:'html':'UTF-8'}</pre>{/if}
                        {if $check.action_url && $check.action_label}<br><a href="{$check.action_url|escape:'html':'UTF-8'}">{$check.action_label|escape:'html':'UTF-8'}</a>{/if}
                    </td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    </div>
{else}
    {include file="partials/empty_state.tpl" emptyIcon="fa-stethoscope" emptyTitle="Noch keine Prüfergebnisse" emptyText="Starten Sie den Systemcheck, um API, Einstellungen und Datenbank zu validieren."}
{/if}

<div class="well well-sm sd-system-facts clearfix">
    <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=health" class="pull-right" data-loading-form>
        <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
        <button type="submit" name="run" value="1" class="btn btn-default btn-sm"><i class="fas fa-redo-alt" aria-hidden="true"></i> Erneut prüfen</button>
    </form>
    <span>Modul <code>{$stats.module_version|default:'—'|escape:'html':'UTF-8'}</code></span>
    <span>WHMCS <code>{$stats.whmcs_version|default:'—'|escape:'html':'UTF-8'}</code></span>
    <span>PHP <code>{$stats.php_version|default:'—'|escape:'html':'UTF-8'}</code></span>
    <span>sevdesk-System <code>{$stats.bookkeeping_version|default:'—'|escape:'html':'UTF-8'}</code></span>
</div>

{include file="partials/layout_bottom.tpl"}
