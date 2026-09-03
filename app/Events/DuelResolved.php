<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\MatchGame;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DuelResolved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  string  $resolutionType  'VICTORY' | 'FORFEIT' | 'DISPUTE_REFUND'
     */
    public function __construct(
        public MatchGame $match,
        public ?User $winner,
        public string $resolutionType = 'VICTORY',
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("duel.{$this->match->uuid}"),
            new PrivateChannel("match.{$this->match->uuid}"),
        ];
    }

    /**
     * Data broadcast to clients.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $runs = $this->match->runs;
        $creatorRun = $runs->firstWhere('user_id', $this->match->creator_user_id);
        $opponentRun = $runs->firstWhere('user_id', $this->match->opponent_user_id);

        return [
            'match_uuid' => $this->match->uuid,
            'status' => $this->match->status->value,
            'winner_uuid' => $this->winner?->uuid,
            'winner_user_id' => $this->winner?->id,
            'winner_payout_cents' => $this->match->winner_payout_cents,
            'total_pot_cents' => $this->match->total_pot_cents ?? ($this->match->stake_amount_cents * 2),
            'platform_fee_cents' => $this->match->platform_fee_cents,
            'rake_bps' => $this->match->rake_bps,
            'resolution_type' => $this->resolutionType,
            'settled_at' => $this->match->settled_at?->toISOString(),
            'creator' => [
                'user_id' => $this->match->creator_user_id,
                'authoritative_score' => $creatorRun?->authoritative_score,
                'authoritative_distance' => $creatorRun?->authoritative_distance,
            ],
            'opponent' => [
                'user_id' => $this->match->opponent_user_id,
                'authoritative_score' => $opponentRun?->authoritative_score,
                'authoritative_distance' => $opponentRun?->authoritative_distance,
            ],
        ];
    }
}
