<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Support;

use RuntimeException;

final class Csrf
{
    public function token(): string
    {
        if (function_exists('generate_token')) {
            return (string) generate_token('plain');
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('No authenticated WHMCS session is available.');
        }
        if (!isset($_SESSION['sevdesk_csrf']) || !is_string($_SESSION['sevdesk_csrf'])) {
            $_SESSION['sevdesk_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['sevdesk_csrf'];
    }

    public function assertPost(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            throw new RuntimeException('Diese Aktion ist nur per POST zulässig.');
        }

        if (function_exists('check_token')) {
            $result = check_token('WHMCS.admin.default');
            if ($result === false) {
                throw new RuntimeException('Das Sicherheitstoken ist ungültig oder abgelaufen.');
            }

            return;
        }

        $expected = $_SESSION['sevdesk_csrf'] ?? null;
        $provided = $_POST['token'] ?? null;
        if (!is_string($expected) || !is_string($provided) || !hash_equals($expected, $provided)) {
            throw new RuntimeException('Das Sicherheitstoken ist ungültig oder abgelaufen.');
        }
    }
}
