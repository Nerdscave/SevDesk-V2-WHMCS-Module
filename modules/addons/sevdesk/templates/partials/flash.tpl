{if $flash}
    {assign var="flashType" value=$flash.type|default:'info'}
    <div class="sd-alert sd-alert--{$flashType|escape:'html':'UTF-8'}" role="{if $flashType === 'error' || $flashType === 'danger'}alert{else}status{/if}">
        <i class="fas {if $flashType === 'success'}fa-check-circle{elseif $flashType === 'warning'}fa-exclamation-triangle{elseif $flashType === 'error' || $flashType === 'danger'}fa-times-circle{else}fa-info-circle{/if}" aria-hidden="true"></i>
        <div>
            {if $flash.title}<strong>{$flash.title|escape:'html':'UTF-8'}</strong>{/if}
            <p>{$flash.message|default:'Die Aktion wurde verarbeitet.'|escape:'html':'UTF-8'}</p>
        </div>
        <button type="button" class="sd-alert-close" data-dismiss-alert aria-label="Hinweis schließen">
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>
    </div>
{/if}
