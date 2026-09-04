<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditStatus;
use App\Enums\MatchStatus;
use App\Events\DuelResolved;
use App\Models\DuelRun;
use App\Models\MatchGame;
use App\Models\User;
use App\Services\AntiCheat\RunAuditService;
use App\Services\Financial\WalletLedgerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProcessDuelSettlement implements ShouldQueue
{
    use Queueable;

    public const int FORFEIT_TIMEOUT_SECONDS = 180;

    /**
     * @param  int  $matchId  ID of the match to settle
     */
    public function __construct(public int $matchId) {}

    /**
     * Execute the job.
     */
    public function handle(WalletLedgerService $ledgerService, RunAuditService $auditService): void
    {
        /** @var MatchGame|null $matchRecord */
        $matchRecord = MatchGame::find($this->matchId);
        if ($matchRecord === null || $matchRecord->opponent_user_id === null) {
            return;
        }

        // Distributed atomic locking across workers
        $lock = Cache::lock("match_settlement_{$matchRecord->uuid}", 15);
        if (! $lock->get()) {
            return; // Job already being handled by another worker
        }

        try {
            DB::transaction(function () use ($ledgerService, $auditService): void {
                // Strict lock hierarchy: 1. MatchGame row lock
                /** @var MatchGame|null $match */
                $match = MatchGame::where('id', $this->matchId)
                    ->lockForUpdate()
                    ->first();

                if ($match === null || $match->opponent_user_id === null) {
                    return;
                }

                if ($match->status !== MatchStatus::InProgress && $match->status !== MatchStatus::Ready) {
                    return;
                }

                /** @var User $creator */
                $creator = User::findOrFail($match->creator_user_id);
                /** @var User $opponent */
                $opponent = User::findOrFail($match->opponent_user_id);

                // 2. Lock both DuelRun rows
                $runs = DuelRun::where('match_id', $match->id)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('user_id');

                /** @var DuelRun|null $creatorRun */
                $creatorRun = $runs->get($creator->id);
                /** @var DuelRun|null $opponentRun */
                $opponentRun = $runs->get($opponent->id);

                $creatorSubmitted = $creatorRun?->submitted_at !== null;
                $opponentSubmitted = $opponentRun?->submitted_at !== null;

                // Case A: Both runs submitted
                if ($creatorSubmitted && $opponentSubmitted) {
                    $this->ensureRunAudited($creatorRun, $auditService);
                    $this->ensureRunAudited($opponentRun, $auditService);

                    $creatorPassed = $creatorRun->audit_status === AuditStatus::Passed;
                    $opponentPassed = $opponentRun->audit_status === AuditStatus::Passed;

                    if (! $creatorPassed || ! $opponentPassed) {
                        if ($creatorPassed && ! $opponentPassed) {
                            $ledgerService->refundHonestPlayerOnDispute(
                                $match,
                                $creator,
                                $opponent,
                                $opponentRun->audit_failure_reason ?? 'Opponent anti-cheat failure'
                            );
                            DB::afterCommit(static function () use ($match): void {
                                event(new DuelResolved($match, null, 'DISPUTED'));
                            });

                            return;
                        }

                        if ($opponentPassed && ! $creatorPassed) {
                            $ledgerService->refundHonestPlayerOnDispute(
                                $match,
                                $opponent,
                                $creator,
                                $creatorRun->audit_failure_reason ?? 'Creator anti-cheat failure'
                            );
                            DB::afterCommit(static function () use ($match): void {
                                event(new DuelResolved($match, null, 'DISPUTED'));
                            });

                            return;
                        }

                        // Both failed
                        $match->status = MatchStatus::Disputed;
                        $match->save();
                        DB::afterCommit(static function () use ($match): void {
                            event(new DuelResolved($match, null, 'DISPUTED'));
                        });

                        return;
                    }

                    // Both passed cleanly -> determine winner
                    $winner = $this->determineWinner($match, $creator, $opponent, $creatorRun, $opponentRun);
                    $ledgerService->settleMatch($match, $winner);
                    $match->refresh();
                    DB::afterCommit(static function () use ($match, $winner): void {
                        event(new DuelResolved($match, $winner, 'VICTORY'));
                    });

                    return;
                }

                // Case B: Exactly one player submitted -> verify forfeit deadline
                if ($creatorSubmitted && ! $opponentSubmitted) {
                    $forfeitDeadline = $match->forfeit_deadline_at ?? $creatorRun?->submitted_at?->addSeconds(180);
                    if ($forfeitDeadline !== null && now()->isAfter($forfeitDeadline)) {
                        $this->resolveForfeitVictory($match, $creator, $opponent, $opponentRun, $ledgerService);
                    }

                    return;
                }

                if ($opponentSubmitted && ! $creatorSubmitted) {
                    $forfeitDeadline = $match->forfeit_deadline_at ?? $opponentRun?->submitted_at?->addSeconds(180);
                    if ($forfeitDeadline !== null && now()->isAfter($forfeitDeadline)) {
                        $this->resolveForfeitVictory($match, $opponent, $creator, $creatorRun, $ledgerService);
                    }

                    return;
                }
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * Ensures run has been audited, executing audit pipeline if pending.
     */
    protected function ensureRunAudited(DuelRun $run, RunAuditService $auditService): void
    {
        if ($run->audit_status !== AuditStatus::Pending) {
            return;
        }

        $payload = [
            'ticks_elapsed' => $run->ticks_elapsed ?? 0,
            'final_distance' => (float) ($run->final_distance ?? 0),
            'final_score' => $run->final_score ?? 0,
            'inputs' => $run->input_log ?? [],
            'signature' => $run->client_signature ?? '',
            'started_at' => $run->started_at,
            'submitted_at' => $run->submitted_at,
        ];

        $result = $auditService->auditRun($run, $payload);

        if ($result->passed) {
            $run->audit_status = AuditStatus::Passed;
            $run->audit_failure_reason = null;
        } else {
            $run->audit_status = AuditStatus::Failed;
            $run->audit_failure_reason = $result->failureReason;
        }

        $run->save();
    }

    /**
     * Resolves default victory when an opponent exceeds forfeit window.
     */
    protected function resolveForfeitVictory(
        MatchGame $match,
        User $winner,
        User $forfeiter,
        ?DuelRun $forfeiterRun,
        WalletLedgerService $ledgerService
    ): void {
        if ($forfeiterRun !== null) {
            $forfeiterRun->audit_status = AuditStatus::Forfeit;
            $forfeiterRun->audit_failure_reason = 'Timed out: forfeit';
            $forfeiterRun->save();
        }

        $ledgerService->settleMatch($match, $winner);
        $match->refresh();
        DB::afterCommit(static function () use ($match, $winner): void {
            event(new DuelResolved($match, $winner, 'FORFEIT'));
        });
    }

    /**
     * Determines winner based on final_score with final_distance tiebreaker.
     */
    protected function determineWinner(
        MatchGame $match,
        User $creator,
        User $opponent,
        DuelRun $creatorRun,
        DuelRun $opponentRun
    ): User {
        $scoreA = $creatorRun->final_score ?? 0;
        $scoreB = $opponentRun->final_score ?? 0;

        if ($scoreA > $scoreB) {
            return $creator;
        }

        if ($scoreB > $scoreA) {
            return $opponent;
        }

        // Tiebreaker: Final Distance
        $distA = (float) ($creatorRun->final_distance ?? 0);
        $distB = (float) ($opponentRun->final_distance ?? 0);

        if ($distA > $distB) {
            return $creator;
        }

        if ($distB > $distA) {
            return $opponent;
        }

        // Exact tie fallback: creator
        return $creator;
    }
}
