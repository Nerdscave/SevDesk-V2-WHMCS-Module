{include file="partials/layout_top.tpl" pageTitle="Exportjobs" pageDescription="Laufende, abgeschlossene und fehlgeschlagene Exporte dauerhaft nachvollziehen."}

<section class="sd-summary-strip sd-summary-strip--compact" aria-label="Jobstatus">
    <div class="sd-summary-item"><span>Ausstehend</span><strong>{$stats.pending|default:0|escape:'html':'UTF-8'}</strong></div>
    <div class="sd-summary-item"><span>In Arbeit</span><strong>{$stats.running|default:0|escape:'html':'UTF-8'}</strong></div>
    <div class="sd-summary-item"><span>Fehlgeschlagen</span><strong>{$stats.failed|default:0|escape:'html':'UTF-8'}</strong></div>
    <div class="sd-summary-item"><span>Unklar</span><strong>{$stats.ambiguous|default:0|escape:'html':'UTF-8'}</strong></div>
</section>

<form method="get" action="addonmodules.php" class="sd-toolbar" aria-label="Jobs filtern">
    <input type="hidden" name="module" value="sevdesk">
    <input type="hidden" name="a" value="jobs">
    <div class="sd-field sd-field--inline">
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
    <div class="sd-field sd-field--inline sd-field--grow">
        <label for="job-query">Suche</label>
        <input type="search" id="job-query" name="q" class="form-control input-sm" value="{$filters.q|escape:'html':'UTF-8'}" placeholder="Job-ID oder Jobtyp">
    </div>
    <button type="submit" class="btn btn-default btn-sm">Filtern</button>
    {if $filters.status || $filters.q}<a class="btn btn-link btn-sm" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobs">Filter zurücksetzen</a>{/if}
</form>

{if $jobs|@count}
    <div class="sd-table-wrap">
        <table class="table sd-table">
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
                    <td><span class="sd-result-inline"><span class="is-success">{$job.succeeded_items|default:0|escape:'html':'UTF-8'} erfolgreich</span><span>{$job.skipped_items|default:0|escape:'html':'UTF-8'} übersprungen</span>{if $job.failed_items}<span class="is-danger">{$job.failed_items|escape:'html':'UTF-8'} fehlerhaft</span>{/if}</span></td>
                    <td>
                        <div class="sd-progress sd-progress--small" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{$job.progress_percent|default:0|escape:'html':'UTF-8'}"><span style="width: {$job.progress_percent|default:0|escape:'html':'UTF-8'}%"></span></div>
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
