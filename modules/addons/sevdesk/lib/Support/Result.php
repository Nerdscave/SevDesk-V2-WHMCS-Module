<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Support;

use LogicException;

/**
 * A minimal result type for expected, item-scoped failures.
 *
 */
final class Result
{
    /**
     * @param array<string, scalar|null> $context
     */
    private function __construct(
        private readonly bool $success,
        private readonly mixed $value,
        private readonly ?string $errorCode,
        private readonly ?string $errorMessage,
        private readonly array $context,
    ) {
    }

    /**
     */
    public static function success(mixed $value): self
    {
        return new self(true, $value, null, null, []);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public static function failure(string $code, string $message, array $context = []): self
    {
        if ($code === '') {
            throw new \InvalidArgumentException('A result error code is required.');
        }

        return new self(false, null, $code, $message, $context);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function isFailure(): bool
    {
        return !$this->success;
    }

    public function value(): mixed
    {
        if (!$this->success) {
            throw new LogicException('Cannot read the value of a failed result.');
        }

        return $this->value;
    }

    public function valueOrNull(): mixed
    {
        return $this->success ? $this->value : null;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /** @return array<string, scalar|null> */
    public function context(): array
    {
        return $this->context;
    }
}
