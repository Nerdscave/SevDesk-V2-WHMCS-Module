<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Service;

use XMLReader;
use WHMCS\Module\Addon\SevDesk\Api\SevdeskClient;

/** Fetches and validates sevdesk's native ZUGFeRD XML without persisting it. */
final class InvoiceXml
{
    private const MAX_XML_BYTES = 2_000_000;

    public function __construct(private readonly SevdeskClient $client)
    {
    }

    /** @return array{contents:string,sha256:string} */
    public function fetch(string $remoteId): array
    {
        if (preg_match('/^[1-9]\d*$/', $remoteId) !== 1) {
            throw new \InvalidArgumentException('A valid sevdesk Invoice ID is required.');
        }

        $response = $this->client->get('/Invoice/' . rawurlencode($remoteId) . '/getXml');
        $xml = $response['objects'] ?? null;
        if (array_is_list($response) && count($response) === 1 && is_string($response[0])) {
            $xml = $response[0];
        }
        if (!is_string($xml)) {
            throw new \RuntimeException('sevdesk returned no supported E-Invoice XML response.');
        }

        $xml = self::withoutUtf8Bom($xml);
        if (
            $xml === ''
            || strlen($xml) > self::MAX_XML_BYTES
            || !mb_check_encoding($xml, 'UTF-8')
            || !str_starts_with(ltrim($xml), '<?xml')
            || stripos($xml, '<!DOCTYPE') !== false
            || stripos($xml, '<!ENTITY') !== false
            || !str_contains($xml, 'CrossIndustryInvoice')
            || !str_contains(substr($xml, -4096), 'CrossIndustryInvoice>')
        ) {
            throw new \RuntimeException('The sevdesk E-Invoice XML failed structural or size validation.');
        }
        self::assertWellFormedCii($xml);

        return [
            'contents' => $xml,
            'sha256' => hash('sha256', $xml),
        ];
    }

    private static function withoutUtf8Bom(string $xml): string
    {
        return str_starts_with($xml, "\xEF\xBB\xBF") ? substr($xml, 3) : $xml;
    }

    private static function assertWellFormedCii(string $xml): void
    {
        if (!class_exists(XMLReader::class)) {
            throw new \RuntimeException('PHP XMLReader is required to validate native E-Invoice XML.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader();
        $rootFound = false;
        try {
            if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
                throw new \RuntimeException('The sevdesk E-Invoice XML could not be parsed.');
            }
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $rootFound) {
                    continue;
                }
                $rootFound = true;
                if (
                    $reader->localName !== 'CrossIndustryInvoice'
                    || !str_contains($reader->namespaceURI, 'CrossIndustryInvoice')
                ) {
                    throw new \RuntimeException('The sevdesk E-Invoice XML has an unsupported root element.');
                }
            }
            if (!$rootFound || libxml_get_errors() !== []) {
                throw new \RuntimeException('The sevdesk E-Invoice XML is not well-formed.');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
