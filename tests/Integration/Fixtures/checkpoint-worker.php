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

$itemId = (int) ($argv[1] ?? 0);
$token = (string) ($argv[2] ?? '');
if ($itemId < 1 || $token === '') {
    fwrite(STDERR, "Item ID and lease token are required.\n");
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

$updated = (new JobRepository())->checkpoint(
    $itemId,
    $token,
    'invoice_payment_pending',
    ['invoicePaymentPending' => true],
);

fwrite(STDOUT, $updated ? "1\n" : "0\n");
