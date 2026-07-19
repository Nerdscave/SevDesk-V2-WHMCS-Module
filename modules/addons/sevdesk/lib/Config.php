<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk;

use WHMCS\Database\Capsule;

final class Config
{
    public const MODULE = 'sevdesk';

    public const RUNTIME_SIGNATURE_SETTING = 'rewrite_runtime_signature';

    public const RUNTIME_SIGNATURE = 'nerdscave-sevdesk-rewrite-v1';

    public const RUNTIME_REVIEW_SETTING = 'runtime_review_required';

    public const RUNTIME_QUARANTINE_TOKEN_SETTING = 'runtime_quarantine_token';

    /** @var array<string, string>|null */
    private ?array $values = null;

    /** @var array<string, string> */
    private const DEFAULTS = [
        'module_active' => '',
        'sync_enabled' => '',
        'runtime_review_required' => '',
        'runtime_quarantine_token' => '',
        'health_alarm' => '',
        'export_mode' => 'voucher_only',
        'document_authority' => 'whmcs',
        'oss_profile' => 'blocked',
        'invoice_canary_confirmed' => '',
        'invoice_sev_user_id' => '',
        'invoice_unity_id' => '',
        'e_invoice_mode' => 'off',
        'e_invoice_client_field_id' => '',
        'e_invoice_payment_method_id' => '',
        'e_invoice_active_from' => '',
        'e_invoice_canary_confirmed' => '',
        'invoice_delivery_channel' => 'sevdesk',
        'whmcs_invoice_email_template' => '',
        'sevdesk_email_subject' => 'Ihre Rechnung {invoice_number}',
        'sevdesk_email_body' => "Guten Tag,\n\nim Anhang finden Sie Ihre Rechnung {invoice_number}.",
        'theme_adapter_confirmed' => '',
        'customer_number_contact_creation_confirmed' => '',
        'import_after' => '01-01-1999',
        'import_only_paid' => 'on',
        'eu_b2b_goods_confirmed' => '',
        'eu_b2c_mode' => 'blocked',
        'taxRuleGeneral' => '1',
        'taxRuleInterCommunityBusiness' => '3',
        'taxRuleInterCommunityConsumer' => '1',
        'taxRuleThirdPartyCountry' => '',
        'taxRuleCredit' => '',
        'taxRuleSmallBusinessOwner' => '11',
        'third_country_confirmed' => '',
        'add_funds_confirmed' => '',
        'small_business_confirmed' => '',
        'debug_logging' => '',
    ];

    public function get(string $key, ?string $default = null): ?string
    {
        $this->load();

        return $this->values[$key] ?? self::DEFAULTS[$key] ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $this->load();
        if (array_key_exists($key, $this->values ?? [])) {
            $value = (string) $this->values[$key];
        } elseif (array_key_exists($key, self::DEFAULTS)) {
            $value = self::DEFAULTS[$key];
        } else {
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
        $stored = match (true) {
            is_bool($value) => $value ? 'on' : '',
            $value === null => '',
            default => (string) $value,
        };

        Capsule::table('tbladdonmodules')->updateOrInsert(
            ['module' => self::MODULE, 'setting' => $key],
            ['value' => $stored],
        );

        $this->values = null;
    }

    public function delete(string $key): void
    {
        Capsule::table('tbladdonmodules')
            ->where('module', self::MODULE)
            ->where('setting', $key)
            ->delete();
        $this->values = null;
    }

    /** Discard the process-local setting cache before a concurrency gate read. */
    public function refresh(): void
    {
        $this->values = null;
    }

    /** @return array<string,string> Settings actually persisted, without defaults. */
    public function stored(): array
    {
        $this->load();

        return $this->values ?? [];
    }

    public function ensureDefaults(): void
    {
        $this->load();
        $existing = $this->values ?? [];

        foreach (self::DEFAULTS as $key => $value) {
            if (!array_key_exists($key, $existing)) {
                Capsule::table('tbladdonmodules')->updateOrInsert(
                    ['module' => self::MODULE, 'setting' => $key],
                    ['value' => $value],
                );
            }
        }

        $this->values = null;
    }

    /**
     * Stop every remote-capable path after an untrusted replacement or a
     * structural runtime failure. Review, generation token and invalid runtime
     * signature form one atomic intent. The later writes reassert that state
     * independently so a selective database error still fails closed.
     */
    public function quarantineRuntime(): string
    {
        $quarantineToken = self::newQuarantineToken();

        if (!$this->persistRuntimeQuarantineIntentAtomically($quarantineToken)) {
            // A token-row failure must not leave the old generation eligible
            // for setup release. Invalidating the signature together with the
            // review marker is the fail-closed secondary latch.
            $this->persistInvalidRuntimeReviewAtomically();
        }

        foreach (
            [
                [self::RUNTIME_SIGNATURE_SETTING, ''],
                ['sync_enabled', ''],
                // Signature and sync are deliberately independent safety
                // writes. Reassert review last so either atomic latch remains
                // visibly fail-closed if a later write fails.
                [self::RUNTIME_REVIEW_SETTING, 'on'],
            ] as [$key, $value]
        ) {
            try {
                $this->set($key, $value);
            } catch (\Throwable) {
                $this->values = null;
            }
        }

        return $quarantineToken;
    }

    /**
     * Lock every durable gate that decides whether a newly claimed item may
     * start. The caller must already be inside a database transaction.
     *
     * @return array{moduleActive:bool,runtimeReviewRequired:bool,runtimeSignature:string,
     *     quarantineToken:string,authenticationAlarm:string}
     */
    public function lockRuntimeGates(): array
    {
        $keys = [
            'health_alarm',
            'module_active',
            self::RUNTIME_QUARANTINE_TOKEN_SETTING,
            self::RUNTIME_REVIEW_SETTING,
            self::RUNTIME_SIGNATURE_SETTING,
        ];
        $stored = [];
        foreach (
            Capsule::table('tbladdonmodules')
                ->where('module', self::MODULE)
                ->whereIn('setting', $keys)
                ->orderBy('setting')
                ->lockForUpdate()
                ->get() as $row
        ) {
            $stored[(string) $row->setting] = (string) $row->value;
        }
        $this->values = null;

        return [
            'moduleActive' => self::truthy($stored['module_active'] ?? self::DEFAULTS['module_active']),
            'runtimeReviewRequired' => self::truthy(
                $stored[self::RUNTIME_REVIEW_SETTING] ?? self::DEFAULTS[self::RUNTIME_REVIEW_SETTING],
            ),
            'runtimeSignature' => $stored[self::RUNTIME_SIGNATURE_SETTING] ?? '',
            'quarantineToken' => $stored[self::RUNTIME_QUARANTINE_TOKEN_SETTING]
                ?? self::DEFAULTS[self::RUNTIME_QUARANTINE_TOKEN_SETTING],
            'authenticationAlarm' => $stored['health_alarm'] ?? self::DEFAULTS['health_alarm'],
        ];
    }

    /** The lockRuntimeGates() call is the claim's database linearization point. */
    public function runtimeAllowsClaimWhileLocked(): bool
    {
        $gates = $this->lockRuntimeGates();

        return $gates['moduleActive']
            && !$gates['runtimeReviewRequired']
            && $gates['runtimeSignature'] === self::RUNTIME_SIGNATURE
            && trim($gates['authenticationAlarm']) !== 'api_authentication_failed';
    }

    /**
     * Release inventory quarantine only if no newer quarantine was raised while
     * setup performed its read-only tenant validation. Runtime gate rows must
     * already be locked by lockRuntimeGates() in the same transaction.
     */
    public function clearRuntimeReviewIfUnchanged(string $expectedQuarantineToken): bool
    {
        $gates = $this->lockRuntimeGates();
        if (
            !$gates['runtimeReviewRequired']
            || $gates['runtimeSignature'] !== self::RUNTIME_SIGNATURE
            || !hash_equals($expectedQuarantineToken, $gates['quarantineToken'])
        ) {
            return false;
        }

        $updated = Capsule::table('tbladdonmodules')
            ->where('module', self::MODULE)
            ->where('setting', self::RUNTIME_REVIEW_SETTING)
            ->where('value', 'on')
            ->update(['value' => '']);
        $this->values = null;

        return $updated === 1;
    }

    /**
     * Persist every local 401/403 safety gate independently. If the primary
     * tenant alarm row cannot be written, runtime review is the claim-blocking
     * fallback. Callers may additionally pause their current job.
     *
     * @return array{alarm:bool,reviewFallback:bool,syncDisabled:bool}
     */
    public function tripAuthenticationSafetyGates(): array
    {
        $alarm = false;
        $reviewFallback = false;
        $syncDisabled = false;
        try {
            $this->set('health_alarm', 'api_authentication_failed');
            $alarm = true;
        } catch (\Throwable) {
            $fallbackToken = self::newQuarantineToken();
            $intentPersisted = $this->persistRuntimeReviewIntentAtomically($fallbackToken);
            $invalidReviewPersisted = $intentPersisted
                ? false
                : $this->persistInvalidRuntimeReviewAtomically();
            if (!$intentPersisted && !$invalidReviewPersisted) {
                try {
                    // Last-resort claim stop when neither transactional
                    // fallback could be stored. A successful fallback must not
                    // overwrite a newer, validated setup release afterwards.
                    $this->set(self::RUNTIME_REVIEW_SETTING, 'on');
                } catch (\Throwable) {
                    $this->values = null;
                }
            }
            $this->refresh();
            $reviewFallback = $this->bool(self::RUNTIME_REVIEW_SETTING) && (
                ($intentPersisted && hash_equals(
                    $fallbackToken,
                    (string) $this->get(self::RUNTIME_QUARANTINE_TOKEN_SETTING, ''),
                ))
                || ($invalidReviewPersisted
                    && (string) $this->get(self::RUNTIME_SIGNATURE_SETTING, '') === '')
            );
        }
        try {
            $this->set('sync_enabled', '');
            $syncDisabled = true;
        } catch (\Throwable) {
            $this->values = null;
        }

        return [
            'alarm' => $alarm,
            'reviewFallback' => $reviewFallback,
            'syncDisabled' => $syncDisabled,
        ];
    }

    /**
     * Persist a replacement/migration quarantine as one indivisible gate state.
     * A newly loaded setup form must never see the new token together with a
     * still-valid runtime signature.
     */
    private function persistRuntimeQuarantineIntentAtomically(string $quarantineToken): bool
    {
        try {
            Capsule::connection()->transaction(function () use ($quarantineToken): void {
                $this->lockRuntimeGates();
                $this->set(self::RUNTIME_QUARANTINE_TOKEN_SETTING, $quarantineToken);
                $this->set(self::RUNTIME_REVIEW_SETTING, 'on');
                $this->set(self::RUNTIME_SIGNATURE_SETTING, '');
            });

            return true;
        } catch (\Throwable) {
            $this->values = null;

            return false;
        }
    }

    /**
     * Persist the authentication-review generation under the same ordered gate
     * locks used by setup and worker claims. This path deliberately keeps the
     * signature valid so the guarded mail and PDF paths remain available.
     */
    private function persistRuntimeReviewIntentAtomically(string $quarantineToken): bool
    {
        try {
            Capsule::connection()->transaction(function () use ($quarantineToken): void {
                $this->lockRuntimeGates();
                $this->set(self::RUNTIME_QUARANTINE_TOKEN_SETTING, $quarantineToken);
                $this->set(self::RUNTIME_REVIEW_SETTING, 'on');
            });

            return true;
        } catch (\Throwable) {
            $this->values = null;

            return false;
        }
    }

    /**
     * Secondary latch for a failed generation-token write. It prevents both a
     * worker claim and an old setup form from releasing the runtime.
     */
    private function persistInvalidRuntimeReviewAtomically(): bool
    {
        try {
            Capsule::connection()->transaction(function (): void {
                $this->lockRuntimeGates();
                $this->set(self::RUNTIME_REVIEW_SETTING, 'on');
                $this->set(self::RUNTIME_SIGNATURE_SETTING, '');
            });

            return true;
        } catch (\Throwable) {
            $this->values = null;

            return false;
        }
    }

    /** Persist the quarantine marker before any migration may continue. */
    public function quarantineRuntimeOrFail(): void
    {
        $quarantineToken = $this->quarantineRuntime();
        $this->refresh();
        if (
            !$this->bool(self::RUNTIME_REVIEW_SETTING)
            || !hash_equals(
                $quarantineToken,
                (string) $this->get(self::RUNTIME_QUARANTINE_TOKEN_SETTING, ''),
            )
            || (string) $this->get(self::RUNTIME_SIGNATURE_SETTING, '') !== ''
        ) {
            throw new \RuntimeException('The sevdesk replacement inventory quarantine could not be persisted.');
        }
    }

    /** @return array{accountDatevId:int,taxRuleId:int}|null */
    public function taxProfile(string $profile): ?array
    {
        $keys = [
            'domestic' => ['accountingTypeGeneral', 'taxRuleGeneral'],
            'eu_b2b' => ['accountingTypeInterCommunityBusiness', 'taxRuleInterCommunityBusiness'],
            'eu_b2c' => ['accountingTypeInterCommunityConsumer', 'taxRuleInterCommunityConsumer'],
            'third_country' => ['accountingTypeThirdPartyCountry', 'taxRuleThirdPartyCountry'],
            'add_funds' => ['accountingTypeCredit', 'taxRuleCredit'],
            'small_business' => ['accountingTypeSmallBusinessOwner', 'taxRuleSmallBusinessOwner'],
        ][$profile] ?? null;

        if ($keys === null) {
            return null;
        }

        $account = $this->int($keys[0]);
        $rule = $this->int($keys[1]);

        return $account > 0 && $rule > 0
            ? ['accountDatevId' => $account, 'taxRuleId' => $rule]
            : null;
    }

    /** @return array<string, array{accountDatev:string,taxRule:string,confirmed:bool}> */
    public function taxProfiles(): array
    {
        return [
            'domestic' => $this->profile('accountingTypeGeneral', 'taxRuleGeneral', true),
            'eu_b2b' => $this->profile(
                'accountingTypeInterCommunityBusiness',
                'taxRuleInterCommunityBusiness',
                $this->bool('eu_b2b_goods_confirmed'),
            ),
            'eu_b2c_domestic' => $this->profile(
                'accountingTypeInterCommunityConsumer',
                'taxRuleInterCommunityConsumer',
                $this->get('eu_b2c_mode') === 'domestic_confirmed',
            ),
            'third_country' => $this->profile(
                'accountingTypeThirdPartyCountry',
                'taxRuleThirdPartyCountry',
                $this->bool('third_country_confirmed'),
            ),
            'add_funds' => $this->profile(
                'accountingTypeCredit',
                'taxRuleCredit',
                $this->bool('add_funds_confirmed'),
            ),
            'small_business' => $this->profile(
                'accountingTypeSmallBusinessOwner',
                'taxRuleSmallBusinessOwner',
                $this->bool('small_business_confirmed'),
            ),
        ];
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $this->load();

        return array_merge(self::DEFAULTS, $this->values ?? []);
    }

    private function load(): void
    {
        if ($this->values !== null) {
            return;
        }

        $this->values = [];
        foreach (Capsule::table('tbladdonmodules')->where('module', self::MODULE)->get() as $row) {
            $this->values[(string) $row->setting] = (string) $row->value;
        }
    }

    private static function truthy(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    private static function newQuarantineToken(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return hash('sha256', uniqid('sevdesk-quarantine-', true));
        }
    }

    /** @return array{accountDatev:string,taxRule:string,confirmed:bool} */
    private function profile(string $accountKey, string $ruleKey, bool $confirmed): array
    {
        return [
            'accountDatev' => (string) $this->get($accountKey, ''),
            'taxRule' => (string) $this->get($ruleKey, ''),
            'confirmed' => $confirmed,
        ];
    }
}
