<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Support\AdminAssets;

final class AdminAssetsTest extends TestCase
{
    public function testStylesheetIsAvailableAsInlineAdminMarkup(): void
    {
        $markup = AdminAssets::stylesheetMarkup();

        self::assertStringStartsWith('<style id="sevdesk-admin-styles">', $markup);
        self::assertStringContainsString('.sd-admin {', $markup);
        self::assertSame(1, substr_count(strtolower($markup), '</style>'));
        self::assertStringEndsWith('</style>', $markup);
    }

    public function testScriptIsAvailableAsInlineAdminMarkup(): void
    {
        $markup = AdminAssets::scriptMarkup();

        self::assertStringStartsWith('<script id="sevdesk-admin-script">', $markup);
        self::assertStringContainsString("document.querySelector('.sd-admin')", $markup);
        self::assertSame(1, substr_count(strtolower($markup), '</script>'));
        self::assertStringEndsWith('</script>', $markup);
    }

    public function testLayoutsDoNotDependOnPublicModuleAssetUrls(): void
    {
        $moduleRoot = dirname(__DIR__, 3) . '/modules/addons/sevdesk';
        $top = file_get_contents($moduleRoot . '/templates/partials/layout_top.tpl');
        $bottom = file_get_contents($moduleRoot . '/templates/partials/layout_bottom.tpl');

        self::assertIsString($top);
        self::assertIsString($bottom);
        self::assertStringNotContainsString('/modules/addons/sevdesk/assets', $top . $bottom);
        self::assertStringNotContainsString('<link rel="stylesheet"', $top);
        self::assertStringNotContainsString('<script src=', $bottom);
    }

    public function testWhmcsOutputHooksDeliverAssetsOnlyOnTheModulePage(): void
    {
        $hooks = file_get_contents(dirname(__DIR__, 3) . '/modules/addons/sevdesk/hooks.php');

        self::assertIsString($hooks);
        self::assertStringContainsString("add_hook('AdminAreaHeadOutput'", $hooks);
        self::assertStringContainsString("add_hook('AdminAreaFooterOutput'", $hooks);
        self::assertSame(2, substr_count($hooks, "(\$_GET['module'] ?? null) !== 'sevdesk'"));
        self::assertStringContainsString('AdminAssets::stylesheetMarkup()', $hooks);
        self::assertStringContainsString('AdminAssets::scriptMarkup()', $hooks);
    }
}
