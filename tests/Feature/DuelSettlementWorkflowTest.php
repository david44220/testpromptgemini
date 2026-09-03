<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AuditStatus;
use App\Enums\MatchStatus;
use App\Events\DuelResolved;
use App\Jobs\ProcessDuelSettlement;
use App\Models\DuelRun;
use App\Models\MatchGame;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AntiCheat\RunAuditService;
use App\Services\Financial\WalletLedgerService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DuelSettlementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LedgerAccountSeeder::class);
    }

    /**
     * Test successful match resolution with both runs valid:
     * verify winner wallet balance is credited and loser locked balance is wiped.
     */
    public function test_successful_match_resolution_credits_winner_and_wipes_loser_locked(): void
    {
        Event::fake([DuelResolved::class]);

        $stake = 5000; // $50.00 each, $100.00 total pot, 10% rake = $10.00, payout = $90.00

        /** @var User $creator */
        $creator = User::factory()->create();
        $walletA = Wallet::factory()->create([
            'user_id' => $creator->id,
            'balance_cents' => 5000,
            'locked_balance_cents' => $stake,
        ]);

        /** @var User $opponent */
        $opponent = User::factory()->create();
        $walletB = Wallet::factory()->create([
            'user_id' => $opponent->id,
            'balance_cents' => 5000,
            'locked_balance_cents' => $stake,
        ]);

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'stake_amount_cents' => $stake,
            'rake_percentage' => 10.00,
            'status' => MatchStatus::InProgress,
        ]);

        // Creator Run: 4500 points
        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $creator->id,
            'final_score' => 4500,
            'final_distance' => 500.00,
            'started_at' => now()->subSeconds(30),
            'submitted_at' => now(),
            'audit_status' => AuditStatus::Passed,
        ]);

        // Opponent Run: 7200 points (Winner!)
        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $opponent->id,
            'final_score' => 7200,
            'final_distance' => 650.00,
            'started_at' => now()->subSeconds(30),
            'submitted_at' => now(),
            'audit_status' => AuditStatus::Passed,
        ]);

        // Execute asynchronous settlement job
        $job = new ProcessDuelSettlement($match->id);
        $job->handle(app(WalletLedgerService::class), app(RunAuditService::class));

        // Winner verification (Opponent)
        $walletB->refresh();
        $this->assertSame(0, $walletB->locked_balance_cents, 'Winner locked balance must be 0.');
        $this->assertSame(14000, $walletB->balance_cents, 'Winner balance must receive 9000 payout (5000 + 9000).');

        // Loser verification (Creator)
        $walletA->refresh();
        $this->assertSame(0, $walletA->locked_balance_cents, 'Loser locked balance must be wiped to 0.');
        $this->assertSame(5000, $walletA->balance_cents, 'Loser balance must remain at 5000.');

        // Match state verification
        $match->refresh();
        $this->assertSame(MatchStatus::Completed, $match->status);
        $this->assertSame($opponent->id, $match->winner_user_id);
        $this->assertSame(9000, $match->winner_payout_cents);
        $this->assertSame(1000, $match->platform_fee_cents);

        Event::assertDispatched(DuelResolved::class, function (DuelResolved $event) use ($match, $opponent) {
            return $event->match->id === $match->id
                && $event->winner?->id === $opponent->id
                && $event->resolutionType === 'VICTORY';
        });
    }

    /**
     * Test tiebreaker on final_distance when scores are identical.
     */
    public function test_match_resolution_tiebreaker_on_final_distance(): void
    {
        $stake = 3000;

        /** @var User $creator */
        $creator = User::factory()->create();
        Wallet::factory()->create(['user_id' => $creator->id, 'balance_cents' => 3000, 'locked_balance_cents' => $stake]);

        /** @var User $opponent */
        $opponent = User::factory()->create();
        Wallet::factory()->create(['user_id' => $opponent->id, 'balance_cents' => 3000, 'locked_balance_cents' => $stake]);

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'stake_amount_cents' => $stake,
            'status' => MatchStatus::InProgress,
        ]);

        // Equal scores (5000), but Opponent ran further (750m vs 620m)
        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $creator->id,
            'final_score' => 5000,
            'final_distance' => 620.00,
            'submitted_at' => now(),
            'audit_status' => AuditStatus::Passed,
        ]);

        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $opponent->id,
            'final_score' => 5000,
            'final_distance' => 750.00,
            'submitted_at' => now(),
            'audit_status' => AuditStatus::Passed,
        ]);

        $job = new ProcessDuelSettlement($match->id);
        $job->handle(app(WalletLedgerService::class), app(RunAuditService::class));

        $match->refresh();
        $this->assertSame($opponent->id, $match->winner_user_id, 'Tiebreaker on distance must award victory to opponent.');
    }

    /**
     * Test timeout handling: opponent fails to submit within 180s, creator wins by forfeit.
     */
    public function test_timeout_handling_forfeits_unsubmitted_opponent(): void
    {
        $stake = 2000;

        /** @var User $creator */
        $creator = User::factory()->create();
        $walletA = Wallet::factory()->create(['user_id' => $creator->id, 'balance_cents' => 2000, 'locked_balance_cents' => $stake]);

        /** @var User $opponent */
        $opponent = User::factory()->create();
        $walletB = Wallet::factory()->create(['user_id' => $opponent->id, 'balance_cents' => 2000, 'locked_balance_cents' => $stake]);

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'stake_amount_cents' => $stake,
            'status' => MatchStatus::InProgress,
        ]);

        // Creator submitted 200 seconds ago
        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $creator->id,
            'final_score' => 3000,
            'final_distance' => 300.00,
            'submitted_at' => now()->subSeconds(200),
            'audit_status' => AuditStatus::Passed,
        ]);

        // Opponent has not submitted
        $opponentRun = DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $opponent->id,
            'submitted_at' => null,
            'audit_status' => AuditStatus::Pending,
        ]);

        $job = new ProcessDuelSettlement($match->id);
        $job->handle(app(WalletLedgerService::class), app(RunAuditService::class));

        $match->refresh();
        $this->assertSame(MatchStatus::Completed, $match->status);
        $this->assertSame($creator->id, $match->winner_user_id);

        $opponentRun->refresh();
        $this->assertSame(AuditStatus::Forfeit, $opponentRun->audit_status);

        $walletA->refresh();
        $this->assertSame(0, $walletA->locked_balance_cents);
        $this->assertSame(5600, $walletA->balance_cents, 'Creator receives payout (2000 + 3600).');
    }

    /**
     * Test cheat detection: opponent fails anti-cheat audit, honest creator stake is refunded immediately.
     */
    public function test_cheat_detection_refunds_honest_player_immediately(): void
    {
        $stake = 4000;

        /** @var User $creator */
        $creator = User::factory()->create();
        $walletA = Wallet::factory()->create(['user_id' => $creator->id, 'balance_cents' => 4000, 'locked_balance_cents' => $stake]);

        /** @var User $opponent */
        $opponent = User::factory()->create(['risk_score' => 0]);
        $walletB = Wallet::factory()->create(['user_id' => $opponent->id, 'balance_cents' => 4000, 'locked_balance_cents' => $stake]);

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'stake_amount_cents' => $stake,
            'status' => MatchStatus::InProgress,
        ]);

        // Honest Creator Run
        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $creator->id,
            'final_score' => 4000,
            'final_distance' => 400.00,
            'submitted_at' => now(),
            'audit_status' => AuditStatus::Passed,
        ]);

        // Cheater Opponent Run (Failed audit)
        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $opponent->id,
            'final_score' => 999999,
            'final_distance' => 50000.00,
            'submitted_at' => now(),
            'audit_status' => AuditStatus::Failed,
            'audit_failure_reason' => 'CHEATER_EXCESSIVE_DISTANCE',
        ]);

        $job = new ProcessDuelSettlement($match->id);
        $job->handle(app(WalletLedgerService::class), app(RunAuditService::class));

        $match->refresh();
        $this->assertSame(MatchStatus::Disputed, $match->status);

        // Honest player stake is refunded to balance immediately
        $walletA->refresh();
        $this->assertSame(0, $walletA->locked_balance_cents);
        $this->assertSame(8000, $walletA->balance_cents, 'Honest player refunded original 4000 stake (4000 + 4000 = 8000).');

        // Cheater flagged
        $opponent->refresh();
        $this->assertGreaterThanOrEqual(100, $opponent->risk_score);
    }
}
