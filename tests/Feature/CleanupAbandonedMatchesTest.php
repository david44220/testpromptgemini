<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AuditStatus;
use App\Enums\MatchStatus;
use App\Models\DuelRun;
use App\Models\MatchGame;
use App\Models\User;
use App\Services\Financial\WalletLedgerService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupAbandonedMatchesTest extends TestCase
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
     * Test neither player submitted -> match is cancelled and escrow refunded to both.
     */
    public function test_cleans_up_and_refunds_match_when_neither_player_submitted(): void
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
        ]);

        // Lock stakes for both players
        $this->ledgerService->lockStake($creator, $match);
        $this->ledgerService->lockStake($opponent, $match);

        // Make match stale (>10 minutes old)
        MatchGame::where('id', $match->id)->update([
            'updated_at' => now()->subMinutes(15),
        ]);

        $this->artisan('duels:cleanup-abandoned')
            ->assertSuccessful();

        $match->refresh();
        $this->assertSame(MatchStatus::Cancelled, $match->status);

        $creator->wallet->refresh();
        $opponent->wallet->refresh();

        $this->assertSame(0, $creator->wallet->locked_balance_cents);
        $this->assertSame(10000, $creator->wallet->balance_cents);

        $this->assertSame(0, $opponent->wallet->locked_balance_cents);
        $this->assertSame(10000, $opponent->wallet->balance_cents);
    }

    /**
     * Test one player submitted and other vanished -> submitted player awarded forfeit victory.
     */
    public function test_awards_forfeit_victory_when_one_player_submitted_and_other_vanished(): void
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
            'rake_percentage' => '10.00',
            'status' => MatchStatus::InProgress,
        ]);

        $this->ledgerService->lockStake($creator, $match);
        $this->ledgerService->lockStake($opponent, $match);

        // Creator submitted their run
        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $creator->id,
            'ticks_elapsed' => 1200,
            'final_score' => 2400,
            'final_distance' => '450.00',
            'submitted_at' => now()->subMinutes(12),
            'audit_status' => AuditStatus::Passed,
        ]);

        // Opponent vanished without submitting
        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $opponent->id,
            'submitted_at' => null,
            'audit_status' => AuditStatus::Pending,
        ]);

        // Age match
        MatchGame::where('id', $match->id)->update([
            'updated_at' => now()->subMinutes(12),
        ]);

        $this->artisan('duels:cleanup-abandoned')
            ->assertSuccessful();

        $match->refresh();
        $this->assertSame(MatchStatus::Completed, $match->status);
        $this->assertSame($creator->id, $match->winner_user_id);

        $creator->wallet->refresh();
        $opponent->wallet->refresh();

        $this->assertSame(0, $creator->wallet->locked_balance_cents);
        $this->assertSame(0, $opponent->wallet->locked_balance_cents);

        // Pot = 5000, Rake = 500 (10%), Winner Payout = 4500
        // Creator balance = 10000 - 2500 + 4500 = 12000
        $this->assertSame(12000, $creator->wallet->balance_cents);
        $this->assertSame(7500, $opponent->wallet->balance_cents);
    }

    /**
     * Test active/fresh matches under 10 minutes are ignored by cleanup command.
     */
    public function test_ignores_fresh_matches_under_10_minutes(): void
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

        MatchGame::where('id', $match->id)->update([
            'updated_at' => now()->subMinutes(2),
        ]);

        $this->artisan('duels:cleanup-abandoned')
            ->assertSuccessful();

        $match->refresh();
        $this->assertSame(MatchStatus::InProgress, $match->status);
    }
}
