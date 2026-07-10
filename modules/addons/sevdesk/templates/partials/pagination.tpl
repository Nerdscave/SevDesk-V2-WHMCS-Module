{if $pagination && $pagination.total_pages > 1}
    <nav class="sd-pagination-wrap" aria-label="Seitennavigation">
        <p>Seite {$pagination.page|default:1|escape:'html':'UTF-8'} von {$pagination.total_pages|escape:'html':'UTF-8'}</p>
        <ul class="pagination pagination-sm">
            <li{if !$pagination.previous_url} class="disabled"{/if}>
                {if $pagination.previous_url}
                    <a href="{$pagination.previous_url|escape:'html':'UTF-8'}" rel="prev"><span aria-hidden="true">&lsaquo;</span> Zurück</a>
                {else}
                    <span><span aria-hidden="true">&lsaquo;</span> Zurück</span>
                {/if}
            </li>
            <li class="active"><span>{$pagination.page|default:1|escape:'html':'UTF-8'}</span></li>
            <li{if !$pagination.next_url} class="disabled"{/if}>
                {if $pagination.next_url}
                    <a href="{$pagination.next_url|escape:'html':'UTF-8'}" rel="next">Weiter <span aria-hidden="true">&rsaquo;</span></a>
                {else}
                    <span>Weiter <span aria-hidden="true">&rsaquo;</span></span>
                {/if}
            </li>
        </ul>
    </nav>
{/if}
