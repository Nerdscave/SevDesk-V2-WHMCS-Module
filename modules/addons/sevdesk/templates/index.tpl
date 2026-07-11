{include file="partials/layout_top.tpl" pageTitle="Übersicht"}

<div class="row sd-stat-row" aria-label="Aktueller Verarbeitungsstand">
    <div class="col-sm-3">
        <div class="panel panel-default">
            <div class="panel-body"><span class="sd-stat-value">{$stats.mapped|default:0|escape:'html':'UTF-8'}</span><span class="text-muted small">Zugeordnet</span></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="panel panel-default">
            <div class="panel-body"><span class="sd-stat-value">{$stats.unmapped|default:0|escape:'html':'UTF-8'}</span><span class="text-muted small">Ohne Zuordnung</span></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="panel panel-default">
            <div class="panel-body"><span class="sd-stat-value">{$stats.running|default:0|escape:'html':'UTF-8'}</span><span class="text-muted small">In Verarbeitung, {$stats.pending|default:0|escape:'html':'UTF-8'} ausstehend</span></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="panel {if $stats.failed || $stats.ambiguous}panel-danger{else}panel-default{/if}">
            <div class="panel-body"><span class="sd-stat-value">{$stats.failed|default:0|escape:'html':'UTF-8'}</span><span class="text-muted small">Fehler, {$stats.ambiguous|default:0|escape:'html':'UTF-8'} unklar</span></div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-7">
        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title">Export starten</h3></div>
            <div class="panel-body">
                <a class="btn btn-primary" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=massImport">
                    <i class="fas fa-layer-group" aria-hidden="true"></i> Zeitraum prüfen
                </a>
                <a class="btn btn-default" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=singleImport">
                    Einzelrechnung prüfen
                </a>
                <p class="help-block">Der Export läuft im Hintergrund weiter und prüft vor jedem Schreibvorgang die bestehende Zuordnung.</p>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="panel panel-default">
            <div class="panel-heading clearfix">
                <span class="pull-right">{include file="partials/status_badge.tpl" status=$stats.health_status|default:'warning'}</span>
                <h3 class="panel-title">Systemcheck</h3>
            </div>
            <div class="panel-body">
                {if $healthChecks|@count}
                    <ul class="list-unstyled">
                        {foreach from=$healthChecks item=check}
                            <li>
                                <i class="fas {if $check.ok || $check.status === 'healthy' || $check.status === 'ok'}fa-check-circle text-success{else}fa-exclamation-circle text-warning{/if}" aria-hidden="true"></i>
                                <span class="sr-only">{if $check.ok || $check.status === 'healthy' || $check.status === 'ok'}In Ordnung{else}Prüfen{/if}: </span>
                                <strong>{$check.name|escape:'html':'UTF-8'}</strong>
                                {if $check.summary}<small class="text-muted">{$check.summary|escape:'html':'UTF-8'}</small>{/if}
                            </li>
                        {/foreach}
                    </ul>
                {else}
                    <p class="text-muted">Es liegen noch keine Prüfergebnisse vor.</p>
                {/if}
                <a href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=health">Vollständigen Systemcheck öffnen</a>
            </div>
        </div>
    </div>
</div>

<div class="panel panel-default">
    <div class="panel-heading clearfix">
        <a class="btn btn-default btn-sm pull-right" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobs">Alle Jobs</a>
        <h3 class="panel-title">Zuletzt gestartete Jobs</h3>
    </div>
    {if $jobs|@count}
        <div class="table-responsive">
            <table class="table table-striped">
                <caption class="sr-only">Die zehn zuletzt gestarteten Export-, Buchungs- und Korrekturjobs</caption>
                <thead>
                <tr>
                    <th scope="col">Job</th>
                    <th scope="col">Zeitraum / Anlass</th>
                    <th scope="col">Fortschritt</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="sd-table-action"><span class="sr-only">Aktion</span></th>
                </tr>
                </thead>
                <tbody>
                {foreach from=$jobs item=job}
                    <tr>
                        <td><span class="sd-mono">#{$job.id|escape:'html':'UTF-8'}</span><small>{$job.created_at|escape:'html':'UTF-8'}</small></td>
                        <td>{if $job.label}{$job.label|escape:'html':'UTF-8'}{else}{include file="partials/job_type_label.tpl" jobType=$job.type}{/if}</td>
                        <td>
                            <div class="progress sd-progress-sm">
                                <div class="progress-bar" role="progressbar" aria-label="Fortschritt von Job {$job.id|escape:'html':'UTF-8'}" aria-describedby="sd-job-progress-{$job.id|escape:'html':'UTF-8'}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{$job.progress_percent|default:0|escape:'html':'UTF-8'}" style="width: {$job.progress_percent|default:0|escape:'html':'UTF-8'}%"></div>
                            </div>
                            <small id="sd-job-progress-{$job.id|escape:'html':'UTF-8'}">{$job.processed_items|default:0|escape:'html':'UTF-8'} von {$job.total_items|default:0|escape:'html':'UTF-8'} verarbeitet</small>
                        </td>
                        <td>{include file="partials/status_badge.tpl" status=$job.status}</td>
                        <td class="sd-table-action"><a class="btn btn-default btn-sm" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobDetail&amp;id={$job.id|escape:'url'}">Job öffnen<span class="sr-only">: {$job.id|escape:'html':'UTF-8'}</span></a></td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        </div>
    {else}
        <div class="panel-body">
            {capture assign="massImportUrl"}{$moduleLink}&a=massImport{/capture}
            {include file="partials/empty_state.tpl" emptyIcon="fa-tasks" emptyTitle="Noch keine Jobs vorhanden" emptyText="Prüfen Sie zunächst einen Zeitraum." emptyActionUrl=$massImportUrl emptyActionLabel="Zeitraum prüfen"}
        </div>
    {/if}
</div>

{include file="partials/layout_bottom.tpl"}
