<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Api;

use RuntimeException;
use Throwable;

/**
 * A deliberately small, sanitised representation of a failed sevdesk call.
 *
 * Response bodies are not retained because validation errors can contain invoice,
 * contact or document data. The whitelisted fields below are safe to persist in a
 * job item and sufficient for retry and support decisions.
 */
final class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $sevdeskCode = null,
        public readonly ?string $exceptionUuid = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $outcomeUnknown = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    public function isAuthenticationFailure(): bool
    {
        return $this->httpStatus === 401 || $this->httpStatus === 403;
    }

    public function isRateLimit(): bool
    {
        return $this->httpStatus === 429;
    }

    public function isPermanentClientFailure(): bool
    {
        return in_array($this->httpStatus, [400, 409, 422], true);
    }

    /**
     * @return array{
     *     httpStatus: int|null,
     *     sevdeskCode: string|null,
     *     exceptionUuid: string|null,
     *     retryAfterSeconds: int|null,
     *     outcomeUnknown: bool
     * }
     */
    public function context(): array
    {
        return [
            'httpStatus' => $this->httpStatus,
            'sevdeskCode' => $this->sevdeskCode,
            'exceptionUuid' => $this->exceptionUuid,
            'retryAfterSeconds' => $this->retryAfterSeconds,
            'outcomeUnknown' => $this->outcomeUnknown,
        ];
    }
}
