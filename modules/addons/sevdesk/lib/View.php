<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk;

use RuntimeException;
use WHMCS\Module\Addon\SevDesk\Support\Csrf;

final class View
{
    public function __construct(private readonly Csrf $csrf)
    {
    }

    /** @param array<string, mixed> $variables */
    public function render(string $template, array $variables = []): void
    {
        if (!defined('ROOTDIR') || !class_exists(\WHMCS\Smarty::class)) {
            throw new RuntimeException('The WHMCS Smarty runtime is unavailable.');
        }

        $smarty = new \WHMCS\Smarty();
        $smarty->setTemplateDir(ROOTDIR . '/modules/addons/sevdesk/templates');
        $variables = array_merge([
            'moduleLink' => 'addonmodules.php?module=sevdesk',
            'activeRoute' => pathinfo($template, PATHINFO_FILENAME),
            'csrfToken' => $this->csrf->token(),
            'flash' => $this->consumeFlash(),
        ], $variables);

        foreach ($variables as $key => $value) {
            $smarty->assign($key, $value);
        }
        $smarty->display($template);
    }

    public function flash(string $type, string $message, string $title = ''): void
    {
        $type = in_array($type, ['success', 'warning', 'danger', 'info'], true) ? $type : 'info';
        $existing = $_SESSION['sevdesk_flash'] ?? null;
        $priority = ['info' => 1, 'success' => 2, 'warning' => 3, 'danger' => 4];
        $existingType = is_array($existing) && is_string($existing['type'] ?? null)
            && array_key_exists($existing['type'], $priority)
            ? $existing['type']
            : 'info';
        if (
            is_array($existing)
            && $priority[$existingType] > $priority[$type]
        ) {
            return;
        }

        $_SESSION['sevdesk_flash'] = [
            'type' => $type,
            'title' => mb_substr($title, 0, 150),
            'message' => mb_substr($message, 0, 2000),
        ];
    }

    /** @return array{type:string,title:string,message:string}|null */
    private function consumeFlash(): ?array
    {
        $flash = $_SESSION['sevdesk_flash'] ?? null;
        unset($_SESSION['sevdesk_flash']);

        if (
            !is_array($flash)
            || !is_string($flash['type'] ?? null)
            || !is_string($flash['title'] ?? null)
            || !is_string($flash['message'] ?? null)
        ) {
            return null;
        }

        return [
            'type' => $flash['type'],
            'title' => $flash['title'],
            'message' => $flash['message'],
        ];
    }
}
