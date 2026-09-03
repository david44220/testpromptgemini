<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AuditStatus;
use App\Enums\MatchStatus;
use App\Events\Duel\DuelOpponentJoined;
use App\Events\Duel\DuelTelemetryUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLobbyRequest;
use App\Http\Requests\SubmitRunPayloadRequest;
use App\Jobs\ProcessDuelSettlement;
use App\Models\DuelRun;
use App\Models\MatchGame;
use App\Models\User;
use App\Services\AntiCheat\RunAuditService;
use App\Services\Financial\WalletLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class DuelLobbyController extends Controller
{
    public function __construct(
        protected WalletLedgerService $walletLedgerService,
        protected RunAuditService $runAuditService,
    ) {}

    /**
     * 1. POST /api/v1/duels/lobbies
     * Create a duel challenge with specified stake, locks creator stake into escrow.
     */
    public function createLobby(CreateLobbyRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        $stake = (int) $validated['stake_amount_cents'];
        $rakePercentage = isset($validated['rake_percentage']) ? (float) $validated['rake_percentage'] : 10.00;

        /** @var MatchGame $match */
        $match = DB::transaction(function () use ($user, $stake, $rakePercentage): MatchGame {
            $match = MatchGame::create([
                'creator_user_id' => $user->id,
                'stake_amount_cents' => $stake,
                'rake_percentage' => $rakePercentage,
                'status' => MatchStatus::WaitingForOpponent,
            ]);

            // Lock creator stake into escrow
            $this->walletLedgerService->lockStake($user, $match);

            // Create initial DuelRun entry for creator
            DuelRun::create([
                'match_id' => $match->id,
                'user_id' => $user->id,
                'session_secret' => bin2hex(random_bytes(32)),
                'audit_status' => AuditStatus::Pending,
            ]);

            return $match;
        });

        return response()->json([
            'message' => 'Duel lobby created successfully.',
            'lobby' => [
                'uuid' => $match->uuid,
                'stake_amount_cents' => $match->stake_amount_cents,
                'rake_percentage' => $match->rake_percentage,
                'game_seed' => $match->game_seed,
                'status' => $match->status->value,
                'created_at' => $match->created_at->toISOString(),
            ],
        ], 201);
    }

    /**
     * 2. GET /api/v1/duels/lobbies
     * List available lobbies waiting for opponent, excluding lobbies created by the caller.
     */
    public function listLobbies(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $lobbies = MatchGame::openLobbies()
            ->where('creator_user_id', '!=', $user->id)
            ->with('creator:id,uuid,name')
            ->latest()
            ->paginate(20);

        return response()->json($lobbies);
    }

    /**
     * 3. POST /api/v1/duels/lobbies/{uuid}/join
     * Atomically locks opponent's stake, pairs players, transitions match to IN_PROGRESS.
     */
    public function joinLobby(string $uuid, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Distributed atomic cache lock per lobby to eliminate concurrency race condition
        $lockKey = "match:join:{$uuid}";
        $lock = Cache::lock($lockKey, 10);

        if (! $lock->get()) {
            return response()->json([
                'message' => 'Lobby is currently being joined by another player. Please retry.',
            ], 409);
        }

        try {
            return DB::transaction(function () use ($uuid, $user): JsonResponse {
                /** @var MatchGame|null $match */
                $match = MatchGame::where('uuid', $uuid)
                    ->lockForUpdate()
                    ->first();

                if ($match === null) {
                    return response()->json(['message' => 'Match lobby not found.'], 404);
                }

                if ($match->status !== MatchStatus::WaitingForOpponent || $match->opponent_user_id !== null) {
                    return response()->json([
                        'message' => 'This match lobby is no longer available.',
                    ], 409);
                }

                if ($match->creator_user_id === $user->id) {
                    return response()->json([
                        'message' => 'You cannot join your own match lobby.',
                    ], 422);
                }

                // Pair opponent and transition status
                $match->opponent_user_id = $user->id;
                $match->status = MatchStatus::InProgress;
                $match->save();

                // Lock opponent stake into escrow
                $this->walletLedgerService->lockStake($user, $match);

                // Create or retrieve opponent DuelRun
                $opponentRun = DuelRun::firstOrCreate(
                    [
                        'match_id' => $match->id,
                        'user_id' => $user->id,
                    ],
                    [
                        'session_secret' => bin2hex(random_bytes(32)),
                        'audit_status' => AuditStatus::Pending,
                    ]
                );

                // Broadcast DuelOpponentJoined to presence channel
                broadcast(new DuelOpponentJoined($match, $user));

                return response()->json([
                    'message' => 'Successfully joined duel lobby.',
                    'match' => [
                        'uuid' => $match->uuid,
                        'status' => $match->status->value,
                        'stake_amount_cents' => $match->stake_amount_cents,
                        'game_seed' => $match->game_seed,
                    ],
                ]);
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * 4. POST /api/v1/duels/matches/{uuid}/start-run
     * Issues single-use run ticket containing ephemeral timestamp and HMAC secret.
     */
    public function startRun(string $uuid, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var MatchGame|null $match */
        $match = MatchGame::where('uuid', $uuid)->first();

        if ($match === null) {
            return response()->json(['message' => 'Match not found.'], 404);
        }

        if ($match->status !== MatchStatus::InProgress && $match->status !== MatchStatus::Ready) {
            return response()->json(['message' => 'Match is not in an active running state.'], 409);
        }

        if ($match->creator_user_id !== $user->id && $match->opponent_user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized: not a participant in this match.'], 403);
        }

        /** @var DuelRun $run */
        $run = DuelRun::firstOrCreate(
            ['match_id' => $match->id, 'user_id' => $user->id],
            ['session_secret' => bin2hex(random_bytes(32)), 'audit_status' => AuditStatus::Pending]
        );

        if ($run->submitted_at !== null) {
            return response()->json(['message' => 'Run has already been completed and submitted.'], 409);
        }

        // Issue ticket if not yet issued or refreshed
        if ($run->ticket_token === null || $run->started_at === null) {
            $run->ticket_token = Str::random(40);
            $run->started_at = now();
            $run->save();
        }

        return response()->json([
            'ticket_token' => $run->ticket_token,
            'started_at' => $run->started_at->toISOString(),
            'game_seed' => $match->game_seed,
            'match_uuid' => $match->uuid,
        ]);
    }

    /**
     * 5. POST /api/v1/duels/matches/{uuid}/submit-run
     * Receives run payload, validates deterministic kinematics, saves run, and queues verification.
     */
    public function submitRun(string $uuid, SubmitRunPayloadRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var MatchGame|null $match */
        $match = MatchGame::where('uuid', $uuid)->first();

        if ($match === null) {
            return response()->json(['message' => 'Match not found.'], 404);
        }

        if ($match->status !== MatchStatus::InProgress && $match->status !== MatchStatus::Ready) {
            return response()->json(['message' => 'Match is not accepting run submissions.'], 409);
        }

        $validated = $request->validated();
        $ticks = (int) $validated['ticks_elapsed'];
        $score = (int) $validated['final_score'];
        $distance = (float) $validated['final_distance'];
        $inputs = $validated['inputs'] ?? [];
        $signature = (string) ($validated['signature'] ?? '');
        $inputsHash = hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($match, $user, $ticks, $score, $distance, $inputs, $signature, $inputsHash): JsonResponse {
            /** @var DuelRun|null $run */
            $run = DuelRun::where('match_id', $match->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($run === null) {
                return response()->json(['message' => 'No active run session found for this user.'], 404);
            }

            if ($run->submitted_at !== null) {
                return response()->json(['message' => 'Run has already been submitted.'], 409);
            }

            // Server-side deterministic kinematic simulation and audit
            $payload = [
                'ticks_elapsed' => $ticks,
                'final_distance' => $distance,
                'final_score' => $score,
                'inputs' => $inputs,
                'signature' => $signature,
                'started_at' => $run->started_at,
                'submitted_at' => now(),
            ];

            $auditResult = $this->runAuditService->auditRun($run, $payload);
            if (! $auditResult->passed) {
                $run->submitted_at = now();
                $run->ticks_elapsed = $ticks;
                $run->final_score = $score;
                $run->final_distance = (string) $distance;
                $run->inputs_hash = $inputsHash;
                $run->input_log = $inputs;
                $run->audit_status = AuditStatus::Failed;
                $run->audit_failure_reason = $auditResult->failureReason;
                $run->save();

                return response()->json([
                    'message' => "Run rejected: Anti-cheat audit failed ({$auditResult->failureReason}).",
                    'failure_reason' => $auditResult->failureReason,
                ], 403);
            }

            // Store verified run
            $run->submitted_at = now();
            $run->ticks_elapsed = $ticks;
            $run->final_score = $score;
            $run->final_distance = (string) $distance;
            $run->inputs_hash = $inputsHash;
            $run->input_log = $inputs;
            $run->client_signature = $signature;
            $run->audit_status = AuditStatus::Passed;
            $run->audit_failure_reason = null;
            $run->save();

            // Queue asynchronous settlement job
            ProcessDuelSettlement::dispatch($match->id);

            return response()->json([
                'message' => 'Run submitted successfully and verified.',
                'status' => 'ACCEPTED',
                'inputs_hash' => $inputsHash,
            ], 202);
        });
    }

    /**
     * 6. POST /api/v1/duels/matches/{uuid}/telemetry
     * Broadcasts throttled (4-5 Hz) live run telemetry to opponent via presence channel.
     */
    public function broadcastTelemetry(string $uuid, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var MatchGame|null $match */
        $match = MatchGame::where('uuid', $uuid)->first();

        if ($match === null) {
            return response()->json(['message' => 'Match not found.'], 404);
        }

        if ($match->creator_user_id !== $user->id && $match->opponent_user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized: not a match participant.'], 403);
        }

        if ($match->status !== MatchStatus::InProgress) {
            return response()->json(['message' => 'Match is not currently in progress.'], 409);
        }

        // Bandwidth control: strictly throttle to 5 updates per second (5 Hz) per participant
        $rateLimitKey = "telemetry:{$uuid}:{$user->id}";
        if (! RateLimiter::attempt($rateLimitKey, 5, function () {}, 1)) {
            return response()->json(['message' => 'Telemetry rate limit exceeded (maximum 5 Hz).'], 429);
        }

        $validated = $request->validate([
            'distance' => ['required', 'numeric'],
            'score' => ['required', 'integer'],
            'current_lane' => ['required', 'integer', 'between:-1,1'],
            'is_alive' => ['required', 'boolean'],
            'timestamp' => ['nullable', 'integer'],
        ]);

        broadcast(new DuelTelemetryUpdated(
            matchUuid: $match->uuid,
            userId: $user->id,
            distance: (float) $validated['distance'],
            score: (int) $validated['score'],
            currentLane: (int) $validated['current_lane'],
            isAlive: (bool) $validated['is_alive'],
            timestamp: isset($validated['timestamp']) ? (int) $validated['timestamp'] : null,
        ))->toOthers();

        return response()->json(['status' => 'OK']);
    }
}
