<div class="sd-empty-state">
    <span class="sd-empty-icon" aria-hidden="true"><i class="fas {$emptyIcon|default:'fa-inbox'}"></i></span>
    <h3>{$emptyTitle|default:'Keine Einträge vorhanden'|escape:'html':'UTF-8'}</h3>
    <p>{$emptyText|default:'Für die aktuelle Auswahl wurden keine Daten gefunden.'|escape:'html':'UTF-8'}</p>
    {if $emptyActionUrl && $emptyActionLabel}
        <a class="btn btn-default" href="{$emptyActionUrl|escape:'html':'UTF-8'}">{$emptyActionLabel|escape:'html':'UTF-8'}</a>
    {/if}
</div>
