<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Support;

use Throwable;
use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\SevDesk\Database\Migrator;

/**
 * Bounds repeated sevdesk PDF reads for one authenticated WHMCS client.
 *
 * The limiter stores no document data and deliberately remains stricter than a
 * browser refresh loop. The database row serialises parallel sessions; the
 * session state is a fail-closed fallback while the additive table is missing.
 */
final class ClientPdfRateLimiter
{
    private const SESSION_KEY = 'sevdesk_pdf_downloads_v1';
    private const WINDOW_SECONDS = 60;
    private const MAX_REQUESTS = 8;

    /** @return array{allowed:bool,retryAfter:int} */
    public static function consume(int $clientId, ?int $now = null): array
    {
        if ($clientId < 1) {
            return ['allowed' => false, 'retryAfter' => self::WINDOW_SECONDS];
        }

        $now ??= time();
        try {
            if (Capsule::schema()->hasTable(Migrator::PDF_RATE_TABLE)) {
                return self::consumePersistent($clientId, $now);
            }
        } catch (Throwable) {
            // A session-local fallback still protects the shared API token while
            // an additive migration or transient database read is unavailable.
        }

        return self::consumeSession($clientId, $now);
    }

    /** @return array{allowed:bool,retryAfter:int} */
    private static function consumePersistent(int $clientId, int $now): array
    {
        $nowSql = date('Y-m-d H:i:s', $now);
        Capsule::table(Migrator::PDF_RATE_TABLE)->insertOrIgnore([
            'client_id' => $clientId,
            'window_started_at' => $nowSql,
            'request_count' => 0,
            'updated_at' => $nowSql,
        ]);

        return Capsule::connection()->transaction(function () use ($clientId, $now, $nowSql): array {
            $row = Capsule::table(Migrator::PDF_RATE_TABLE)
                ->where('client_id', $clientId)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                return ['allowed' => false, 'retryAfter' => self::WINDOW_SECONDS];
            }

            $windowStartedAt = strtotime((string) $row->window_started_at);
            if (
                $windowStartedAt === false
                || $windowStartedAt > $now
                || $windowStartedAt <= $now - self::WINDOW_SECONDS
            ) {
                Capsule::table(Migrator::PDF_RATE_TABLE)->where('client_id', $clientId)->update([
                    'window_started_at' => $nowSql,
                    'request_count' => 1,
                    'updated_at' => $nowSql,
                ]);

                return ['allowed' => true, 'retryAfter' => 0];
            }

            $requestCount = max(0, (int) $row->request_count);
            if ($requestCount >= self::MAX_REQUESTS) {
                return [
                    'allowed' => false,
                    'retryAfter' => max(1, $windowStartedAt + self::WINDOW_SECONDS - $now),
                ];
            }

            Capsule::table(Migrator::PDF_RATE_TABLE)->where('client_id', $clientId)->update([
                'request_count' => $requestCount + 1,
                'updated_at' => $nowSql,
            ]);

            return ['allowed' => true, 'retryAfter' => 0];
        });
    }

    /** @return array{allowed:bool,retryAfter:int} */
    private static function consumeSession(int $clientId, int $now): array
    {
        $cutoff = $now - self::WINDOW_SECONDS;
        $state = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($state)) {
            $state = [];
        }
        $timestamps = $state[$clientId] ?? [];
        if (!is_array($timestamps)) {
            $timestamps = [];
        }
        $timestamps = array_values(array_filter(
            array_map('intval', $timestamps),
            static fn (int $timestamp): bool => $timestamp > $cutoff && $timestamp <= $now,
        ));
        sort($timestamps, SORT_NUMERIC);

        if (count($timestamps) >= self::MAX_REQUESTS) {
            $state[$clientId] = $timestamps;
            $_SESSION[self::SESSION_KEY] = $state;

            return [
                'allowed' => false,
                'retryAfter' => max(1, $timestamps[0] + self::WINDOW_SECONDS - $now),
            ];
        }

        $timestamps[] = $now;
        $state[$clientId] = $timestamps;
        $_SESSION[self::SESSION_KEY] = $state;

        return ['allowed' => true, 'retryAfter' => 0];
    }
}
