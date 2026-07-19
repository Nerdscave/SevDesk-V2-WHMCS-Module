<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\SevDesk\Support\EmailAttachmentContext;

final class EmailAttachmentContextTest extends TestCase
{
    public function testContextIsBoundToInvoiceAndTemplateAndCanOnlyBeConsumedOnce(): void
    {
        $pdf = "%PDF-1.7\nsynthetic";
        $token = EmailAttachmentContext::register(42, 'Final sevdesk Invoice', '../invoice 42.pdf', $pdf);

        self::assertNull(EmailAttachmentContext::consume($token, 41, 'Final sevdesk Invoice'));
        self::assertNull(EmailAttachmentContext::consume($token, 42, 'Final sevdesk Invoice'));

        $token = EmailAttachmentContext::register(42, 'Final sevdesk Invoice', '../invoice 42.pdf', $pdf);
        self::assertSame(
            ['filename' => 'invoice-42.pdf', 'data' => $pdf],
            EmailAttachmentContext::consume($token, 42, 'Final sevdesk Invoice'),
        );
        self::assertNull(EmailAttachmentContext::consume($token, 42, 'Final sevdesk Invoice'));
    }

    public function testInvalidOrOversizedPdfIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EmailAttachmentContext::register(42, 'Template', 'invoice.pdf', 'not-a-pdf');
    }
}
