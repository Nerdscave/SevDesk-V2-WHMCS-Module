{include file="partials/layout_top.tpl" pageTitle="Anfrage nicht möglich"}

<div class="alert alert-danger" role="alert">
    <h4>{$error.title|default:'Es ist ein unerwarteter Fehler aufgetreten.'|escape:'html':'UTF-8'}</h4>
    <p>{$error.message|default:$message|default:'Bitte versuchen Sie es erneut. Bleibt der Fehler bestehen, prüfen Sie den Systemcheck und den WHMCS-Modul-Log.'|escape:'html':'UTF-8'}</p>
    {if $error.reference}<p>Referenz: <code>{$error.reference|escape:'html':'UTF-8'}</code></p>{/if}
</div>

<div class="btn-toolbar" role="group">
    <a class="btn btn-primary" href="{$moduleLink|escape:'html':'UTF-8'}">Zur Übersicht</a>
    <a class="btn btn-default" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=health">Systemcheck öffnen</a>
    <button type="button" class="btn btn-link" data-history-back>Zurück</button>
</div>

{include file="partials/layout_bottom.tpl"}
