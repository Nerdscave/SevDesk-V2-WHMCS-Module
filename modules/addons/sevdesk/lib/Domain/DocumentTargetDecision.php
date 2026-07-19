<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Domain;

/** Immutable export target which can be persisted before the first remote write. */
final class DocumentTargetDecision
{
    public const DOCUMENT_VOUCHER = 'voucher';
    public const DOCUMENT_INVOICE = 'invoice';

    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $documentType,
        public readonly string $documentAuthority,
        public readonly string $exportMode,
        public readonly string $ossProfile,
        public readonly ?string $taxRuleId,
        public readonly string $code,
        public readonly string $message,
    ) {
        if ($allowed && !in_array($documentType, [self::DOCUMENT_VOUCHER, self::DOCUMENT_INVOICE], true)) {
            throw new \InvalidArgumentException('An allowed document target needs a known document type.');
        }
        if (!$allowed && $documentType !== null) {
            throw new \InvalidArgumentException('A blocked document target cannot select a document type.');
        }
    }

    public static function select(
        string $documentType,
        string $documentAuthority,
        string $exportMode,
        string $ossProfile,
        string $taxRuleId,
        string $code,
        string $message,
    ): self {
        return new self(
            true,
            $documentType,
            $documentAuthority,
            $exportMode,
            $ossProfile,
            $taxRuleId,
            $code,
            $message,
        );
    }

    public static function block(
        string $documentAuthority,
        string $exportMode,
        string $ossProfile,
        ?string $taxRuleId,
        string $code,
        string $message,
    ): self {
        return new self(
            false,
            null,
            $documentAuthority,
            $exportMode,
            $ossProfile,
            $taxRuleId,
            $code,
            $message,
        );
    }

    /**
     * @return array{
     *     allowed: bool,
     *     documentType: string|null,
     *     documentAuthority: string,
     *     exportMode: string,
     *     ossProfile: string,
     *     taxRuleId: string|null,
     *     code: string,
     *     message: string
     * }
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'documentType' => $this->documentType,
            'documentAuthority' => $this->documentAuthority,
            'exportMode' => $this->exportMode,
            'ossProfile' => $this->ossProfile,
            'taxRuleId' => $this->taxRuleId,
            'code' => $this->code,
            'message' => $this->message,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    public static function fromArray(array $snapshot): self
    {
        $allowed = $snapshot['allowed'] ?? null;
        $documentType = $snapshot['documentType'] ?? null;
        $documentAuthority = $snapshot['documentAuthority'] ?? null;
        $exportMode = $snapshot['exportMode'] ?? null;
        $ossProfile = $snapshot['ossProfile'] ?? null;
        $taxRuleId = $snapshot['taxRuleId'] ?? null;
        $code = $snapshot['code'] ?? null;
        $message = $snapshot['message'] ?? null;

        if (
            !is_bool($allowed)
            || ($documentType !== null && !is_string($documentType))
            || !is_string($documentAuthority)
            || !is_string($exportMode)
            || !is_string($ossProfile)
            || ($taxRuleId !== null && !is_string($taxRuleId))
            || !is_string($code)
            || !is_string($message)
        ) {
            throw new \InvalidArgumentException('The frozen document target is invalid.');
        }

        return new self(
            $allowed,
            $documentType,
            $documentAuthority,
            $exportMode,
            $ossProfile,
            $taxRuleId,
            $code,
            $message,
        );
    }
}
