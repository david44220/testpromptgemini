<?php

namespace App\Console\Commands;

use App\Enums\MatchStatus;
use App\Http\Controllers\Api\DuelLobbyController;
use App\Http\Requests\SubmitRunPayloadRequest;
use App\Models\MatchGame;
use App\Models\User;
use App\Services\Financial\WalletLedgerService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class DuelConcurrencyWorkerCommand extends Command
{
    protected $signature = 'duel:concurrency-worker {scenario} {payload}';

    protected $description = 'Worker process for real multi-process concurrency race testing.';

    public function handle(WalletLedgerService $ledgerService): int
    {
        config(['database.default' => 'pgsql']);
        config(['database.connections.pgsql.host' => '127.0.0.1']);
        config(['database.connections.pgsql.port' => '5432']);
        config(['database.connections.pgsql.database' => 'cyber_arena_test']);
        config(['database.connections.pgsql.username' => 'postgres']);
        config(['database.connections.pgsql.password' => 'postgres']);
        config(['broadcasting.default' => 'null']);
        DB::purge('pgsql');

        $scenario = (string) $this->argument('scenario');
        $payloadRaw = (string) $this->argument('payload');
        if (($decoded = base64_decode($payloadRaw, true)) !== false && str_starts_with($decoded, '{')) {
            $payloadStr = $decoded;
        } else {
            $payloadStr = $payloadRaw;
        }
        /** @var array<string, mixed> $payload */
        $payload = json_decode($payloadStr, true) ?? [];

        // Random jitter (0 - 15ms) to maximize simultaneous interleaving
        usleep(random_int(0, 15000));

        try {
            switch ($scenario) {
                case 'submit-run':
                    return $this->handleSubmitRun($payload);

                case 'settle-match':
                    return $this->handleSettleMatch($payload, $ledgerService);

                case 'join-lobby':
                    return $this->handleJoinLobby($payload);

                case 'cleanup-match':
                    return $this->handleCleanupMatch($payload, $ledgerService);

                default:
                    $this->line(json_encode(['error' => "Unknown scenario: {$scenario}"]));

                    return self::FAILURE;
            }
        } catch (Throwable $e) {
            $this->line(json_encode([
                'status' => 'EXCEPTION',
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]));

            return self::FAILURE;
        }
    }

    /**
     * RACE-01: Double submit-run on the same run ticket.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubmitRun(array $payload): int
    {
        $uuid = (string) ($payload['uuid'] ?? '');
        $userId = (int) ($payload['user_id'] ?? 0);
        $user = User::findOrFail($userId);

        $request = SubmitRunPayloadRequest::create(
            "/api/v1/duels/matches/{$uuid}/submit-run",
            'POST',
            $payload
        );
        $request->setUserResolver(fn () => $user);
        $request->setContainer(app());
        $request->validateResolved();

        /** @var DuelLobbyController $controller */
        $controller = app(DuelLobbyController::class);
        $response = $controller->submitRun($uuid, $request);

        $this->line(json_encode([
            'status' => $response->getStatusCode(),
            'data' => $response->getData(true),
        ]));

        return $response->getStatusCode() === 202 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * RACE-02: Simultaneous settle / forfeit against the same match.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleSettleMatch(array $payload, WalletLedgerService $ledgerService): int
    {
        $matchId = (int) ($payload['match_id'] ?? 0);
        $winnerUserId = (int) ($payload['winner_user_id'] ?? 0);

        $match = MatchGame::findOrFail($matchId);
        $winner = User::findOrFail($winnerUserId);

        $ledgerService->settleMatch($match, $winner);

        $this->line(json_encode([
            'status' => 'SETTLED',
            'match_id' => $match->id,
            'winner_id' => $winner->id,
        ]));

        return self::SUCCESS;
    }

    /**
     * RACE-03: Simultaneous join on a 1-slot lobby.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleJoinLobby(array $payload): int
    {
        $uuid = (string) ($payload['uuid'] ?? '');
        $userId = (int) ($payload['user_id'] ?? 0);
        $user = User::findOrFail($userId);

        $request = Request::create("/api/v1/duels/lobbies/{$uuid}/join", 'POST');
        $request->setUserResolver(fn () => $user);

        /** @var DuelLobbyController $controller */
        $controller = app(DuelLobbyController::class);
        $response = $controller->joinLobby($uuid, $request);

        $this->line(json_encode([
            'status' => $response->getStatusCode(),
            'data' => $response->getData(true),
        ]));

        return $response->getStatusCode() === 200 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * RACE-04: Escrow refund vs late submit.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleCleanupMatch(array $payload, WalletLedgerService $ledgerService): int
    {
        $matchId = (int) ($payload['match_id'] ?? 0);

        return DB::transaction(function () use ($matchId, $ledgerService): int {
            /** @var MatchGame|null $match */
            $match = MatchGame::where('id', $matchId)->lockForUpdate()->first();

            if ($match === null) {
                $this->line(json_encode(['status' => 'NOT_FOUND']));

                return self::FAILURE;
            }

            if ($match->status !== MatchStatus::InProgress && $match->status !== MatchStatus::Ready) {
                $this->line(json_encode(['status' => 'ALREADY_TERMINAL', 'match_status' => $match->status->value]));

                return self::FAILURE;
            }

            $ledgerService->releaseEscrowOnCancel($match);

            $this->line(json_encode([
                'status' => 'CANCELLED_AND_REFUNDED',
                'match_id' => $match->id,
            ]));

            return self::SUCCESS;
        });
    }
}
