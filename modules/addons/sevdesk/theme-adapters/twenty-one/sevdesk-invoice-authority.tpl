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
            Ihre Zahlung ist eingegangen. Die endgültige Rechnung wird gerade erstellt.
        {else}
            Bis zum Zahlungseingang wird dieses Dokument als Proforma-Rechnung angezeigt.
        {/if}
    </div>
{/if}
