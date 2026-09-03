<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Enums\MatchStatus;
use App\Enums\TransactionCategory;
use App\Exceptions\InvalidMatchStateException;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\MatchGame;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchSettlementEscrowTest extends TestCase
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
     * Test complete match settlement: correct winner payout, exact platform rake,
     * zero funds leaked, and complete conservation of monetary invariants.
     */
    public function test_match_settlement_payout_rake_and_zero_leakage(): void
    {
        $initialBalance = 10000; // $100.00
        $stake = 5000;          // $50.00 each => $100 pool
        $rakePercentage = 10.00; // 10% rake => $10 platform fee, $90 payout

        /** @var User $creator */
        $creator = User::factory()->create();
        $creatorWallet = Wallet::factory()->create([
            'user_id' => $creator->id,
            'balance_cents' => $initialBalance,
            'locked_balance_cents' => 0,
        ]);

        /** @var User $opponent */
        $opponent = User::factory()->create();
        $opponentWallet = Wallet::factory()->create([
            'user_id' => $opponent->id,
            'balance_cents' => $initialBalance,
            'locked_balance_cents' => 0,
        ]);

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'stake_amount_cents' => $stake,
            'rake_percentage' => $rakePercentage,
            'status' => MatchStatus::Ready,
        ]);

        // Lock stakes for both players
        $this->service->lockStake($creator, $match);
        $this->service->lockStake($opponent, $match);

        $creatorWallet->refresh();
        $opponentWallet->refresh();

        $this->assertSame(5000, $creatorWallet->balance_cents);
        $this->assertSame(5000, $creatorWallet->locked_balance_cents);
        $this->assertSame(5000, $opponentWallet->balance_cents);
        $this->assertSame(5000, $opponentWallet->locked_balance_cents);

        // Transition match to InProgress
        $match->status = MatchStatus::InProgress;
        $match->save();

        // Settle match with creator as winner
        $this->service->settleMatch($match, $creator);

        // Refresh models
        $match->refresh();
        $creatorWallet->refresh();
        $opponentWallet->refresh();

        // 1. Verify Match Attributes
        $this->assertSame(MatchStatus::Completed, $match->status);
        $this->assertSame($creator->id, $match->winner_user_id);
        $this->assertSame(10000, $match->total_pot_cents);
        $this->assertSame(1000, $match->platform_fee_cents); // 10% of 10000
        $this->assertSame(9000, $match->winner_payout_cents); // 10000 - 1000
        $this->assertNotNull($match->settled_at);

        // 2. Verify Wallet Balances
        // Winner: 5000 remaining + 9000 payout = 14000
        $this->assertSame(14000, $creatorWallet->balance_cents);
        $this->assertSame(0, $creatorWallet->locked_balance_cents);

        // Loser: 5000 remaining, 0 locked
        $this->assertSame(5000, $opponentWallet->balance_cents);
        $this->assertSame(0, $opponentWallet->locked_balance_cents);

        // 3. Verify Platform Revenue Rake in Ledger
        $platformRakeAccount = LedgerAccount::platformRake();
        $rakeEntry = LedgerEntry::where('ledger_account_id', $platformRakeAccount->id)
            ->where('category', TransactionCategory::PlatformFee)
            ->first();

        $this->assertNotNull($rakeEntry);
        $this->assertSame(1000, $rakeEntry->amount_cents);
        $this->assertSame(LedgerEntryType::Credit, $rakeEntry->type);

        // 4. Verify Escrow Holding Cleared to Zero Net Pool
        $escrowHoldingAccount = LedgerAccount::escrowHolding();
        $escrowCredits = (int) LedgerEntry::where('ledger_account_id', $escrowHoldingAccount->id)
            ->where('type', LedgerEntryType::Credit)
            ->sum('amount_cents');
        $escrowDebits = (int) LedgerEntry::where('ledger_account_id', $escrowHoldingAccount->id)
            ->where('type', LedgerEntryType::Debit)
            ->sum('amount_cents');

        $this->assertSame(10000, $escrowCredits, 'Escrow received exactly two stakes of 5000 = 10000.');
        $this->assertSame(10000, $escrowDebits, 'Escrow released exactly the total pot of 10000.');
        $this->assertSame(0, $escrowCredits - $escrowDebits, 'Escrow holding account must return to 0 net.');

        // 5. Zero Funds Leaked: Conservation of Total Money
        $totalInitialMoney = $initialBalance + $initialBalance; // 20000 cents ($200)
        $totalFinalMoney = $creatorWallet->balance_cents + $opponentWallet->balance_cents + $match->platform_fee_cents;

        $this->assertSame(
            $totalInitialMoney,
            $totalFinalMoney,
            "Monetary leak detected: initial {$totalInitialMoney} != final {$totalFinalMoney}."
        );
    }

    /**
     * Test precision with odd stakes and fractional percentages (e.g. 7.5% rake on 3333 cents stake).
     */
    public function test_settlement_with_fractional_rake_and_odd_stakes_guarantees_zero_leakage(): void
    {
        $stake = 3333; // 3333 * 2 = 6666 pot
        $rakePercentage = 7.50; // 7.5%

        /** @var User $creator */
        $creator = User::factory()->create();
        $creatorWallet = Wallet::factory()->create(['user_id' => $creator->id, 'balance_cents' => $stake]);

        /** @var User $opponent */
        $opponent = User::factory()->create();
        $opponentWallet = Wallet::factory()->create(['user_id' => $opponent->id, 'balance_cents' => $stake]);

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'stake_amount_cents' => $stake,
            'rake_percentage' => $rakePercentage,
            'status' => MatchStatus::Ready,
        ]);

        $this->service->lockStake($creator, $match);
        $this->service->lockStake($opponent, $match);

        $this->service->settleMatch($match, $opponent);

        $match->refresh();
        $creatorWallet->refresh();
        $opponentWallet->refresh();

        $totalPot = 6666;
        $expectedRake = (int) intval(round(($totalPot * (float) $rakePercentage) / 100)); // 500 cents
        $expectedPayout = $totalPot - $expectedRake; // 6166 cents

        $this->assertSame($totalPot, $match->total_pot_cents);
        $this->assertSame($expectedRake, $match->platform_fee_cents);
        $this->assertSame($expectedPayout, $match->winner_payout_cents);
        $this->assertSame($totalPot, $match->platform_fee_cents + $match->winner_payout_cents);

        // Winner (opponent) received payout
        $this->assertSame($expectedPayout, $opponentWallet->balance_cents);
        $this->assertSame(0, $creatorWallet->balance_cents);

        // Zero funds leaked
        $this->assertSame($totalPot, $opponentWallet->balance_cents + $match->platform_fee_cents);
    }

    /**
     * Test that settling an already completed match throws InvalidMatchStateException.
     */
    public function test_settle_match_rejects_already_completed_match(): void
    {
        $stake = 1000;

        /** @var User $creator */
        $creator = User::factory()->create();
        Wallet::factory()->create(['user_id' => $creator->id, 'balance_cents' => $stake]);

        /** @var User $opponent */
        $opponent = User::factory()->create();
        Wallet::factory()->create(['user_id' => $opponent->id, 'balance_cents' => $stake]);

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'stake_amount_cents' => $stake,
            'status' => MatchStatus::Completed,
        ]);

        $this->expectException(InvalidMatchStateException::class);
        $this->service->settleMatch($match, $creator);
    }
}
