<?php

declare(strict_types=1);

namespace WHMCS\Database;

use Illuminate\Database\Capsule\Manager;

/**
 * Test-only bridge for the database facade that WHMCS provides in production.
 *
 * illuminate/database is a development dependency only. The release archive
 * contains neither this bridge nor Composer's vendor directory.
 */
final class Capsule extends Manager
{
    /** @param list<mixed> $bindings @return list<object> */
    public static function select(string $query, array $bindings = []): array
    {
        return self::connection()->select($query, $bindings);
    }

    /** @param list<mixed> $bindings */
    public static function selectOne(string $query, array $bindings = []): ?object
    {
        $result = self::connection()->selectOne($query, $bindings);

        return is_object($result) ? $result : null;
    }

    public static function raw(mixed $value): \Illuminate\Contracts\Database\Query\Expression
    {
        return self::connection()->raw($value);
    }
}
