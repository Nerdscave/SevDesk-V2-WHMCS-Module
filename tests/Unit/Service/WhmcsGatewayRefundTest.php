<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use Illuminate\Database\Capsule\Manager as IlluminateCapsule;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Config;
use WHMCS\Module\Addon\SevDesk\Service\WhmcsGateway;

#[RunClassInSeparateProcess]
#[PreserveGlobalState(false)]
final class WhmcsGatewayRefundTest extends TestCase
{
    private static ?IlluminateCapsule $database = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!class_exists(Capsule::class, false)) {
            class_alias(IlluminateCapsule::class, Capsule::class);
        }
        self::$database = new IlluminateCapsule();
        self::$database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        self::$database->setAsGlobal();

        Capsule::schema()->create('tblaccounts', static function ($table): void {
            $table->increments('id');
            $table->unsignedInteger('invoiceid');
            $table->decimal('amountin', 16, 2)->default(0);
            $table->decimal('amountout', 16, 2)->default(0);
            $table->unsignedInteger('refundid')->default(0);
            $table->text('description')->nullable();
        });
        Capsule::table('tblaccounts')->insert([
            'id' => 10,
            'invoiceid' => 42,
            'amountin' => 100,
            'amountout' => 0,
            'refundid' => 0,
            'description' => 'Synthetic payment',
        ]);
    }

    public function testRefundVerificationIsSharedAndFailClosed(): void
    {
        $gateway = new WhmcsGateway(new Config());
        $refund = (object) [
            'invoiceid' => 42,
            'amountout' => 20,
            'refundid' => 10,
            'description' => 'Customer refund',
        ];

        self::assertTrue($gateway->isVerifiedRefundTransaction($refund));

        $chargeback = clone $refund;
        $chargeback->description = 'Kartenrückbelastung';
        self::assertFalse($gateway->isVerifiedRefundTransaction($chargeback));

        $wrongInvoice = clone $refund;
        $wrongInvoice->invoiceid = 43;
        self::assertFalse($gateway->isVerifiedRefundTransaction($wrongInvoice));

        $notOutbound = clone $refund;
        $notOutbound->amountout = 0;
        self::assertFalse($gateway->isVerifiedRefundTransaction($notOutbound));
    }
}
