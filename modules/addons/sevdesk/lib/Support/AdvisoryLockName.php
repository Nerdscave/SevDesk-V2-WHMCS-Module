<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Support;

use Throwable;
use WHMCS\Database\Capsule;

/** Namespaces MySQL advisory locks per WHMCS schema on a shared database server. */
final class AdvisoryLockName
{
    public static function jobRunner(): string
    {
        try {
            $database = trim((string) Capsule::connection()->getDatabaseName());
        } catch (Throwable) {
            $database = '';
        }

        return 'whmcs_sevdesk_job_runner_' . substr(
            hash('sha256', $database !== '' ? $database : 'unknown-schema'),
            0,
            24,
        );
    }
}
