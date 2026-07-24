<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use DateTimeImmutable;
use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Config;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class ConfigSmallBusinessPeriodTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!class_exists(Capsule::class, false)) {
            class_alias(IlluminateCapsule::class, Capsule::class);
        }
        $database = new IlluminateCapsule();
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $database->setAsGlobal();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Capsule::schema()->dropIfExists('tbladdonmodules');
        Capsule::schema()->create('tbladdonmodules', static function ($table): void {
            $table->increments('id');
            $table->string('module', 64);
            $table->string('setting', 191);
            $table->text('value')->nullable();
            $table->unique(['module', 'setting']);
        });
    }

    public function testDisabledProfileNeverApplies(): void
    {
        $config = new Config();
        $config->set('smallBusinessOwner', false);
        $config->set('small_business_until', '31-02-2025');

        self::assertFalse($config->smallBusinessAppliesOn(new DateTimeImmutable('2025-06-01')));
    }

    public function testBlankCutoffPreservesLegacyUnlimitedBehavior(): void
    {
        $config = new Config();
        $config->set('smallBusinessOwner', true);
        $config->set('small_business_until', '');

        self::assertTrue($config->smallBusinessAppliesOn(new DateTimeImmutable('2025-12-31')));
        self::assertTrue($config->smallBusinessAppliesOn(new DateTimeImmutable('2026-01-01')));
    }

    public function testCutoffIncludes2025AndExcludes2026(): void
    {
        $config = new Config();
        $config->set('smallBusinessOwner', true);
        $config->set('small_business_until', '31-12-2025');

        self::assertTrue($config->smallBusinessAppliesOn(new DateTimeImmutable('2025-12-31 23:59:59')));
        self::assertFalse($config->smallBusinessAppliesOn(new DateTimeImmutable('2026-01-01')));
    }

    public function testInvalidStoredCutoffFailsClosed(): void
    {
        $config = new Config();
        $config->set('smallBusinessOwner', true);
        $config->set('small_business_until', '31-02-2025');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Kleinunternehmer-Stichtag');
        $config->smallBusinessAppliesOn(new DateTimeImmutable('2025-01-01'));
    }
}
