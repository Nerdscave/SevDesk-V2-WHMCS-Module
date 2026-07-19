<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Domain;

final class ExportResult
{
    public const SUCCEEDED = 'succeeded';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';
    public const AMBIGUOUS = 'ambiguous';

    /** @param array<string, scalar|null> $context */
    private function __construct(
        public readonly string $status,
        public readonly int $invoiceId,
        public readonly ?string $remoteId,
        public readonly string $code,
        public readonly string $message,
        public readonly array $context = [],
    ) {
    }

    /** @param array<string, scalar|null> $context */
    public static function succeeded(
        int $invoiceId,
        ?string $remoteId,
        string $code = 'exported',
        string $message = 'Invoice exported successfully.',
        array $context = [],
    ): self {
        return new self(self::SUCCEEDED, $invoiceId, $remoteId, $code, $message, $context);
    }

    public static function skipped(int $invoiceId, string $remoteId, string $code = 'already_mapped'): self
    {
        return new self(self::SKIPPED, $invoiceId, $remoteId, $code, 'Invoice already has a sevdesk mapping.');
    }

    /** @param array<string, scalar|null> $context */
    public static function failed(int $invoiceId, string $code, string $message, array $context = []): self
    {
        return new self(self::FAILED, $invoiceId, null, $code, $message, $context);
    }

    /** @param array<string, scalar|null> $context */
    public static function ambiguous(
        int $invoiceId,
        string $code,
        string $message,
        ?string $remoteId = null,
        array $context = [],
    ): self {
        return new self(self::AMBIGUOUS, $invoiceId, $remoteId, $code, $message, $context);
    }

    /**
     * @return array{
     *     status: string,
     *     invoiceId: int,
     *     remoteId: string|null,
     *     code: string,
     *     message: string,
     *     context: array<string, scalar|null>
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'invoiceId' => $this->invoiceId,
            'remoteId' => $this->remoteId,
            'code' => $this->code,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
