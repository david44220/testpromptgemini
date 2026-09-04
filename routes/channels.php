<?php

declare(strict_types=1);

use App\Models\MatchGame;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

/**
 * 1. presence-duel.{matchUuid}
 * Authorized ONLY for active participants (creator or opponent).
 * Returns public player payload for presence tracking.
 */
Broadcast::channel('duel.{matchUuid}', function (User $user, string $matchUuid) {
    if (! Str::isUuid($matchUuid)) {
        return false;
    }

    /** @var MatchGame|null $match */
    $match = MatchGame::where('uuid', $matchUuid)->first();

    if ($match === null) {
        return false;
    }

    if ($match->creator_user_id !== $user->id && $match->opponent_user_id !== $user->id) {
        return false;
    }

    return [
        'id' => $user->id,
        'uuid' => $user->uuid,
        'username' => $user->name,
        'avatar_url' => 'https://ui-avatars.com/api/?name='.urlencode($user->name).'&background=00f0ff&color=000',
        'ranking' => max(1, 1200 - $user->risk_score),
    ];
});

/**
 * 2. private-user.{userId}
 * For transactional and financial alerts (stake locked, funds refunded, payout credited).
 */
Broadcast::channel('user.{userId}', function (User $user, int|string $userId) {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('App.Models.User.{id}', function (User $user, int|string $id) {
    return (int) $user->id === (int) $id;
});
