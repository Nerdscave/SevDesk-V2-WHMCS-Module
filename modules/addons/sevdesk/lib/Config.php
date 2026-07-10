<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk;

use WHMCS\Database\Capsule;

final class Config
{
    public const MODULE = 'sevdesk';

    /** @var array<string, string>|null */
    private ?array $values = null;

    /** @var array<string, string> */
    private const DEFAULTS = [
        'module_active' => '',
        'sync_enabled' => '',
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
