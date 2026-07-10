{include file="partials/layout_top.tpl" pageTitle="Übersicht" pageDescription="Rechnungen exportieren, laufende Jobs prüfen und Klärfälle im Blick behalten."}

<section class="sd-summary-strip" aria-labelledby="sd-summary-title">
    <h2 id="sd-summary-title" class="sr-only">Aktueller Verarbeitungsstand</h2>
    <div class="sd-summary-item">
        <span>Zugeordnet</span>
        <strong>{$stats.mapped|default:0|escape:'html':'UTF-8'}</strong>
        <small>Rechnungen mit sevdesk-Beleg-ID</small>
    </div>
    <div class="sd-summary-item">
        <span>Ohne Zuordnung</span>
        <strong>{$stats.unmapped|default:0|escape:'html':'UTF-8'}</strong>
        <small>Rechnungen im zulässigen Status</small>
    </div>
    <div class="sd-summary-item">
        <span>Verarbeitung</span>
        <strong>{$stats.running|default:0|escape:'html':'UTF-8'}</strong>
        <small>laufend, {$stats.pending|default:0|escape:'html':'UTF-8'} weitere ausstehend</small>
    </div>
    <div class="sd-summary-item{if $stats.failed || $stats.ambiguous} has-attention{/if}">
        <span>Fehler &amp; Klärfälle</span>
        <strong>{$stats.failed|default:0|escape:'html':'UTF-8'}</strong>
        <small>fehlgeschlagen, {$stats.ambiguous|default:0|escape:'html':'UTF-8'} mit unklarem Ergebnis</small>
    </div>
</section>

<div class="sd-dashboard-grid">
    <section class="sd-panel sd-panel--primary" aria-labelledby="sd-next-step-title">
        <header class="sd-panel-heading">
            <div>
                <p class="sd-kicker">Export starten</p>
                <h2 id="sd-next-step-title">Rechnungen sicher übertragen</h2>
            </div>
            <span class="sd-panel-icon" aria-hidden="true"><i class="fas fa-exchange-alt"></i></span>
        </header>
        <p class="sd-panel-lead">Wählen Sie eine einzelne Rechnung oder prüfen Sie einen Zeitraum vorab. Der eigentliche Export läuft anschließend im Hintergrund und wird nach einem Einzelfehler fortgesetzt.</p>
        <div class="sd-action-row">
            <a class="btn btn-primary" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=massImport">
                <i class="fas fa-layer-group" aria-hidden="true"></i> Zeitraum prüfen
            </a>
            <a class="btn btn-default" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=singleImport">
                Einzelrechnung prüfen
            </a>
        </div>
        <div class="sd-inline-note">
            <i class="fas fa-shield-alt" aria-hidden="true"></i>
            <span><strong>Vor Dubletten geschützt:</strong> Vor jedem Schreibvorgang prüft das Modul die bestehende Zuordnung. Das Schließen des Browsers unterbricht einen gestarteten Job nicht.</span>
        </div>
    </section>

    <aside class="sd-panel" aria-labelledby="sd-health-title">
        <header class="sd-panel-heading sd-panel-heading--compact">
            <div>
                <p class="sd-kicker">Systemstatus</p>
                <h2 id="sd-health-title">Systemcheck</h2>
            </div>
            {include file="partials/status_badge.tpl" status=$stats.health_status|default:'warning'}
        </header>
        {if $healthChecks|@count}
            <ul class="sd-check-list">
                {foreach from=$healthChecks item=check}
                    <li>
                        <i class="fas {if $check.ok || $check.status === 'healthy' || $check.status === 'ok'}fa-check-circle is-ok{else}fa-exclamation-circle is-warning{/if}" aria-hidden="true"></i>
                        <span>
                            <span class="sr-only">{if $check.ok || $check.status === 'healthy' || $check.status === 'ok'}In Ordnung{else}Prüfen{/if}: </span>
                            <strong>{$check.name|escape:'html':'UTF-8'}</strong>
                            {if $check.summary}<small>{$check.summary|escape:'html':'UTF-8'}</small>{/if}
                        </span>
                    </li>
                {/foreach}
            </ul>
        {else}
            <p class="sd-muted">Es liegen noch keine Prüfergebnisse vor. Öffnen Sie den Systemcheck, bevor Sie den ersten Export starten.</p>
        {/if}
        <a class="sd-text-link" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=health">Vollständigen Systemcheck öffnen <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
    </aside>
</div>

<section class="sd-section" aria-labelledby="sd-recent-jobs-title">
    <header class="sd-section-heading">
        <div>
            <p class="sd-kicker">Verlauf</p>
            <h2 id="sd-recent-jobs-title">Zuletzt gestartete Jobs</h2>
        </div>
        <a class="btn btn-default btn-sm" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobs">Alle Jobs</a>
    </header>

    {if $jobs|@count}
        <div class="sd-table-wrap">
            <table class="table sd-table">
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
                            <div class="sd-progress sd-progress--small" role="progressbar" aria-label="Fortschritt von Job {$job.id|escape:'html':'UTF-8'}" aria-describedby="sd-job-progress-{$job.id|escape:'html':'UTF-8'}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{$job.progress_percent|default:0|escape:'html':'UTF-8'}">
                                <span style="width: {$job.progress_percent|default:0|escape:'html':'UTF-8'}%"></span>
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
        {capture assign="massImportUrl"}{$moduleLink}&a=massImport{/capture}
        {include file="partials/empty_state.tpl" emptyIcon="fa-tasks" emptyTitle="Noch keine Jobs vorhanden" emptyText="Prüfen Sie zunächst einen Zeitraum. Nach dem Start sehen Sie hier Fortschritt und Ergebnis – auch wenn Sie diese Seite zwischenzeitlich schließen." emptyActionUrl=$massImportUrl emptyActionLabel="Zeitraum prüfen"}
    {/if}
</section>

{include file="partials/layout_bottom.tpl"}
