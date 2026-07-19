<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Domain\DocumentTargetDecision;
use WHMCS\Module\Addon\SevDesk\Domain\TaxDecision;
use WHMCS\Module\Addon\SevDesk\Service\DocumentTargetResolver;

final class DocumentTargetResolverTest extends TestCase
{
    #[DataProvider('modeMatrix')]
    public function testItSelectsTheConfiguredDocumentType(
        string $mode,
        TaxDecision $taxDecision,
        string $ossProfile,
        bool $allowed,
        ?string $documentType,
    ): void {
        $decision = (new DocumentTargetResolver(
            $mode,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            $ossProfile,
        ))->resolve($taxDecision, true, true);

        self::assertSame($allowed, $decision->allowed);
        self::assertSame($documentType, $decision->documentType);
    }

    /** @return iterable<string, array{string, TaxDecision, string, bool, string|null}> */
    public static function modeMatrix(): iterable
    {
        $standard = TaxDecision::allow('domestic', '1000', '1', 'Domestic profile.');
        $rule19 = TaxDecision::allowInvoiceRule19(
            'eu_b2c_oss_rule19',
            'Confirmed electronic service.',
            ['19'],
        );

        yield 'voucher only standard' => [
            DocumentTargetResolver::MODE_VOUCHER_ONLY,
            $standard,
            DocumentTargetResolver::OSS_BLOCKED,
            true,
            DocumentTargetDecision::DOCUMENT_VOUCHER,
        ];
        yield 'voucher only blocks rule 19' => [
            DocumentTargetResolver::MODE_VOUCHER_ONLY,
            $rule19,
            DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
            false,
            null,
        ];
        yield 'hybrid keeps standard vouchers' => [
            DocumentTargetResolver::MODE_INVOICE_FOR_OSS,
            $standard,
            DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
            true,
            DocumentTargetDecision::DOCUMENT_VOUCHER,
        ];
        yield 'hybrid routes confirmed rule 19' => [
            DocumentTargetResolver::MODE_INVOICE_FOR_OSS,
            $rule19,
            DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
            true,
            DocumentTargetDecision::DOCUMENT_INVOICE,
        ];
        yield 'invoice only routes standard profile' => [
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            $standard,
            DocumentTargetResolver::OSS_BLOCKED,
            true,
            DocumentTargetDecision::DOCUMENT_INVOICE,
        ];
        yield 'invoice only routes confirmed rule 19' => [
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            $rule19,
            DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
            true,
            DocumentTargetDecision::DOCUMENT_INVOICE,
        ];
    }

    public function testRule19NeedsAnAllowedDecisionAndExplicitProfileConfirmation(): void
    {
        $resolver = new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_FOR_OSS,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
        );

        $stillBlocked = $resolver->resolve(
            TaxDecision::block('unsupported_oss', 'Voucher OSS remains blocked.', 'eu_b2c'),
            true,
            true,
        );
        self::assertFalse($stillBlocked->allowed);
        self::assertSame('unsupported_oss', $stillBlocked->code);

        $unconfirmed = (new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_FOR_OSS,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            DocumentTargetResolver::OSS_BLOCKED,
        ))->resolve(
            TaxDecision::allowInvoiceRule19('eu_b2c_oss_rule19', 'Rule 19.', ['20']),
            true,
            true,
        );
        self::assertFalse($unconfirmed->allowed);
        self::assertSame('oss_profile_not_confirmed', $unconfirmed->code);
    }

    #[DataProvider('blockedOssRules')]
    public function testRules18And20StayBlocked(string $taxRuleId): void
    {
        $decision = (new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
        ))->resolve(TaxDecision::allow('test', '1000', $taxRuleId, 'Test.'), true, true);

        self::assertFalse($decision->allowed);
        self::assertSame('unsupported_oss_rule', $decision->code);
    }

    /** @return iterable<string, array{string}> */
    public static function blockedOssRules(): iterable
    {
        yield 'rule 18 goods' => ['18'];
        yield 'rule 20 other services' => ['20'];
    }

    public function testInvoiceTargetsRequireFullPaymentAndAFinalNumber(): void
    {
        $resolver = new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            DocumentTargetResolver::OSS_BLOCKED,
        );
        $tax = TaxDecision::allow('domestic', '1000', '1', 'Domestic.');

        self::assertSame('invoice_requires_payment', $resolver->resolve($tax, false, true)->code);
        self::assertSame('invoice_number_not_final', $resolver->resolve($tax, true, false)->code);
    }

    public function testSevdeskAuthorityIsValidOnlyWithInvoiceOnly(): void
    {
        $tax = TaxDecision::allow('domestic', '1000', '1', 'Domestic.');
        $invalid = (new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_FOR_OSS,
            DocumentTargetResolver::AUTHORITY_SEVDESK,
            DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
        ))->resolve($tax, true, true);
        self::assertFalse($invalid->allowed);
        self::assertSame('invalid_document_authority_mode', $invalid->code);

        $valid = (new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            DocumentTargetResolver::AUTHORITY_SEVDESK,
            DocumentTargetResolver::OSS_BLOCKED,
        ))->resolve($tax, true, true);
        self::assertTrue($valid->allowed);
        self::assertSame(DocumentTargetDecision::DOCUMENT_INVOICE, $valid->documentType);
    }

    public function testFrozenTargetRoundTripsWithoutReResolvingConfiguration(): void
    {
        $target = (new DocumentTargetResolver(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            DocumentTargetResolver::OSS_BLOCKED,
        ))->resolve(TaxDecision::allow('domestic', '1000', '1', 'Domestic.'), true, true);

        self::assertEquals($target, DocumentTargetDecision::fromArray($target->toArray()));
    }

    public function testPersistedContextMatrixHasOneCanonicalValidator(): void
    {
        self::assertTrue(DocumentTargetResolver::contextValuesAreValid(
            DocumentTargetResolver::MODE_INVOICE_FOR_OSS,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
            'blocked',
            null,
        ));
        self::assertFalse(DocumentTargetResolver::contextValuesAreValid(
            DocumentTargetResolver::MODE_VOUCHER_ONLY,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
            'blocked',
            null,
        ));
        self::assertFalse(DocumentTargetResolver::contextValuesAreValid(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            DocumentTargetResolver::AUTHORITY_WHMCS,
            DocumentTargetResolver::OSS_RULE_19_CONFIRMED,
            'domestic_confirmed',
            null,
        ));
        self::assertFalse(DocumentTargetResolver::contextValuesAreValid(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            DocumentTargetResolver::AUTHORITY_SEVDESK,
            DocumentTargetResolver::OSS_BLOCKED,
            'blocked',
            null,
        ));
        self::assertTrue(DocumentTargetResolver::contextValuesAreValid(
            DocumentTargetResolver::MODE_INVOICE_ONLY,
            DocumentTargetResolver::AUTHORITY_SEVDESK,
            DocumentTargetResolver::OSS_BLOCKED,
            'blocked',
            DocumentTargetResolver::DELIVERY_WHMCS_TEMPLATE,
        ));
    }
}
