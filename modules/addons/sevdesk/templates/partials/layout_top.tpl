{if !$moduleLink}{assign var="moduleLink" value="addonmodules.php?module=sevdesk"}{/if}
{if !$activeRoute}{assign var="activeRoute" value="index"}{/if}

<div class="sd-admin" data-module-link="{$moduleLink|escape:'html':'UTF-8'}">
    <a class="sd-skip-link" href="#sd-main">Zum Inhalt springen</a>

    <nav aria-label="Modulnavigation">
        <ul class="nav nav-tabs sd-nav-tabs" role="list">
            <li class="sd-nav-item{if $activeRoute === 'index'} active{/if}">
                <a class="sd-nav-link" href="{$moduleLink|escape:'html':'UTF-8'}"{if $activeRoute === 'index'} aria-current="page"{/if}>
                    <i class="fas fa-tachometer-alt" aria-hidden="true"></i><span>Übersicht</span>
                </a>
            </li>
            <li class="sd-nav-item{if $activeRoute === 'setup'} active{/if}">
                <a class="sd-nav-link" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=setup"{if $activeRoute === 'setup'} aria-current="page"{/if}>
                    <i class="fas fa-sliders-h" aria-hidden="true"></i><span>Einrichtung</span>
                </a>
            </li>
            <li class="sd-nav-item{if $activeRoute === 'singleImport' || $activeRoute === 'single_import'} active{/if}">
                <a class="sd-nav-link" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=singleImport"{if $activeRoute === 'singleImport' || $activeRoute === 'single_import'} aria-current="page"{/if}>
                    <i class="fas fa-file-export" aria-hidden="true"></i><span>Einzelexport</span>
                </a>
            </li>
            <li class="sd-nav-item{if $activeRoute === 'massImport' || $activeRoute === 'mass_import'} active{/if}">
                <a class="sd-nav-link" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=massImport"{if $activeRoute === 'massImport' || $activeRoute === 'mass_import'} aria-current="page"{/if}>
                    <i class="fas fa-layer-group" aria-hidden="true"></i><span>Sammelexport</span>
                </a>
            </li>
            <li class="sd-nav-item{if $activeRoute === 'jobs' || $activeRoute === 'jobDetail' || $activeRoute === 'job_detail'} active{/if}">
                <a class="sd-nav-link" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=jobs"{if $activeRoute === 'jobs' || $activeRoute === 'jobDetail' || $activeRoute === 'job_detail'} aria-current="page"{/if}>
                    <i class="fas fa-tasks" aria-hidden="true"></i><span>Jobs</span>
                </a>
            </li>
            <li class="sd-nav-item{if $activeRoute === 'assignmentManager' || $activeRoute === 'assignment_manager'} active{/if}">
                <a class="sd-nav-link" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=assignmentManager"{if $activeRoute === 'assignmentManager' || $activeRoute === 'assignment_manager'} aria-current="page"{/if}>
                    <i class="fas fa-link" aria-hidden="true"></i><span>Zuordnungen</span>
                </a>
            </li>
            <li class="sd-nav-item{if $activeRoute === 'bookingAssistant' || $activeRoute === 'booking_assistant'} active{/if}">
                <a class="sd-nav-link" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=bookingAssistant"{if $activeRoute === 'bookingAssistant' || $activeRoute === 'booking_assistant'} aria-current="page"{/if}>
                    <i class="fas fa-money-check-alt" aria-hidden="true"></i><span>Buchungen</span>
                </a>
            </li>
            <li class="sd-nav-item{if $activeRoute === 'corrections'} active{/if}">
                <a class="sd-nav-link" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=corrections"{if $activeRoute === 'corrections'} aria-current="page"{/if}>
                    <i class="fas fa-exclamation-triangle" aria-hidden="true"></i><span>Klärfälle</span>
                </a>
            </li>
            <li class="sd-nav-item{if $activeRoute === 'health'} active{/if}">
                <a class="sd-nav-link" href="{$moduleLink|escape:'html':'UTF-8'}&amp;a=health"{if $activeRoute === 'health'} aria-current="page"{/if}>
                    <i class="fas fa-stethoscope" aria-hidden="true"></i><span>Systemcheck</span>
                </a>
            </li>
        </ul>
    </nav>

    {include file="partials/flash.tpl"}

    <main id="sd-main" class="sd-main" tabindex="-1">
        <h2 class="sd-page-title">{$pageTitle|default:'sevdesk Export'|escape:'html':'UTF-8'}</h2>
