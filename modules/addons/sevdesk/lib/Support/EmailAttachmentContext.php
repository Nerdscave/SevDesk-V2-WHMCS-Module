<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Support;

/**
 * One-request hand-off between the worker's SendEmail call and EmailPreSend.
 * PDF bytes are deliberately never persisted or copied into job payloads.
 */
final class EmailAttachmentContext
{
    private const MAX_PDF_BYTES = 10_485_760;

    /**
     * @var array<string, array{invoiceId:int,template:string,filename:string,contents:string}>
     */
    private static array $contexts = [];

    public static function register(
        int $invoiceId,
        string $template,
        string $filename,
        #[\SensitiveParameter]
        string $contents,
    ): string {
        $template = trim($template);
        if ($invoiceId < 1 || $template === '') {
            throw new \InvalidArgumentException('Invoice and email template are required.');
        }
        if (!str_starts_with($contents, '%PDF-') || strlen($contents) > self::MAX_PDF_BYTES) {
            throw new \InvalidArgumentException('The email attachment is not a supported PDF.');
        }

        $filename = self::safeFilename($filename);
        $token = bin2hex(random_bytes(32));
        self::$contexts[$token] = [
            'invoiceId' => $invoiceId,
            'template' => $template,
            'filename' => $filename,
            'contents' => $contents,
        ];

        return $token;
    }

    /** @return array{filename:string,data:string}|null */
    public static function consume(string $token, int $invoiceId, string $template): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }

        $context = self::$contexts[$token] ?? null;
        unset(self::$contexts[$token]);
        if (
            $context === null
            || $context['invoiceId'] !== $invoiceId
            || !hash_equals($context['template'], trim($template))
        ) {
            return null;
        }

        return ['filename' => $context['filename'], 'data' => $context['contents']];
    }

    public static function discard(string $token): void
    {
        unset(self::$contexts[$token]);
    }

    public static function hasActiveContext(int $invoiceId, string $template): bool
    {
        foreach (self::$contexts as $context) {
            if ($context['invoiceId'] === $invoiceId && hash_equals($context['template'], trim($template))) {
                return true;
            }
        }

        return false;
    }

    private static function safeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));
        $filename = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
        if ($filename === '' || !str_ends_with(strtolower($filename), '.pdf')) {
            return 'invoice.pdf';
        }

        return substr($filename, 0, 120);
    }
}
