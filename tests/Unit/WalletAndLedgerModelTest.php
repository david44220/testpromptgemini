<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Enums\MatchStatus;
use App\Enums\TransactionCategory;
use App\Exceptions\ImmutableModelException;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\MatchGame;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WalletAndLedgerModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test Wallet available balance and total balance accessors.
     */
    public function test_wallet_available_balance_accessor_calculation(): void
    {
        $wallet = new Wallet([
            'balance_cents' => 10500,
            'locked_balance_cents' => 4500,
        ]);

        $this->assertSame(10500, $wallet->available_balance);
        $this->assertSame(15000, $wallet->total_balance);
        $this->assertSame(4500, $wallet->locked_balance_cents);
    }

    /**
     * Test LedgerEntry model is strictly read-only and prevents update.
     */
    public function test_ledger_entry_is_strictly_immutable_on_update(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);

        $entry = LedgerEntry::create([
            'transaction_group_id' => (string) Str::uuid(),
            'wallet_id' => $wallet->id,
            'type' => LedgerEntryType::Debit,
            'amount_cents' => 5000,
            'category' => TransactionCategory::EscrowLock,
            'description' => 'Original description',
            'balance_after_cents' => 5000,
        ]);

        $this->expectException(ImmutableModelException::class);
        $entry->update(['description' => 'Maliciously altered description']);
    }

    /**
     * Test LedgerEntry model is strictly read-only and prevents save() when persisted.
     */
    public function test_ledger_entry_is_strictly_immutable_on_save(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);

        $entry = LedgerEntry::create([
            'transaction_group_id' => (string) Str::uuid(),
            'wallet_id' => $wallet->id,
            'type' => LedgerEntryType::Credit,
            'amount_cents' => 5000,
            'category' => TransactionCategory::WagerWin,
            'description' => 'Original prize',
            'balance_after_cents' => 10000,
        ]);

        $entry->amount_cents = 999999999;

        $this->expectException(ImmutableModelException::class);
        $entry->save();
    }

    /**
     * Test LedgerEntry model is strictly read-only and prevents delete().
     */
    public function test_ledger_entry_is_strictly_immutable_on_delete(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['user_id' => $user->id]);

        $entry = LedgerEntry::create([
            'transaction_group_id' => (string) Str::uuid(),
            'wallet_id' => $wallet->id,
            'type' => LedgerEntryType::Debit,
            'amount_cents' => 2000,
            'category' => TransactionCategory::Withdrawal,
            'description' => 'Withdrawal entry',
            'balance_after_cents' => 3000,
        ]);

        $this->expectException(ImmutableModelException::class);
        $entry->delete();
    }

    /**
     * Test LedgerAccount static resolvers return singleton chart of accounts.
     */
    public function test_ledger_account_static_resolvers(): void
    {
        $escrow1 = LedgerAccount::escrowHolding();
        $escrow2 = LedgerAccount::escrowHolding();
        $this->assertSame($escrow1->id, $escrow2->id);
        $this->assertSame(LedgerAccount::CODE_ESCROW_HOLDING, $escrow1->account_code);
        $this->assertSame(LedgerAccountType::Liability, $escrow1->type);

        $rake1 = LedgerAccount::platformRake();
        $rake2 = LedgerAccount::platformRevenue();
        $this->assertSame($rake1->id, $rake2->id);
        $this->assertSame(LedgerAccount::CODE_PLATFORM_REVENUE_RAKE, $rake1->account_code);
        $this->assertSame(LedgerAccountType::Revenue, $rake1->type);

        $liabilities = LedgerAccount::userLiabilities();
        $this->assertSame(LedgerAccount::CODE_USER_LIABILITIES, $liabilities->account_code);
        $this->assertSame(LedgerAccountType::Liability, $liabilities->type);
    }

    /**
     * Test MatchGame scopes and deterministic game seed generation.
     */
    public function test_match_game_scopes_and_seed_generation(): void
    {
        /** @var User $userA */
        $userA = User::factory()->create();
        /** @var User $userB */
        $userB = User::factory()->create();
        /** @var User $userC */
        $userC = User::factory()->create();

        // Match 1: open lobby by userA
        $openMatch = MatchGame::create([
            'creator_user_id' => $userA->id,
            'stake_amount_cents' => 1000,
            'status' => MatchStatus::WaitingForOpponent,
        ]);

        // Verify deterministic 64-char game seed was automatically generated
        $this->assertNotNull($openMatch->game_seed);
        $this->assertSame(64, strlen($openMatch->game_seed));
        $this->assertNotNull($openMatch->uuid);

        // Match 2: ready lobby between userA and userB
        $readyMatch = MatchGame::create([
            'creator_user_id' => $userA->id,
            'opponent_user_id' => $userB->id,
            'stake_amount_cents' => 1000,
            'status' => MatchStatus::Ready,
        ]);

        // Match 3: match between userB and userC
        $otherMatch = MatchGame::create([
            'creator_user_id' => $userB->id,
            'opponent_user_id' => $userC->id,
            'stake_amount_cents' => 1000,
            'status' => MatchStatus::InProgress,
        ]);

        // Test scopeOpenLobbies
        $openLobbies = MatchGame::openLobbies()->get();
        $this->assertCount(1, $openLobbies);
        $this->assertTrue($openLobbies->contains($openMatch));
        $this->assertFalse($openLobbies->contains($readyMatch));

        // Test scopeForUser
        $matchesForUserA = MatchGame::forUser($userA->id)->get();
        $this->assertCount(2, $matchesForUserA);
        $this->assertTrue($matchesForUserA->contains($openMatch));
        $this->assertTrue($matchesForUserA->contains($readyMatch));
        $this->assertFalse($matchesForUserA->contains($otherMatch));

        $matchesForUserB = MatchGame::forUser($userB->id)->get();
        $this->assertCount(2, $matchesForUserB);
        $this->assertTrue($matchesForUserB->contains($readyMatch));
        $this->assertTrue($matchesForUserB->contains($otherMatch));
    }
}
