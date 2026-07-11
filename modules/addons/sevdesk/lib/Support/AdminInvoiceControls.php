<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Support;

use InvalidArgumentException;

/**
 * Renders native WHMCS invoice controls without nesting a form in the invoice form.
 *
 * The quick action is owned by a hidden footer form because WHMCS renders this
 * hook inside its invoice form. The button can reference that form by ID while
 * the ordinary link remains a read-only navigation to the full preflight page.
 */
final class AdminInvoiceControls
{
    private const QUICK_ACTION = 'addonmodules.php?module=sevdesk&amp;a=quickExport';

    private const NOTICE_SESSION_KEY = 'sevdesk_admin_invoice_notices';

    /** @var list<string> */
    private const NOTICE_CODES = [
        'queued',
        'already_active',
        'already_mapped',
        'legacy_mapping',
        'not_found',
        'blocked',
        'failed',
    ];

    /** @var array<int, string> */
    private static array $footerForms = [];

    public static function render(
        int $invoiceId,
        ?string $remoteId,
        bool $hasLegacyMapping,
        bool $quickEligible,
        string $csrfToken,
    ): string {
        if ($invoiceId < 1) {
            return '';
        }

        $notice = self::consumeNoticeMarkup($invoiceId);
        $remoteId = $remoteId !== null ? trim($remoteId) : null;

        if ($remoteId !== null && $remoteId !== '') {
            return $notice . self::mappedControl($remoteId);
        }

        if ($hasLegacyMapping) {
            return $notice . self::legacyControl($invoiceId);
        }

        $preflightUrl = 'addonmodules.php?module=sevdesk&amp;a=singleImport&amp;invoiceid=' . $invoiceId;
        $preflightTitle = $quickEligible
            ? 'Einzelexport öffnen, Vorprüfung ansehen und Export bestätigen'
            : 'Einzelexport öffnen; die Vorprüfung erklärt, warum kein Kurzexport möglich ist';

        $controls = '<div class="btn-group" role="group" aria-label="sevdesk-Export">'
            . '<a class="btn btn-default" href="' . $preflightUrl . '"'
            . ' title="' . self::escape($preflightTitle) . '">'
            . '<i class="fas fa-file-export" aria-hidden="true"></i> Zu sevdesk exportieren</a>';

        // Never expose a state-changing action without a usable CSRF token.
        if ($quickEligible && $csrfToken !== '') {
            $formId = 'sevdesk-quick-export-' . $invoiceId;
            self::$footerForms[$invoiceId] = self::footerForm($invoiceId, $formId, $csrfToken);
            $quickLabel = 'Kurzexport: gespeicherten Rechnungsstand als Job einreihen. '
                . 'Ungespeicherte Änderungen werden nicht übernommen.';
            $controls .= '<button type="submit" class="btn btn-default" form="' . $formId . '"'
                . ' title="' . self::escape($quickLabel) . '"'
                . ' aria-label="' . self::escape($quickLabel) . '">'
                . self::quickMark() . '</button>';
        }

        return $notice . $controls . '</div>';
    }

    /**
     * Returns and clears all external quick-export forms registered this request.
     */
    public static function footerForms(): string
    {
        $forms = implode('', self::$footerForms);
        self::$footerForms = [];

        return $forms;
    }

    public static function storeNotice(int $invoiceId, string $code, ?int $jobId = null): void
    {
        if ($invoiceId < 1) {
            throw new InvalidArgumentException('An invoice notice requires a positive invoice ID.');
        }
        if (!in_array($code, self::NOTICE_CODES, true)) {
            throw new InvalidArgumentException('Unknown invoice notice code.');
        }

        $notices = $_SESSION[self::NOTICE_SESSION_KEY] ?? [];
        if (!is_array($notices)) {
            $notices = [];
        }
        $notices[(string) $invoiceId] = [
            'code' => $code,
            'job_id' => $jobId !== null && $jobId > 0 ? $jobId : null,
        ];
        $_SESSION[self::NOTICE_SESSION_KEY] = $notices;
    }

    private static function mappedControl(string $remoteId): string
    {
        $url = 'https://my.sevdesk.de/#/ex/detail/id/' . rawurlencode($remoteId);

        return '<div class="btn-group" role="group" aria-label="sevdesk-Beleg">'
            . '<a target="_blank" rel="noopener" href="' . self::escape($url) . '" class="btn btn-default"'
            . ' title="Zugeordneten Beleg in sevdesk öffnen">'
            . '<i class="fas fa-external-link-alt" aria-hidden="true"></i> sevdesk-Beleg öffnen</a>'
            . '</div>';
    }

    private static function legacyControl(int $invoiceId): string
    {
        $url = 'addonmodules.php?module=sevdesk&amp;a=assignmentManager&amp;status=incomplete&amp;q=' . $invoiceId;
        $title = 'Unvollständige Legacy-Zuordnung prüfen; ein Kurzexport ist zum Schutz vor Duplikaten gesperrt';

        return '<div class="btn-group" role="group" aria-label="sevdesk-Zuordnung">'
            . '<a class="btn btn-warning" href="' . $url . '" title="' . self::escape($title) . '">'
            . '<i class="fas fa-exclamation-triangle" aria-hidden="true"></i> sevdesk-Zuordnung prüfen</a>'
            . '</div>';
    }

    private static function footerForm(int $invoiceId, string $formId, string $csrfToken): string
    {
        return '<form id="' . self::escape($formId) . '" method="post" action="' . self::QUICK_ACTION
            . '" hidden>'
            . '<input type="hidden" name="token" value="' . self::escape($csrfToken) . '">'
            . '<input type="hidden" name="invoiceid" value="' . $invoiceId . '">'
            . '</form>';
    }

    /** Keep the compact sevdesk mark independent of webserver-blocked module assets. */
    private static function quickMark(): string
    {
        return '<svg class="sevdesk-quick-mark" width="18" height="18" viewBox="0 0 36 36"'
            . ' xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true">'
            . '<rect width="36" height="36" rx="10" fill="#FB523B"></rect>'
            . '<rect x="9.25" y="19" width="3.5" height="7" rx="0.56" fill="white"></rect>'
            . '<rect x="16.25" y="15" width="3.5" height="11" rx="0.56" fill="white"></rect>'
            . '<rect x="23.25" y="10" width="3.5" height="16" rx="0.56" fill="white"></rect>'
            . '</svg>';
    }

    private static function consumeNoticeMarkup(int $invoiceId): string
    {
        $notices = $_SESSION[self::NOTICE_SESSION_KEY] ?? null;
        if (!is_array($notices)) {
            unset($_SESSION[self::NOTICE_SESSION_KEY]);

            return '';
        }

        $notice = $notices[(string) $invoiceId] ?? null;
        unset($notices[(string) $invoiceId]);
        if ($notices === []) {
            unset($_SESSION[self::NOTICE_SESSION_KEY]);
        } else {
            $_SESSION[self::NOTICE_SESSION_KEY] = $notices;
        }

        if (!is_array($notice) || !is_string($notice['code'] ?? null)) {
            return '';
        }

        $definition = self::noticeDefinition($notice['code']);
        if ($definition === null) {
            return '';
        }

        $jobId = is_int($notice['job_id'] ?? null) && $notice['job_id'] > 0
            ? $notice['job_id']
            : null;
        $role = in_array($definition['type'], ['warning', 'danger'], true) ? 'alert' : 'status';
        $jobLink = $jobId !== null && in_array($notice['code'], ['queued', 'already_active'], true)
            ? ' <a class="alert-link" href="addonmodules.php?module=sevdesk&amp;a=jobDetail&amp;id=' . $jobId
                . '">Job #' . $jobId . ' öffnen</a>.'
            : '';

        return '<div class="alert alert-' . $definition['type'] . '" role="' . $role . '">'
            . '<strong>' . self::escape($definition['title']) . '</strong> '
            . self::escape($definition['message']) . $jobLink . '</div>';
    }

    /** @return array{type:string,title:string,message:string}|null */
    private static function noticeDefinition(string $code): ?array
    {
        return match ($code) {
            'queued' => [
                'type' => 'success',
                'title' => 'Export eingereiht.',
                'message' => 'Die Verarbeitung läuft unabhängig von dieser Seite über den WHMCS-Cron.',
            ],
            'already_active' => [
                'type' => 'info',
                'title' => 'Export bereits vorgemerkt.',
                'message' => 'Für diese Rechnung läuft schon ein Exportjob; es wurde kein zweiter aktiver Export eingeplant.',
            ],
            'already_mapped' => [
                'type' => 'info',
                'title' => 'Bereits zugeordnet.',
                'message' => 'Die Rechnung besitzt bereits eine vollständige sevdesk-Zuordnung.',
            ],
            'legacy_mapping' => [
                'type' => 'warning',
                'title' => 'Zuordnung zuerst prüfen.',
                'message' => 'Es existiert eine unvollständige Legacy-Zuordnung; ein neuer Export ist gesperrt.',
            ],
            'not_found' => [
                'type' => 'danger',
                'title' => 'Rechnung nicht gefunden.',
                'message' => 'Es wurde kein Exportjob angelegt.',
            ],
            'blocked' => [
                'type' => 'warning',
                'title' => 'Kurzexport nicht verfügbar.',
                'message' => 'Öffnen Sie den Einzelexport, um die Vorprüfung und den Grund anzusehen.',
            ],
            'failed' => [
                'type' => 'danger',
                'title' => 'Kurzexport fehlgeschlagen.',
                'message' => 'Es wurde kein sevdesk-Aufruf ausgeführt. Bitte öffnen Sie den Einzelexport.',
            ],
            default => null,
        };
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
