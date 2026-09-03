<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AuditStatus;
use App\Enums\MatchStatus;
use App\Events\DuelResolved;
use App\Models\DuelRun;
use App\Models\MatchGame;
use App\Models\User;
use App\Services\Financial\WalletLedgerService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

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
        $staleThreshold = now()->subMinutes(10);

        /** @var Collection<int, MatchGame> $abandonedMatches */
        $abandonedMatches = MatchGame::where('status', MatchStatus::InProgress)
            ->where('updated_at', '<', $staleThreshold)
            ->with(['creator', 'opponent', 'runs'])
            ->get();

        if ($abandonedMatches->isEmpty()) {
            $this->info('No abandoned matches found.');

            return self::SUCCESS;
        }

        $this->info("Found {$abandonedMatches->count()} abandoned match(es) to process.");

        foreach ($abandonedMatches as $match) {
            $lock = Cache::lock("match_settlement_{$match->uuid}", 15);
            if (! $lock->get()) {
                $this->warn("Match #{$match->id} ({$match->uuid}) is locked by another process; skipping.");

                continue;
            }

            try {
                $match->refresh();
                if ($match->status !== MatchStatus::InProgress) {
                    continue;
                }

                $runs = $match->runs->keyBy('user_id');
                /** @var DuelRun|null $creatorRun */
                $creatorRun = $runs->get($match->creator_user_id);
                /** @var DuelRun|null $opponentRun */
                $opponentRun = $match->opponent_user_id ? $runs->get($match->opponent_user_id) : null;

                $creatorSubmitted = $creatorRun?->submitted_at !== null;
                $opponentSubmitted = $opponentRun?->submitted_at !== null;

                /** @var User $creator */
                $creator = $match->creator;
                /** @var User|null $opponent */
                $opponent = $match->opponent;

                if ($creatorSubmitted && ! $opponentSubmitted && $opponent !== null) {
                    // Creator finished, opponent vanished -> Creator wins by forfeit
                    if ($opponentRun !== null) {
                        $opponentRun->audit_status = AuditStatus::Forfeit;
                        $opponentRun->audit_failure_reason = 'Abandoned: forfeit';
                        $opponentRun->save();
                    }

                    $ledgerService->settleMatch($match, $creator);
                    event(new DuelResolved($match, $creator, 'FORFEIT'));
                    $this->info("Match #{$match->id}: Creator #{$creator->id} awarded forfeit victory.");
                } elseif ($opponentSubmitted && ! $creatorSubmitted && $opponent !== null) {
                    // Opponent finished, creator vanished -> Opponent wins by forfeit
                    if ($creatorRun !== null) {
                        $creatorRun->audit_status = AuditStatus::Forfeit;
                        $creatorRun->audit_failure_reason = 'Abandoned: forfeit';
                        $creatorRun->save();
                    }

                    $ledgerService->settleMatch($match, $opponent);
                    event(new DuelResolved($match, $opponent, 'FORFEIT'));
                    $this->info("Match #{$match->id}: Opponent #{$opponent->id} awarded forfeit victory.");
                } else {
                    // Neither player submitted (both crashed / disconnected) -> Full escrow refund
                    $ledgerService->releaseEscrowOnCancel($match);
                    event(new DuelResolved($match, null, 'ABANDONED_CANCELLED'));
                    $this->info("Match #{$match->id}: Both players abandoned. Escrow refunded and match cancelled.");
                }
            } catch (\Throwable $e) {
                $this->error("Error cleaning up match #{$match->id}: {$e->getMessage()}");
            } finally {
                $lock->release();
            }
        }

        return self::SUCCESS;
    }
}
