<div class="well text-center sd-empty-state">
    <p><i class="fas {$emptyIcon|default:'fa-inbox'} fa-2x text-muted" aria-hidden="true"></i></p>
    <h4>{$emptyTitle|default:'Keine Einträge vorhanden'|escape:'html':'UTF-8'}</h4>
    <p class="text-muted">{$emptyText|default:'Für die aktuelle Auswahl wurden keine Daten gefunden.'|escape:'html':'UTF-8'}</p>
    {if $emptyActionUrl && $emptyActionLabel}
        <a class="btn btn-default" href="{$emptyActionUrl|escape:'html':'UTF-8'}">{$emptyActionLabel|escape:'html':'UTF-8'}</a>
    {/if}
</div>
