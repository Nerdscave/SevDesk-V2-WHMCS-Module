<?php

declare(strict_types=1);

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
require_once $root . '/tests/Integration/Support/WhmcsCapsule.php';

$host = getenv('SEVDESK_TEST_DB_HOST');
if (!is_string($host) || $host === '') {
    fwrite(STDERR, "SEVDESK_TEST_DB_HOST is required.\n");
    exit(2);
}

$capsule = new Capsule();
$capsule->addConnection([
    'driver' => 'mysql',
    'host' => $host,
    'port' => (int) (getenv('SEVDESK_TEST_DB_PORT') ?: 3306),
    'database' => getenv('SEVDESK_TEST_DB_DATABASE') ?: 'sevdesk_test',
    'username' => getenv('SEVDESK_TEST_DB_USERNAME') ?: 'sevdesk',
    'password' => getenv('SEVDESK_TEST_DB_PASSWORD') ?: 'sevdesk_test',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => false,
]);
$capsule->setAsGlobal();

$limit = max(1, min(100, (int) ($argv[1] ?? 1)));
$repository = new JobRepository();
$ids = [];
for ($claim = 0; $claim < $limit; ++$claim) {
    $item = $repository->claimNext(300);
    if ($item === null) {
        break;
    }
    $ids[] = (int) $item->id;
    usleep(2_000);
}

fwrite(STDOUT, json_encode($ids, JSON_THROW_ON_ERROR));
