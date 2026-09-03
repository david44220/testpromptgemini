<?php

declare(strict_types=1);

namespace App\Services\AntiCheat;

/**
 * Server-authoritative physics & collision simulation layer.
 * Replays tick-by-tick runner kinematics, lane navigation, jumps, ducks,
 * obstacle collisions, and coin collections against the authoritative track.
 */
class RunSimulator
{
    public const float DT = 1.0 / 60.0;

    public const float GROUND_Y = 0.9;

    public const float GRAVITY = 38.0;

    public const float JUMP_VELOCITY = 14.5;

    public const float FAST_FALL_VELOCITY = -24.0;

    public const float ROLL_DURATION = 0.55;

    public const float BASE_SPEED = 22.0;

    public const float MAX_SPEED = 46.0;

    public const float SPEED_ACCELERATION = 0.08;

    public const float LANE_WIDTH = 2.4;

    public const float LANE_LERP_SPEED = 16.0;

    /**
     * Executes the complete server-authoritative simulation.
     *
     * @param  list<array{tick: int, action: string, x?: float|null, z?: float|null}>  $inputs
     * @return array{
     *     authoritative_score: int,
     *     authoritative_distance: float,
     *     coin_count: int,
     *     ticks_simulated: int,
     *     terminated_early: bool,
     *     termination_reason: string|null,
     *     final_x: float,
     *     final_y: float,
     *     final_z: float
     * }
     */
    public function simulate(string $gameSeed, array $inputs, int $submittedTicks): array
    {
        // 1. Initialize isolated gameplay PRNG stream
        $gameplayPrng = new DeterministicPrng($gameSeed.':gameplay');
        $trackGen = new TrackGenerator($gameplayPrng);

        // 2. Pre-generate track obstacles & coins
        $activeObstacles = [];
        $activeCoins = [];
        $highestGeneratedZ = 0.0;

        for ($z = 30.0; $z <= 240.0; $z += 30.0) {
            $seg = $trackGen->generateSegment($z, 30.0);
            foreach ($seg['obstacles'] as $obs) {
                $activeObstacles[] = $obs;
            }
            foreach ($seg['coins'] as $c) {
                $c['collected'] = false;
                $activeCoins[] = $c;
            }
            $highestGeneratedZ = $z + 30.0;
        }

        // 3. Index inputs by tick for O(1) lookup
        $actionsByTick = [];
        foreach ($inputs as $input) {
            $t = (int) ($input['tick'] ?? 0);
            $actionsByTick[$t][] = (string) ($input['action'] ?? '');
        }

        // 4. Initial Player Kinematics
        $currentLane = 0;
        $targetX = 0.0;
        $posX = 0.0;
        $posY = self::GROUND_Y;
        $posZ = 0.0;
        $speed = self::BASE_SPEED;
        $velocityY = 0.0;
        $isJumping = false;
        $isRolling = false;
        $rollTimer = 0.0;
        $score = 0;
        $coinCount = 0;

        $terminatedEarly = false;
        $terminationReason = null;
        $totalTicksToSimulate = max(1, min($submittedTicks, 36000)); // Up to 10 minutes max
        $actualTicksRun = 0;

        for ($tick = 0; $tick < $totalTicksToSimulate; $tick++) {
            $actualTicksRun = $tick + 1;

            // Dynamically generate subsequent track segments ahead of player
            while ($posZ + 200.0 > $highestGeneratedZ) {
                $seg = $trackGen->generateSegment($highestGeneratedZ, 30.0);
                foreach ($seg['obstacles'] as $obs) {
                    $activeObstacles[] = $obs;
                }
                foreach ($seg['coins'] as $c) {
                    $c['collected'] = false;
                    $activeCoins[] = $c;
                }
                $highestGeneratedZ += 30.0;
            }

            // Process player input events for this tick
            if (isset($actionsByTick[$tick])) {
                foreach ($actionsByTick[$tick] as $action) {
                    if ($action === 'MOVE_LEFT') {
                        if ($currentLane > -1) {
                            $currentLane--;
                            $targetX = -$currentLane * self::LANE_WIDTH;
                        }
                    } elseif ($action === 'MOVE_RIGHT') {
                        if ($currentLane < 1) {
                            $currentLane++;
                            $targetX = -$currentLane * self::LANE_WIDTH;
                        }
                    } elseif ($action === 'JUMP') {
                        if (! $isJumping) {
                            $isJumping = true;
                            $velocityY = self::JUMP_VELOCITY;
                            if ($isRolling) {
                                $isRolling = false;
                                $rollTimer = 0.0;
                            }
                        }
                    } elseif ($action === 'ROLL') {
                        if ($isJumping) {
                            $velocityY = self::FAST_FALL_VELOCITY;
                        }
                        $isRolling = true;
                        $rollTimer = self::ROLL_DURATION;
                    }
                }
            }

            // Forward kinematics
            $posZ += $speed * self::DT;

            if ($speed < self::MAX_SPEED) {
                $speed += self::SPEED_ACCELERATION * self::DT;
            }

            // Smooth lane interpolation
            $dx = $targetX - $posX;
            $posX += $dx * min(self::LANE_LERP_SPEED * self::DT, 1.0);

            // Vertical jump / gravity
            if ($isJumping) {
                $velocityY -= self::GRAVITY * self::DT;
                $posY += $velocityY * self::DT;

                if ($posY <= self::GROUND_Y) {
                    $posY = self::GROUND_Y;
                    $velocityY = 0.0;
                    $isJumping = false;
                }
            }

            // Roll timer countdown
            if ($isRolling) {
                $rollTimer -= self::DT;
                if ($rollTimer <= 0.0) {
                    $isRolling = false;
                    $rollTimer = 0.0;
                }
            }

            // Distance score accumulation
            $score += (int) floor($speed * self::DT * 2.0);

            // Player Bounding Box
            $halfWidth = 0.36;
            $halfDepth = 0.36;
            $height = $isRolling ? 0.75 : 1.7;

            $pMinX = $posX - $halfWidth;
            $pMaxX = $posX + $halfWidth;
            $pMinY = $posY - 0.05;
            $pMaxY = $posY + $height;
            $pMinZ = $posZ - $halfDepth;
            $pMaxZ = $posZ + $halfDepth;

            // Collision detection against obstacles
            foreach ($activeObstacles as $obs) {
                $dz = $obs['z'] - $posZ;
                if ($dz > 6.0 || $dz < -4.0) {
                    continue;
                }

                // Check AABB intersection
                if (
                    $pMinX <= $obs['max_x'] && $pMaxX >= $obs['min_x'] &&
                    $pMinY <= $obs['max_y'] && $pMaxY >= $obs['min_y'] &&
                    $pMinZ <= $obs['max_z'] && $pMaxZ >= $obs['min_z']
                ) {
                    // Clearance mechanics
                    if ($obs['type'] === 'HURDLE' && $isJumping && $posY > 1.25) {
                        continue; // Successfully cleared hurdle
                    }

                    if ($obs['type'] === 'ARCHWAY' && $isRolling && $posY <= 1.0) {
                        continue; // Successfully rolled under archway
                    }

                    // Lethal collision occurred!
                    $terminatedEarly = true;
                    $terminationReason = "COLLISION_{$obs['type']}";
                    break 2;
                }
            }

            // Collectibles (Coins) detection
            foreach ($activeCoins as &$coin) {
                if ($coin['collected']) {
                    continue;
                }

                $dz = $coin['z'] - $posZ;
                if ($dz > 4.0 || $dz < -2.0) {
                    continue;
                }

                if (
                    $pMinX <= $coin['max_x'] && $pMaxX >= $coin['min_x'] &&
                    $pMinY <= $coin['max_y'] && $pMaxY >= $coin['min_y'] &&
                    $pMinZ <= $coin['max_z'] && $pMaxZ >= $coin['min_z']
                ) {
                    $coin['collected'] = true;
                    $coinCount++;
                    $score += 100;
                }
            }
            unset($coin);
        }

        return [
            'authoritative_score' => $score,
            'authoritative_distance' => round($posZ, 2),
            'coin_count' => $coinCount,
            'ticks_simulated' => $actualTicksRun,
            'terminated_early' => $terminatedEarly,
            'termination_reason' => $terminationReason,
            'final_x' => round($posX, 2),
            'final_y' => round($posY, 2),
            'final_z' => round($posZ, 2),
        ];
    }
}
