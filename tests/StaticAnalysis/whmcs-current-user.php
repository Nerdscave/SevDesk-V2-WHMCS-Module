<?php

declare(strict_types=1);

namespace WHMCS\Authentication;

use WHMCS\User\Client;

/** Test-only signature for the WHMCS 8 authenticated-user facade. */
final class CurrentUser
{
    public function client(): ?Client
    {
        return null;
    }
}
