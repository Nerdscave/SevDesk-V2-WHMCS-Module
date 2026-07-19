<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Jobs;

final readonly class JobOutcome
{
    /** @param array<string, scalar|null>|null $candidate */
    private function __construct(
        public string $status,
        public string $message,
        public ?string $sevdeskId = null,
        public ?int $httpStatus = null,
        public ?string $exceptionUuid = null,
        public ?string $errorCode = null,
        public ?array $candidate = null,
        public ?int $retryAfterSeconds = null,
        public string $checkpoint = 'finished',
    ) {
    }

    /** @param array<string, scalar|null>|null $candidate */
    public static function succeeded(string $message, ?string $sevdeskId = null, ?array $candidate = null): self
    {
        return new self('succeeded', $message, $sevdeskId, candidate: $candidate);
    }

    public static function skipped(string $message, ?string $sevdeskId = null): self
    {
        return new self('skipped', $message, $sevdeskId);
    }

    public static function permanentFailure(
        string $message,
        ?int $httpStatus = null,
        ?string $exceptionUuid = null,
        ?string $errorCode = null,
        string $checkpoint = 'finished',
    ): self {
        return new self(
            'permanent_failed',
            $message,
            httpStatus: $httpStatus,
            exceptionUuid: $exceptionUuid,
            errorCode: $errorCode,
            checkpoint: $checkpoint,
        );
    }

    /** @param array<string, scalar|null>|null $candidate */
    public static function ambiguous(
        string $message,
        string $checkpoint = 'write_requested',
        ?string $sevdeskId = null,
        ?int $httpStatus = null,
        ?string $exceptionUuid = null,
        ?string $errorCode = null,
        ?array $candidate = null,
    ): self {
        return new self(
            'ambiguous',
            $message,
            $sevdeskId,
            $httpStatus,
            $exceptionUuid,
            $errorCode,
            $candidate,
            checkpoint: $checkpoint,
        );
    }

    public static function retry(
        string $message,
        int $retryAfterSeconds,
        ?int $httpStatus = null,
        ?string $exceptionUuid = null,
        ?string $errorCode = null,
        string $checkpoint = 'finished',
    ): self {
        return new self(
            'retry_wait',
            $message,
            httpStatus: $httpStatus,
            exceptionUuid: $exceptionUuid,
            errorCode: $errorCode,
            retryAfterSeconds: $retryAfterSeconds,
            checkpoint: $checkpoint,
        );
    }
}
