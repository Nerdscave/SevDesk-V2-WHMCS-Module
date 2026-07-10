{include file="partials/layout_top.tpl" pageTitle="Systemcheck" pageDescription="Konfiguration, Datenbestand und externe Verbindung gezielt prüfen."}

<div class="sd-health-hero">
    <div>
        <p class="sd-kicker">Gesamtzustand</p>
        <h2>{if $stats.health_status === 'healthy' || $stats.health_status === 'ok'}Bereit für den Export{elseif $stats.health_status === 'failed' || $stats.health_status === 'error'}Export derzeit blockiert{else}Einige Punkte benötigen Aufmerksamkeit{/if}</h2>
        <p>Die Einzelprüfungen zeigen, was bereits funktioniert und welche Konfiguration vor einem größeren Export korrigiert werden sollte.</p>
    </div>
    <div class="sd-health-score">
        <strong>{$stats.healthy|default:0|escape:'html':'UTF-8'}</strong>
        <span>von {$healthChecks|@count|escape:'html':'UTF-8'} Prüfungen in Ordnung</span>
        {include file="partials/status_badge.tpl" status=$stats.health_status|default:'warning'}
    </div>
</div>

<div class="sd-health-grid">
    {foreach from=$healthChecks item=check}
        {assign var="checkStatus" value=$check.status}
        {if !$checkStatus}
            {if $check.ok}{assign var="checkStatus" value="healthy"}{else}{assign var="checkStatus" value="failed"}{/if}
        {/if}
        <article class="sd-health-check{if $check.ok || $check.status === 'healthy' || $check.status === 'ok'} is-ok{elseif $check.status === 'warning'} is-warning{else} is-error{/if}">
            <div class="sd-health-check-icon" aria-hidden="true"><i class="fas {if $check.ok || $check.status === 'healthy' || $check.status === 'ok'}fa-check{elseif $check.status === 'warning'}fa-exclamation{else}fa-times{/if}"></i></div>
            <div class="sd-health-check-content">
                <div class="sd-health-check-heading"><h3>{$check.name|escape:'html':'UTF-8'}</h3>{include file="partials/status_badge.tpl" status=$checkStatus}</div>
                <p>{$check.message|default:$check.description|escape:'html':'UTF-8'}</p>
                {if $check.details}<pre class="sd-health-detail">{$check.details|escape:'html':'UTF-8'}</pre>{/if}
                {if $check.action_url && $check.action_label}<a class="sd-text-link" href="{$check.action_url|escape:'html':'UTF-8'}">{$check.action_label|escape:'html':'UTF-8'} <i class="fas fa-arrow-right" aria-hidden="true"></i></a>{/if}
            </div>
        </article>
    {foreachelse}
        {include file="partials/empty_state.tpl" emptyIcon="fa-stethoscope" emptyTitle="Noch keine Prüfergebnisse" emptyText="Starten Sie den Systemcheck, um API, Einstellungen und Datenbank zu validieren."}
    {/foreach}
</div>

<section class="sd-system-facts" aria-label="Laufzeitinformationen">
    <div><span>Modul</span><strong class="sd-mono">{$stats.module_version|default:'—'|escape:'html':'UTF-8'}</strong></div>
    <div><span>WHMCS</span><strong class="sd-mono">{$stats.whmcs_version|default:'—'|escape:'html':'UTF-8'}</strong></div>
    <div><span>PHP</span><strong class="sd-mono">{$stats.php_version|default:'—'|escape:'html':'UTF-8'}</strong></div>
    <div><span>sevdesk-System</span><strong class="sd-mono">{$stats.bookkeeping_version|default:'—'|escape:'html':'UTF-8'}</strong></div>
    <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=health" data-loading-form>
        <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
        <button type="submit" name="run" value="1" class="btn btn-default"><i class="fas fa-redo-alt" aria-hidden="true"></i> Erneut prüfen</button>
    </form>
</section>

{include file="partials/layout_bottom.tpl"}
