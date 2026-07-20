<?php

declare(strict_types=1);

namespace WHMCS\User;

/** Test-only shape of the runtime WHMCS user model. */
final class User
{
    /** @return list<Client> */
    public function getClientsByPermission(string|int $permission): array
    {
        return [];
    }
}
