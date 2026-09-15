<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Integration\Support;

/** Captures the real View assignments without needing a licensed WHMCS installation. */
final class SetupResponseSmarty
{
    /** @var array<string, mixed> */
    public static array $variables = [];

    public function setTemplateDir(string $directory): void
    {
    }

    public function assign(string $key, mixed $value): void
    {
        self::$variables[$key] = $value;
    }

    public function display(string $template): void
    {
    }
}
