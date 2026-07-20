<?php

declare(strict_types=1);

if (!function_exists('sevdesk_email_pre_send')) {
    /** @param array<string, mixed> $vars @return array<string, mixed> */
    function sevdesk_email_pre_send(array $vars): array
    {
        return [];
    }
}
