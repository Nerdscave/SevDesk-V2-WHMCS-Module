{if !$moduleLink}{assign var="moduleLink" value="addonmodules.php?module=sevdesk"}{/if}
{if !$activeRoute}{assign var="activeRoute" value="index"}{/if}

<link rel="stylesheet" href="../modules/addons/sevdesk/assets/css/admin.css?v=2.0.0">

<div class="sd-admin" data-module-link="{$moduleLink|escape:'html':'UTF-8'}">
    <a class="sd-skip-link" href="#sd-main">Zum Inhalt springen</a>

    <header class="sd-page-header">
        <div class="sd-page-heading">
            <p class="sd-eyebrow"><i class="fas fa-receipt" aria-hidden="true"></i> WHMCS Buchhaltung</p>
            <h1>{$pageTitle|default:'sevdesk Export'|escape:'html':'UTF-8'}</h1>
            {if $pageDescription}
                <p class="sd-page-description">{$pageDescription|escape:'html':'UTF-8'}</p>
            {/if}
        </div>
        <div class="sd-page-meta" aria-label="Modulstatus">
            <span class="sd-module-mark" aria-hidden="true">SEV</span>
            <span>
                <strong>sevdesk Export</strong>
                <small>robust und nachvollziehbar</small>
            </span>
        </div>
    </header>

    <nav class="sd-nav" aria-label="Modulnavigation">
        <a class="sd-nav-link{if $activeRoute === 'index'} is-active{/if}" href="{$moduleLink|escape:'html':'UTF-8'}"{if $activeRoute === 'index'} aria-current="page"{/if}>
            <i class="fas fa-tachometer-alt" aria-hidden="true"></i><span>Übersicht</span>
        </a>
        <a class="sd-nav-link{if $activeRoute === 'setup'} is-active{/if}" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=setup"{if $activeRoute === 'setup'} aria-current="page"{/if}>
            <i class="fas fa-sliders-h" aria-hidden="true"></i><span>Einrichtung</span>
        </a>
        <a class="sd-nav-link{if $activeRoute === 'singleImport' || $activeRoute === 'single_import'} is-active{/if}" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=singleImport"{if $activeRoute === 'singleImport' || $activeRoute === 'single_import'} aria-current="page"{/if}>
            <i class="fas fa-file-export" aria-hidden="true"></i><span>Einzelexport</span>
        </a>
        <a class="sd-nav-link{if $activeRoute === 'massImport' || $activeRoute === 'mass_import'} is-active{/if}" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=massImport"{if $activeRoute === 'massImport' || $activeRoute === 'mass_import'} aria-current="page"{/if}>
            <i class="fas fa-layer-group" aria-hidden="true"></i><span>Sammelexport</span>
        </a>
        <a class="sd-nav-link{if $activeRoute === 'jobs' || $activeRoute === 'jobDetail' || $activeRoute === 'job_detail'} is-active{/if}" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobs"{if $activeRoute === 'jobs' || $activeRoute === 'jobDetail' || $activeRoute === 'job_detail'} aria-current="page"{/if}>
            <i class="fas fa-tasks" aria-hidden="true"></i><span>Jobs</span>
        </a>
        <a class="sd-nav-link{if $activeRoute === 'assignmentManager' || $activeRoute === 'assignment_manager'} is-active{/if}" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=assignmentManager"{if $activeRoute === 'assignmentManager' || $activeRoute === 'assignment_manager'} aria-current="page"{/if}>
            <i class="fas fa-link" aria-hidden="true"></i><span>Zuordnungen</span>
        </a>
        <a class="sd-nav-link{if $activeRoute === 'bookingAssistant' || $activeRoute === 'booking_assistant'} is-active{/if}" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=bookingAssistant"{if $activeRoute === 'bookingAssistant' || $activeRoute === 'booking_assistant'} aria-current="page"{/if}>
            <i class="fas fa-money-check-alt" aria-hidden="true"></i><span>Buchungen</span>
        </a>
        <a class="sd-nav-link{if $activeRoute === 'corrections'} is-active{/if}" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=corrections"{if $activeRoute === 'corrections'} aria-current="page"{/if}>
            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i><span>Klärfälle</span>
        </a>
        <a class="sd-nav-link{if $activeRoute === 'health'} is-active{/if}" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=health"{if $activeRoute === 'health'} aria-current="page"{/if}>
            <i class="fas fa-stethoscope" aria-hidden="true"></i><span>Systemcheck</span>
        </a>
    </nav>

    {include file="partials/flash.tpl"}

    <main id="sd-main" class="sd-main" tabindex="-1">
