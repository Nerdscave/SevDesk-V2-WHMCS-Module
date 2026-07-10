<?php

declare(strict_types=1);

namespace WHMCS\Module\Addon\SevDesk\Support;

/**
 * Loads the module UI assets from disk for authenticated WHMCS output hooks.
 *
 * WHMCS installations commonly deny direct web access to /modules. Keeping the
 * assets local and injecting them through the admin page avoids depending on a
 * public exception in the web-server configuration.
 */
final class AdminAssets
{
    public static function stylesheetMarkup(): string
    {
        $stylesheet = self::read('css/admin.css');

        return $stylesheet === ''
            ? ''
            : '<style id="sevdesk-admin-styles">'
                . self::neutralizeClosingTag($stylesheet, 'style')
                . '</style>';
    }

    public static function scriptMarkup(): string
    {
        $script = self::read('js/admin.js');

        return $script === ''
            ? ''
            : '<script id="sevdesk-admin-script">'
                . self::neutralizeClosingTag($script, 'script')
                . '</script>';
    }

    private static function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/assets/' . $relativePath;
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        // A missing asset must not turn an unrelated WHMCS admin request into a
        // warning or error page. The release allowlist verifies both files too.
        $contents = @file_get_contents($path);

        return is_string($contents) ? $contents : '';
    }

    private static function neutralizeClosingTag(string $contents, string $tag): string
    {
        return str_ireplace('</' . $tag, '<\/' . $tag, $contents);
    }
}
