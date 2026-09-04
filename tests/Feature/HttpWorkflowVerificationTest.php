<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MatchStatus;
use App\Models\MatchGame;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AntiCheat\RunSimulator;
use App\Services\Financial\WalletLedgerService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HttpWorkflowVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected WalletLedgerService $ledgerService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LedgerAccountSeeder::class);
        $this->ledgerService = app(WalletLedgerService::class);
    }

    /**
     * E2E-01: Guest redirected from authenticated game routes.
     */
    public function test_e2e_01_guest_redirected_from_authenticated_routes(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/lobby')->assertRedirect('/login');
        $this->get('/game')->assertRedirect('/login');
        $this->get('/duels/00000000-0000-0000-0000-000000000000/play')->assertRedirect('/login');
    }

    /**
     * E2E-02: Authenticated user with balance=0 cannot create lobby.
     */
    public function test_e2e_02_user_with_zero_balance_cannot_create_lobby(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        Wallet::create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'balance_cents' => 0,
            'locked_balance_cents' => 0,
            'bonus_balance_cents' => 0,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/duels/lobbies', [
            'stake_amount_cents' => 2500,
        ]);

        $response->assertStatus(422);
    }

    /**
     * E2E-03: Solo practice route loads Three.js canvas with zero stakes and no match UUID.
     */
    public function test_e2e_03_solo_practice_route_loads_with_zero_stakes_and_no_match(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/game');

        $response->assertOk()
            ->assertSee('data-stake="0"', false)
            ->assertSee('data-pot="0"', false)
            ->assertSee('data-match=""', false);
    }

    /**
     * E2E-04: Authoritative duel route loads DB values and refreshing 10 times produces at most 1 token.
     */
    public function test_e2e_04_authoritative_duel_route_loads_db_values_and_token_stays_at_one(): void
    {
        /** @var User $creator */
        $creator = User::factory()->create();
        /** @var User $opponent */
        $opponent = User::factory()->create();
        /** @var User $stranger */
        $stranger = User::factory()->create();

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'stake_amount_cents' => 5000,
            'status' => MatchStatus::InProgress,
            'game_seed' => 'deadbeefcafebabedeadbeefcafebabedeadbeefcafebabedeadbeefcafebabe',
        ]);

        // Refresh 10 times as creator
        for ($i = 0; $i < 10; $i++) {
            $response = $this->actingAs($creator)->get("/duels/{$match->uuid}/play");
            $response->assertOk()
                ->assertSee('data-stake="5000"', false)
                ->assertSee('data-pot="10000"', false)
                ->assertSee("data-match=\"{$match->uuid}\"", false)
                ->assertDontSee("data-seed=\"{$match->game_seed}\"", false)
                ->assertSee('data-commitment="'.hash('sha256', $match->game_seed).'"', false);
        }

        // Must not mint personal_access_tokens for web gameplay (session + CSRF auth)
        $tokenCount = $creator->tokens()->count();
        $this->assertSame(0, $tokenCount);

        // Stranger attempting to load duel route is rejected with 403
        $this->actingAs($stranger)
            ->get("/duels/{$match->uuid}/play")
            ->assertStatus(403);
    }

    /**
     * E2E-05 & E2E-06: Ticket token lifecycle, single-use, and authoritative settlement dispatch.
     */
    public function test_e2e_05_and_06_ticket_token_single_use(): void
    {
        /** @var User $creator */
        $creator = User::factory()->create();
        /** @var User $opponent */
        $opponent = User::factory()->create();

        $this->ledgerService->deposit($creator, 10000);
        $this->ledgerService->deposit($opponent, 10000);

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'stake_amount_cents' => 2500,
            'status' => MatchStatus::InProgress,
            'game_seed' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        ]);

        $this->ledgerService->lockStake($creator, $match);
        $this->ledgerService->lockStake($opponent, $match);

        Sanctum::actingAs($creator);

        // Start run
        $startRes = $this->postJson("/api/v1/duels/matches/{$match->uuid}/start-run");
        $startRes->assertOk();
        $ticketToken = $startRes->json('ticket_token');

        $simulator = new RunSimulator;
        $sim = $simulator->simulate($match->game_seed, [], 100);

        $payload = [
            'ticket_token' => $ticketToken,
            'ticks_elapsed' => 100,
            'final_distance' => $sim['authoritative_distance'],
            'final_score' => $sim['authoritative_score'],
            'inputs' => [],
        ];

        // First submission -> 202 Accepted
        $submitRes = $this->postJson("/api/v1/duels/matches/{$match->uuid}/submit-run", $payload);
        $submitRes->assertStatus(202)
            ->assertJsonPath('status', 'ACCEPTED');

        // Second submission -> 409 Conflict (already submitted)
        $secondRes = $this->postJson("/api/v1/duels/matches/{$match->uuid}/submit-run", $payload);
        $secondRes->assertStatus(409);
    }

    /**
     * E2E-07 & E2E-08: Telemetry and Billboard impressions.
     */
    public function test_e2e_07_and_08_telemetry_and_ad_impressions(): void
    {
        /** @var User $creator */
        $creator = User::factory()->create();
        /** @var User $opponent */
        $opponent = User::factory()->create();

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'status' => MatchStatus::InProgress,
        ]);

        Sanctum::actingAs($creator);

        // Telemetry relay
        $telemetryRes = $this->postJson("/api/v1/duels/matches/{$match->uuid}/telemetry", [
            'distance' => 150.5,
            'score' => 450,
            'current_lane' => 0,
            'is_alive' => true,
            'timestamp' => now()->getTimestampMs(),
        ]);
        $telemetryRes->assertOk();

        // Active ad creatives
        $creativesRes = $this->getJson('/api/v1/ads/active-creatives');
        $creativesRes->assertOk()
            ->assertJsonStructure(['billboards_3d', 'web_banners', 'rewarded_interstitials']);

        // Impression beacon ping
        $impressionRes = $this->postJson('/api/v1/ads/impression/sponsor-aegis-escrow');
        $impressionRes->assertOk()
            ->assertJsonPath('status', 'OK');
    }
}
