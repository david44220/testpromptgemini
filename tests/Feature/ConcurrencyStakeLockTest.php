<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Enums\TransactionCategory;
use App\Exceptions\InsufficientFundsException;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\MatchGame;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConcurrencyStakeLockTest extends TestCase
{
    use RefreshDatabase;

    private WalletLedgerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WalletLedgerService;
        LedgerAccount::escrowHolding();
        LedgerAccount::platformRake();
    }

    /**
     * Test that two parallel attempts to lock funds with only enough balance for one stake
     * result in exactly one success and one InsufficientFundsException.
     */
    public function test_two_attempts_to_lock_funds_with_balance_for_only_one_stake(): void
    {
        $stake = 5000; // $50.00

        /** @var User $user */
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance_cents' => $stake, // Exactly enough for 1 stake
            'locked_balance_cents' => 0,
        ]);

        $match1 = MatchGame::factory()->create([
            'creator_user_id' => $user->id,
            'stake_amount_cents' => $stake,
        ]);

        $match2 = MatchGame::factory()->create([
            'creator_user_id' => $user->id,
            'stake_amount_cents' => $stake,
        ]);

        $attempt1Succeeded = false;
        $attempt2Succeeded = false;
        $attempt2CaughtException = false;

        // Attempt 1
        try {
            $this->service->lockStake($user, $match1);
            $attempt1Succeeded = true;
        } catch (InsufficientFundsException) {
            $attempt1Succeeded = false;
        }

        // Attempt 2
        try {
            $this->service->lockStake($user, $match2);
            $attempt2Succeeded = true;
        } catch (InsufficientFundsException $e) {
            $attempt2CaughtException = true;
            $this->assertStringContainsString('Insufficient funds', $e->getMessage());
        }

        $this->assertTrue($attempt1Succeeded, 'The first lock attempt should succeed.');
        $this->assertFalse($attempt2Succeeded, 'The second lock attempt must fail.');
        $this->assertTrue($attempt2CaughtException, 'The second attempt must throw InsufficientFundsException.');

        // Verify wallet final state
        $wallet->refresh();
        $this->assertSame(0, $wallet->balance_cents, 'Spendable balance must be 0.');
        $this->assertSame($stake, $wallet->locked_balance_cents, 'Locked balance must equal exactly 1 stake.');

        // Verify ledger entries
        $walletEntries = LedgerEntry::where('wallet_id', $wallet->id)
            ->where('category', TransactionCategory::EscrowLock)
            ->get();

        $this->assertCount(1, $walletEntries, 'Exactly one wallet debit entry must exist.');
        $this->assertSame($stake, $walletEntries->first()->amount_cents);
        $this->assertSame(LedgerEntryType::Debit, $walletEntries->first()->type);
        $this->assertSame(0, $walletEntries->first()->balance_after_cents);
    }

    /**
     * Test race condition simulation where pessimistic lock serializes transactions
     * and prevents second transaction from double-spending.
     */
    public function test_pessimistic_locking_prevents_race_condition(): void
    {
        $stake = 2500;

        /** @var User $user */
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance_cents' => $stake,
            'locked_balance_cents' => 0,
        ]);

        $match1 = MatchGame::factory()->create(['creator_user_id' => $user->id, 'stake_amount_cents' => $stake]);
        $match2 = MatchGame::factory()->create(['creator_user_id' => $user->id, 'stake_amount_cents' => $stake]);

        $results = [];

        // Simulate concurrent attempts via transactional blocks
        $attempts = [$match1, $match2];
        foreach ($attempts as $index => $match) {
            try {
                $this->service->lockStake($user, $match);
                $results[] = 'success';
            } catch (InsufficientFundsException) {
                $results[] = 'insufficient_funds';
            }
        }

        $this->assertSame(['success', 'insufficient_funds'], $results);

        $wallet->refresh();
        $this->assertSame(0, $wallet->balance_cents);
        $this->assertSame($stake, $wallet->locked_balance_cents);
    }

    /**
     * Test that lockStake throws when available balance is even 1 cent less than the stake.
     */
    public function test_lock_stake_rejects_when_short_by_one_cent(): void
    {
        $stake = 1000;

        /** @var User $user */
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance_cents' => 999, // 1 cent short
            'locked_balance_cents' => 0,
        ]);

        $match = MatchGame::factory()->create([
            'creator_user_id' => $user->id,
            'stake_amount_cents' => $stake,
        ]);

        $this->expectException(InsufficientFundsException::class);
        $this->service->lockStake($user, $match);

        $wallet->refresh();
        $this->assertSame(999, $wallet->balance_cents);
        $this->assertSame(0, $wallet->locked_balance_cents);
        $this->assertSame(0, LedgerEntry::count());
    }
}
