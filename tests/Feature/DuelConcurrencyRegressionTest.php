<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AuditStatus;
use App\Enums\MatchStatus;
use App\Jobs\ProcessDuelSettlement;
use App\Models\DuelRun;
use App\Models\MatchGame;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AntiCheat\RunSimulator;
use App\Services\Financial\WalletLedgerService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DuelConcurrencyRegressionTest extends TestCase
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
     * C-01 & C-02: settleMatch is idempotent and never double-credits under re-entry.
     */
    public function test_settle_match_is_idempotent_and_never_double_credits(): void
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
            'stake_amount_cents' => 5000,
            'status' => MatchStatus::InProgress,
            'rake_bps' => 1000, // 10%
        ]);

        $this->ledgerService->lockStake($creator, $match);
        $this->ledgerService->lockStake($opponent, $match);

        // First settlement
        $this->ledgerService->settleMatch($match, $creator);

        $creatorWallet = $creator->wallet()->firstOrFail();
        $opponentWallet = $opponent->wallet()->firstOrFail();

        $balanceAfterFirst = $creatorWallet->balance_cents;
        // Initial was 10000 - 5000 stake + 9000 payout (10000 pot - 1000 rake) = 14000
        $this->assertSame(14000, $balanceAfterFirst);
        $this->assertSame(0, $creatorWallet->locked_balance_cents);
        $this->assertSame(0, $opponentWallet->locked_balance_cents);

        // Second settlement attempt (idempotent re-entry)
        $this->ledgerService->settleMatch($match, $creator);

        $creatorWallet->refresh();
        $this->assertSame(14000, $creatorWallet->balance_cents);
        $this->assertSame(0, $creatorWallet->locked_balance_cents);
    }

    /**
     * C-01: Concurrent submissions result in exactly one settlement and balanced ledger.
     */
    public function test_concurrent_submissions_result_in_one_settlement_and_consistent_ledger(): void
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
            'stake_amount_cents' => 5000,
            'status' => MatchStatus::InProgress,
            'rake_bps' => 1000,
            'game_seed' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        ]);

        $this->ledgerService->lockStake($creator, $match);
        $this->ledgerService->lockStake($opponent, $match);

        // Runs for both players
        $creatorRun = DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $creator->id,
            'started_at' => now()->subMinutes(1),
            'submitted_at' => now(),
            'final_score' => 600,
            'final_distance' => 120.0,
            'audit_status' => AuditStatus::Passed,
        ]);

        $opponentRun = DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $opponent->id,
            'started_at' => now()->subMinutes(1),
            'submitted_at' => now(),
            'final_score' => 900,
            'final_distance' => 160.0,
            'audit_status' => AuditStatus::Passed,
        ]);

        // Trigger settlement job twice concurrently
        ProcessDuelSettlement::dispatchSync($match->id);
        ProcessDuelSettlement::dispatchSync($match->id);

        $match->refresh();
        $creator->wallet->refresh();
        $opponent->wallet->refresh();

        $this->assertSame(MatchStatus::Completed, $match->status);
        $this->assertSame($opponent->id, $match->winner_user_id);
        $this->assertSame(9000, $match->winner_payout_cents);
        $this->assertSame(1000, $match->platform_fee_cents);

        // Opponent wallet: 10000 - 5000 + 9000 = 14000
        $this->assertSame(14000, $opponent->wallet->balance_cents);
        $this->assertSame(0, $opponent->wallet->locked_balance_cents);

        // Creator wallet: 10000 - 5000 = 5000
        $this->assertSame(5000, $creator->wallet->balance_cents);
        $this->assertSame(0, $creator->wallet->locked_balance_cents);
    }

    /**
     * C-03: Cannot claim forfeit while forfeit deadline is unexpired.
     */
    public function test_cannot_claim_forfeit_while_deadline_unexpired(): void
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
            'stake_amount_cents' => 5000,
            'status' => MatchStatus::InProgress,
            'in_progress_at' => now(),
            'first_run_submitted_at' => now(),
            'forfeit_deadline_at' => now()->addSeconds(180), // 3 minutes remaining
            'abandon_deadline_at' => now()->addMinutes(10),
        ]);

        $this->ledgerService->lockStake($creator, $match);
        $this->ledgerService->lockStake($opponent, $match);

        // Creator submitted
        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $creator->id,
            'submitted_at' => now(),
            'audit_status' => AuditStatus::Passed,
        ]);

        // Run cleanup while deadline is unexpired
        $this->artisan('duels:cleanup-abandoned')->assertSuccessful();

        $match->refresh();
        // Match must remain InProgress because forfeit deadline has not arrived
        $this->assertSame(MatchStatus::InProgress, $match->status);
    }

    /**
     * C-04: Cannot reuse submit ticket token.
     */
    public function test_cannot_reuse_submit_ticket_token(): void
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
            'stake_amount_cents' => 5000,
            'status' => MatchStatus::InProgress,
            'game_seed' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        ]);

        $this->ledgerService->lockStake($creator, $match);
        $this->ledgerService->lockStake($opponent, $match);

        // 1. Start run to obtain ticket
        Sanctum::actingAs($creator);
        $startRes = $this->postJson("/api/v1/duels/matches/{$match->uuid}/start-run");

        $this->assertSame(200, $startRes->status(), 'start-run failed: '.$startRes->getContent());
        $ticketToken = $startRes->json('ticket_token');
        $this->assertNotEmpty($ticketToken);

        $simulator = new RunSimulator;
        $simResult = $simulator->simulate($match->game_seed, [], 120);

        $payload = [
            'ticket_token' => $ticketToken,
            'ticks_elapsed' => 120,
            'final_distance' => $simResult['authoritative_distance'],
            'final_score' => $simResult['authoritative_score'],
            'inputs' => [],
        ];

        // 2. Submit run first time -> succeeds (202 Accepted)
        $firstSubmit = $this->postJson("/api/v1/duels/matches/{$match->uuid}/submit-run", $payload);
        $this->assertSame(202, $firstSubmit->status(), 'first submit failed: '.$firstSubmit->getContent());

        // 3. Submit run second time with SAME ticket token -> rejected (409 Conflict)
        $secondSubmit = $this->postJson("/api/v1/duels/matches/{$match->uuid}/submit-run", $payload);
        $secondSubmit->assertStatus(409);
    }

    /**
     * C-05: Cannot inject client rake override.
     */
    public function test_cannot_inject_client_rake_override(): void
    {
        /** @var User $creator */
        $creator = User::factory()->create();
        $this->ledgerService->deposit($creator, 10000);

        $response = $this->actingAs($creator, 'sanctum')
            ->postJson('/api/v1/duels/lobbies', [
                'stake_amount_cents' => 2500,
                'rake_percentage' => 0.0,
                'rake_bps' => 0,
            ]);

        // Prohibited fields must fail validation
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rake_percentage', 'rake_bps']);
    }

    /**
     * C-06: Rewarded ad verification fails closed without valid provider proof.
     */
    public function test_rewarded_ad_fails_closed_in_production(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/ads/rewarded-complete', [
                'creative_id' => 'sponsor-aegis-escrow',
                'provider_event_id' => 'unverified_arbitrary_client_event_12345',
            ]);

        $response->assertStatus(422);
    }

    /**
     * Task 4: Cleanup command retries settlement when both runs submitted (never refunds).
     */
    public function test_cleanup_command_retries_settlement_when_both_runs_submitted(): void
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
            'stake_amount_cents' => 5000,
            'status' => MatchStatus::InProgress,
            'in_progress_at' => now()->subMinutes(12),
            'abandon_deadline_at' => now()->subMinutes(2),
        ]);

        $this->ledgerService->lockStake($creator, $match);
        $this->ledgerService->lockStake($opponent, $match);

        // Both players submitted earlier
        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $creator->id,
            'submitted_at' => now()->subMinutes(5),
            'final_score' => 500,
            'final_distance' => 120.0,
            'audit_status' => AuditStatus::Passed,
        ]);

        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $opponent->id,
            'submitted_at' => now()->subMinutes(4),
            'final_score' => 800,
            'final_distance' => 180.0,
            'audit_status' => AuditStatus::Passed,
        ]);

        // Run cleanup
        $this->artisan('duels:cleanup-abandoned')
            ->expectsOutputToContain('Both runs submitted')
            ->assertSuccessful();

        $match->refresh();
        // Crucial: MUST NOT be cancelled! Must be settled with opponent as winner!
        $this->assertSame(MatchStatus::Completed, $match->status);
        $this->assertSame($opponent->id, $match->winner_user_id);
    }
}
