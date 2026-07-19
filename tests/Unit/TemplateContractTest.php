<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TemplateContractTest extends TestCase
{
    private const REQUIRED_SETUP_FIELDS = [
        'token',
        'save',
        'runtime_quarantine_token',
        'runtime_review_confirmed',
        'transition_inventory_fingerprint',
        'transition_inventory_confirmed',
        'sevdesk_api_key',
        'custom_field_id',
        'customer_number_contact_creation_confirmed',
        'e_invoice_mode',
        'e_invoice_client_field_id',
        'e_invoice_payment_method_id',
        'e_invoice_active_from',
        'e_invoice_canary_confirmed',
        'e_invoice_profile_acknowledged',
        'import_after',
        'import_only_paid',
        'sync_enabled',
        'debug_logging',
        'eu_b2c_mode',
        'eu_b2c_acknowledged',
        'smallBusinessOwner',
        'accountingTypeGeneral',
        'taxRuleGeneral',
        'accountingTypeInterCommunityBusiness',
        'taxRuleInterCommunityBusiness',
        'eu_b2b_goods_confirmed',
        'accountingTypeInterCommunityConsumer',
        'taxRuleInterCommunityConsumer',
        'accountingTypeThirdPartyCountry',
        'taxRuleThirdPartyCountry',
        'third_country_confirmed',
        'accountingTypeCredit',
        'taxRuleCredit',
        'add_funds_confirmed',
        'accountingTypeSmallBusinessOwner',
        'taxRuleSmallBusinessOwner',
        'small_business_confirmed',
        'customer_number_contact_creation_confirmed',
    ];

    private const SAFETY_CONFIRMATIONS = [
        'eu_b2c_acknowledged',
        'eu_b2b_goods_confirmed',
        'third_country_confirmed',
        'add_funds_confirmed',
        'small_business_confirmed',
        'transition_inventory_confirmed',
        'e_invoice_canary_confirmed',
        'e_invoice_profile_acknowledged',
    ];

    private const TAX_PROFILES = [
        'tax-profile-general-title' => [
            'account-general',
            'accountingTypeGeneral',
            'generalAccountId',
            'generalAccountFound',
        ],
        'tax-profile-eu-business-title' => [
            'account-eu-business',
            'accountingTypeInterCommunityBusiness',
            'euBusinessAccountId',
            'euBusinessAccountFound',
        ],
        'tax-profile-eu-consumer-title' => [
            'account-eu-consumer',
            'accountingTypeInterCommunityConsumer',
            'euConsumerAccountId',
            'euConsumerAccountFound',
        ],
        'tax-profile-third-country-title' => [
            'account-third-country',
            'accountingTypeThirdPartyCountry',
            'thirdCountryAccountId',
            'thirdCountryAccountFound',
        ],
        'tax-profile-credit-title' => [
            'account-credit',
            'accountingTypeCredit',
            'creditAccountId',
            'creditAccountFound',
        ],
        'tax-profile-small-business-title' => [
            'account-small-business',
            'accountingTypeSmallBusinessOwner',
            'smallBusinessAccountId',
            'smallBusinessAccountFound',
        ],
    ];

    public function testLayoutPartialsDoNotReferencePublicModuleAssets(): void
    {
        $markup = $this->template('partials/layout_top.tpl')
            . $this->template('partials/layout_bottom.tpl');

        self::assertStringNotContainsString('/modules/addons/sevdesk/assets', $markup);
        self::assertDoesNotMatchRegularExpression(
            '~<(?:link|script)\b[^>]*(?:admin\.css|admin\.js)[^>]*>~i',
            $markup
        );
    }

    public function testModuleNavigationUsesAccessiblePageTabs(): void
    {
        $navigation = $this->template('partials/layout_top.tpl');
        $stylesheet = file_get_contents(
            dirname(__DIR__, 2) . '/modules/addons/sevdesk/assets/css/admin.css'
        );

        self::assertIsString($stylesheet);
        self::assertStringContainsString('<ul class="nav nav-tabs sd-nav-tabs" role="list">', $navigation);
        self::assertSame(9, substr_count($navigation, '<li class="sd-nav-item'));
        self::assertSame(9, substr_count($navigation, '<a class="sd-nav-link'));
        self::assertSame(9, substr_count($navigation, 'aria-current="page"'));
        self::assertStringNotContainsString('role="tablist"', $navigation);
        self::assertStringNotContainsString('role="tab"', $navigation);
        self::assertDoesNotMatchRegularExpression(
            '/\.sd-nav-link\s+span\s*\{[^}]*display:\s*none/s',
            $stylesheet
        );
        // Tabs must wrap on narrow admin frames instead of being clipped or scrolled.
        self::assertDoesNotMatchRegularExpression(
            '/\.sd-nav-tabs[^{]*\{[^}]*(white-space:\s*nowrap|flex-wrap:\s*nowrap|overflow-x)/s',
            $stylesheet
        );
    }

    public function testSetupRetainsItsSaveFieldsAndSafetyConfirmations(): void
    {
        $setup = $this->template('setup.tpl');
        $matches = [];

        self::assertGreaterThan(0, preg_match_all('/\bname="([^"]+)"/', $setup, $matches));
        $fieldNames = array_values(array_unique($matches[1]));

        foreach (self::REQUIRED_SETUP_FIELDS as $fieldName) {
            self::assertContains(
                $fieldName,
                $fieldNames,
                sprintf('Setup field "%s" must remain part of the save contract.', $fieldName)
            );
        }

        foreach (self::SAFETY_CONFIRMATIONS as $fieldName) {
            self::assertMatchesRegularExpression(
                sprintf(
                    '/<input\b(?=[^>]*\btype="checkbox")(?=[^>]*\bname="%s")[^>]*>/',
                    preg_quote($fieldName, '/')
                ),
                $setup,
                sprintf('Safety confirmation "%s" must remain an explicit checkbox.', $fieldName)
            );
        }

        self::assertStringContainsString('OSS-Regeln 18–20 sind für Voucher nicht unterstützt', $setup);
        self::assertStringContainsString('bei keinem Treffer wird kein neuer Kontakt angelegt', $setup);
        self::assertStringContainsString('die bisherige deutsche Besteuerung und OSS dürfen nicht gleichzeitig aktiv sein', $setup);
        self::assertStringContainsString(
            'Hosting, Domains, Lizenzen und andere Dienstleistungen bleiben ausgeschlossen',
            $setup
        );
        self::assertStringContainsString('Das Speichern startet keinen Export.', $setup);
        self::assertStringContainsString('Das Modul legt kein Feld an.', $setup);
        self::assertStringContainsString('es gibt keinen stillen Rückfall auf eine normale PDF-Invoice', $setup);
    }

    public function testSetupNeverRendersAPartialStoredApiToken(): void
    {
        $setup = $this->template('setup.tpl');
        $controller = file_get_contents(
            dirname(__DIR__, 2) . '/modules/addons/sevdesk/lib/Controllers/AdminController.php'
        );

        self::assertIsString($controller);
        self::assertStringContainsString('sevdesk_api_key_placeholder', $setup);
        self::assertStringContainsString('Token gespeichert – leer lassen zum Beibehalten', $controller);
        self::assertStringNotContainsString('sevdesk_api_key_masked', $setup . $controller);
        self::assertStringNotContainsString('substr($storedToken', $controller);
    }

    public function testRule19AndDomesticEuB2cProfilesCannotBeSavedTogether(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2) . '/modules/addons/sevdesk/lib/Controllers/AdminController.php'
        );

        self::assertIsString($controller);
        self::assertStringContainsString(
            '$ossProfile === \'rule19_digital_services_confirmed\' && $mode !== \'blocked\'',
            $controller,
        );
    }

    public function testAllTaxProfilesProvideAccessibleExpandableInformation(): void
    {
        $setup = $this->template('setup.tpl');
        $profileMatches = [];

        self::assertSame(
            count(self::TAX_PROFILES),
            preg_match_all(
                '/<article\b(?=[^>]*\bclass="[^"]*\bsd-tax-profile\b[^"]*")'
                . '(?=[^>]*\baria-labelledby="([^"]+)")[^>]*>/',
                $setup,
                $profileMatches
            )
        );
        self::assertEqualsCanonicalizing(array_keys(self::TAX_PROFILES), $profileMatches[1]);

        $informationMatches = [];
        self::assertSame(
            count(self::TAX_PROFILES),
            preg_match_all(
                '/<details\b(?=[^>]*\bclass="[^"]*\bsd-info\b[^"]*")[^>]*>\s*'
                . '<summary\b(?=[^>]*\bclass="[^"]*\bsd-info-trigger\b[^"]*")'
                . '(?=[^>]*\baria-label="[^"]+")[^>]*>.*?<\/summary>\s*'
                . '<div\b(?=[^>]*\bclass="[^"]*\bsd-info-popover\b[^"]*")'
                . '(?=[^>]*\brole="(?:note|tooltip)")[^>]*>.*?<\/div>\s*<\/details>/s',
                $setup,
                $informationMatches
            ),
            'Each tax profile needs a native, keyboard-focusable details/summary explanation.'
        );
    }

    public function testEveryTaxProfileOffersAccountSelectionAndSafeFallbacks(): void
    {
        $setup = $this->template('setup.tpl');

        foreach (self::TAX_PROFILES as $profileId => $accountContract) {
            [$controlId, $fieldName, $storedIdVariable, $foundVariable] = $accountContract;
            $profile = $this->taxProfile($setup, $profileId);

            self::assertStringContainsString('{if $accountOptions|@count}', $profile);
            self::assertMatchesRegularExpression(
                sprintf(
                    '/<select\b(?=[^>]*\bid="%s")(?=[^>]*\bname="%s")[^>]*>/',
                    preg_quote($controlId, '/'),
                    preg_quote($fieldName, '/')
                ),
                $profile,
                sprintf('Tax profile "%s" must offer the current sevdesk accounts as a select.', $profileId)
            );
            self::assertStringContainsString(
                sprintf('{if $%s && !$%s}', $storedIdVariable, $foundVariable),
                $profile
            );
            self::assertStringContainsString(
                sprintf('Gespeicherte ID {$%s|escape:', $storedIdVariable),
                $profile,
                sprintf('Tax profile "%s" must preserve an account missing from current guidance.', $profileId)
            );
            self::assertStringContainsString('{else}', $profile);
            self::assertMatchesRegularExpression(
                sprintf(
                    '/<input\b(?=[^>]*\btype="number")(?=[^>]*\bid="%s")'
                    . '(?=[^>]*\bname="%s")[^>]*>/',
                    preg_quote($controlId, '/'),
                    preg_quote($fieldName, '/')
                ),
                $profile,
                sprintf('Tax profile "%s" needs a numeric fallback when guidance is unavailable.', $profileId)
            );
        }
    }

    public function testAssignmentManagerUsesThePersistedMappingIdentifier(): void
    {
        $template = $this->template('assignment_manager.tpl');

        self::assertStringContainsString('<th scope="col">Mapping-ID</th>', $template);
        self::assertStringContainsString('$mapping.mapping_id|escape:', $template);
        self::assertStringNotContainsString('$mapping.created_at', $template);
    }

    public function testStaleExportContextHasAnExplicitMailFreeRequeueControl(): void
    {
        $template = $this->template('corrections.tpl');
        $controller = file_get_contents(
            dirname(__DIR__, 2) . '/modules/addons/sevdesk/lib/Controllers/AdminController.php',
        );

        self::assertIsString($controller);
        self::assertStringContainsString('name="requeue_current_mode"', $template);
        self::assertStringContainsString('name="confirm_mail_free_requeue"', $template);
        self::assertStringContainsString('requeueExportDocument(', $controller);
        self::assertStringContainsString("'stale_export_context_requeue_required'", $controller);
    }

    private function template(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/modules/addons/sevdesk/templates/' . $relativePath;
        $contents = file_get_contents($path);

        self::assertIsString($contents, sprintf('Template "%s" must be readable.', $relativePath));

        return $contents;
    }

    private function taxProfile(string $setup, string $profileId): string
    {
        $labelPosition = strpos($setup, sprintf('aria-labelledby="%s"', $profileId));
        self::assertNotFalse($labelPosition, sprintf('Tax profile "%s" must exist.', $profileId));

        $start = strrpos(substr($setup, 0, $labelPosition), '<article');
        self::assertNotFalse($start, sprintf('Tax profile "%s" must use an article.', $profileId));

        $end = strpos($setup, '</article>', $labelPosition);
        self::assertNotFalse($end, sprintf('Tax profile "%s" must close its article.', $profileId));

        return substr($setup, $start, $end + strlen('</article>') - $start);
    }
}
