<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MatchStatus;
use App\Events\Duel\DuelOpponentJoined;
use App\Events\Duel\DuelTelemetryUpdated;
use App\Models\MatchGame;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReverbWebSocketBroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LedgerAccountSeeder::class);
        RateLimiter::clear('telemetry:*');
    }

    /**
     * Test presence-duel.{matchUuid} channel authorization.
     */
    public function test_presence_channel_authorizes_active_participants_and_rejects_outsiders(): void
    {
        /** @var User $creator */
        $creator = User::factory()->create();
        /** @var User $opponent */
        $opponent = User::factory()->create();
        /** @var User $outsider */
        $outsider = User::factory()->create();

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'status' => MatchStatus::InProgress,
        ]);

        // Retrieve channel callbacks from Broadcaster
        $channelCallback = Broadcast::driver()->getChannels()->get('duel.{matchUuid}');
        $this->assertNotNull($channelCallback, 'Presence channel route duel.{matchUuid} must be registered.');

        // 1. Creator authorized
        $creatorPayload = $channelCallback($creator, $match->uuid);
        $this->assertIsArray($creatorPayload);
        $this->assertSame($creator->id, $creatorPayload['id']);
        $this->assertSame($creator->name, $creatorPayload['username']);

        // 2. Opponent authorized
        $opponentPayload = $channelCallback($opponent, $match->uuid);
        $this->assertIsArray($opponentPayload);
        $this->assertSame($opponent->id, $opponentPayload['id']);

        // 3. Outsider rejected
        $outsiderPayload = $channelCallback($outsider, $match->uuid);
        $this->assertFalse($outsiderPayload, 'Outsider must not be authorized to join duel presence channel.');

        // 4. Invalid match UUID syntax and non-existent records safely return false without QueryException
        $invalidVectors = [
            'non-existent-uuid',
            '',
            'foo',
            '../../etc/passwd',
            '123',
            '00000000-0000-0000-0000-000000000000', // Valid UUID syntax, non-existent record
        ];

        foreach ($invalidVectors as $invalidUuid) {
            $result = $channelCallback($creator, $invalidUuid);
            $this->assertFalse($result, "Expected false for UUID input [{$invalidUuid}].");
        }
    }

    /**
     * Test private-user.{userId} channel authorization.
     */
    public function test_private_user_channel_authorizes_only_matching_user(): void
    {
        /** @var User $userA */
        $userA = User::factory()->create();
        /** @var User $userB */
        $userB = User::factory()->create();

        $channelCallback = Broadcast::driver()->getChannels()->get('user.{userId}');
        $this->assertNotNull($channelCallback, 'Private channel route user.{userId} must be registered.');

        $this->assertTrue($channelCallback($userA, $userA->id));
        $this->assertFalse($channelCallback($userA, $userB->id));
    }

    /**
     * Test DuelOpponentJoined event properties and broadcast payload.
     */
    public function test_duel_opponent_joined_event_implements_should_broadcast_now(): void
    {
        /** @var User $creator */
        $creator = User::factory()->create();
        /** @var User $opponent */
        $opponent = User::factory()->create();

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
        ]);

        $event = new DuelOpponentJoined($match, $opponent);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event, 'Event must implement ShouldBroadcastNow to bypass queue delay.');

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PresenceChannel::class, $channels[0]);
        $this->assertSame("presence-duel.{$match->uuid}", $channels[0]->name);

        $payload = $event->broadcastWith();
        $this->assertSame($match->uuid, $payload['match_uuid']);
        $this->assertSame($opponent->id, $payload['opponent']['id']);
        $this->assertArrayHasKey('start_at_epoch_ms', $payload);
        $this->assertGreaterThan(0, $payload['start_at_epoch_ms']);
    }

    /**
     * Test DuelTelemetryUpdated event properties and payload.
     */
    public function test_duel_telemetry_updated_event_broadcasts_on_presence_channel(): void
    {
        $matchUuid = 'test-match-uuid-1234';
        $userId = 42;

        $event = new DuelTelemetryUpdated(
            matchUuid: $matchUuid,
            userId: $userId,
            distance: 412.8,
            score: 5200,
            currentLane: 1,
            isAlive: true,
            timestamp: 1726000021450,
        );

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PresenceChannel::class, $channels[0]);
        $this->assertSame("presence-duel.{$matchUuid}", $channels[0]->name);

        $payload = $event->broadcastWith();
        $this->assertSame($userId, $payload['user_id']);
        $this->assertSame(412.8, $payload['distance']);
        $this->assertSame(5200, $payload['score']);
        $this->assertSame(1, $payload['current_lane']);
        $this->assertTrue($payload['is_alive']);
        $this->assertSame(1726000021450, $payload['timestamp']);
    }

    /**
     * Test telemetry API endpoint broadcasts telemetry and enforces rate limiting (5 Hz).
     */
    public function test_telemetry_endpoint_broadcasts_and_enforces_bandwidth_throttle(): void
    {
        Event::fake([DuelTelemetryUpdated::class]);

        /** @var User $creator */
        $creator = User::factory()->create();
        /** @var User $opponent */
        $opponent = User::factory()->create();
        /** @var User $outsider */
        $outsider = User::factory()->create();

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'status' => MatchStatus::InProgress,
        ]);

        // 1. Outsider cannot broadcast telemetry
        Sanctum::actingAs($outsider);
        $resOutsider = $this->postJson("/api/v1/duels/matches/{$match->uuid}/telemetry", [
            'distance' => 100.0,
            'score' => 500,
            'current_lane' => 0,
            'is_alive' => true,
        ]);
        $resOutsider->assertStatus(403);

        // 2. Active player can broadcast telemetry
        Sanctum::actingAs($creator);
        RateLimiter::clear("telemetry:{$match->uuid}:{$creator->id}");

        $response = $this->postJson("/api/v1/duels/matches/{$match->uuid}/telemetry", [
            'distance' => 150.5,
            'score' => 1200,
            'current_lane' => -1,
            'is_alive' => true,
        ]);
        $response->assertStatus(200)
            ->assertJson(['status' => 'OK']);

        Event::assertDispatched(DuelTelemetryUpdated::class, function (DuelTelemetryUpdated $event) use ($match, $creator) {
            return $event->matchUuid === $match->uuid
                && $event->userId === $creator->id
                && $event->distance === 150.5
                && $event->currentLane === -1;
        });

        // 3. Rate limiting: sending > 5 requests within the same second returns 429 Too Many Requests
        for ($i = 0; $i < 4; $i++) {
            $this->postJson("/api/v1/duels/matches/{$match->uuid}/telemetry", [
                'distance' => 160.0 + $i,
                'score' => 1300 + $i,
                'current_lane' => 0,
                'is_alive' => true,
            ]);
        }

        // 6th request within 1 second triggers 429 throttle
        $throttledResponse = $this->postJson("/api/v1/duels/matches/{$match->uuid}/telemetry", [
            'distance' => 200.0,
            'score' => 2000,
            'current_lane' => 1,
            'is_alive' => true,
        ]);
        $throttledResponse->assertStatus(429);
    }

    /**
     * Test joining a lobby dispatches the DuelOpponentJoined broadcast event.
     */
    public function test_joining_lobby_dispatches_duel_opponent_joined_event(): void
    {
        Event::fake([DuelOpponentJoined::class]);

        $stake = 2000;
        /** @var User $creator */
        $creator = User::factory()->create();
        Wallet::factory()->create(['user_id' => $creator->id, 'balance_cents' => 5000, 'locked_balance_cents' => 0]);

        /** @var User $opponent */
        $opponent = User::factory()->create();
        Wallet::factory()->create(['user_id' => $opponent->id, 'balance_cents' => 5000, 'locked_balance_cents' => 0]);

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'opponent_user_id' => null,
            'stake_amount_cents' => $stake,
            'status' => MatchStatus::WaitingForOpponent,
        ]);

        Sanctum::actingAs($opponent);
        $response = $this->postJson("/api/v1/duels/lobbies/{$match->uuid}/join");
        $response->assertStatus(200);

        Event::assertDispatched(DuelOpponentJoined::class, function (DuelOpponentJoined $event) use ($match, $opponent) {
            return $event->match->id === $match->id
                && $event->opponent->id === $opponent->id;
        });
    }
}
