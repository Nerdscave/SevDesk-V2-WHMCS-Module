<?php

declare(strict_types=1);

// This isolated process deliberately defines the minimum WHMCS runtime stubs
// together so hooks.php can be executed without loading a WHMCS installation.
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures {
    final class HookState
    {
        /** @var array<string, string> */
        public static array $config = [
            'module_active' => 'on',
            'rewrite_runtime_signature' => 'nerdscave-sevdesk-rewrite-v1',
            'runtime_review_required' => '',
            'sync_enabled' => 'on',
            'invoice_canary_confirmed' => 'on',
            'export_mode' => 'invoice_only',
            'document_authority' => 'sevdesk',
            'oss_profile' => 'blocked',
            'eu_b2c_mode' => 'blocked',
            'invoice_delivery_channel' => 'whmcs_template',
            'import_only_paid' => 'on',
        ];

        /** @var list<array{type:string,items:array<array-key,mixed>,filters:array<array-key,mixed>}> */
        public static array $jobs = [];

        /** @var array<string, list<callable>> */
        public static array $hooks = [];

        /** @var list<string> */
        public static array $logs = [];

        public static ?object $mapping = null;

        /** @var array<string, mixed>|null */
        public static ?array $documentContext = null;

        public static bool $invoiceTemplate = true;

        public static bool $throwLocalRead = false;

        public static bool $throwMappingRead = false;

        public static int $remoteCalls = 0;

        public static int $runnerCalls = 0;
    }

    final class FakeConfig
    {
        /** @var array<string,string>|null */
        private ?array $values = null;

        public function get(string $key, ?string $default = null): ?string
        {
            $this->values ??= HookState::$config;

            return $this->values[$key] ?? $default;
        }

        public function bool(string $key, bool $default = false): bool
        {
            $this->values ??= HookState::$config;
            $value = $this->values[$key] ?? null;
            if ($value === null) {
                return $default;
            }

            return in_array(strtolower($value), ['1', 'true', 'yes', 'on', 'enabled'], true);
        }

        public function int(string $key, int $default = 0): int
        {
            $value = $this->get($key);

            return is_numeric($value) ? (int) $value : $default;
        }

        public function set(string $key, string|int|bool|null $value): void
        {
            HookState::$config[$key] = is_bool($value)
                ? ($value ? 'on' : '')
                : (string) ($value ?? '');
            $this->values = null;
        }

        public function refresh(): void
        {
            $this->values = null;
        }
    }

    final class FakeRunner
    {
        /** @return array<string, int> */
        public function run(int $maxItems, int $maxSeconds): array
        {
            ++HookState::$runnerCalls;

            return ['processed' => 0];
        }
    }

    final class FakeJobs
    {
        /**
         * @param array<array-key, mixed> $items
         * @param array<array-key, mixed> $filters
         */
        public function create(string $type, array $items, array $filters = [], ?int $adminId = null): object
        {
            HookState::$jobs[] = ['type' => $type, 'items' => $items, 'filters' => $filters];

            return (object) ['id' => count(HookState::$jobs)];
        }

        /** @return array<string, mixed>|null */
        public function latestDocumentContextForInvoice(int $invoiceId, bool $hasMapping): ?array
        {
            return HookState::$documentContext;
        }
    }

    final class FakeMappings
    {
        public function findCompleteByInvoice(int $invoiceId): ?object
        {
            return HookState::$mapping;
        }

        public function findByInvoice(int $invoiceId): ?object
        {
            if (HookState::$throwMappingRead) {
                throw new \RuntimeException('Synthetic mapping read failure.');
            }

            return HookState::$mapping;
        }
    }

    final class FakeWhmcs
    {
        public function isActiveCustomInvoiceTemplate(string $template): bool
        {
            return true;
        }
    }

    final class FakeSchema
    {
        public function hasTable(string $table): bool
        {
            return true;
        }
    }

    final class FakeQuery
    {
        public function __construct(private readonly string $table)
        {
        }

        /** @param array<array-key, mixed> $bindings */
        public function whereRaw(string $expression, array $bindings = []): self
        {
            return $this;
        }

        public function where(string $column, mixed $operator = null, mixed $value = null): self
        {
            return $this;
        }

        /** @param list<string> $columns */
        public function select(array $columns): self
        {
            return $this;
        }

        public function first(): ?object
        {
            return $this->table === 'tblinvoices'
                ? (object) ['id' => 42, 'invoicenum' => 'RE-42', 'status' => 'Paid']
                : null;
        }

        public function exists(): bool
        {
            return $this->table === 'tblemailtemplates' && HookState::$invoiceTemplate;
        }

        public function value(string $column): mixed
        {
            return $this->table === 'tblinvoices' && $column === 'status' ? 'Paid' : null;
        }
    }
}

namespace WHMCS\Database {
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\FakeQuery;
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\FakeSchema;
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\HookState;

    final class Capsule
    {
        public static function schema(): FakeSchema
        {
            return new FakeSchema();
        }

        public static function table(string $table): FakeQuery
        {
            if (HookState::$throwLocalRead) {
                throw new \RuntimeException('Synthetic local read failure.');
            }

            return new FakeQuery($table);
        }
    }
}

namespace WHMCS\Module\Addon\SevDesk\Database {
    final class Migrator
    {
        public const JOBS_TABLE = 'mod_sevdesk_jobs';
        public const ITEMS_TABLE = 'mod_sevdesk_job_items';
        public const MAPPING_TABLE = 'mod_sevdesk';
    }
}

namespace WHMCS\Module\Addon\SevDesk {
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\FakeConfig;
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\FakeJobs;
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\FakeMappings;
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\FakeRunner;
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\FakeWhmcs;
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\HookState;

    final class Application
    {
        private static ?self $instance = null;

        public readonly FakeConfig $config;

        public readonly FakeJobs $jobs;

        public readonly FakeMappings $mappings;

        public readonly FakeWhmcs $whmcs;

        private function __construct()
        {
            $this->config = new FakeConfig();
            $this->jobs = new FakeJobs();
            $this->mappings = new FakeMappings();
            $this->whmcs = new FakeWhmcs();
        }

        public static function instance(): self
        {
            return self::$instance ??= new self();
        }

        public function client(): never
        {
            ++HookState::$remoteCalls;
            throw new \LogicException('Hooks must not construct a remote client.');
        }

        public function runner(): FakeRunner
        {
            return new FakeRunner();
        }
    }
}

namespace {
    use WHMCS\Module\Addon\SevDesk\Repository\JobRepository;
    use WHMCS\Module\Addon\SevDesk\Support\ClientDocumentPresenter;
    use WHMCS\Module\Addon\SevDesk\Support\DocumentDeliveryContext;
    use WHMCS\Module\Addon\SevDesk\Support\InvoiceEmailGuardContext;
    use WHMCS\Module\Addon\SevDesk\Tests\Unit\Fixtures\HookState;

    define('WHMCS', true);

    function add_hook(string $name, int $priority, callable $callback): void
    {
        HookState::$hooks[$name][] = $callback;
    }

    function logActivity(string $message): void
    {
        HookState::$logs[] = $message;
    }

    require dirname(__DIR__, 3) . '/modules/addons/sevdesk/hooks.php';

    /** @return callable(array<string, mixed>): mixed */
    function hook_callback(string $name): callable
    {
        $callback = HookState::$hooks[$name][0] ?? null;
        if (!is_callable($callback)) {
            throw new RuntimeException('Hook was not registered: ' . $name);
        }

        return $callback;
    }

    /** @param array<string, mixed> $result */
    function emit_result(array $result): never
    {
        echo json_encode($result, JSON_THROW_ON_ERROR);
        exit(0);
    }

    $scenario = $argv[1] ?? '';
    if ($scenario === 'first_paid_email') {
        $preEmail = hook_callback('InvoicePaidPreEmail');
        $preEmail(['invoiceid' => 42]);
        $preEmail(['invoiceid' => 42]);
        $email = hook_callback('EmailPreSend');

        emit_result([
            'guard' => InvoiceEmailGuardContext::appliesTo(42),
            'jobsBeforeInvoicePaid' => count(HookState::$jobs),
            'mailResult' => $email(['relid' => 42, 'messagename' => 'Invoice Payment Confirmation']),
            'remoteCalls' => HookState::$remoteCalls,
        ]);
    }

    if ($scenario === 'invoice_paid_delivery') {
        hook_callback('InvoicePaidPreEmail')(['invoiceid' => 42]);
        $jobsBeforeInvoicePaid = count(HookState::$jobs);
        hook_callback('InvoicePaid')(['invoiceid' => 42]);
        $candidate = HookState::$jobs[0]['items'][0]['candidate'] ?? [];

        emit_result([
            'jobsBeforeInvoicePaid' => $jobsBeforeInvoicePaid,
            'jobsAfterInvoicePaid' => count(HookState::$jobs),
            'trigger' => $candidate['trigger'] ?? null,
            'deliveryRequested' => $candidate['delivery_requested'] ?? null,
            'remoteCalls' => HookState::$remoteCalls,
        ]);
    }

    if ($scenario === 'local_read_failure') {
        hook_callback('InvoicePaidPreEmail')(['invoiceid' => 42]);
        HookState::$throwLocalRead = true;
        $result = hook_callback('EmailPreSend')([
            'relid' => 42,
            'messagename' => 'Invoice Payment Confirmation',
        ]);

        emit_result([
            'mailResult' => $result,
            'logged' => HookState::$logs !== [],
            'remoteCalls' => HookState::$remoteCalls,
        ]);
    }

    if ($scenario === 'later_local_read_failure') {
        HookState::$throwMappingRead = true;
        $result = hook_callback('EmailPreSend')([
            'relid' => 42,
            'messagename' => 'Invoice Payment Confirmation',
        ]);

        emit_result([
            'guard' => InvoiceEmailGuardContext::appliesTo(42),
            'mailResult' => $result,
            'logged' => HookState::$logs !== [],
            'remoteCalls' => HookState::$remoteCalls,
        ]);
    }

    if ($scenario === 'later_whmcs_local_read_failure') {
        HookState::$config['document_authority'] = 'whmcs';
        HookState::$throwMappingRead = true;
        $result = hook_callback('EmailPreSend')([
            'relid' => 42,
            'messagename' => 'Invoice Payment Confirmation',
        ]);

        emit_result([
            'mailResult' => $result,
            'logged' => HookState::$logs !== [],
            'remoteCalls' => HookState::$remoteCalls,
        ]);
    }

    if ($scenario === 'later_template_read_failure') {
        HookState::$throwLocalRead = true;
        $result = hook_callback('EmailPreSend')([
            'relid' => 42,
            'messagename' => 'Potential Invoice Template',
        ]);

        emit_result([
            'mailResult' => $result,
            'logged' => HookState::$logs !== [],
            'remoteCalls' => HookState::$remoteCalls,
        ]);
    }

    if ($scenario === 'whmcs_authority') {
        HookState::$config['document_authority'] = 'whmcs';
        hook_callback('InvoicePaidPreEmail')(['invoiceid' => 42]);
        $result = hook_callback('EmailPreSend')([
            'relid' => 42,
            'messagename' => 'Invoice Payment Confirmation',
        ]);

        emit_result([
            'guard' => InvoiceEmailGuardContext::appliesTo(42),
            'mailResult' => $result,
            'remoteCalls' => HookState::$remoteCalls,
        ]);
    }

    if ($scenario === 'automatic_enqueue_disabled') {
        HookState::$config['sync_enabled'] = '';
        hook_callback('InvoicePaidPreEmail')(['invoiceid' => 42]);
        hook_callback('InvoicePaid')(['invoiceid' => 42]);
        $result = hook_callback('EmailPreSend')([
            'relid' => 42,
            'messagename' => 'Invoice Payment Confirmation',
        ]);

        emit_result([
            'jobCount' => count(HookState::$jobs),
            'guard' => InvoiceEmailGuardContext::appliesTo(42),
            'mailResult' => $result,
            'remoteCalls' => HookState::$remoteCalls,
        ]);
    }

    if ($scenario === 'runtime_signature_missing') {
        HookState::$config['health_alarm'] = 'api_authentication_failed';
        HookState::$config['sync_enabled'] = '';
        unset(HookState::$config['rewrite_runtime_signature']);
        hook_callback('InvoicePaidPreEmail')(['invoiceid' => 42]);
        hook_callback('InvoicePaid')(['invoiceid' => 42]);
        $mailResult = hook_callback('EmailPreSend')([
            'relid' => 42,
            'messagename' => 'Invoice Payment Confirmation',
        ]);

        emit_result([
            'jobCount' => count(HookState::$jobs),
            'guard' => InvoiceEmailGuardContext::appliesTo(42),
            'mailResult' => $mailResult,
            'remoteCalls' => HookState::$remoteCalls,
        ]);
    }

    if ($scenario === 'runtime_review_required') {
        HookState::$config['health_alarm'] = 'api_authentication_failed';
        HookState::$config['sync_enabled'] = '';
        HookState::$config['runtime_review_required'] = 'on';
        hook_callback('InvoicePaidPreEmail')(['invoiceid' => 42]);
        hook_callback('InvoicePaid')(['invoiceid' => 42]);
        hook_callback('AfterCronJob')([]);
        $mailResult = hook_callback('EmailPreSend')([
            'relid' => 42,
            'messagename' => 'Invoice Payment Confirmation',
        ]);

        emit_result([
            'jobCount' => count(HookState::$jobs),
            'runnerCalls' => HookState::$runnerCalls,
            'guard' => InvoiceEmailGuardContext::appliesTo(42),
            'mailResult' => $mailResult,
            'remoteCalls' => HookState::$remoteCalls,
        ]);
    }

    if ($scenario === 'invoice_canary_disabled') {
        HookState::$config['health_alarm'] = 'api_authentication_failed';
        HookState::$config['sync_enabled'] = '';
        HookState::$config['invoice_canary_confirmed'] = '';
        hook_callback('InvoicePaidPreEmail')(['invoiceid' => 42]);
        hook_callback('InvoicePaid')(['invoiceid' => 42]);
        $result = hook_callback('EmailPreSend')([
            'relid' => 42,
            'messagename' => 'Invoice Payment Confirmation',
        ]);

        emit_result([
            'jobCount' => count(HookState::$jobs),
            'guard' => InvoiceEmailGuardContext::appliesTo(42),
            'mailResult' => $result,
            'remoteCalls' => HookState::$remoteCalls,
        ]);
    }

    if ($scenario === 'authentication_alarm') {
        hook_callback('InvoicePaidPreEmail')(['invoiceid' => 42]);
        // Simulate a parallel worker tripping the account-wide alarm between
        // PreEmail and InvoicePaid while Application keeps its local cache.
        HookState::$config['health_alarm'] = 'api_authentication_failed';
        HookState::$config['sync_enabled'] = '';
        hook_callback('InvoicePaid')(['invoiceid' => 42]);
        $mailResult = hook_callback('EmailPreSend')([
            'relid' => 42,
            'messagename' => 'Invoice Payment Confirmation',
        ]);

        $queued = HookState::$jobs[0]['items'][0] ?? [];
        $context = JobRepository::documentContextFromItem((object) [
            'id' => 1,
            'status' => 'pending',
            'checkpoint' => 'queued',
            'message' => null,
            'candidate_json' => json_encode($queued['candidate'] ?? null, JSON_THROW_ON_ERROR),
        ]);
        $clientState = null;
        if (DocumentDeliveryContext::usesSevdeskInvoiceAuthority($context, null)) {
            $clientState = ClientDocumentPresenter::present(
                'Paid',
                'RE-42',
                null,
                $context['itemStatus'] ?? null,
                '',
            )['state'];
        }

        emit_result([
            'jobCount' => count(HookState::$jobs),
            'action' => $queued['action'] ?? null,
            'dedupeKey' => $queued['dedupe_key'] ?? null,
            'deliveryRequested' => $queued['candidate']['delivery_requested'] ?? null,
            'clientState' => $clientState,
            'guard' => InvoiceEmailGuardContext::appliesTo(42),
            'mailResult' => $mailResult,
            'remoteCalls' => HookState::$remoteCalls,
        ]);
    }

    if ($scenario === 'non_invoice_template') {
        hook_callback('InvoicePaidPreEmail')(['invoiceid' => 42]);
        HookState::$invoiceTemplate = false;
        $result = hook_callback('EmailPreSend')(['relid' => 42, 'messagename' => 'Support Message']);

        emit_result(['mailResult' => $result, 'remoteCalls' => HookState::$remoteCalls]);
    }

    if ($scenario === 'existing_legacy_mapping') {
        HookState::$mapping = (object) ['sevdesk_id' => '99', 'document_type' => null];
        hook_callback('InvoicePaidPreEmail')(['invoiceid' => 42]);
        $result = hook_callback('EmailPreSend')([
            'relid' => 42,
            'messagename' => 'Invoice Payment Confirmation',
        ]);

        emit_result([
            'guard' => InvoiceEmailGuardContext::appliesTo(42),
            'mailResult' => $result,
            'remoteCalls' => HookState::$remoteCalls,
        ]);
    }

    if ($scenario === 'mapped_sevdesk_invoice_later_read_failure') {
        HookState::$mapping = (object) ['sevdesk_id' => '99', 'document_type' => 'invoice'];
        HookState::$documentContext = [
            'itemId' => 1,
            'itemStatus' => 'succeeded',
            'checkpoint' => 'finished',
            'source' => 'frozen',
            'allowed' => true,
            'documentType' => 'invoice',
            'documentAuthority' => 'sevdesk',
            'exportMode' => 'invoice_only',
            'ossProfile' => 'blocked',
            'euB2cMode' => 'blocked',
            'deliveryChannel' => 'whmcs_template',
        ];
        hook_callback('InvoicePaidPreEmail')(['invoiceid' => 42]);
        HookState::$throwMappingRead = true;
        $result = hook_callback('EmailPreSend')([
            'relid' => 42,
            'messagename' => 'Invoice Payment Confirmation',
        ]);

        emit_result([
            'guard' => InvoiceEmailGuardContext::appliesTo(42),
            'mailResult' => $result,
            'logged' => HookState::$logs !== [],
            'remoteCalls' => HookState::$remoteCalls,
        ]);
    }

    if ($scenario === 'mapped_sevdesk_invoice_after_global_whmcs_switch') {
        HookState::$config['document_authority'] = 'whmcs';
        HookState::$mapping = (object) ['sevdesk_id' => '99', 'document_type' => 'invoice'];
        HookState::$documentContext = [
            'itemId' => 1,
            'itemStatus' => 'succeeded',
            'checkpoint' => 'finished',
            'source' => 'frozen',
            'allowed' => true,
            'documentType' => 'invoice',
            'documentAuthority' => 'sevdesk',
            'exportMode' => 'invoice_only',
            'ossProfile' => 'blocked',
            'euB2cMode' => 'blocked',
            'deliveryChannel' => 'whmcs_template',
        ];
        hook_callback('InvoicePaidPreEmail')(['invoiceid' => 42]);
        HookState::$throwMappingRead = true;
        $result = hook_callback('EmailPreSend')([
            'relid' => 42,
            'messagename' => 'Invoice Payment Confirmation',
        ]);

        emit_result([
            'guard' => InvoiceEmailGuardContext::appliesTo(42),
            'mailResult' => $result,
            'logged' => HookState::$logs !== [],
            'remoteCalls' => HookState::$remoteCalls,
        ]);
    }

    if ($scenario === 'enqueue_matrix') {
        $mode = (string) ($argv[2] ?? 'voucher_only');
        $onlyPaid = (string) ($argv[3] ?? 'on');
        $event = (string) ($argv[4] ?? 'InvoicePaid');
        HookState::$config['export_mode'] = $mode;
        HookState::$config['import_only_paid'] = $onlyPaid;
        HookState::$config['document_authority'] = $mode === 'invoice_only' ? 'sevdesk' : 'whmcs';

        hook_callback($event)(['invoiceid' => 42]);
        $item = HookState::$jobs[0]['items'][0] ?? [];
        $candidate = is_array($item['candidate'] ?? null) ? $item['candidate'] : [];

        emit_result([
            'jobCount' => count(HookState::$jobs),
            'action' => $item['action'] ?? null,
            'dedupeKey' => $item['dedupe_key'] ?? null,
            'trigger' => $candidate['trigger'] ?? null,
            'deliveryRequested' => $candidate['delivery_requested'] ?? null,
            'remoteCalls' => HookState::$remoteCalls,
        ]);
    }

    if ($scenario === 'client_invoice_ready') {
        HookState::$mapping = (object) [
            'sevdesk_id' => '99',
            'document_type' => 'invoice',
            'document_number' => 'RE-42',
            'document_ready_at' => '2026-07-19 12:00:00',
            'pdf_sha256' => str_repeat('a', 64),
        ];
        HookState::$documentContext = [
            'itemId' => 1,
            'itemStatus' => 'succeeded',
            'checkpoint' => 'finished',
            'source' => 'frozen',
            'allowed' => true,
            'documentType' => 'invoice',
            'documentAuthority' => 'sevdesk',
            'exportMode' => 'invoice_only',
            'ossProfile' => 'blocked',
            'euB2cMode' => 'blocked',
            'deliveryChannel' => 'whmcs_template',
        ];

        $result = hook_callback('ClientAreaPageViewInvoice')([
            'invoiceid' => 42,
            'WEB_ROOT' => '/whmcs',
        ]);

        emit_result(['result' => $result, 'remoteCalls' => HookState::$remoteCalls]);
    }

    fwrite(STDERR, 'Unknown hook scenario.');
    exit(2);
}
