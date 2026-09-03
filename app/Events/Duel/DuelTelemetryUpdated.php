<?php

declare(strict_types=1);

namespace App\Events\Duel;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DuelTelemetryUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $matchUuid,
        public int $userId,
        public float $distance,
        public int $score,
        public int $currentLane,
        public bool $isAlive = true,
        public ?int $timestamp = null
    ) {
        $this->timestamp = $timestamp ?? (int) (microtime(true) * 1000);
    }

    /**
     * Broadcast on the match presence channel.
     *
     * @return array<int, PresenceChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("duel.{$this->matchUuid}"),
        ];
    }

    /**
     * Broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'DuelTelemetryUpdated';
    }

    /**
     * Payload for opponent client's ghost runner interpolation.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'distance' => round($this->distance, 2),
            'score' => $this->score,
            'current_lane' => $this->currentLane,
            'is_alive' => $this->isAlive,
            'timestamp' => $this->timestamp,
        ];
    }
}
