{if $flash}
    {assign var="flashType" value=$flash.type|default:'info'}
    {assign var="alertClass" value=$flashType}
    {if $flashType === 'error'}{assign var="alertClass" value="danger"}{/if}
    <div class="alert alert-{$alertClass|escape:'html':'UTF-8'}" role="{if $flashType === 'error' || $flashType === 'danger'}alert{else}status{/if}">
        <button type="button" class="close" data-dismiss-alert aria-label="Hinweis schließen"><span aria-hidden="true">&times;</span></button>
        {if $flash.title}<strong>{$flash.title|escape:'html':'UTF-8'}</strong> {/if}{$flash.message|default:'Die Aktion wurde verarbeitet.'|escape:'html':'UTF-8'}
    </div>
{/if}
