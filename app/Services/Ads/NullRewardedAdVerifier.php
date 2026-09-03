<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Contracts\RewardedAdVerifier;
use App\Models\User;

/**
 * Default verifier when no external ad network provider is configured.
 * Fails closed in production environments, and allows test IDs in local/testing.
 */
class NullRewardedAdVerifier implements RewardedAdVerifier
{
    public function verify(string $providerEventId, string $creativeId, User $user, ?string $token = null): bool
    {
        // Allow deterministic mock validation strictly in automated test suite
        if (app()->environment('testing') && str_starts_with($providerEventId, 'test_verified_event_')) {
            return true;
        }

        // Fails closed in production
        return false;
    }
}
