<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AuditStatus;
use App\Enums\MatchStatus;
use App\Events\DuelResolved;
use App\Jobs\ProcessDuelSettlement;
use App\Models\DuelRun;
use App\Models\MatchGame;
use App\Models\User;
use App\Services\Financial\WalletLedgerService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CleanupAbandonedMatchesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'duels:cleanup-abandoned';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up abandoned in-progress matches older than 10 minutes and resolve or refund stuck escrow funds.';

    /**
     * Execute the console command.
     */
    public function handle(WalletLedgerService $ledgerService): int
    {
        /** @var Collection<int, MatchGame> $candidates */
        $candidates = MatchGame::where('status', MatchStatus::InProgress)
            ->where(function ($query): void {
                $query->where(function ($q): void {
                    $q->whereNotNull('forfeit_deadline_at')
                        ->where('forfeit_deadline_at', '<', now());
                })->orWhere(function ($q): void {
                    $q->whereNotNull('abandon_deadline_at')
                        ->where('abandon_deadline_at', '<', now());
                })->orWhere(function ($q): void {
                    // Fallback for legacy records without explicit deadlines
                    $q->where('updated_at', '<', now()->subMinutes(10));
                });
            })
            ->with(['creator', 'opponent', 'runs'])
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No abandoned matches found.');

            return self::SUCCESS;
        }

        $this->info("Found {$candidates->count()} candidate match(es) for recovery evaluation.");

        foreach ($candidates as $candidate) {
            // CASE 1: Both players submitted -> RETRY AUTHORITATIVE SETTLEMENT
            // ProcessDuelSettlement manages its own distributed lock and DB transaction.
            $runs = DuelRun::where('match_id', $candidate->id)->get()->keyBy('user_id');
            $creatorSubmitted = $runs->get($candidate->creator_user_id)?->submitted_at !== null;
            $opponentSubmitted = $candidate->opponent_user_id ? $runs->get($candidate->opponent_user_id)?->submitted_at !== null : false;

            if ($creatorSubmitted && $opponentSubmitted) {
                ProcessDuelSettlement::dispatchSync($candidate->id);
                $this->info("Match #{$candidate->id}: Both runs submitted. Retried authoritative settlement.");

                continue;
            }

            $lock = Cache::lock("match_settlement_{$candidate->uuid}", 15);
            if (! $lock->get()) {
                $this->warn("Match #{$candidate->id} ({$candidate->uuid}) is locked by another process; skipping.");

                continue;
            }

            try {
                DB::transaction(function () use ($candidate, $ledgerService): void {
                    /** @var MatchGame|null $match */
                    $match = MatchGame::where('id', $candidate->id)
                        ->lockForUpdate()
                        ->first();

                    if ($match === null || $match->status !== MatchStatus::InProgress) {
                        return;
                    }

                    $runs = DuelRun::where('match_id', $match->id)
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('user_id');

                    /** @var DuelRun|null $creatorRun */
                    $creatorRun = $runs->get($match->creator_user_id);
                    /** @var DuelRun|null $opponentRun */
                    $opponentRun = $match->opponent_user_id ? $runs->get($match->opponent_user_id) : null;

                    $creatorSubmitted = $creatorRun?->submitted_at !== null;
                    $opponentSubmitted = $opponentRun?->submitted_at !== null;

                    /** @var User $creator */
                    $creator = User::findOrFail($match->creator_user_id);
                    /** @var User|null $opponent */
                    $opponent = $match->opponent_user_id ? User::find($match->opponent_user_id) : null;

                    // CASE 2: Exactly one player submitted -> evaluate forfeit deadline
                    $forfeitDeadline = $match->forfeit_deadline_at ?? ($creatorSubmitted ? $creatorRun->submitted_at?->addSeconds(180) : $opponentRun?->submitted_at?->addSeconds(180));
                    $isForfeitExpired = $forfeitDeadline !== null && now()->isAfter($forfeitDeadline);

                    if ($creatorSubmitted && ! $opponentSubmitted && $opponent !== null) {
                        if ($isForfeitExpired) {
                            if ($opponentRun !== null) {
                                $opponentRun->audit_status = AuditStatus::Forfeit;
                                $opponentRun->audit_failure_reason = 'Abandoned: forfeit';
                                $opponentRun->save();
                            }

                            $ledgerService->settleMatch($match, $creator);
                            DB::afterCommit(static function () use ($match, $creator): void {
                                event(new DuelResolved($match, $creator, 'FORFEIT'));
                            });
                            $this->info("Match #{$match->id}: Creator #{$creator->id} awarded forfeit victory.");
                        }

                        return;
                    }

                    if ($opponentSubmitted && ! $creatorSubmitted && $opponent !== null) {
                        if ($isForfeitExpired) {
                            if ($creatorRun !== null) {
                                $creatorRun->audit_status = AuditStatus::Forfeit;
                                $creatorRun->audit_failure_reason = 'Abandoned: forfeit';
                                $creatorRun->save();
                            }

                            $ledgerService->settleMatch($match, $opponent);
                            DB::afterCommit(static function () use ($match, $opponent): void {
                                event(new DuelResolved($match, $opponent, 'FORFEIT'));
                            });
                            $this->info("Match #{$match->id}: Opponent #{$opponent->id} awarded forfeit victory.");
                        }

                        return;
                    }

                    // CASE 3: Neither player submitted -> evaluate abandon deadline
                    $abandonDeadline = $match->abandon_deadline_at ?? ($match->in_progress_at?->addMinutes(10) ?? $match->updated_at->addMinutes(10));
                    if (now()->isAfter($abandonDeadline)) {
                        $ledgerService->releaseEscrowOnCancel($match);
                        DB::afterCommit(static function () use ($match): void {
                            event(new DuelResolved($match, null, 'ABANDONED_CANCELLED'));
                        });
                        $this->info("Match #{$match->id}: Both players abandoned. Escrow refunded and match cancelled.");
                    }
                });
            } catch (\Throwable $e) {
                $this->error("Error cleaning up match #{$candidate->id}: {$e->getMessage()}");
            } finally {
                $lock->release();
            }
        }

        return self::SUCCESS;
    }
}
