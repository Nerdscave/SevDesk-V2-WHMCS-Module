<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$rootDir = dirname(__DIR__, 4);
$bootstrap = $rootDir . '/init.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "WHMCS init.php was not found.\n");
    exit(2);
}

require_once $bootstrap;
require_once dirname(__DIR__) . '/lib/Autoloader.php';

use WHMCS\Module\Addon\SevDesk\Application;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;

try {
    $config = new Config();
    Migrator::prepareWorkerRuntime($config);
} catch (Throwable $error) {
    fwrite(STDERR, 'sevdesk worker failed safely: ' . get_class($error) . PHP_EOL);
    exit(1);
}

try {
    $application = Application::instance();
    if (
        !$application->config->bool('module_active')
        || $application->config->bool(Config::RUNTIME_REVIEW_SETTING)
        || (string) $application->config->get(Config::RUNTIME_SIGNATURE_SETTING, '')
            !== Config::RUNTIME_SIGNATURE
    ) {
        throw new RuntimeException('The sevdesk module is deactivated.');
    }

    $maxItems = max(1, min(50, (int) ($argv[1] ?? 50)));
    $maxSeconds = max(5, min(240, (int) ($argv[2] ?? 240)));
    // The runner must execute even if no pending row is visible: claimNext()
    // first recovers expired running leases left behind by a crashed worker.
    $result = $application->runner()->run($maxItems, $maxSeconds);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'sevdesk worker failed safely: ' . get_class($error) . PHP_EOL);
    exit(1);
}
