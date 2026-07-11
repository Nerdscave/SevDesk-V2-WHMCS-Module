<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk;

use WHMCS\Module\Addon\SevDesk\Controllers\AdminController;
use WHMCS\Module\Addon\SevDesk\Support\Csrf;

final class Controller
{
    /** @param array<string, mixed> $moduleVariables */
    public function dispatch(string $action, array $moduleVariables = []): void
    {
        $routes = [
            'index' => 'index',
            'setup' => 'setup',
            'singleImport' => 'singleImport',
            'quickExport' => 'quickExport',
            'massImport' => 'massImport',
            'jobs' => 'jobs',
            'jobDetail' => 'jobDetail',
            'jobStatus' => 'jobStatus',
            'jobCsv' => 'jobCsv',
            'assignmentManager' => 'assignmentManager',
            'bookingAssistant' => 'bookingAssistant',
            'transactionSelectPaymentMethod' => 'bookingAssistant',
            'corrections' => 'corrections',
            'health' => 'health',
        ];
        $method = $routes[$action] ?? 'notFound';
        $application = Application::instance();
        $csrf = new Csrf();
        $controller = new AdminController(
            $application,
            new View($csrf),
            $csrf,
            (string) ($moduleVariables['modulelink'] ?? 'addonmodules.php?module=sevdesk'),
        );
        $controller->{$method}();
    }
}
