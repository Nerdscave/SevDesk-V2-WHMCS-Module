<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Hand-written client for the small sevdesk API surface used by this module.
 *
 * WHMCS already ships Guzzle, so the module intentionally does not bundle another
 * HTTP stack or a generated OpenAPI client.
 */
final class SevdeskClient
{
    private const MAX_RESPONSE_BYTES = 2_097_152;

    /** Bounded allowance for base64-encoded Invoice PDFs returned as JSON. */
    private const MAX_LARGE_JSON_RESPONSE_BYTES = 15_728_640;

    /** Raw Invoice PDFs do not need the Base64 envelope allowance. */
    private const MAX_PDF_RESPONSE_BYTES = 10_485_760;

    private readonly string $baseUrl;

    private readonly string $apiToken;

    private readonly string $userAgent;

    public function __construct(
        private readonly ClientInterface $httpClient,
        #[\SensitiveParameter]
        string $apiToken,
        string $baseUrl = 'https://my.sevdesk.de/api/v1',
        string $userAgent = 'WHMCS-sevdesk/2.1.0-rc.5',
    ) {
        $apiToken = trim($apiToken);
        if ($apiToken === '') {
            throw new \InvalidArgumentException('A sevdesk API token is required.');
        }

        $baseUrl = rtrim(trim($baseUrl), '/');
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $loopback)) {
            throw new \InvalidArgumentException('The sevdesk base URL must use HTTPS (HTTP is allowed for loopback tests only).');
        }
        if (!$loopback && $host !== 'my.sevdesk.de') {
            throw new \InvalidArgumentException('The production sevdesk base URL must use my.sevdesk.de.');
        }
        $port = parse_url($baseUrl, PHP_URL_PORT);
        $path = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
        if (!$loopback && ($port !== null && $port !== 443)) {
            throw new \InvalidArgumentException('The production sevdesk base URL must use the standard HTTPS port.');
        }
        if (!$loopback && $path !== '/api/v1') {
            throw new \InvalidArgumentException('The production sevdesk base URL must end in /api/v1.');
        }
        if (
            parse_url($baseUrl, PHP_URL_USER) !== null
            || parse_url($baseUrl, PHP_URL_PASS) !== null
            || parse_url($baseUrl, PHP_URL_QUERY) !== null
            || parse_url($baseUrl, PHP_URL_FRAGMENT) !== null
        ) {
            throw new \InvalidArgumentException('The sevdesk base URL must not contain credentials, query or fragment data.');
        }

        $userAgent = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $userAgent));
        if ($userAgent === '') {
            throw new \InvalidArgumentException('A non-empty user agent is required.');
        }

        $this->apiToken = $apiToken;
        $this->baseUrl = $baseUrl;
        $this->userAgent = $userAgent;
    }

    /**
     * @param array<string, scalar|array<array-key, scalar|null>|null> $query
     * @return array<array-key, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query], false, 30.0, maxResponseBytes: self::MAX_RESPONSE_BYTES);
    }

    /**
     * Read a documented JSON resource which may contain a base64-encoded PDF.
     * The larger limit is opt-in so ordinary API responses remain capped at 2 MiB.
     *
     * @param array<string, scalar|array<array-key, scalar|null>|null> $query
     * @return array<array-key, mixed>
     */
    public function getLargeJson(string $path, array $query = []): array
    {
        return $this->request(
            'GET',
            $path,
            ['query' => $query],
            false,
            60.0,
            maxResponseBytes: self::MAX_LARGE_JSON_RESPONSE_BYTES,
        );
    }

    /**
     * sevdesk installations return getPdf either as the documented JSON/Base64
     * envelope or directly as application/pdf. Keep both contracts bounded.
     *
     * @param array<string, scalar|array<array-key, scalar|null>|null> $query
     * @return array{kind:'binary',mimeType:string,content:string}|array{kind:'json',payload:array<array-key,mixed>}
     */
    public function getPdfResource(string $path, array $query = []): array
    {
        $curlOptions = [];
        if (defined('CURLOPT_HTTP_CONTENT_DECODING')) {
            $curlOptions[constant('CURLOPT_HTTP_CONTENT_DECODING')] = false;
        }
        $response = $this->send(
            'GET',
            $path,
            [
                'query' => $query,
                'headers' => ['Accept-Encoding' => 'identity'],
                'curl' => $curlOptions,
                'decode_content' => false,
            ],
            false,
            60.0,
        );
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300 && $status !== 200) {
            throw new ApiException(
                'sevdesk returned an unexpected success status.',
                $status,
                'unexpected_http_status',
            );
        }
        $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0]));
        if ($status === 200 && $contentType === 'application/pdf') {
            try {
                $content = (string) $response->getBody();
            } catch (Throwable) {
                throw new ApiException(
                    'The sevdesk PDF response could not be read.',
                    $response->getStatusCode(),
                    'response_read_error',
                );
            }
            if (strlen($content) > self::MAX_PDF_RESPONSE_BYTES) {
                throw new ApiException(
                    'sevdesk returned an unexpectedly large PDF response.',
                    $response->getStatusCode(),
                    'response_too_large',
                );
            }

            return [
                'kind' => 'binary',
                'mimeType' => $contentType,
                'content' => $content,
            ];
        }

        return [
            'kind' => 'json',
            'payload' => $this->decodeResponse(
                $response,
                false,
                [200],
                self::MAX_LARGE_JSON_RESPONSE_BYTES,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<int> $expectedStatuses
     * @return array<array-key, mixed>
     */
    public function post(
        string $path,
        #[\SensitiveParameter]
        array $payload,
        bool $outcomeMayBeUnknown = true,
        array $expectedStatuses = [],
    ): array {
        return $this->request(
            'POST',
            $path,
            [
                'body' => self::encodeJson($payload),
                'headers' => ['Content-Type' => 'application/json'],
            ],
            $outcomeMayBeUnknown,
            30.0,
            $expectedStatuses,
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<int> $expectedStatuses
     * @return array<array-key, mixed>
     */
    public function put(
        string $path,
        #[\SensitiveParameter]
        array $payload,
        bool $outcomeMayBeUnknown = true,
        array $expectedStatuses = [],
    ): array {
        return $this->request(
            'PUT',
            $path,
            [
                'body' => self::encodeJson($payload),
                'headers' => ['Content-Type' => 'application/json'],
            ],
            $outcomeMayBeUnknown,
            30.0,
            $expectedStatuses,
        );
    }

    /**
     * Upload a temporary voucher attachment without ever putting its contents in
     * an exception, log context or JSON payload.
     *
     * @return array<array-key, mixed>
     */
    public function upload(
        string $path,
        string $fileName,
        #[\SensitiveParameter]
        string $contents,
        string $mimeType = 'application/pdf',
    ): array {
        if ($contents === '') {
            throw new \InvalidArgumentException('Cannot upload an empty file.');
        }

        return $this->request(
            'POST',
            $path,
            [
                'multipart' => [[
                    'name' => 'file',
                    'contents' => $contents,
                    'filename' => self::safeFileName($fileName),
                    'headers' => ['Content-Type' => $mimeType],
                ]],
            ],
            false,
            60.0,
            [201],
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param list<int> $expectedStatuses
     * @return array<array-key, mixed>
     */
    private function request(
        string $method,
        string $path,
        #[\SensitiveParameter]
        array $options,
        bool $outcomeMayBeUnknown,
        float $timeout,
        array $expectedStatuses = [],
        int $maxResponseBytes = self::MAX_RESPONSE_BYTES,
    ): array {
        $response = $this->send($method, $path, $options, $outcomeMayBeUnknown, $timeout);

        try {
            return $this->decodeResponse(
                $response,
                $outcomeMayBeUnknown,
                $expectedStatuses,
                $maxResponseBytes,
            );
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ApiException(
                'The sevdesk response could not be read.',
                $response->getStatusCode(),
                'response_read_error',
                null,
                self::retryAfter($response),
                self::isUnknownWriteOutcome($outcomeMayBeUnknown, $response->getStatusCode()),
            );
        }
    }

    /** @param array<string, mixed> $options */
    private function send(
        string $method,
        string $path,
        #[\SensitiveParameter]
        array $options,
        bool $outcomeMayBeUnknown,
        float $timeout,
    ): ResponseInterface {
        $options['headers'] = array_merge(
            $options['headers'] ?? [],
            [
                'Accept' => 'application/json',
                // sevdesk expects the raw token, not a Bearer scheme.
                'Authorization' => $this->apiToken,
                'User-Agent' => $this->userAgent,
            ],
        );
        $options['connect_timeout'] = 5.0;
        $options['timeout'] = $timeout;
        $options['http_errors'] = false;

        try {
            $response = $this->httpClient->request(
                $method,
                $this->baseUrl . '/' . ltrim($path, '/'),
                $options,
            );
        } catch (GuzzleException) {
            throw new ApiException(
                'The sevdesk API request could not be completed.',
                null,
                'transport_error',
                null,
                null,
                $outcomeMayBeUnknown,
            );
        } catch (Throwable) {
            // ClientInterface implementations are allowed to throw other runtime
            // exceptions. Keep their messages out of persistent job results.
            throw new ApiException(
                'The HTTP client failed while contacting sevdesk.',
                null,
                'http_client_error',
                null,
                null,
                $outcomeMayBeUnknown,
            );
        }

        return $response;
    }

    /**
     * @param list<int> $expectedStatuses
     * @return array<array-key, mixed>
     */
    private function decodeResponse(
        ResponseInterface $response,
        bool $outcomeMayBeUnknown,
        array $expectedStatuses,
        int $maxResponseBytes,
    ): array {
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if (strlen($body) > $maxResponseBytes) {
            throw new ApiException(
                'sevdesk returned an unexpectedly large response.',
                $status,
                'response_too_large',
                null,
                null,
                self::isUnknownWriteOutcome($outcomeMayBeUnknown, $status),
            );
        }

        $decoded = [];
        if ($body !== '') {
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new ApiException(
                    'sevdesk returned invalid JSON.',
                    $status,
                    'invalid_json',
                    null,
                    self::retryAfter($response),
                    self::isUnknownWriteOutcome($outcomeMayBeUnknown, $status),
                    $exception,
                );
            }
        }

        if (!is_array($decoded)) {
            throw new ApiException(
                'sevdesk returned an unexpected JSON value.',
                $status,
                'unexpected_response',
                null,
                self::retryAfter($response),
                self::isUnknownWriteOutcome($outcomeMayBeUnknown, $status),
            );
        }

        if ($status < 200 || $status >= 300) {
            $code = self::firstSafeIdentifier($decoded, [
                ['error', 'code'],
                ['errorCode'],
                ['code'],
            ]);
            $uuid = self::firstSafeIdentifier($decoded, [
                ['exceptionUUID'],
                ['exceptionUuid'],
                ['error', 'exceptionUUID'],
                ['error', 'exceptionUuid'],
            ]);
            $code = $this->redactToken($code);
            $uuid = $this->redactToken($uuid);

            $details = ['HTTP ' . $status];
            if ($code !== null) {
                $details[] = 'code ' . $code;
            }
            if ($uuid !== null) {
                $details[] = 'exception ' . $uuid;
            }

            throw new ApiException(
                'The sevdesk API rejected the request (' . implode(', ', $details) . ').',
                $status,
                $code,
                $uuid,
                self::retryAfter($response),
                self::isUnknownWriteOutcome($outcomeMayBeUnknown, $status),
            );
        }

        if ($expectedStatuses !== [] && !in_array($status, $expectedStatuses, true)) {
            throw new ApiException(
                'sevdesk returned an unexpected success status.',
                $status,
                'unexpected_http_status',
                null,
                null,
                $outcomeMayBeUnknown,
            );
        }

        // Depending on the endpoint and sevdesk release, successful resources are
        // either returned directly or wrapped in an `objects` member.
        if (array_key_exists('objects', $decoded) && is_array($decoded['objects'])) {
            return $decoded['objects'];
        }

        return $decoded;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<list<string>> $paths
     */
    private static function firstSafeIdentifier(array $data, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $data;
            foreach ($path as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    continue 2;
                }
                $value = $value[$key];
            }

            if (!is_string($value) && !is_int($value)) {
                continue;
            }

            $identifier = substr((string) $value, 0, 120);
            if ($identifier !== '' && preg_match('/^[A-Za-z0-9_.:-]+$/', $identifier) === 1) {
                return $identifier;
            }
        }

        return null;
    }

    private static function retryAfter(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return min((int) $value, 21_600);
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return min(max(0, $timestamp - time()), 21_600);
    }

    private static function safeFileName(string $fileName): string
    {
        $fileName = basename(str_replace('\\', '/', $fileName));
        $fileName = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $fileName);

        return $fileName !== '' ? substr($fileName, 0, 120) : 'document.pdf';
    }

    /** @param array<array-key, mixed> $payload */
    private static function encodeJson(#[\SensitiveParameter] array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new ApiException(
                'The sevdesk request payload could not be encoded.',
                null,
                'request_json_invalid',
                null,
                null,
                false,
                $exception,
            );
        }
    }

    private static function isUnknownWriteOutcome(bool $outcomeMayBeUnknown, int $status): bool
    {
        return $outcomeMayBeUnknown
            && ($status === 408 || ($status >= 200 && $status < 300) || $status >= 500);
    }

    private function redactToken(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (str_contains($value, $this->apiToken)) {
            return 'redacted';
        }

        // Error identifiers are bounded to 120 characters before they reach
        // this method. A longer echoed token would otherwise lose its tail and
        // evade the full-token comparison while still exposing its prefix.
        $tokenPrefix = substr($this->apiToken, 0, 12);

        return strlen($this->apiToken) >= 12 && str_contains($value, $tokenPrefix)
            ? 'redacted'
            : $value;
    }
}
