<?php

declare(strict_types=1);

namespace App\Services\AntiCheat;

use App\Models\DuelRun;
use Carbon\CarbonInterface;

class RunAuditService
{
    /**
     * Physics and kinematic constraints aligned with Player.js.
     */
    public const float MIN_START_SPEED = 22.0; // m/s

    public const float MAX_TERMINAL_SPEED = 46.0; // m/s

    public const float SPEED_ACCELERATION = 0.08; // m/s^2

    public const float KINEMATIC_TOLERANCE_MULTIPLIER = 1.15; // ±15% buffer

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
     *     signature?: string|null,
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
        $inputsHash = hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));

        // 1. Rule 1: Temporal Integrity
        $startedAt = $payload['started_at'] ?? $run->started_at;
        $submittedAt = $payload['submitted_at'] ?? $run->submitted_at ?? now();

        if ($startedAt !== null && ! $this->verifyTemporalIntegrity($ticksElapsed, $startedAt, $submittedAt)) {
            return AuditResult::failure('SPEEDHACK_TEMPORAL_ANOMALY', [
                'ticks_elapsed' => $ticksElapsed,
                'real_duration_seconds' => $submittedAt->diffInMilliseconds($startedAt) / 1000.0,
            ]);
        }

        // 2. Rule 2: Kinematic Simulation & Distance Verification
        if (! $this->verifyKinematics($ticksElapsed, $finalDistance)) {
            $simulated = $this->simulateKinematics($ticksElapsed)['simulated_distance'];

            return AuditResult::failure('CHEATER_EXCESSIVE_DISTANCE', [
                'submitted_distance' => $finalDistance,
                'simulated_distance' => $simulated,
                'delta' => abs($finalDistance - $simulated),
            ]);
        }

        // 3. Rule 3: Kinematic Score Bound Verification
        if (! $this->verifyScoreFeasibility($ticksElapsed, $finalScore, $finalDistance)) {
            $simulated = $this->simulateKinematics($ticksElapsed)['simulated_score'];

            return AuditResult::failure('SCORE_KINEMATIC_ANOMALY', [
                'submitted_score' => $finalScore,
                'simulated_distance_score' => $simulated,
            ]);
        }

        // 4. Rule 4: Input Frequency & Track Coordinates Verification
        $inputCheck = $this->verifyInputIntegrity($inputs, $ticksElapsed);
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
     * Rule 2: Kinematic Simulation & Distance Verification
     * Verifies distance against forward kinematics integration with tolerance.
     */
    public function verifyKinematics(int $ticksElapsed, float $finalDistance): bool
    {
        if ($ticksElapsed <= 0 || $finalDistance < 0) {
            return false;
        }

        $simulated = $this->simulateKinematics($ticksElapsed)['simulated_distance'];
        $toleranceMargin = max(20.0, $simulated * 0.12); // 12% margin or 20m buffer

        return abs($finalDistance - $simulated) <= $toleranceMargin;
    }

    /**
     * Rule 3: Kinematic Score Feasibility Verification
     * Validates that score does not exceed theoretical maximum of distance score + plausible coin pickups.
     */
    public function verifyScoreFeasibility(int $ticksElapsed, int $finalScore, float $finalDistance): bool
    {
        if ($finalScore < 0) {
            return false;
        }

        $simulated = $this->simulateKinematics($ticksElapsed);
        $durationSeconds = $ticksElapsed / 60.0;
        // Maximum plausible coins: ~4 coins per second (400 pts/sec) + simulated distance score + buffer
        $maxAllowedScore = $simulated['simulated_score'] + (int) ceil($durationSeconds * 400.0) + 1000;

        return $finalScore <= $maxAllowedScore;
    }

    /**
     * Deterministically simulates runner forward kinematics at 60Hz.
     *
     * @return array{simulated_distance: float, simulated_score: int, terminal_speed: float}
     */
    public function simulateKinematics(int $ticksElapsed): array
    {
        $dt = 1.0 / 60.0;
        $speed = self::MIN_START_SPEED;
        $simDistance = 0.0;
        $simScore = 0;

        for ($i = 0; $i < $ticksElapsed; $i++) {
            $simDistance += $speed * $dt;
            if ($speed < self::MAX_TERMINAL_SPEED) {
                $speed += self::SPEED_ACCELERATION * $dt;
            }
            $simScore += (int) floor($speed * $dt * 2.0);
        }

        return [
            'simulated_distance' => $simDistance,
            'simulated_score' => $simScore,
            'terminal_speed' => $speed,
        ];
    }

    /**
     * Calculates simulated forward distance at an arbitrary tick.
     */
    public function simulateDistanceAtTick(int $tick): float
    {
        $dt = 1.0 / 60.0;
        $speed = self::MIN_START_SPEED;
        $dist = 0.0;

        for ($i = 0; $i < $tick; $i++) {
            $dist += $speed * $dt;
            if ($speed < self::MAX_TERMINAL_SPEED) {
                $speed += self::SPEED_ACCELERATION * $dt;
            }
        }

        return $dist;
    }

    /**
     * Rule 4: Input Frequency & State Transitions
     */
    public function verifyInputIntegrity(array $inputs, int $totalTicks = 0): AuditResult
    {
        $lastLaneSwitchTick = -9999;
        $lastJumpTick = -9999;
        $currentLane = 0; // Starts in Center Lane (0)

        foreach ($inputs as $log) {
            $tick = (int) ($log['tick'] ?? 0);
            $action = (string) ($log['action'] ?? '');

            if ($tick < 0 || ($totalTicks > 0 && $tick > $totalTicks + 10)) {
                return AuditResult::failure('CHEATER_INVALID_TICK', [
                    'tick' => $tick,
                    'total_ticks' => $totalTicks,
                ]);
            }

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
                // Ensure coordinate matches allowable lanes (-2.4m, 0m, 2.4m ± 0.8m tolerance)
                if (abs($x) > 3.2) {
                    return AuditResult::failure('CHEATER_LANE_TELEPORTATION', [
                        'tick' => $tick,
                        'x' => $x,
                    ]);
                }
            }

            // Check coordinate teleportation along Z forward track
            if (isset($log['z'])) {
                $z = (float) $log['z'];
                $simulatedAtTick = $this->simulateDistanceAtTick($tick);
                $zMargin = max(25.0, $simulatedAtTick * 0.20);
                if (abs($z - $simulatedAtTick) > $zMargin) {
                    return AuditResult::failure('CHEATER_COORDINATE_TELEPORTATION', [
                        'tick' => $tick,
                        'submitted_z' => $z,
                        'simulated_z' => $simulatedAtTick,
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
