{include file="partials/layout_top.tpl" pageTitle="Exportjobs"}

<div class="row sd-stat-row" aria-label="Jobstatus">
    <div class="col-sm-3">
        <div class="panel panel-default">
            <div class="panel-body"><span class="sd-stat-value">{$stats.pending|default:0|escape:'html':'UTF-8'}</span><span class="text-muted small">Ausstehend</span></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="panel panel-default">
            <div class="panel-body"><span class="sd-stat-value">{$stats.running|default:0|escape:'html':'UTF-8'}</span><span class="text-muted small">In Arbeit</span></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="panel {if $stats.failed}panel-danger{else}panel-default{/if}">
            <div class="panel-body"><span class="sd-stat-value">{$stats.failed|default:0|escape:'html':'UTF-8'}</span><span class="text-muted small">Fehlgeschlagen</span></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="panel {if $stats.ambiguous}panel-warning{else}panel-default{/if}">
            <div class="panel-body"><span class="sd-stat-value">{$stats.ambiguous|default:0|escape:'html':'UTF-8'}</span><span class="text-muted small">Unklar</span></div>
        </div>
    </div>
</div>

<div class="well well-sm">
    <form method="get" action="addonmodules.php" class="form-inline sd-toolbar" aria-label="Jobs filtern">
        <input type="hidden" name="module" value="sevdesk">
        <input type="hidden" name="a" value="jobs">
        <div class="form-group">
            <label for="job-status-filter">Status</label>
            <select id="job-status-filter" name="status" class="form-control input-sm">
                <option value="">Alle Status</option>
                <option value="pending"{if $filters.status === 'pending'} selected{/if}>Ausstehend</option>
                <option value="running"{if $filters.status === 'running'} selected{/if}>In Arbeit</option>
                <option value="completed"{if $filters.status === 'completed'} selected{/if}>Abgeschlossen</option>
                <option value="completed_with_errors"{if $filters.status === 'completed_with_errors'} selected{/if}>Abgeschlossen mit Klärfällen</option>
                <option value="cancelled"{if $filters.status === 'cancelled'} selected{/if}>Abgebrochen</option>
            </select>
        </div>
        <div class="form-group">
            <label for="job-query">Suche</label>
            <input type="search" id="job-query" name="q" class="form-control input-sm" value="{$filters.q|escape:'html':'UTF-8'}" placeholder="Job-ID oder Jobtyp">
        </div>
        <button type="submit" class="btn btn-default btn-sm">Filtern</button>
        {if $filters.status || $filters.q}<a class="btn btn-link btn-sm" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobs">Filter zurücksetzen</a>{/if}
    </form>
</div>

{if $jobs|@count}
    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
            <tr>
                <th scope="col">Job</th>
                <th scope="col">Angelegt</th>
                <th scope="col">Umfang</th>
                <th scope="col">Ergebnis</th>
                <th scope="col">Fortschritt</th>
                <th scope="col">Status</th>
                <th scope="col" class="sd-table-action"><span class="sr-only">Aktion</span></th>
            </tr>
            </thead>
            <tbody>
            {foreach from=$jobs item=job}
                <tr>
                    <td><a class="sd-mono" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobDetail&amp;id={$job.id|escape:'url'}">#{$job.id|escape:'html':'UTF-8'}</a><small>{if $job.label}{$job.label|escape:'html':'UTF-8'}{else}{include file="partials/job_type_label.tpl" jobType=$job.type}{/if}</small></td>
                    <td>{$job.created_at|default:'—'|escape:'html':'UTF-8'}{if $job.created_by}<small>{$job.created_by|escape:'html':'UTF-8'}</small>{/if}</td>
                    <td><strong>{$job.total_items|default:0|escape:'html':'UTF-8'}</strong> Rechnungen</td>
                    <td><span class="text-success">{$job.succeeded_items|default:0|escape:'html':'UTF-8'} erfolgreich</span> · {$job.skipped_items|default:0|escape:'html':'UTF-8'} übersprungen{if $job.failed_items} · <span class="text-danger">{$job.failed_items|escape:'html':'UTF-8'} fehlerhaft</span>{/if}</td>
                    <td>
                        <div class="progress sd-progress-sm">
                            <div class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{$job.progress_percent|default:0|escape:'html':'UTF-8'}" style="width: {$job.progress_percent|default:0|escape:'html':'UTF-8'}%"></div>
                        </div>
                        <small>{$job.processed_items|default:0|escape:'html':'UTF-8'} / {$job.total_items|default:0|escape:'html':'UTF-8'}</small>
                    </td>
                    <td>{include file="partials/status_badge.tpl" status=$job.status}</td>
                    <td class="sd-table-action"><a class="btn btn-default btn-sm" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobDetail&amp;id={$job.id|escape:'url'}">Öffnen</a></td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    </div>
    {include file="partials/pagination.tpl"}
{else}
    {capture assign="massImportUrl"}{$moduleLink}&a=massImport{/capture}
    {include file="partials/empty_state.tpl" emptyIcon="fa-tasks" emptyTitle="Keine Jobs gefunden" emptyText="Für diesen Filter gibt es keine Exportjobs." emptyActionUrl=$massImportUrl emptyActionLabel="Sammelexport vorbereiten"}
{/if}

{include file="partials/layout_bottom.tpl"}
