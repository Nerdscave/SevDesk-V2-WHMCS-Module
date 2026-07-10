{include file="partials/layout_top.tpl" pageTitle="Anfrage nicht möglich" pageDescription="Die Aktion konnte nicht vollständig verarbeitet werden."}

<section class="sd-error-state" role="alert">
    <div class="sd-error-symbol" aria-hidden="true"><i class="fas fa-exclamation-circle"></i></div>
    <div>
        <p class="sd-kicker">Fehler</p>
        <h2>{$error.title|default:'Es ist ein unerwarteter Fehler aufgetreten.'|escape:'html':'UTF-8'}</h2>
        <p>{$error.message|default:$message|default:'Bitte versuchen Sie es erneut. Bleibt der Fehler bestehen, prüfen Sie den Systemcheck und den WHMCS-Modul-Log.'|escape:'html':'UTF-8'}</p>
        {if $error.reference}<p class="sd-error-reference">Referenz: <code>{$error.reference|escape:'html':'UTF-8'}</code></p>{/if}
        <div class="sd-action-row">
            <a class="btn btn-primary" href="{$moduleLink|escape:'html':'UTF-8'}">Zur Übersicht</a>
            <a class="btn btn-default" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=health">Systemcheck öffnen</a>
            <button type="button" class="btn btn-link" data-history-back>Zurück</button>
        </div>
    </div>
</section>

{include file="partials/layout_bottom.tpl"}
