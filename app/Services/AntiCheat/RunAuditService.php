<?php

declare(strict_types=1);

namespace App\Services\AntiCheat;

use App\Models\DuelRun;
use Carbon\CarbonInterface;

class RunAuditService
{
    /**
     * Physics and kinematic constraints.
     */
    public const float MIN_START_SPEED = 18.0; // m/s

    public const float MAX_TERMINAL_SPEED = 38.0; // m/s

    public const float KINEMATIC_TOLERANCE_MULTIPLIER = 1.02; // +2% buffer

    public const float MAX_TEMPORAL_DELTA_SECONDS = 2.5; // ±2.5s network latency margin

    public const int MIN_TICKS_BETWEEN_LANE_SWITCHES = 7; // ~120ms at 60Hz

    public const int MIN_JUMP_DURATION_TICKS = 36; // Physics airtime curve limit

    /**
     * Executes the complete anti-cheat audit pipeline sequentially.
     *
     * @param array{
     *     ticks_elapsed: int,
     *     final_distance: float,
     *     final_score: int,
     *     inputs: array<int, array<string, mixed>>,
     *     signature: string,
     *     started_at?: CarbonInterface|null,
     *     submitted_at?: CarbonInterface|null,
     * } $payload
     */
    public function auditRun(DuelRun $run, array $payload): AuditResult
    {
        $match = $run->match;
        $seed = $match->game_seed;
        $ticksElapsed = (int) $payload['ticks_elapsed'];
        $finalDistance = (float) $payload['final_distance'];
        $finalScore = (int) $payload['final_score'];
        $inputs = $payload['inputs'] ?? [];
        $signature = (string) $payload['signature'];
        $inputsHash = hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));

        // 1. Rule 4: Cryptographic Signature Verification
        if (! $this->verifyCryptographicSignature($seed, $finalScore, $ticksElapsed, $inputsHash, $run->session_secret, $signature)) {
            return AuditResult::failure('INVALID_CRYPTOGRAPHIC_SIGNATURE', [
                'expected_inputs_hash' => $inputsHash,
            ]);
        }

        // 2. Rule 1: Temporal Integrity
        $startedAt = $payload['started_at'] ?? $run->started_at;
        $submittedAt = $payload['submitted_at'] ?? $run->submitted_at ?? now();

        if ($startedAt !== null && ! $this->verifyTemporalIntegrity($ticksElapsed, $startedAt, $submittedAt)) {
            return AuditResult::failure('SPEEDHACK_TEMPORAL_ANOMALY', [
                'ticks_elapsed' => $ticksElapsed,
                'real_duration_seconds' => $submittedAt->diffInMilliseconds($startedAt) / 1000.0,
            ]);
        }

        // 3. Rule 2: Kinematic Feasibility (Distance vs. Time)
        if (! $this->verifyKinematics($ticksElapsed, $finalDistance)) {
            return AuditResult::failure('CHEATER_EXCESSIVE_DISTANCE', [
                'submitted_distance' => $finalDistance,
                'theoretical_max' => $this->calculateTheoreticalMaxDistance($ticksElapsed),
            ]);
        }

        // 4. Rule 3: Input Frequency & State Transitions
        $inputCheck = $this->verifyInputIntegrity($inputs);
        if (! $inputCheck->passed) {
            return $inputCheck;
        }

        return AuditResult::success([
            'ticks_elapsed' => $ticksElapsed,
            'final_distance' => $finalDistance,
            'final_score' => $finalScore,
            'inputs_hash' => $inputsHash,
        ]);
    }

    /**
     * Rule 1: Temporal Integrity
     * Compare (server_received_at - run_started_at) with ticks_elapsed * (1/60s).
     */
    public function verifyTemporalIntegrity(int $ticksElapsed, CarbonInterface $startedAt, CarbonInterface $submittedAt): bool
    {
        $realDurationSeconds = max(0.001, ($submittedAt->getTimestampMs() - $startedAt->getTimestampMs()) / 1000.0);
        $ticksSeconds = $ticksElapsed / 60.0;

        $delta = $realDurationSeconds - $ticksSeconds;

        // If client ran significantly faster than 60 FPS (e.g. 40s ticks in 10s real time)
        if ($ticksSeconds - $realDurationSeconds > self::MAX_TEMPORAL_DELTA_SECONDS) {
            return false;
        }

        // Operational network latency allow up to +2.5 seconds (or reasonable mobile delay)
        return abs($delta) <= self::MAX_TEMPORAL_DELTA_SECONDS;
    }

    /**
     * Rule 2: Kinematic Feasibility (Distance vs. Time)
     * Speed is bounded between 18 m/s and 38 m/s.
     */
    public function verifyKinematics(int $ticksElapsed, float $finalDistance): bool
    {
        if ($ticksElapsed <= 0 || $finalDistance < 0) {
            return false;
        }

        $theoreticalMax = $this->calculateTheoreticalMaxDistance($ticksElapsed);

        return $finalDistance <= ($theoreticalMax * self::KINEMATIC_TOLERANCE_MULTIPLIER);
    }

    /**
     * Calculates the upper bound integral of distance over elapsed ticks.
     */
    public function calculateTheoreticalMaxDistance(int $ticksElapsed): float
    {
        $durationSeconds = $ticksElapsed / 60.0;

        return self::MAX_TERMINAL_SPEED * $durationSeconds;
    }

    /**
     * Rule 3: Input Frequency & State Transitions
     */
    public function verifyInputIntegrity(array $inputs): AuditResult
    {
        $lastLaneSwitchTick = -9999;
        $lastJumpTick = -9999;
        $currentLane = 0; // Starts in Center Lane (0)

        foreach ($inputs as $log) {
            $tick = (int) ($log['tick'] ?? 0);
            $action = (string) ($log['action'] ?? '');

            if ($action === 'MOVE_LEFT' || $action === 'MOVE_RIGHT') {
                // Minimum 7 ticks between lane shifts (120ms at 60Hz)
                if (($tick - $lastLaneSwitchTick) < self::MIN_TICKS_BETWEEN_LANE_SWITCHES) {
                    return AuditResult::failure('CHEATER_EXCESSIVE_LANE_SWITCH_FREQUENCY', [
                        'tick' => $tick,
                        'previous_lane_tick' => $lastLaneSwitchTick,
                    ]);
                }

                $deltaLane = ($action === 'MOVE_LEFT') ? -1 : 1;
                $newLane = $currentLane + $deltaLane;

                // Validate lane boundary [-1, 0, 1]
                if ($newLane < -1 || $newLane > 1) {
                    return AuditResult::failure('CHEATER_OUT_OF_BOUNDS_LANE_SHIFT', [
                        'tick' => $tick,
                        'attempted_lane' => $newLane,
                    ]);
                }

                $currentLane = $newLane;
                $lastLaneSwitchTick = $tick;
            } elseif ($action === 'JUMP') {
                // Cannot trigger a second jump while still in mid-air
                if (($tick - $lastJumpTick) < self::MIN_JUMP_DURATION_TICKS) {
                    return AuditResult::failure('CHEATER_INVALID_JUMP_CURVE', [
                        'tick' => $tick,
                        'previous_jump_tick' => $lastJumpTick,
                    ]);
                }
                $lastJumpTick = $tick;
            }

            // Check coordinate teleportation if x coordinates provided in log
            if (isset($log['x'])) {
                $x = (float) $log['x'];
                // Ensure coordinate matches allowable lanes (-2.4m, 0m, 2.4m ± 0.5m tolerance)
                if (abs($x) > 3.2) {
                    return AuditResult::failure('CHEATER_LANE_TELEPORTATION', [
                        'tick' => $tick,
                        'x' => $x,
                    ]);
                }
            }
        }

        return AuditResult::success();
    }

    /**
     * Rule 4: Cryptographic Signature Verification
     * Client computes: HMAC_SHA256(seed + ":" + final_score + ":" + ticks_elapsed + ":" + inputs_hash, session_secret)
     */
    public function verifyCryptographicSignature(
        string $seed,
        int $finalScore,
        int $ticksElapsed,
        string $inputsHash,
        string $sessionSecret,
        string $clientSignature
    ): bool {
        $message = "{$seed}:{$finalScore}:{$ticksElapsed}:{$inputsHash}";
        $expectedSignature = hash_hmac('sha256', $message, $sessionSecret);

        return hash_equals($expectedSignature, $clientSignature);
    }
}
