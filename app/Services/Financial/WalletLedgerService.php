<?php

declare(strict_types=1);

namespace App\Services\Financial;

use App\Enums\LedgerEntryType;
use App\Enums\MatchStatus;
use App\Enums\TransactionCategory;
use App\Exceptions\InsufficientFundsException;
use App\Exceptions\InvalidMatchStateException;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\MatchGame;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletLedgerService
{
    /**
     * Lock a player's stake into escrow for a match.
     *
     * @throws InsufficientFundsException
     */
    public function lockStake(User $user, MatchGame $match): void
    {
        if ($match->stake_amount_cents <= 0) {
            throw new \InvalidArgumentException('Stake amount must be strictly positive.');
        }

        DB::transaction(function () use ($user, $match): void {
            /** @var Wallet|null $wallet */
            $wallet = Wallet::where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($wallet === null) {
                throw new InsufficientFundsException("Wallet not found for user #{$user->id}.");
            }

            // Idempotency check: don't double-lock if already locked
            $alreadyLocked = LedgerEntry::where('wallet_id', $wallet->id)
                ->where('reference_type', MatchGame::class)
                ->where('reference_id', $match->id)
                ->where('category', TransactionCategory::EscrowLock)
                ->exists();

            if ($alreadyLocked) {
                return;
            }

            if ($wallet->balance_cents < $match->stake_amount_cents) {
                throw new InsufficientFundsException(sprintf(
                    'Insufficient funds: required %d cents, available %d cents.',
                    $match->stake_amount_cents,
                    $wallet->balance_cents
                ));
            }

            // Deduct stake_amount_cents from balance_cents and add to locked_balance_cents
            $wallet->balance_cents -= $match->stake_amount_cents;
            $wallet->locked_balance_cents += $match->stake_amount_cents;
            $wallet->save();

            $groupId = (string) Str::uuid();
            $escrowHolding = LedgerAccount::escrowHolding();

            // 1. Debit User Wallet Liability
            LedgerEntry::create([
                'transaction_group_id' => $groupId,
                'wallet_id' => $wallet->id,
                'ledger_account_id' => null,
                'type' => LedgerEntryType::Debit,
                'amount_cents' => $match->stake_amount_cents,
                'category' => TransactionCategory::EscrowLock,
                'reference_type' => MatchGame::class,
                'reference_id' => $match->id,
                'description' => "Escrow stake lock for match #{$match->id}",
                'balance_after_cents' => $wallet->balance_cents,
            ]);

            // 2. Credit Escrow Holding Liability
            LedgerEntry::create([
                'transaction_group_id' => $groupId,
                'wallet_id' => null,
                'ledger_account_id' => $escrowHolding->id,
                'type' => LedgerEntryType::Credit,
                'amount_cents' => $match->stake_amount_cents,
                'category' => TransactionCategory::EscrowLock,
                'reference_type' => MatchGame::class,
                'reference_id' => $match->id,
                'description' => "Escrow holding pool received stake for match #{$match->id} from user #{$user->id}",
                'balance_after_cents' => 0,
            ]);
        });
    }

    /**
     * Refund locked balances to participants if the match is aborted or no opponent joins.
     *
     * @throws InvalidMatchStateException
     */
    public function releaseEscrowOnCancel(MatchGame $match): void
    {
        DB::transaction(function () use ($match): void {
            /** @var MatchGame $lockedMatch */
            $lockedMatch = MatchGame::where('id', $match->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedMatch->status === MatchStatus::Completed || $lockedMatch->status === MatchStatus::Cancelled) {
                throw new InvalidMatchStateException("Match #{$lockedMatch->id} cannot be cancelled in state {$lockedMatch->status->value}.");
            }

            /** @var list<int> $participantIds */
            $participantIds = array_values(array_filter([
                $lockedMatch->creator_user_id,
                $lockedMatch->opponent_user_id,
            ]));
            sort($participantIds);

            $escrowHolding = LedgerAccount::escrowHolding();

            foreach ($participantIds as $participantId) {
                /** @var Wallet $wallet */
                $wallet = Wallet::where('user_id', $participantId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Reverse the lock, credit back to balance_cents, decrement locked_balance_cents
                $wallet->balance_cents += $lockedMatch->stake_amount_cents;
                $wallet->locked_balance_cents -= $lockedMatch->stake_amount_cents;
                $wallet->save();

                $groupId = (string) Str::uuid();

                // Debit Escrow Holding
                LedgerEntry::create([
                    'transaction_group_id' => $groupId,
                    'wallet_id' => null,
                    'ledger_account_id' => $escrowHolding->id,
                    'type' => LedgerEntryType::Debit,
                    'amount_cents' => $lockedMatch->stake_amount_cents,
                    'category' => TransactionCategory::EscrowRelease,
                    'reference_type' => MatchGame::class,
                    'reference_id' => $lockedMatch->id,
                    'description' => "Escrow release from holding pool on match cancellation #{$lockedMatch->id}",
                    'balance_after_cents' => 0,
                ]);

                // Credit User Wallet
                LedgerEntry::create([
                    'transaction_group_id' => $groupId,
                    'wallet_id' => $wallet->id,
                    'ledger_account_id' => null,
                    'type' => LedgerEntryType::Credit,
                    'amount_cents' => $lockedMatch->stake_amount_cents,
                    'category' => TransactionCategory::EscrowRelease,
                    'reference_type' => MatchGame::class,
                    'reference_id' => $lockedMatch->id,
                    'description' => "Escrow stake refunded for cancelled match #{$lockedMatch->id}",
                    'balance_after_cents' => $wallet->balance_cents,
                ]);
            }

            $lockedMatch->status = MatchStatus::Cancelled;
            $lockedMatch->save();
        });
    }

    /**
     * Refunds the honest player's stake immediately when an opponent is caught cheating.
     * Match is transitioned to Disputed, and cheater is flagged.
     */
    public function refundHonestPlayerOnDispute(MatchGame $match, User $honestPlayer, User $cheater, string $reason = 'Anti-cheat violation'): void
    {
        DB::transaction(function () use ($match, $honestPlayer, $cheater, $reason): void {
            /** @var MatchGame $lockedMatch */
            $lockedMatch = MatchGame::where('id', $match->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Wallet $honestWallet */
            $honestWallet = Wallet::where('user_id', $honestPlayer->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Return locked stake back to honest player's available balance
            $honestWallet->locked_balance_cents -= $lockedMatch->stake_amount_cents;
            $honestWallet->balance_cents += $lockedMatch->stake_amount_cents;
            $honestWallet->save();

            $groupId = (string) Str::uuid();
            $escrowHolding = LedgerAccount::escrowHolding();

            // Debit Escrow Holding
            LedgerEntry::create([
                'transaction_group_id' => $groupId,
                'wallet_id' => null,
                'ledger_account_id' => $escrowHolding->id,
                'type' => LedgerEntryType::Debit,
                'amount_cents' => $lockedMatch->stake_amount_cents,
                'category' => TransactionCategory::EscrowRelease,
                'reference_type' => MatchGame::class,
                'reference_id' => $lockedMatch->id,
                'description' => "Escrow dispute refund for honest user #{$honestPlayer->id} on match #{$lockedMatch->id}: {$reason}",
                'balance_after_cents' => 0,
            ]);

            // Credit Honest User Wallet
            LedgerEntry::create([
                'transaction_group_id' => $groupId,
                'wallet_id' => $honestWallet->id,
                'ledger_account_id' => null,
                'type' => LedgerEntryType::Credit,
                'amount_cents' => $lockedMatch->stake_amount_cents,
                'category' => TransactionCategory::EscrowRelease,
                'reference_type' => MatchGame::class,
                'reference_id' => $lockedMatch->id,
                'description' => "Stake refunded due to opponent anti-cheat violation on match #{$lockedMatch->id}",
                'balance_after_cents' => $honestWallet->balance_cents,
            ]);

            // Flag cheater
            $cheater->risk_score = max($cheater->risk_score, 100);
            $cheater->save();

            $lockedMatch->status = MatchStatus::Disputed;
            $lockedMatch->save();
        });
    }

    /**
     * Settle a duel match: deduct locked funds from both players, credit payout to winner, and rake to platform.
     *
     * @throws InvalidMatchStateException
     * @throws InsufficientFundsException
     */
    public function settleMatch(MatchGame $match, User $winner): void
    {
        DB::transaction(function () use ($match, $winner): void {
            /** @var MatchGame $lockedMatch */
            $lockedMatch = MatchGame::where('id', $match->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedMatch->status !== MatchStatus::InProgress && $lockedMatch->status !== MatchStatus::Ready) {
                throw new InvalidMatchStateException("Match must be InProgress or Ready to settle, current: {$lockedMatch->status->value}.");
            }

            if ($lockedMatch->opponent_user_id === null) {
                throw new InvalidMatchStateException("Match #{$lockedMatch->id} cannot be settled without an opponent.");
            }

            if ($winner->id !== $lockedMatch->creator_user_id && $winner->id !== $lockedMatch->opponent_user_id) {
                throw new InvalidMatchStateException("Winner must be a participant in match #{$lockedMatch->id}.");
            }

            // Lock both players' wallets in deterministic order to eliminate deadlocks
            /** @var list<int> $participantIds */
            $participantIds = [$lockedMatch->creator_user_id, $lockedMatch->opponent_user_id];
            sort($participantIds);

            $wallets = Wallet::whereIn('user_id', $participantIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('user_id');

            // Deduct the locked amounts completely from both wallets' locked_balance_cents
            foreach ($participantIds as $uid) {
                /** @var Wallet|null $w */
                $w = $wallets->get($uid);
                if ($w === null) {
                    throw new InsufficientFundsException("Wallet not found for participant #{$uid}.");
                }
                $w->locked_balance_cents -= $lockedMatch->stake_amount_cents;
                $w->save();
            }

            // Calculate rake and payout
            $totalPot = $lockedMatch->stake_amount_cents * 2;
            $rakeBps = (int) round(((float) $lockedMatch->rake_percentage) * 100);
            $platformFee = intdiv($totalPot * $rakeBps, 10000);
            $payout = $totalPot - $platformFee;

            // Credit winner wallet: balance_cents += payout
            /** @var Wallet $winnerWallet */
            $winnerWallet = $wallets->get($winner->id);
            $winnerWallet->balance_cents += $payout;
            $winnerWallet->save();

            $groupId = (string) Str::uuid();
            $escrowHolding = LedgerAccount::escrowHolding();
            $platformRevenue = LedgerAccount::platformRake();

            // 1. Debit Escrow Holding account for full pool amount
            LedgerEntry::create([
                'transaction_group_id' => $groupId,
                'wallet_id' => null,
                'ledger_account_id' => $escrowHolding->id,
                'type' => LedgerEntryType::Debit,
                'amount_cents' => $totalPot,
                'category' => TransactionCategory::WagerWin,
                'reference_type' => MatchGame::class,
                'reference_id' => $lockedMatch->id,
                'description' => "Escrow pool released for match #{$lockedMatch->id} settlement",
                'balance_after_cents' => 0,
            ]);

            // 2. Credit Winner wallet for payout amount (WagerWin)
            LedgerEntry::create([
                'transaction_group_id' => $groupId,
                'wallet_id' => $winnerWallet->id,
                'ledger_account_id' => null,
                'type' => LedgerEntryType::Credit,
                'amount_cents' => $payout,
                'category' => TransactionCategory::WagerWin,
                'reference_type' => MatchGame::class,
                'reference_id' => $lockedMatch->id,
                'description' => "Wager prize payout for match #{$lockedMatch->id}",
                'balance_after_cents' => $winnerWallet->balance_cents,
            ]);

            // 3. Credit Platform Revenue account for rake amount (PlatformFee)
            LedgerEntry::create([
                'transaction_group_id' => $groupId,
                'wallet_id' => null,
                'ledger_account_id' => $platformRevenue->id,
                'type' => LedgerEntryType::Credit,
                'amount_cents' => $platformFee,
                'category' => TransactionCategory::PlatformFee,
                'reference_type' => MatchGame::class,
                'reference_id' => $lockedMatch->id,
                'description' => "Platform rake revenue ({$lockedMatch->rake_percentage}%) for match #{$lockedMatch->id}",
                'balance_after_cents' => 0,
            ]);

            // Update match status to Completed, set winner_user_id, settled_at
            $lockedMatch->total_pot_cents = $totalPot;
            $lockedMatch->platform_fee_cents = $platformFee;
            $lockedMatch->winner_payout_cents = $payout;
            $lockedMatch->status = MatchStatus::Completed;
            $lockedMatch->winner_user_id = $winner->id;
            $lockedMatch->settled_at = now();
            $lockedMatch->save();
        });
    }

    /**
     * Credit funds into a user's wallet (e.g. from payment gateway or bank).
     */
    public function deposit(User $user, int $amountCents, string $description = 'Deposit'): void
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Deposit amount must be strictly positive.');
        }

        DB::transaction(function () use ($user, $amountCents, $description): void {
            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'currency' => 'USD',
                        'balance_cents' => 0,
                        'bonus_balance_cents' => 0,
                        'locked_balance_cents' => 0,
                    ]
                );

            $wallet->balance_cents += $amountCents;
            $wallet->save();

            $groupId = (string) Str::uuid();
            $userLiabilities = LedgerAccount::userLiabilities();

            // Debit External / Liabilities
            LedgerEntry::create([
                'transaction_group_id' => $groupId,
                'wallet_id' => null,
                'ledger_account_id' => $userLiabilities->id,
                'type' => LedgerEntryType::Debit,
                'amount_cents' => $amountCents,
                'category' => TransactionCategory::Deposit,
                'reference_type' => null,
                'reference_id' => null,
                'description' => "Incoming funds deposit for user #{$user->id}",
                'balance_after_cents' => 0,
            ]);

            // Credit User Wallet
            LedgerEntry::create([
                'transaction_group_id' => $groupId,
                'wallet_id' => $wallet->id,
                'ledger_account_id' => null,
                'type' => LedgerEntryType::Credit,
                'amount_cents' => $amountCents,
                'category' => TransactionCategory::Deposit,
                'reference_type' => null,
                'reference_id' => null,
                'description' => $description,
                'balance_after_cents' => $wallet->balance_cents,
            ]);
        });
    }

    /**
     * Debit funds from a user's wallet for withdrawal.
     *
     * @throws InsufficientFundsException
     */
    public function withdraw(User $user, int $amountCents, string $description = 'Withdrawal'): void
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Withdrawal amount must be strictly positive.');
        }

        DB::transaction(function () use ($user, $amountCents, $description): void {
            /** @var Wallet|null $wallet */
            $wallet = Wallet::where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($wallet === null || $wallet->balance_cents < $amountCents) {
                throw new InsufficientFundsException('Insufficient funds for withdrawal.');
            }

            $wallet->balance_cents -= $amountCents;
            $wallet->save();

            $groupId = (string) Str::uuid();
            $userLiabilities = LedgerAccount::userLiabilities();

            // Debit User Wallet
            LedgerEntry::create([
                'transaction_group_id' => $groupId,
                'wallet_id' => $wallet->id,
                'ledger_account_id' => null,
                'type' => LedgerEntryType::Debit,
                'amount_cents' => $amountCents,
                'category' => TransactionCategory::Withdrawal,
                'reference_type' => null,
                'reference_id' => null,
                'description' => $description,
                'balance_after_cents' => $wallet->balance_cents,
            ]);

            // Credit Liabilities
            LedgerEntry::create([
                'transaction_group_id' => $groupId,
                'wallet_id' => null,
                'ledger_account_id' => $userLiabilities->id,
                'type' => LedgerEntryType::Credit,
                'amount_cents' => $amountCents,
                'category' => TransactionCategory::Withdrawal,
                'reference_type' => null,
                'reference_id' => null,
                'description' => "Funds disbursed for user #{$user->id} withdrawal",
                'balance_after_cents' => 0,
            ]);
        });
    }
}
