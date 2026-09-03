<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AuditStatus;
use App\Enums\MatchStatus;
use App\Events\Duel\DuelOpponentJoined;
use App\Events\Duel\DuelTelemetryUpdated;
use App\Exceptions\InsufficientFundsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateLobbyRequest;
use App\Http\Requests\SubmitRunPayloadRequest;
use App\Jobs\ProcessDuelSettlement;
use App\Models\DuelRun;
use App\Models\MatchGame;
use App\Models\RewardGrant;
use App\Models\User;
use App\Services\AntiCheat\RunAuditService;
use App\Services\Financial\WalletLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        /** @var MatchGame $match */
        try {
            $match = DB::transaction(function () use ($user, $stake): MatchGame {
                // Check if user has an active unconsumed reward grant
                /** @var RewardGrant|null $grant */
                $grant = RewardGrant::where('user_id', $user->id)
                    ->whereNull('consumed_at')
                    ->where('expires_at', '>', now())
                    ->lockForUpdate()
                    ->first();

                $defaultRakeBps = (int) config('duels.default_rake_bps', 1000);
                $rakeBps = $defaultRakeBps;

                if ($grant !== null) {
                    $grant->consumed_at = now();
                    $grant->save();
                    $rakeBps = max(0, $defaultRakeBps - $grant->value_bps);
                }

                $match = MatchGame::create([
                    'creator_user_id' => $user->id,
                    'stake_amount_cents' => $stake,
                    'rake_bps' => $rakeBps,
                    'rake_percentage' => (string) number_format($rakeBps / 100, 2, '.', ''),
                    'status' => MatchStatus::WaitingForOpponent,
                ]);

                // Lock creator stake into escrow
                $this->walletLedgerService->lockStake($user, $match);

                // Create initial DuelRun entry for creator
                DuelRun::create([
                    'match_id' => $match->id,
                    'user_id' => $user->id,
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
                    'status' => $match->status->value,
                    'created_at' => $match->created_at->toISOString(),
                ],
            ], 201);
        } catch (InsufficientFundsException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
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

                // Pair opponent and transition status with explicit domain deadlines
                $match->opponent_user_id = $user->id;
                $match->status = MatchStatus::InProgress;
                $match->in_progress_at = now();
                $match->abandon_deadline_at = now()->addMinutes((int) config('duels.abandon_timeout_minutes', 10));
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
                        'audit_status' => AuditStatus::Pending,
                    ]
                );

                // Broadcast DuelOpponentJoined to presence channel after transaction commits
                DB::afterCommit(static function () use ($match, $user): void {
                    try {
                        broadcast(new DuelOpponentJoined($match, $user));
                    } catch (\Throwable $e) {
                        Log::warning('DuelOpponentJoined broadcast skipped: '.$e->getMessage());
                    }
                });

                return response()->json([
                    'message' => 'Successfully joined duel lobby.',
                    'match' => [
                        'uuid' => $match->uuid,
                        'status' => $match->status->value,
                        'stake_amount_cents' => $match->stake_amount_cents,
                        'seed_commitment' => hash('sha256', $match->game_seed),
                    ],
                ]);
            });
        } catch (InsufficientFundsException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } finally {
            $lock->release();
        }
    }

    /**
     * 4. POST /api/v1/duels/matches/{uuid}/start-run
     * Issues single-use cryptographically secure run ticket; stores SHA-256 hash in database.
     * Enforces that once started, started_at is immutable and cannot be restarted.
     */
    public function startRun(string $uuid, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return DB::transaction(function () use ($uuid, $user): JsonResponse {
            /** @var MatchGame|null $match */
            $match = MatchGame::where('uuid', $uuid)->lockForUpdate()->first();

            if ($match === null) {
                return response()->json(['message' => 'Match not found.'], 404);
            }

            if ($match->status !== MatchStatus::InProgress && $match->status !== MatchStatus::Ready) {
                return response()->json(['message' => 'Match is not in an active running state.'], 409);
            }

            if ((int) $match->creator_user_id !== (int) $user->id && (int) $match->opponent_user_id !== (int) $user->id) {
                return response()->json(['message' => 'Unauthorized: not a participant in this match.'], 403);
            }

            /** @var DuelRun $run */
            $run = DuelRun::where('match_id', $match->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($run === null) {
                $run = new DuelRun([
                    'match_id' => $match->id,
                    'user_id' => $user->id,
                    'audit_status' => AuditStatus::Pending,
                ]);
            }

            if ($run->submitted_at !== null) {
                return response()->json(['message' => 'Run has already been completed and submitted.'], 409);
            }

            // Task 5: Make paid start-run fair and non-resettable
            if ($run->started_at !== null) {
                // Check if run session expired
                if ($run->ticket_expires_at !== null && now()->isAfter($run->ticket_expires_at)) {
                    return response()->json(['message' => 'Paid run session has expired.'], 409);
                }
                // Check forfeit deadline
                if ($match->forfeit_deadline_at !== null && now()->isAfter($match->forfeit_deadline_at)) {
                    return response()->json(['message' => 'Paid run forfeit deadline has expired.'], 409);
                }
                // Network retry before expiry: keep started_at unchanged!
            } else {
                // Initial request: freeze authoritative clock
                $run->started_at = now();
            }

            // Issue single-use cryptographically secure raw token; persist SHA-256 hash
            $rawTicket = Str::random(48);
            $run->ticket_token = null; // Do not keep raw token in plaintext
            $run->ticket_hash = hash('sha256', $rawTicket);
            $run->ticket_expires_at = now()->addSeconds((int) config('duels.ticket_ttl_seconds', 600));
            $run->save();

            // Authoritative game_seed revealed exclusively upon verified start-run
            return response()->json([
                'ticket_token' => $rawTicket,
                'started_at' => $run->started_at->toISOString(),
                'game_seed' => $match->game_seed,
                'seed_commitment' => hash('sha256', $match->game_seed),
                'match_uuid' => $match->uuid,
            ]);
        });
    }

    /**
     * 5. POST /api/v1/duels/matches/{uuid}/submit-run
     * Receives run payload, validates ticket atomically, simulates authoritative kinematics,
     * updates match timing, and dispatches settlement via DB::afterCommit().
     */
    public function submitRun(string $uuid, SubmitRunPayloadRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        $ticketToken = (string) $validated['ticket_token'];
        $ticks = (int) $validated['ticks_elapsed'];
        $score = (int) $validated['final_score'];
        $distance = (float) $validated['final_distance'];
        $inputs = $validated['inputs'] ?? [];
        $inputsHash = hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($uuid, $user, $ticketToken, $ticks, $score, $distance, $inputs, $inputsHash): JsonResponse {
            // 1. Strict lock hierarchy: Lock MatchGame row first
            /** @var MatchGame|null $match */
            $match = MatchGame::where('uuid', $uuid)->lockForUpdate()->first();

            if ($match === null) {
                return response()->json(['message' => 'Match not found.'], 404);
            }

            if ($match->status !== MatchStatus::InProgress && $match->status !== MatchStatus::Ready) {
                return response()->json(['message' => 'Match is not accepting run submissions.'], 409);
            }

            if ((int) $match->creator_user_id !== (int) $user->id && (int) $match->opponent_user_id !== (int) $user->id) {
                return response()->json(['message' => 'Unauthorized: not a participant in this match.'], 403);
            }

            // Reject if forfeit deadline already expired
            if ($match->forfeit_deadline_at !== null && now()->isAfter($match->forfeit_deadline_at)) {
                return response()->json(['message' => 'Forfeit deadline has expired for this match.'], 409);
            }

            // 2. Lock caller's DuelRun row
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

            // 3. Atomically validate one-time ticket token
            $submittedHash = hash('sha256', $ticketToken);
            if ($run->ticket_hash === null || ! hash_equals($run->ticket_hash, $submittedHash)) {
                return response()->json(['message' => 'Invalid or missing run ticket.'], 403);
            }

            if ($run->ticket_expires_at !== null && now()->isAfter($run->ticket_expires_at)) {
                return response()->json(['message' => 'Run ticket has expired.'], 403);
            }

            if ($run->started_at === null) {
                return response()->json(['message' => 'Run was not officially started via start-run.'], 403);
            }

            // Invalidate ticket to guarantee one-time consumption
            $run->ticket_hash = null;
            $run->ticket_expires_at = null;

            // 4. Server-side authoritative kinematic simulation and anti-cheat audit
            $payload = [
                'ticks_elapsed' => $ticks,
                'final_distance' => $distance,
                'final_score' => $score,
                'inputs' => $inputs,
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

            // Store authoritative verified run
            $run->submitted_at = now();
            $run->ticks_elapsed = (int) $auditResult->telemetry['ticks_elapsed'];
            $run->final_score = (int) $auditResult->telemetry['final_score'];
            $run->final_distance = (string) $auditResult->telemetry['final_distance'];
            $run->inputs_hash = $inputsHash;
            $run->input_log = $inputs;
            $run->audit_status = AuditStatus::Passed;
            $run->audit_failure_reason = null;
            $run->save();

            // 5. Update match timing metadata
            if ($match->first_run_submitted_at === null) {
                $match->first_run_submitted_at = now();
                $match->forfeit_deadline_at = now()->addSeconds((int) config('duels.forfeit_timeout_seconds', 180));
                $match->save();
            }

            // Check if both runs are submitted
            $totalSubmitted = DuelRun::where('match_id', $match->id)
                ->whereNotNull('submitted_at')
                ->count();

            $matchId = $match->id;
            if ($totalSubmitted >= 2) {
                DB::afterCommit(fn () => ProcessDuelSettlement::dispatch($matchId));
            } else {
                $delaySeconds = (int) config('duels.forfeit_timeout_seconds', 180);
                DB::afterCommit(fn () => ProcessDuelSettlement::dispatch($matchId)->delay(now()->addSeconds($delaySeconds)));
            }

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

    /**
     * 7. GET /api/v1/duels/matches/{uuid}/result
     * Authoritative post-match recovery endpoint for participants.
     */
    public function getResult(string $uuid, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var MatchGame|null $match */
        $match = MatchGame::where('uuid', $uuid)->first();

        if ($match === null) {
            return response()->json(['message' => 'Match not found.'], 404);
        }

        if ((int) $match->creator_user_id !== (int) $user->id && (int) $match->opponent_user_id !== (int) $user->id) {
            return response()->json(['message' => 'Unauthorized: not a participant in this match.'], 403);
        }

        $terminalStates = [
            MatchStatus::Completed,
            MatchStatus::Cancelled,
            MatchStatus::Disputed,
        ];

        if (! in_array($match->status, $terminalStates, true)) {
            return response()->json([
                'status' => 'PENDING',
                'match_status' => $match->status->value,
                'message' => 'Match settlement is still pending.',
            ], 202);
        }

        $runs = $match->runs;
        $myRun = $runs->firstWhere('user_id', $user->id);
        $rivalUserId = (int) $match->creator_user_id === (int) $user->id ? $match->opponent_user_id : $match->creator_user_id;
        $rivalRun = $rivalUserId ? $runs->firstWhere('user_id', $rivalUserId) : null;

        // Determine client resolution state
        $resolutionState = 'DEFEAT';
        if ($match->status === MatchStatus::Cancelled) {
            $resolutionState = 'CANCELLED';
        } elseif ($match->status === MatchStatus::Disputed) {
            $resolutionState = 'DISPUTED';
        } elseif ((int) $match->winner_user_id === (int) $user->id) {
            $resolutionState = ($rivalRun?->audit_status === AuditStatus::Forfeit) ? 'FORFEIT WIN' : 'VICTORY';
        } else {
            $resolutionState = ($myRun?->audit_status === AuditStatus::Forfeit) ? 'FORFEIT LOSS' : 'DEFEAT';
        }

        return response()->json([
            'match_uuid' => $match->uuid,
            'status' => $match->status->value,
            'resolution_state' => $resolutionState,
            'winner_user_id' => $match->winner_user_id,
            'total_pot_cents' => $match->total_pot_cents ?? ($match->stake_amount_cents * 2),
            'platform_fee_cents' => $match->platform_fee_cents,
            'winner_payout_cents' => $match->winner_payout_cents,
            'rake_bps' => $match->rake_bps,
            'player' => [
                'user_id' => $user->id,
                'authoritative_score' => $myRun?->authoritative_score ?? $myRun?->final_score ?? 0,
                'authoritative_distance' => $myRun?->authoritative_distance ?? $myRun?->final_distance ?? 0.0,
            ],
            'rival' => [
                'user_id' => $rivalUserId,
                'authoritative_score' => $rivalRun?->authoritative_score ?? $rivalRun?->final_score ?? 0,
                'authoritative_distance' => $rivalRun?->authoritative_distance ?? $rivalRun?->final_distance ?? 0.0,
            ],
            'settled_at' => $match->settled_at?->toISOString(),
        ]);
    }
}
