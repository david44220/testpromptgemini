<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Enums\MatchStatus;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\MatchGame;
use App\Models\User;
use App\Services\Financial\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoubleEntryLedgerBalanceTest extends TestCase
{
    use RefreshDatabase;

    private WalletLedgerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WalletLedgerService;
        LedgerAccount::escrowHolding();
        LedgerAccount::platformRake();
        LedgerAccount::userLiabilities();
    }

    /**
     * Test that across deposits, locks, match settlements, cancellations, and withdrawals,
     * every transaction group satisfies: (Credit sum) == (Debit sum),
     * and the entire global ledger maintains strict mathematical balance: Sum(Debits) == Sum(Credits).
     */
    public function test_double_entry_balance_invariance_across_all_transaction_types(): void
    {
        /** @var User $userA */
        $userA = User::factory()->create();
        /** @var User $userB */
        $userB = User::factory()->create();

        // 1. Deposits
        $this->service->deposit($userA, 10000, 'User A deposit $100');
        $this->service->deposit($userB, 10000, 'User B deposit $100');

        // 2. Create and lock match 1 (stake: 3000, rake: 10%)
        $match1 = MatchGame::factory()->create([
            'creator_user_id' => $userA->id,
            'opponent_user_id' => $userB->id,
            'stake_amount_cents' => 3000,
            'rake_percentage' => 10.00,
            'status' => MatchStatus::Ready,
        ]);

        $this->service->lockStake($userA, $match1);
        $this->service->lockStake($userB, $match1);

        // 3. Settle match 1 (winner: userA)
        $this->service->settleMatch($match1, $userA);

        // 4. Create match 2 and cancel with escrow refund
        $match2 = MatchGame::factory()->create([
            'creator_user_id' => $userA->id,
            'opponent_user_id' => $userB->id,
            'stake_amount_cents' => 2000,
            'rake_percentage' => 10.00,
            'status' => MatchStatus::Ready,
        ]);

        $this->service->lockStake($userA, $match2);
        $this->service->lockStake($userB, $match2);
        $this->service->releaseEscrowOnCancel($match2);

        // 5. Withdrawal
        $this->service->withdraw($userA, 1500, 'User A partial withdrawal');

        // Verify total entries exist
        $totalEntries = LedgerEntry::count();
        $this->assertGreaterThan(0, $totalEntries, 'Ledger entries must be recorded.');

        // ASSERTION 1: Every distinct transaction_group_id must have EXACT (Credit sum) == (Debit sum)
        $groupBalances = LedgerEntry::query()
            ->select('transaction_group_id')
            ->selectRaw('SUM(CASE WHEN type = ? THEN amount_cents ELSE 0 END) as debit_sum', [LedgerEntryType::Debit->value])
            ->selectRaw('SUM(CASE WHEN type = ? THEN amount_cents ELSE 0 END) as credit_sum', [LedgerEntryType::Credit->value])
            ->groupBy('transaction_group_id')
            ->get();

        $this->assertGreaterThanOrEqual(7, $groupBalances->count());

        foreach ($groupBalances as $group) {
            $this->assertSame(
                (int) $group->debit_sum,
                (int) $group->credit_sum,
                "Transaction group {$group->transaction_group_id} has mismatched debits ({$group->debit_sum}) and credits ({$group->credit_sum})."
            );
        }

        // ASSERTION 2: Global ledger invariance: Total Sum(Credits) == Total Sum(Debits)
        $totalDebits = (int) LedgerEntry::where('type', LedgerEntryType::Debit)->sum('amount_cents');
        $totalCredits = (int) LedgerEntry::where('type', LedgerEntryType::Credit)->sum('amount_cents');

        $this->assertSame(
            $totalDebits,
            $totalCredits,
            "Global ledger sum mismatch: Debits = {$totalDebits}, Credits = {$totalCredits}."
        );
    }

    /**
     * Test Task 4: Odd-numbered pots with fractional rake strictly conserve integer balance without 1-cent drift.
     */
    public function test_odd_numbered_pot_rake_calculation_strictly_conserves_integer_balance(): void
    {
        /** @var User $userA */
        $userA = User::factory()->create();
        /** @var User $userB */
        $userB = User::factory()->create();

        $this->service->deposit($userA, 5000);
        $this->service->deposit($userB, 5000);

        // Odd stake amount: 1337 cents ($13.37), odd rake: 7.50%
        $match = MatchGame::factory()->create([
            'creator_user_id' => $userA->id,
            'opponent_user_id' => $userB->id,
            'stake_amount_cents' => 1337,
            'rake_percentage' => '7.50',
            'status' => MatchStatus::InProgress,
        ]);

        $this->service->lockStake($userA, $match);
        $this->service->lockStake($userB, $match);

        $this->service->settleMatch($match, $userA);

        $match->refresh();
        $this->assertSame(2674, $match->total_pot_cents);
        $this->assertSame($match->total_pot_cents, $match->winner_payout_cents + $match->platform_fee_cents);

        // Verify global ledger balance remains 100% in equilibrium
        $totalDebits = (int) LedgerEntry::where('type', LedgerEntryType::Debit)->sum('amount_cents');
        $totalCredits = (int) LedgerEntry::where('type', LedgerEntryType::Credit)->sum('amount_cents');
        $this->assertSame($totalDebits, $totalCredits);
    }
}
