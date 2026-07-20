<?php

declare(strict_types=1);

// Isolated executable fixture for the WHMCS client-area entrypoint.
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures {
    use WHMCS\Module\Addon\SevDesk\Api\ApiException;

    final class ClientAreaState
    {
        public const PDF = "%PDF-1.7\nsynthetic sevdesk invoice\n%%EOF";

        public static int $clientId = 20;

        public static int $ownerId = 20;

        public static bool $invoicePermission = true;

        public static bool $moduleActive = true;

        public static int $pdfCalls = 0;

        public static int $mappingCalls = 0;

        public static bool $authenticationFailure = false;

        public static ?string $failedConfigSetting = null;

        /** @var array<string,string> */
        public static array $storedConfig = [];

        /** @var list<string> */
        public static array $logs = [];

        public static ?object $mapping = null;

        /** @var array<string,mixed>|null */
        public static ?array $context = null;

        public static function initialise(): void
        {
            self::$mapping = (object) [
                'sevdesk_id' => '701',
                'document_type' => 'invoice',
                'document_number' => 'RE-42',
                'document_ready_at' => '2026-07-19 12:00:00',
                'pdf_sha256' => hash('sha256', self::PDF),
            ];
            self::$context = [
                'itemId' => 1,
                'itemStatus' => 'succeeded',
                'checkpoint' => 'mapping_persisted',
                'source' => 'frozen',
                'allowed' => true,
                'documentType' => 'invoice',
                'documentAuthority' => 'sevdesk',
                'exportMode' => 'invoice_only',
                'ossProfile' => 'blocked',
                'euB2cMode' => 'blocked',
                'deliveryChannel' => 'sevdesk',
            ];
        }
    }

    final class ClientAreaConfig
    {
        public function bool(string $key, bool $default = false): bool
        {
            return $key === 'module_active' ? ClientAreaState::$moduleActive : $default;
        }

        public function get(string $key, ?string $default = null): ?string
        {
            return $key === 'rewrite_runtime_signature'
                ? 'nerdscave-sevdesk-rewrite-v1'
                : $default;
        }

        public function set(string $key, string $value): void
        {
            if (ClientAreaState::$failedConfigSetting === $key) {
                throw new \RuntimeException('Synthetic config write failure.');
            }
            ClientAreaState::$storedConfig[$key] = $value;
        }

        /** @return array{alarm:bool,reviewFallback:bool,syncDisabled:bool} */
        public function tripAuthenticationSafetyGates(): array
        {
            $alarm = false;
            $reviewFallback = false;
            $syncDisabled = false;
            try {
                $this->set('health_alarm', 'api_authentication_failed');
                $alarm = true;
            } catch (\Throwable) {
                try {
                    $this->set('runtime_review_required', 'on');
                    $this->set('runtime_quarantine_token', 'synthetic-new-token');
                    $this->set('runtime_review_required', 'on');
                    $reviewFallback = true;
                } catch (\Throwable) {
                    // Synthetic safety-write failure remains visible in result.
                }
            }
            try {
                $this->set('sync_enabled', '');
                $syncDisabled = true;
            } catch (\Throwable) {
                // Synthetic safety-write failure remains visible in result.
            }

            return compact('alarm', 'reviewFallback', 'syncDisabled');
        }
    }

    final class ClientAreaWhmcs
    {
        public function invoiceOwnerId(int $invoiceId): int
        {
            return ClientAreaState::$ownerId;
        }
    }

    final class ClientAreaMappings
    {
        public function findByInvoice(int $invoiceId): ?object
        {
            ++ClientAreaState::$mappingCalls;

            return ClientAreaState::$mapping;
        }
    }

    final class ClientAreaJobs
    {
        /** @return array<string,mixed>|null */
        public function latestDocumentContextForInvoice(int $invoiceId, bool $frozenOnly): ?array
        {
            return ClientAreaState::$context;
        }
    }

    final class ClientAreaPdf
    {
        /** @return array{contents:string,sha256:string,filename:string} */
        public function fetch(string $remoteId): array
        {
            ++ClientAreaState::$pdfCalls;
            if (ClientAreaState::$authenticationFailure) {
                throw new ApiException('Synthetic authentication failure.', 401, 'AUTHENTICATION');
            }

            return [
                'contents' => ClientAreaState::PDF,
                'sha256' => hash('sha256', ClientAreaState::PDF),
                'filename' => 'sevdesk-invoice-701.pdf',
            ];
        }
    }
}

namespace WHMCS\User {
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\ClientAreaState;

    final class Client
    {
        public function __construct(public int $id)
        {
        }
    }

    final class User
    {
        /** @return list<Client> */
        public function getClientsByPermission(string|int $permission): array
        {
            return $permission === 'invoices' && ClientAreaState::$invoicePermission
                ? [new Client(ClientAreaState::$clientId)]
                : [];
        }
    }
}

namespace WHMCS\Authentication {
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\ClientAreaState;
    use WHMCS\User\Client;
    use WHMCS\User\User;

    final class CurrentUser
    {
        public function client(): ?Client
        {
            return ClientAreaState::$clientId > 0 ? new Client(ClientAreaState::$clientId) : null;
        }

        public function user(): ?User
        {
            return ClientAreaState::$clientId > 0 ? new User() : null;
        }
    }
}

namespace WHMCS\Module\Addon\SevDesk {
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\ClientAreaConfig;
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\ClientAreaJobs;
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\ClientAreaMappings;
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\ClientAreaPdf;
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\ClientAreaWhmcs;

    final class Application
    {
        private static ?self $instance = null;

        public readonly ClientAreaConfig $config;

        public readonly ClientAreaWhmcs $whmcs;

        public readonly ClientAreaMappings $mappings;

        public readonly ClientAreaJobs $jobs;

        private readonly ClientAreaPdf $pdf;

        private function __construct()
        {
            $this->config = new ClientAreaConfig();
            $this->whmcs = new ClientAreaWhmcs();
            $this->mappings = new ClientAreaMappings();
            $this->jobs = new ClientAreaJobs();
            $this->pdf = new ClientAreaPdf();
        }

        public static function instance(): self
        {
            return self::$instance ??= new self();
        }

        public function invoicePdf(): ClientAreaPdf
        {
            return $this->pdf;
        }
    }
}

namespace {
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\ClientAreaState;

    define('WHMCS', true);

    function logActivity(string $message): void
    {
        ClientAreaState::$logs[] = $message;
    }

    ClientAreaState::initialise();
    $scenario = $argv[1] ?? '';
    if ($scenario === 'foreign_owner') {
        ClientAreaState::$ownerId = 21;
    } elseif ($scenario === 'missing_invoice_permission') {
        ClientAreaState::$invoicePermission = false;
    } elseif ($scenario === 'wrong_type') {
        ClientAreaState::$mapping->document_type = 'voucher';
    } elseif ($scenario === 'not_ready') {
        ClientAreaState::$mapping->document_ready_at = null;
    } elseif ($scenario === 'hash_mismatch') {
        ClientAreaState::$mapping->pdf_sha256 = str_repeat('a', 64);
    } elseif ($scenario === 'auth_failure') {
        ClientAreaState::$authenticationFailure = true;
    } elseif ($scenario === 'auth_alarm_write_failure') {
        ClientAreaState::$authenticationFailure = true;
        ClientAreaState::$failedConfigSetting = 'health_alarm';
    } elseif ($scenario === 'auth_sync_write_failure') {
        ClientAreaState::$authenticationFailure = true;
        ClientAreaState::$failedConfigSetting = 'sync_enabled';
    }

    $_GET = ['a' => 'download', 'id' => '42'];
    require dirname(__DIR__, 3) . '/modules/addons/sevdesk/sevdesk.php';
    $result = sevdesk_clientarea([]);

    echo json_encode([
        'httpStatus' => http_response_code(),
        'result' => $result,
        'pdfCalls' => ClientAreaState::$pdfCalls,
        'mappingCalls' => ClientAreaState::$mappingCalls,
        'storedConfig' => ClientAreaState::$storedConfig,
        'logs' => ClientAreaState::$logs,
    ], JSON_THROW_ON_ERROR);
}
