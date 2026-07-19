<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;

/** Fetches and validates the final sevdesk Invoice PDF without persisting bytes. */
final class InvoicePdf
{
    private const MAX_PDF_BYTES = 10_485_760;

    public function __construct(private readonly SevdeskClient $client)
    {
    }

    /** @return array{filename:string,contents:string,sha256:string} */
    public function fetch(string $remoteId): array
    {
        if (preg_match('/^[1-9]\d*$/', $remoteId) !== 1) {
            throw new \InvalidArgumentException('A valid sevdesk Invoice ID is required.');
        }

        $response = $this->client->getLargeJson(
            '/Invoice/' . rawurlencode($remoteId) . '/getPdf',
            ['download' => true, 'preventSendBy' => true],
        );
        if (array_is_list($response) && count($response) === 1 && is_array($response[0])) {
            $response = $response[0];
        }

        $mimeType = strtolower(trim((string) ($response['mimeType'] ?? '')));
        $encoded = $response['base64encoded'] ?? null;
        $content = $response['content'] ?? null;
        if (
            $mimeType !== 'application/pdf'
            || !in_array($encoded, [true, 1, '1', 'true'], true)
            || !is_string($content)
            || $content === ''
        ) {
            throw new \RuntimeException('sevdesk returned no supported Invoice PDF response.');
        }

        $contents = base64_decode($content, true);
        if (
            !is_string($contents)
            || $contents === ''
            || strlen($contents) > self::MAX_PDF_BYTES
            || !str_starts_with($contents, '%PDF-')
            || !str_contains(substr($contents, -2048), '%%EOF')
        ) {
            throw new \RuntimeException('The sevdesk Invoice PDF failed signature or size validation.');
        }

        return [
            'filename' => self::safeFilename((string) ($response['filename'] ?? 'invoice-' . $remoteId . '.pdf')),
            'contents' => $contents,
            'sha256' => hash('sha256', $contents),
        ];
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
