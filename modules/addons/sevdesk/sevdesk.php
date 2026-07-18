<?php

declare(strict_types=1);

use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Controller;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;

if (!defined('WHMCS')) {
    http_response_code(403);
    exit('Direct access is not allowed.');
}

require_once __DIR__ . '/lib/Autoloader.php';

/** @return array<string, mixed> */
function sevdesk_config(): array
{
    return [
        'name' => 'sevdesk Integration',
        'description' => 'Fortsetzbarer WHMCS→sevdesk Voucher-Export. '
            . 'Die betriebliche Konfiguration erfolgt ausschließlich auf der Modul-Seite „Einrichtung“.',
        'version' => '2.0.0',
        'author' => 'Nerdscave',
        'language' => 'german',
        // WHMCS persists fields declared here without passing through the guarded
        // setup controller. Existing tbladdonmodules rows remain readable, but
        // all future operational changes must use the locked setup workflow.
        'fields' => [],
    ];
}

/** @return array{status:string,description:string} */
function sevdesk_activate(): array
{
    try {
        Migrator::up();
        $config = new Config();
        $config->set('sync_enabled', '');
        $config->set('module_active', 'on');

        return [
            'status' => 'success',
            'description' => 'Schema installiert. Die Synchronisation bleibt bis zur Einrichtung und Canary-Prüfung deaktiviert.',
        ];
    } catch (Throwable $error) {
        return [
            'status' => 'error',
            'description' => 'Die sevdesk-Tabellen konnten nicht sicher vorbereitet werden: ' . $error->getMessage(),
        ];
    }
}

/** @param array<string, mixed> $vars */
function sevdesk_upgrade(array $vars): void
{
    Migrator::up();
    $previousVersion = (string) ($vars['version'] ?? '0.0.0');
    if (version_compare($previousVersion, '2.0.0', '<')) {
        $config = new Config();
        $config->set('sync_enabled', '');
        $config->set('module_active', 'on');
        $config->set('upgraded_from_version', $previousVersion);
    }
}

/** @return array{status:string,description:string} */
function sevdesk_deactivate(): array
{
    $config = new Config();
    $config->set('sync_enabled', '');
    $config->set('module_active', '');

    return [
        'status' => 'success',
        'description' => 'Hooks wurden deaktiviert. Zuordnungen, Jobs und Prüfdaten bleiben vollständig erhalten.',
    ];
}

/** @param array<string, mixed> $vars */
function sevdesk_sidebar(array $vars): string
{
    $moduleLink = htmlspecialchars((string) ($vars['modulelink'] ?? 'addonmodules.php?module=sevdesk'), ENT_QUOTES, 'UTF-8');
    $items = [
        '' => 'Übersicht',
        'setup' => 'Einrichtung',
        'singleImport' => 'Einzelexport',
        'massImport' => 'Sammelexport',
        'jobs' => 'Jobs & Klärfälle',
        'assignmentManager' => 'Zuordnungen',
        'bookingAssistant' => 'Buchungsassistent',
        'corrections' => 'Korrektur-Voucher',
        'health' => 'Systemzustand',
    ];
    $links = '';
    foreach ($items as $action => $label) {
        $href = $moduleLink . ($action === '' ? '' : '&amp;a=' . rawurlencode($action));
        $links .= '<li><a href="' . $href . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a></li>';
    }

    return '<div class="sidebar-header"><i class="fas fa-file-invoice"></i> sevdesk Modul</div>'
        . '<ul class="menu">' . $links . '</ul>';
}

/** @param array<string, mixed> $vars */
function sevdesk_output(array $vars): void
{
    try {
        Migrator::up();
        (new Controller())->dispatch((string) ($_GET['a'] ?? 'index'), $vars);
    } catch (Throwable $error) {
        $reference = substr(hash('sha256', get_class($error) . '|' . microtime(true)), 0, 12);
        if (function_exists('logActivity')) {
            logActivity('sevdesk admin output failed [' . $reference . ']: ' . get_class($error));
        }
        echo '<div class="alert alert-danger"><strong>sevdesk-Modulfehler:</strong> Die Aktion konnte sicher nicht abgeschlossen werden. '
            . 'Referenz: ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
}
