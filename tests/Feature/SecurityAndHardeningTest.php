<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MatchStatus;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\MatchGame;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityAndHardeningTest extends TestCase
{
    use RefreshDatabase;

    private WalletLedgerService $walletLedgerService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->walletLedgerService = new WalletLedgerService;
        LedgerAccount::escrowHolding();
        LedgerAccount::platformRake();
        LedgerAccount::userLiabilities();
    }

    /**
     * Test deposit with non-positive amount throws InvalidArgumentException.
     */
    public function test_deposit_rejects_negative_or_zero_amounts(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->walletLedgerService->deposit($user, 0);
    }

    /**
     * Test withdrawal with non-positive amount throws InvalidArgumentException.
     */
    public function test_withdraw_rejects_negative_or_zero_amounts(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $this->walletLedgerService->deposit($user, 10000);

        $this->expectException(\InvalidArgumentException::class);
        $this->walletLedgerService->withdraw($user, -500);
    }

    /**
     * Test lockStake rejects non-positive stake amounts.
     */
    public function test_lock_stake_rejects_non_positive_stake(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $this->walletLedgerService->deposit($user, 10000);

        $match = MatchGame::factory()->create([
            'stake_amount_cents' => 0,
            'creator_user_id' => $user->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->walletLedgerService->lockStake($user, $match);
    }

    /**
     * Test lockStake is idempotent and prevents double-deduction.
     */
    public function test_lock_stake_is_strictly_idempotent(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $this->walletLedgerService->deposit($user, 10000);

        $match = MatchGame::factory()->create([
            'stake_amount_cents' => 3000,
            'creator_user_id' => $user->id,
            'status' => MatchStatus::WaitingForOpponent,
        ]);

        // First lock
        $this->walletLedgerService->lockStake($user, $match);
        $wallet = $user->wallet->fresh();
        $this->assertSame(7000, $wallet->balance_cents);
        $this->assertSame(3000, $wallet->locked_balance_cents);

        // Second lock attempt on the exact same match should be a no-op
        $this->walletLedgerService->lockStake($user, $match);
        $walletFresh = $user->wallet->fresh();
        $this->assertSame(7000, $walletFresh->balance_cents, 'Idempotent lock must not double-deduct funds.');
        $this->assertSame(3000, $walletFresh->locked_balance_cents);
    }

    /**
     * Test loginAsDemo strictly rejects non-whitelisted emails to prevent account takeover.
     */
    public function test_login_as_demo_rejects_non_whitelisted_emails(): void
    {
        config(['duels.demo_mode' => true]);

        // Target an arbitrary user
        $victim = User::factory()->create(['email' => 'victim_ceo@bank.com']);

        $response = $this->post('/login/demo', [
            'email' => 'victim_ceo@bank.com',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /**
     * Test loginAsDemo succeeds for authorized demo accounts when demo_mode is enabled.
     */
    public function test_login_as_demo_accepts_whitelisted_demo_accounts(): void
    {
        config(['duels.demo_mode' => true]);

        $demoUser = User::factory()->create(['email' => 'apex@cyber-rail.gg']);

        $response = $this->post('/login/demo', [
            'email' => 'apex@cyber-rail.gg',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($demoUser);
    }

    /**
     * Test dashboard deposit creates immutable double-entry ledger records.
     */
    public function test_dashboard_deposit_creates_double_entry_ledger_records(): void
    {
        config(['duels.demo_mode' => true]);

        /** @var User $user */
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create([
            'user_id' => $user->id,
            'balance_cents' => 5000,
            'locked_balance_cents' => 0,
        ]);

        $initialLedgerCount = LedgerEntry::where('wallet_id', $wallet->id)->count();

        $this->actingAs($user)
            ->post('/user/wallet/deposit', [
                'amount_dollars' => 150, // $150 => 15000 cents
            ])
            ->assertRedirect();

        $wallet->refresh();
        $this->assertSame(20000, $wallet->balance_cents);

        $newLedgerCount = LedgerEntry::where('wallet_id', $wallet->id)->count();
        $this->assertSame($initialLedgerCount + 1, $newLedgerCount, 'Deposit must record a LedgerEntry.');
    }
}
