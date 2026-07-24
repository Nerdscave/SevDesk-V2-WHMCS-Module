<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LegacyMappingAdminContractTest extends TestCase
{
    public function testInspectionAndConfirmationAreSeparateCsrfProtectedActions(): void
    {
        $controller = $this->source('lib/Controllers/AdminController.php');
        $method = $this->between(
            $controller,
            'public function assignmentManager(): void',
            'public function bookingAssistant(): void',
        );

        self::assertStringContainsString('$this->csrf->assertPost();', $method);
        self::assertStringContainsString("isset(\$_POST['inspect_legacy_type'])", $method);
        self::assertStringContainsString("isset(\$_POST['confirm_legacy_type'])", $method);
        self::assertStringContainsString('application->legacyMappingType()->inspect(', $method);
        self::assertStringContainsString('application->legacyMappingType()->confirm(', $method);
        self::assertStringContainsString("isset(\$_POST['delete'])", $method);
    }

    public function testConfirmationOnlyEnrichesTheUnchangedCompleteMapping(): void
    {
        $application = $this->source('lib/Application.php');
        $composition = $this->between(
            $application,
            'public function legacyMappingType(): LegacyMappingTypeService',
            'public function documentTargetResolver(): DocumentTargetResolver',
        );

        self::assertStringContainsString("trim((string) (\$current->sevdesk_id ?? '')) !== \$remoteId", $composition);
        self::assertStringContainsString("\$currentAuthority !== '' && \$currentAuthority !== \$documentAuthority", $composition);
        self::assertStringContainsString('legacySevdeskAuthorityReady()', $composition);
        self::assertStringContainsString('pdfSha256:', $composition);
        self::assertStringContainsString('documentAuthority:', $composition);
        self::assertStringContainsString('enrichDocumentMetadata(', $composition);
        self::assertStringNotContainsString('linkDocument(', $composition);
        self::assertStringNotContainsString('unlink', $composition);
    }

    public function testAssignmentViewShowsMetadataAndExplicitLegacyControls(): void
    {
        $template = $this->template('assignment_manager.tpl');

        self::assertStringContainsString('<th scope="col">Typ / Hoheit / Rule</th>', $template);
        self::assertStringContainsString('<th scope="col">Bereit / Zustellung</th>', $template);
        self::assertStringContainsString('$mapping.document_type', $template);
        self::assertStringContainsString('$mapping.document_number', $template);
        self::assertStringContainsString('$mapping.document_ready_at', $template);
        self::assertStringContainsString('$mapping.delivered_at', $template);
        self::assertStringContainsString('$mapping.is_e_invoice', $template);
        self::assertStringContainsString('$mapping.xml_sha256', $template);
        self::assertStringContainsString('name="inspect_legacy_type"', $template);
        self::assertStringContainsString('name="confirm_legacy_type"', $template);
        self::assertStringContainsString('name="document_type"', $template);
        self::assertStringContainsString('name="document_authority"', $template);
        self::assertStringContainsString('name="batch_authorities[', $template);
        self::assertStringContainsString('$typeInspection.context.numberEvidence', $template);
        self::assertStringContainsString('$typeInspection.context.markerEvidence', $template);
        self::assertStringContainsString('Schwächerer Legacy-Vorschlag', $template);
        self::assertStringContainsString('value="untyped"', $template);
        self::assertStringContainsString('name="filter_status"', $template);
        self::assertStringContainsString('name="filter_q"', $template);
        self::assertGreaterThanOrEqual(3, substr_count($template, 'name="token"'));
    }

    public function testUntypedFilterRequiresACompleteExistingLegacyMapping(): void
    {
        $repository = $this->source('lib/Repository/MappingRepository.php');

        self::assertStringContainsString("\$status === 'untyped'", $repository);
        self::assertStringContainsString("whereNotNull('m.sevdesk_id')", $repository);
        self::assertStringContainsString("whereNull('m.document_type')", $repository);
        self::assertStringContainsString("whereNotNull('i.id')", $repository);
    }

    public function testMappingDeletionResolvesAndVerifiesTheStoredInvoiceId(): void
    {
        $controller = $this->source('lib/Controllers/AdminController.php');
        $method = $this->between(
            $controller,
            'private function deleteMapping(int $mappingId, int $invoiceId): void',
            'private function saveSetup(): void',
        );

        self::assertStringContainsString('if ($mappingId < 1)', $method);
        self::assertStringContainsString("->first(['invoice_id', 'sevdesk_id', 'document_type'])", $method);
        self::assertStringContainsString('$invoiceId > 0 && $invoiceId !== $storedInvoiceId', $method);
        self::assertStringContainsString('$invoiceId = $storedInvoiceId;', $method);
        self::assertStringContainsString('Zuordnungs-ID und Rechnungs-ID widersprechen sich', $method);
        self::assertStringContainsString('remoteDocumentDefinitelyMissing', $method);
        self::assertStringContainsString('mappings->unlinkById(', $method);
        self::assertStringContainsString('$remoteMissingConfirmed', $method);
        self::assertStringContainsString('$remoteId !== \'\' ? $remoteId : null', $method);
        self::assertStringNotContainsString('mappings->unlink($invoiceId)', $method);
        $lookupPosition = strpos($method, "->first(['invoice_id', 'sevdesk_id', 'document_type'])");
        $activeItemPosition = strpos($method, "->where('invoice_id', \$invoiceId)");
        self::assertNotFalse($lookupPosition);
        self::assertNotFalse($activeItemPosition);
        self::assertLessThan($activeItemPosition, $lookupPosition);
    }

    public function testBatchTypingOnlyAcceptsMarkerBackedFreshRemoteEvidence(): void
    {
        $controller = $this->source('lib/Controllers/AdminController.php');
        $template = $this->template('assignment_manager.tpl');

        self::assertStringContainsString('inspectLegacyMappingsBatch', $controller);
        self::assertStringContainsString('confirmLegacyMappingsBatch', $controller);
        self::assertStringContainsString("['context']['markerEvidence']", $controller);
        self::assertStringContainsString('name="inspect_legacy_types_batch"', $template);
        self::assertStringContainsString('name="confirm_legacy_types_batch"', $template);
        self::assertStringContainsString('Markerbestätigte Typen übernehmen', $template);
        self::assertStringContainsString('Dieser Beleg bleibt ein Einzelfall', $controller);
    }

    private function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/modules/addons/sevdesk/' . $relativePath;
        $source = file_get_contents($path);
        self::assertIsString($source);

        return $source;
    }

    private function template(string $name): string
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/modules/addons/sevdesk/templates/' . $name);
        self::assertIsString($template);

        return $template;
    }

    private function between(string $source, string $startMarker, string $endMarker): string
    {
        $start = strpos($source, $startMarker);
        $end = strpos($source, $endMarker, $start === false ? 0 : $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        self::assertGreaterThan($start, $end);

        return substr($source, $start, $end - $start);
    }
}
