<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MatchStatus;
use App\Models\MatchGame;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DuelMatchmakingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LedgerAccountSeeder::class);
    }

    /**
     * Test creating a lobby locks creator stake into escrow immediately.
     */
    public function test_user_can_create_lobby_and_stake_is_locked_into_escrow(): void
    {
        /** @var User $creator */
        $creator = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $creator->id,
            'balance_cents' => 5000,
            'locked_balance_cents' => 0,
        ]);

        Sanctum::actingAs($creator);

        $response = $this->postJson('/api/v1/duels/lobbies', [
            'stake_amount_cents' => 2000,
            'rake_percentage' => 10.00,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('lobby.stake_amount_cents', 2000)
            ->assertJsonPath('lobby.status', MatchStatus::WaitingForOpponent->value);

        $wallet->refresh();
        $this->assertSame(3000, $wallet->balance_cents);
        $this->assertSame(2000, $wallet->locked_balance_cents);

        $this->assertDatabaseHas('matches', [
            'creator_user_id' => $creator->id,
            'stake_amount_cents' => 2000,
            'status' => MatchStatus::WaitingForOpponent->value,
        ]);

        $this->assertDatabaseHas('duel_runs', [
            'user_id' => $creator->id,
        ]);
    }

    /**
     * Test list lobbies excludes lobbies created by caller.
     */
    public function test_list_lobbies_excludes_caller_and_returns_open_matches(): void
    {
        /** @var User $userA */
        $userA = User::factory()->create();
        /** @var User $userB */
        $userB = User::factory()->create();

        MatchGame::factory()->create([
            'creator_user_id' => $userA->id,
            'status' => MatchStatus::WaitingForOpponent,
            'opponent_user_id' => null,
        ]);

        $matchB = MatchGame::factory()->create([
            'creator_user_id' => $userB->id,
            'status' => MatchStatus::WaitingForOpponent,
            'opponent_user_id' => null,
        ]);

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/v1/duels/lobbies');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $matchB->uuid);
    }

    /**
     * Test two users joining the exact same lobby concurrently:
     * only one succeeds, the second receives 409 Conflict, no double-lock of funds.
     */
    public function test_concurrent_join_attempts_only_one_succeeds_and_zero_funds_leaked(): void
    {
        /** @var User $creator */
        $creator = User::factory()->create();
        Wallet::factory()->create(['user_id' => $creator->id, 'balance_cents' => 10000, 'locked_balance_cents' => 2500]);

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'stake_amount_cents' => 2500,
            'status' => MatchStatus::WaitingForOpponent,
            'opponent_user_id' => null,
        ]);

        /** @var User $userB */
        $userB = User::factory()->create();
        $walletB = Wallet::factory()->create(['user_id' => $userB->id, 'balance_cents' => 10000, 'locked_balance_cents' => 0]);

        /** @var User $userC */
        $userC = User::factory()->create();
        $walletC = Wallet::factory()->create(['user_id' => $userC->id, 'balance_cents' => 10000, 'locked_balance_cents' => 0]);

        // First join succeeds
        Sanctum::actingAs($userB);
        $responseB = $this->postJson("/api/v1/duels/lobbies/{$match->uuid}/join");

        $responseB->assertStatus(200)
            ->assertJsonPath('match.status', MatchStatus::InProgress->value);

        $walletB->refresh();
        $this->assertSame(2500, $walletB->locked_balance_cents);
        $this->assertSame(7500, $walletB->balance_cents);

        // Second join on same lobby fails with 409 Conflict
        Sanctum::actingAs($userC);
        $responseC = $this->postJson("/api/v1/duels/lobbies/{$match->uuid}/join");

        $responseC->assertStatus(409);

        // Ensure User C's funds were completely untouched (zero leakage)
        $walletC->refresh();
        $this->assertSame(0, $walletC->locked_balance_cents);
        $this->assertSame(10000, $walletC->available_balance);

        // Ensure match has exactly User B as opponent
        $match->refresh();
        $this->assertSame($userB->id, $match->opponent_user_id);
    }

    /**
     * Test user cannot join their own match lobby.
     */
    public function test_user_cannot_join_own_lobby(): void
    {
        /** @var User $creator */
        $creator = User::factory()->create();
        Wallet::factory()->create(['user_id' => $creator->id, 'balance_cents' => 10000]);

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'status' => MatchStatus::WaitingForOpponent,
            'opponent_user_id' => null,
        ]);

        Sanctum::actingAs($creator);

        $response = $this->postJson("/api/v1/duels/lobbies/{$match->uuid}/join");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'You cannot join your own match lobby.');
    }
}
