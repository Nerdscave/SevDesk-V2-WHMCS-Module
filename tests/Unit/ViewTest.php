<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use stdClass;
use WHMCS\Module\Addon\SevDesk\View;

final class ViewTest extends TestCase
{
    public function testCapsuleRowsAreRecursivelyNormalisedForSmartyDotAccess(): void
    {
        $mapping = new stdClass();
        $mapping->mapping_id = 41;
        $mapping->invoice_exists = false;
        $mapping->metadata = (object) ['state' => 'orphan'];
        $job = (object) [
            'id' => 7,
            'items' => [(object) ['status' => 'pending']],
        ];

        $normalised = View::normaliseVariables([
            'mappings' => [$mapping],
            'jobs' => [$job],
            'pagination' => ['page' => 1],
        ]);

        self::assertSame([
            'mappings' => [[
                'mapping_id' => 41,
                'invoice_exists' => false,
                'metadata' => ['state' => 'orphan'],
            ]],
            'jobs' => [[
                'id' => 7,
                'items' => [['status' => 'pending']],
            ]],
            'pagination' => ['page' => 1],
        ], $normalised);
    }

    public function testDomainObjectsAndScalarsRemainUnchanged(): void
    {
        $date = new DateTimeImmutable('2026-07-18T00:00:00+00:00');

        $normalised = View::normaliseVariables([
            'date' => $date,
            'status' => 'mapped',
            'count' => 100,
            'missing' => null,
            'nested' => ['date' => $date],
        ]);

        self::assertSame($date, $normalised['date']);
        self::assertSame('mapped', $normalised['status']);
        self::assertSame(100, $normalised['count']);
        self::assertNull($normalised['missing']);
        self::assertSame($date, $normalised['nested']['date']);
    }
}
