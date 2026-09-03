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
        /** @var MatchGame|null $match */
        $match = MatchGame::with(['creator', 'opponent', 'runs'])->find($this->matchId);

        if ($match === null || $match->opponent_user_id === null) {
            return;
        }

        // Distributed atomic locking to eliminate concurrent worker collision
        $lock = Cache::lock("match_settlement_{$match->uuid}", 15);
        if (! $lock->get()) {
            return; // Job already being handled by another worker
        }

        try {
            // Re-check status under lock
            if ($match->status !== MatchStatus::InProgress && $match->status !== MatchStatus::Ready) {
                return;
            }

            /** @var User $creator */
            $creator = $match->creator;
            /** @var User $opponent */
            $opponent = $match->opponent;

            $runs = $match->runs->keyBy('user_id');
            /** @var DuelRun|null $creatorRun */
            $creatorRun = $runs->get($creator->id);
            /** @var DuelRun|null $opponentRun */
            $opponentRun = $runs->get($opponent->id);

            $creatorSubmitted = $creatorRun?->submitted_at !== null;
            $opponentSubmitted = $opponentRun?->submitted_at !== null;

            // 1. Timeout Forfeit Evaluation
            if ($creatorSubmitted && ! $opponentSubmitted) {
                $secondsSinceSubmission = abs(now()->diffInSeconds($creatorRun->submitted_at));
                if ($secondsSinceSubmission >= self::FORFEIT_TIMEOUT_SECONDS) {
                    $this->resolveForfeitVictory($match, $creator, $opponent, $opponentRun, $ledgerService);

                    return;
                }

                return;
            }

            if ($opponentSubmitted && ! $creatorSubmitted) {
                $secondsSinceSubmission = now()->diffInSeconds($opponentRun->submitted_at);
                if ($secondsSinceSubmission >= self::FORFEIT_TIMEOUT_SECONDS) {
                    $this->resolveForfeitVictory($match, $opponent, $creator, $creatorRun, $ledgerService);

                    return;
                }

                return;
            }

            if (! $creatorSubmitted || ! $opponentSubmitted) {
                return;
            }

            // 2. Anti-Cheat Audit Evaluation for both submitted runs
            $this->ensureRunAudited($creatorRun, $auditService);
            $this->ensureRunAudited($opponentRun, $auditService);

            $creatorPassed = $creatorRun->audit_status === AuditStatus::Passed;
            $opponentPassed = $opponentRun->audit_status === AuditStatus::Passed;

            // One or both players failed audit
            if (! $creatorPassed || ! $opponentPassed) {
                if ($creatorPassed && ! $opponentPassed) {
                    $ledgerService->refundHonestPlayerOnDispute(
                        $match,
                        $creator,
                        $opponent,
                        $opponentRun->audit_failure_reason ?? 'Opponent anti-cheat failure'
                    );
                    event(new DuelResolved($match, null, 'DISPUTED'));

                    return;
                }

                if ($opponentPassed && ! $creatorPassed) {
                    $ledgerService->refundHonestPlayerOnDispute(
                        $match,
                        $opponent,
                        $creator,
                        $creatorRun->audit_failure_reason ?? 'Creator anti-cheat failure'
                    );
                    event(new DuelResolved($match, null, 'DISPUTED'));

                    return;
                }

                // Both failed: cancel match & mark disputed
                $match->status = MatchStatus::Disputed;
                $match->save();
                event(new DuelResolved($match, null, 'DISPUTED'));

                return;
            }

            // 3. Both passed audit cleanly: Determine Winner
            $winner = $this->determineWinner($match, $creator, $opponent, $creatorRun, $opponentRun);

            // Execute atomic double-entry ledger settlement
            $ledgerService->settleMatch($match, $winner);

            // Notify clients via WebSocket
            event(new DuelResolved($match, $winner, 'VICTORY'));
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
        event(new DuelResolved($match, $winner, 'FORFEIT'));
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
