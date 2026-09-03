<?php

declare(strict_types=1);

namespace App\Events\Duel;

use App\Models\MatchGame;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DuelOpponentJoined implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Synchronized countdown timestamp (e.g. current epoch ms + 3000ms countdown).
     */
    public int $startAtEpochMs;

    public function __construct(
        public MatchGame $match,
        public User $opponent,
        ?int $startAtEpochMs = null
    ) {
        $this->startAtEpochMs = $startAtEpochMs ?? (int) (microtime(true) * 1000 + 3000);
    }

    /**
     * Broadcast to the match presence channel.
     *
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("duel.{$this->match->uuid}"),
        ];
    }

    /**
     * Name of the broadcast event for client listeners (.DuelOpponentJoined or default).
     */
    public function broadcastAs(): string
    {
        return 'DuelOpponentJoined';
    }

    /**
     * Payload transmitted to all participants on the presence channel.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'match_uuid' => $this->match->uuid,
            'opponent' => [
                'id' => $this->opponent->id,
                'uuid' => $this->opponent->uuid,
                'username' => $this->opponent->name,
                'avatar_url' => 'https://ui-avatars.com/api/?name='.urlencode($this->opponent->name).'&background=ff0055&color=fff',
                'ranking' => max(1, 1200 - $this->opponent->risk_score),
            ],
            'stake_amount_cents' => $this->match->stake_amount_cents,
            'game_seed' => $this->match->game_seed,
            'start_at_epoch_ms' => $this->startAtEpochMs,
        ];
    }
}
