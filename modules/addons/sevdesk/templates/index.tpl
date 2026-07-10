{include file="partials/layout_top.tpl" pageTitle="Übersicht" pageDescription="Exporte, offene Klärfälle und Systemzustand auf einen Blick."}

<section class="sd-summary-strip" aria-label="Zusammenfassung">
    <div class="sd-summary-item">
        <span>Zugeordnete Rechnungen</span>
        <strong>{$stats.mapped|default:0|escape:'html':'UTF-8'}</strong>
        <small>mit sevdesk-Beleg-ID</small>
    </div>
    <div class="sd-summary-item">
        <span>Noch offen</span>
        <strong>{$stats.unmapped|default:0|escape:'html':'UTF-8'}</strong>
        <small>im gewählten Exportzeitraum</small>
    </div>
    <div class="sd-summary-item">
        <span>In Arbeit</span>
        <strong>{$stats.running|default:0|escape:'html':'UTF-8'}</strong>
        <small>{$stats.pending|default:0|escape:'html':'UTF-8'} weitere ausstehend</small>
    </div>
    <div class="sd-summary-item{if $stats.failed || $stats.ambiguous} has-attention{/if}">
        <span>Zu prüfen</span>
        <strong>{$stats.failed|default:0|escape:'html':'UTF-8'}</strong>
        <small>{$stats.ambiguous|default:0|escape:'html':'UTF-8'} mit unklarem Ergebnis</small>
    </div>
</section>

<div class="sd-dashboard-grid">
    <section class="sd-panel sd-panel--primary" aria-labelledby="sd-next-step-title">
        <div class="sd-panel-heading">
            <div>
                <p class="sd-kicker">Nächster Schritt</p>
                <h2 id="sd-next-step-title">Rechnungen kontrolliert übertragen</h2>
            </div>
            <span class="sd-panel-icon" aria-hidden="true"><i class="fas fa-exchange-alt"></i></span>
        </div>
        <p class="sd-panel-lead">Suchen Sie zuerst den gewünschten Zeitraum. Der Export läuft anschließend als fortsetzbarer Job; ein fehlerhafter Beleg hält die übrigen Rechnungen nicht auf.</p>
        <div class="sd-action-row">
            <a class="btn btn-primary" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=massImport">
                <i class="fas fa-layer-group" aria-hidden="true"></i> Sammelexport vorbereiten
            </a>
            <a class="btn btn-default" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=singleImport">
                Einzelne Rechnung exportieren
            </a>
        </div>
        <div class="sd-inline-note">
            <i class="fas fa-shield-alt" aria-hidden="true"></i>
            <span><strong>Doppelte Belege vermeiden:</strong> Vor jedem Export wird die bestehende Zuordnung geprüft.</span>
        </div>
    </section>

    <aside class="sd-panel" aria-labelledby="sd-health-title">
        <div class="sd-panel-heading sd-panel-heading--compact">
            <div>
                <p class="sd-kicker">Betriebsbereit</p>
                <h2 id="sd-health-title">Systemcheck</h2>
            </div>
            {include file="partials/status_badge.tpl" status=$stats.health_status|default:'warning'}
        </div>
        {if $healthChecks|@count}
            <ul class="sd-check-list">
                {foreach from=$healthChecks item=check}
                    <li>
                        <i class="fas {if $check.ok || $check.status === 'healthy' || $check.status === 'ok'}fa-check-circle is-ok{else}fa-exclamation-circle is-warning{/if}" aria-hidden="true"></i>
                        <span><strong>{$check.name|escape:'html':'UTF-8'}</strong>{if $check.summary}<small>{$check.summary|escape:'html':'UTF-8'}</small>{/if}</span>
                    </li>
                {/foreach}
            </ul>
        {else}
            <p class="sd-muted">Der Systemcheck wurde noch nicht ausgeführt.</p>
        {/if}
        <a class="sd-text-link" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=health">Alle Prüfungen anzeigen <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
    </aside>
</div>

<section class="sd-section" aria-labelledby="sd-recent-jobs-title">
    <div class="sd-section-heading">
        <div>
            <p class="sd-kicker">Verlauf</p>
            <h2 id="sd-recent-jobs-title">Letzte Exportjobs</h2>
        </div>
        <a class="btn btn-default btn-sm" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobs">Alle Jobs</a>
    </div>

    {if $jobs|@count}
        <div class="sd-table-wrap">
            <table class="table sd-table">
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
                            <div class="sd-progress sd-progress--small" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{$job.progress_percent|default:0|escape:'html':'UTF-8'}">
                                <span style="width: {$job.progress_percent|default:0|escape:'html':'UTF-8'}%"></span>
                            </div>
                            <small>{$job.processed_items|default:0|escape:'html':'UTF-8'} von {$job.total_items|default:0|escape:'html':'UTF-8'}</small>
                        </td>
                        <td>{include file="partials/status_badge.tpl" status=$job.status}</td>
                        <td class="sd-table-action"><a class="btn btn-default btn-sm" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobDetail&amp;id={$job.id|escape:'url'}">Details</a></td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        </div>
    {else}
        {capture assign="massImportUrl"}{$moduleLink}&a=massImport{/capture}
        {include file="partials/empty_state.tpl" emptyIcon="fa-tasks" emptyTitle="Noch keine Exportjobs" emptyText="Sobald Sie einen Einzel- oder Sammelexport starten, erscheint der Verlauf hier." emptyActionUrl=$massImportUrl emptyActionLabel="Ersten Export vorbereiten"}
    {/if}
</section>

{include file="partials/layout_bottom.tpl"}
