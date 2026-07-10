{assign var="statusKey" value=$status|default:'unknown'}
<span class="sd-status sd-status--{$statusKey|escape:'html':'UTF-8'}">
    <span class="sd-status-dot" aria-hidden="true"></span>
    {if $statusKey === 'pending' || $statusKey === 'queued'}Ausstehend
    {elseif $statusKey === 'running' || $statusKey === 'processing'}In Arbeit
    {elseif $statusKey === 'completed' || $statusKey === 'succeeded' || $statusKey === 'success'}Erfolgreich
    {elseif $statusKey === 'skipped'}Übersprungen
    {elseif $statusKey === 'retryable_failed' || $statusKey === 'retrying' || $statusKey === 'retry_wait'}Wiederholung geplant
    {elseif $statusKey === 'failed' || $statusKey === 'permanent_failed' || $statusKey === 'error'}Fehlgeschlagen
    {elseif $statusKey === 'ambiguous'}Unklar
    {elseif $statusKey === 'completed_with_errors'}Abgeschlossen mit Klärfällen
    {elseif $statusKey === 'cancelled' || $statusKey === 'canceled'}Abgebrochen
    {elseif $statusKey === 'paused'}Pausiert
    {elseif $statusKey === 'mapped'}Zugeordnet
    {elseif $statusKey === 'ready'}Eindeutig
    {elseif $statusKey === 'blocked'}Blockiert
    {elseif $statusKey === 'unmapped'}Nicht zugeordnet
    {elseif $statusKey === 'healthy' || $statusKey === 'ok'}In Ordnung
    {elseif $statusKey === 'warning'}Prüfen
    {else}{$statusKey|escape:'html':'UTF-8'}{/if}
</span>
