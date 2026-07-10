<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use PHPUnit\Framework\TestCase;

if (!defined('WHMCS')) {
    define('WHMCS', true);
}

require_once dirname(__DIR__, 2) . '/modules/addons/sevdesk/sevdesk.php';

final class SevdeskEntrypointTest extends TestCase
{
    public function testStandardWhmcsConfigurePanelExposesNoOperationalSettings(): void
    {
        $configuration = \sevdesk_config();

        self::assertArrayHasKey('fields', $configuration);
        self::assertSame([], $configuration['fields']);
        self::assertStringContainsString('Modul-Seite „Einrichtung“', (string) $configuration['description']);
    }
}
