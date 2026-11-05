<?php

namespace App\Services;

use RuntimeException;

/** Google refused the stored refresh token (expired, revoked or password-changed): someone must press Connect Google again. */
class GoogleAccessRevoked extends RuntimeException
{
    public function __construct(?string $account)
    {
        parent::__construct('Google access for '.($account ?? 'the connected account').' has expired or was revoked. Press Connect Google again (same account) to resume syncing.');
    }
}
