{if isset($sevdeskDocument) && $sevdeskDocument.authority === 'sevdesk'}
    {if $sevdeskDocument.state !== 'proforma'}
    <style>
        a[href*="dl.php?type=i"],
        a[href*="viewinvoice.php"][href*="pdf=1"] {
            display: none !important;
        }
    </style>
    {/if}
    <div class="alert {if $sevdeskDocument.state === 'ready'}alert-success{elseif $sevdeskDocument.state === 'failure'}alert-danger{else}alert-info{/if}" role="status">
        {if $sevdeskDocument.state === 'ready'}
            Ihre endgültige Rechnung {$sevdeskDocument.invoiceNumber|escape:'html':'UTF-8'} wurde in sevdesk erstellt.
            <a class="btn btn-primary btn-sm ml-2" href="{$sevdeskDocument.downloadUrl|escape:'html':'UTF-8'}">Rechnung herunterladen</a>
        {elseif $sevdeskDocument.state === 'failure'}
            Die endgültige Rechnung konnte noch nicht bereitgestellt werden. Bitte wenden Sie sich an den Support.
        {elseif $sevdeskDocument.state === 'pending'}
            Ihre Rechnung wird gerade in sevdesk erstellt und anschließend hier bereitgestellt.
        {else}
            Bis zum Zahlungseingang wird dieses Dokument als Proforma-Rechnung angezeigt.
        {/if}
    </div>
    {if isset($sevdeskRelatedDocuments) && $sevdeskRelatedDocuments|@count}
        <div class="card mt-3">
            <div class="card-body">
                <strong>Weitere Rechnungsdokumente</strong>
                <ul class="mb-0 mt-2">
                    {foreach from=$sevdeskRelatedDocuments item=related}
                        <li>
                            {if $related.role === 'reminder'}Mahnung Stufe {$related.dunningLevel|escape:'html':'UTF-8'}{elseif $related.role === 'cancellation'}Stornorechnung{else}Buchhaltungsbeleg zur Mahngebühr{/if}
                            {if $related.documentNumber} {$related.documentNumber|escape:'html':'UTF-8'}{/if}
                            {if $related.downloadUrl}<a class="ml-2" href="{$related.downloadUrl|escape:'html':'UTF-8'}">Herunterladen</a>{else}<span class="text-muted ml-2">mailfrei erfasst</span>{/if}
                        </li>
                    {/foreach}
                </ul>
            </div>
        </div>
    {/if}
{/if}
