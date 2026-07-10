{capture assign="jobPageTitle"}Exportjob #{$job.id}{/capture}
{assign var="jobStatusUrl" value=$job.status_url}
{if !$jobStatusUrl}{capture assign="jobStatusUrl"}{$moduleLink}&a=jobStatus&id={$job.id}{/capture}{/if}
{include file="partials/layout_top.tpl" pageTitle=$jobPageTitle pageDescription="Fortschritt und Ergebnis jeder einzelnen Rechnung."}

<section class="sd-job-hero" data-job-monitor data-status-url="{$jobStatusUrl|escape:'html':'UTF-8'}" data-refresh-interval="3000" data-terminal-statuses="completed,completed_with_errors,failed,cancelled">
    <div class="sd-job-identity">
        <p class="sd-kicker">{if $job.label}{$job.label|escape:'html':'UTF-8'}{else}{include file="partials/job_type_label.tpl" jobType=$job.type}{/if}</p>
        <div class="sd-job-title-row">
            <h2>Job <span class="sd-mono">#{$job.id|escape:'html':'UTF-8'}</span></h2>
            <span data-job-status>{include file="partials/status_badge.tpl" status=$job.status}</span>
        </div>
        <dl class="sd-meta-list">
            <div><dt>Angelegt</dt><dd>{$job.created_at|default:'—'|escape:'html':'UTF-8'}</dd></div>
            <div><dt>Gestartet</dt><dd data-job-started>{$job.started_at|default:'Noch nicht'|escape:'html':'UTF-8'}</dd></div>
            <div><dt>Beendet</dt><dd data-job-finished>{$job.finished_at|default:'Noch nicht'|escape:'html':'UTF-8'}</dd></div>
        </dl>
    </div>

    <div class="sd-job-progress-card">
        <div class="sd-progress-copy"><strong data-job-progress-label>{$job.processed_items|default:0|escape:'html':'UTF-8'} von {$job.total_items|default:0|escape:'html':'UTF-8'} verarbeitet</strong><span data-job-progress-percent>{$job.progress_percent|default:0|escape:'html':'UTF-8'} %</span></div>
        <div class="sd-progress sd-progress--large" role="progressbar" aria-label="Jobfortschritt" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{$job.progress_percent|default:0|escape:'html':'UTF-8'}" data-job-progress><span style="width: {$job.progress_percent|default:0|escape:'html':'UTF-8'}%"></span></div>
        <div class="sd-job-counts">
            <span><strong data-count-success>{$job.succeeded_items|default:0|escape:'html':'UTF-8'}</strong> erfolgreich</span>
            <span><strong data-count-skipped>{$job.skipped_items|default:0|escape:'html':'UTF-8'}</strong> übersprungen</span>
            <span><strong data-count-failed>{$job.failed_items|default:0|escape:'html':'UTF-8'}</strong> fehlerhaft</span>
            <span><strong data-count-ambiguous>{$job.ambiguous_items|default:0|escape:'html':'UTF-8'}</strong> unklar</span>
        </div>
        <p class="sd-polling-state" data-polling-state>{if $job.status === 'running' || $job.status === 'pending'}Fortschritt wird automatisch aktualisiert.{else}Letzter gespeicherter Stand.{/if}</p>
    </div>
</section>

<div class="sd-job-actions">
    <a class="btn btn-default" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobs"><i class="fas fa-arrow-left" aria-hidden="true"></i> Zur Jobliste</a>
    <a class="btn btn-default" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobCsv&amp;id={$job.id|escape:'url'}"><i class="fas fa-file-csv" aria-hidden="true"></i> CSV-Bericht</a>
    {if $job.status === 'failed' || $job.status === 'paused'}
        <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobDetail&amp;id={$job.id|escape:'url'}" data-loading-form>
            <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
            <button type="submit" name="resume" value="1" class="btn btn-primary"><i class="fas fa-play" aria-hidden="true"></i> Offene Einträge fortsetzen</button>
        </form>
    {/if}
    {if $job.failed_items || $job.ambiguous_items}
        <a class="btn btn-default" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=corrections&amp;job_id={$job.id|escape:'url'}">Klärfälle öffnen</a>
    {/if}
    {if $job.status === 'pending' || $job.status === 'running'}
        <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobDetail&amp;id={$job.id|escape:'url'}" data-confirm="Möchten Sie diesen Job wirklich pausieren? Bereits laufende API-Anfragen werden noch abgeschlossen.">
            <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
            <button type="submit" name="pause" value="1" class="btn btn-default"><i class="fas fa-pause" aria-hidden="true"></i> Pausieren</button>
        </form>
        <form method="post" action="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobDetail&amp;id={$job.id|escape:'url'}" data-confirm="Job wirklich abbrechen? Noch nicht gestartete Positionen werden beendet; bereits erfolgte Exporte bleiben erhalten.">
            <input type="hidden" name="token" value="{$csrfToken|escape:'html':'UTF-8'}">
            <button type="submit" name="cancel" value="1" class="btn btn-default"><i class="fas fa-ban" aria-hidden="true"></i> Abbrechen</button>
        </form>
    {/if}
</div>

<section class="sd-section" aria-labelledby="sd-job-items-title">
    <div class="sd-section-heading">
        <div><p class="sd-kicker">Einzelergebnisse</p><h2 id="sd-job-items-title">Rechnungen im Job</h2></div>
        <span class="sd-muted">Automatische Aktualisierung betrifft die Zusammenfassung; neue Detailzeilen erscheinen nach dem Neuladen.</span>
    </div>

    {if $items|@count}
        <div class="sd-table-wrap">
            <table class="table sd-table">
                <thead>
                <tr>
                    <th scope="col">Rechnung</th>
                    <th scope="col">Status</th>
                    <th scope="col">Versuche</th>
                    <th scope="col">sevdesk</th>
                    <th scope="col">Meldung</th>
                    <th scope="col">Aktualisiert</th>
                </tr>
                </thead>
                <tbody>
                {foreach from=$items item=item}
                    <tr>
                        <td><a href="invoices.php?action=edit&amp;id={$item.invoice_id|escape:'url'}" target="_blank" rel="noopener">{$item.invoicenum|default:'Rechnung'|escape:'html':'UTF-8'}</a><small class="sd-mono">ID {$item.invoice_id|escape:'html':'UTF-8'}</small></td>
                        <td>{include file="partials/status_badge.tpl" status=$item.status}</td>
                        <td class="sd-mono">{$item.attempts|default:0|escape:'html':'UTF-8'}</td>
                        <td>{if $item.sevdesk_id}<a class="sd-mono" href="https://my.sevdesk.de/#/ex/detail/id/{$item.sevdesk_id|escape:'url'}" target="_blank" rel="noopener">{$item.sevdesk_id|escape:'html':'UTF-8'}</a>{else}<span class="sd-muted">—</span>{/if}</td>
                        <td class="sd-message-cell">{$item.message|default:'—'|escape:'html':'UTF-8'}{if $item.error_code}<small class="sd-mono">{$item.error_code|escape:'html':'UTF-8'}</small>{/if}{if $item.error_code === 'unsupported_oss'}<small><a href="https://api.sevdesk.de/" target="_blank" rel="noopener">sevdesk-Einschränkung öffnen</a></small>{/if}</td>
                        <td>{$item.updated_at|default:'—'|escape:'html':'UTF-8'}</td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        </div>
        {include file="partials/pagination.tpl"}
    {else}
        {include file="partials/empty_state.tpl" emptyIcon="fa-hourglass-start" emptyTitle="Noch keine Einzelergebnisse" emptyText="Der Runner hat für diesen Job noch keine Rechnung verarbeitet."}
    {/if}
</section>

{include file="partials/layout_bottom.tpl"}
