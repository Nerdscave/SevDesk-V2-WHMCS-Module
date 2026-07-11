<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Support\AdminInvoiceControls;

final class AdminInvoiceControlsTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        AdminInvoiceControls::footerForms();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        AdminInvoiceControls::footerForms();
    }

    public function testEligibleInvoiceGetsPreflightLinkAndExternalQuickForm(): void
    {
        $markup = AdminInvoiceControls::render(42, null, false, true, 'token"<unsafe>');

        self::assertStringContainsString('class="btn-group"', $markup);
        self::assertStringContainsString(
            'href="addonmodules.php?module=sevdesk&amp;a=singleImport&amp;invoiceid=42"',
            $markup,
        );
        self::assertStringContainsString('Zu sevdesk exportieren', $markup);
        self::assertStringContainsString('form="sevdesk-quick-export-42"', $markup);
        self::assertStringContainsString('<svg class="sevdesk-quick-mark"', $markup);
        self::assertStringContainsString('fill="#FB523B"', $markup);
        self::assertStringContainsString('gespeicherten Rechnungsstand', $markup);
        self::assertStringContainsString('Ungespeicherte Änderungen werden nicht übernommen.', $markup);
        self::assertStringNotContainsString('<form', $markup);

        $forms = AdminInvoiceControls::footerForms();

        self::assertStringContainsString('<form id="sevdesk-quick-export-42" method="post"', $forms);
        self::assertStringContainsString(
            'action="addonmodules.php?module=sevdesk&amp;a=quickExport"',
            $forms,
        );
        self::assertStringContainsString('name="invoiceid" value="42"', $forms);
        self::assertStringContainsString('value="token&quot;&lt;unsafe&gt;"', $forms);
        self::assertStringNotContainsString('token"<unsafe>', $forms);
    }

    public function testFooterRegistryDeduplicatesInvoiceAndIsConsumed(): void
    {
        AdminInvoiceControls::render(42, null, false, true, 'first-token');
        AdminInvoiceControls::render(42, null, false, true, 'latest-token');

        $forms = AdminInvoiceControls::footerForms();

        self::assertSame(1, substr_count($forms, '<form'));
        self::assertStringContainsString('value="latest-token"', $forms);
        self::assertStringNotContainsString('first-token', $forms);
        self::assertSame('', AdminInvoiceControls::footerForms());
    }

    public function testIneligibleInvoiceKeepsPreflightButHasNoQuickAction(): void
    {
        $markup = AdminInvoiceControls::render(23, null, false, false, 'unused-token');

        self::assertStringContainsString('a=singleImport&amp;invoiceid=23', $markup);
        self::assertStringContainsString('warum kein Kurzexport möglich ist', $markup);
        self::assertStringNotContainsString('form="sevdesk-quick-export-23"', $markup);
        self::assertSame('', AdminInvoiceControls::footerForms());
    }

    public function testMissingCsrfTokenFailsClosedToPreflightLink(): void
    {
        $markup = AdminInvoiceControls::render(23, null, false, true, '');

        self::assertStringContainsString('a=singleImport&amp;invoiceid=23', $markup);
        self::assertStringNotContainsString('sevdesk-quick-mark', $markup);
        self::assertSame('', AdminInvoiceControls::footerForms());
    }

    public function testMappedInvoiceOnlyLinksToEscapedRemoteVoucher(): void
    {
        $markup = AdminInvoiceControls::render(42, 'voucher/42"><script>', false, true, 'unused-token');

        self::assertStringContainsString(
            'href="https://my.sevdesk.de/#/ex/detail/id/voucher%2F42%22%3E%3Cscript%3E"',
            $markup,
        );
        self::assertStringContainsString('target="_blank" rel="noopener"', $markup);
        self::assertStringContainsString('sevdesk-Beleg öffnen', $markup);
        self::assertStringNotContainsString('<script>', $markup);
        self::assertStringNotContainsString('singleImport', $markup);
        self::assertSame('', AdminInvoiceControls::footerForms());
    }

    public function testLegacyNullMappingLinksToFilteredAssignmentManager(): void
    {
        $markup = AdminInvoiceControls::render(91, null, true, true, 'unused-token');

        self::assertStringContainsString(
            'a=assignmentManager&amp;status=incomplete&amp;q=91',
            $markup,
        );
        self::assertStringContainsString('sevdesk-Zuordnung prüfen', $markup);
        self::assertStringContainsString('Schutz vor Duplikaten gesperrt', $markup);
        self::assertStringNotContainsString('singleImport', $markup);
        self::assertSame('', AdminInvoiceControls::footerForms());
    }

    /** @return iterable<string, array{string,string}> */
    public static function noticeProvider(): iterable
    {
        yield 'queued' => ['queued', 'Export eingereiht.'];
        yield 'already active' => ['already_active', 'Export bereits vorgemerkt.'];
        yield 'already mapped' => ['already_mapped', 'Bereits zugeordnet.'];
        yield 'legacy mapping' => ['legacy_mapping', 'Zuordnung zuerst prüfen.'];
        yield 'not found' => ['not_found', 'Rechnung nicht gefunden.'];
        yield 'blocked' => ['blocked', 'Kurzexport nicht verfügbar.'];
        yield 'failed' => ['failed', 'Kurzexport fehlgeschlagen.'];
    }

    #[DataProvider('noticeProvider')]
    public function testWhitelistedNoticeIsRenderedOnce(string $code, string $title): void
    {
        AdminInvoiceControls::storeNotice(42, $code, 73);

        $first = AdminInvoiceControls::render(42, null, false, false, '');
        $second = AdminInvoiceControls::render(42, null, false, false, '');

        self::assertStringContainsString($title, $first);
        self::assertSame(1, substr_count($first, $title));
        self::assertStringNotContainsString($title, $second);
        if (in_array($code, ['queued', 'already_active'], true)) {
            self::assertStringContainsString('a=jobDetail&amp;id=73', $first);
        } else {
            self::assertStringNotContainsString('a=jobDetail', $first);
        }
    }

    public function testNoticeForAnotherInvoiceIsNotConsumed(): void
    {
        AdminInvoiceControls::storeNotice(99, 'queued', 13);

        $otherInvoice = AdminInvoiceControls::render(42, null, false, false, '');
        $matchingInvoice = AdminInvoiceControls::render(99, null, false, false, '');

        self::assertStringNotContainsString('Export eingereiht.', $otherInvoice);
        self::assertStringContainsString('Export eingereiht.', $matchingInvoice);
    }

    public function testStoreNoticeRejectsUnknownCode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdminInvoiceControls::storeNotice(42, 'attacker-controlled');
    }

    public function testStoreNoticeRejectsInvalidInvoiceId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AdminInvoiceControls::storeNotice(0, 'queued');
    }
}
