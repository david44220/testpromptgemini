<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\User;

interface RewardedAdVerifier
{
    /**
     * Verifies server-to-server or cryptographic proof of a completed rewarded ad.
     * Must fail closed (return false) if unconfigured or verification fails.
     */
    public function verify(string $providerEventId, string $creativeId, User $user, ?string $token = null): bool;
}
