<?php

declare(strict_types=1);

namespace App\Services\AntiCheat;

/**
 * Generates deterministic track waves and obstacles identical to ObstaclePool.js.
 */
class TrackGenerator
{
    public const float LANE_WIDTH = 2.4;

    public function __construct(
        protected DeterministicPrng $prng
    ) {}

    /**
     * Generates all obstacles and coins for a track segment [startZ, startZ + length].
     *
     * @return array{
     *     obstacles: list<array{type: string, lane: int, x: float, y: float, z: float, min_x: float, max_x: float, min_y: float, max_y: float, min_z: float, max_z: float}>,
     *     coins: list<array{lane: int, x: float, y: float, z: float, min_x: float, max_x: float, min_y: float, max_y: float, min_z: float, max_z: float}>
     * }
     */
    public function generateSegment(float $startZ, float $length): array
    {
        $obstacles = [];
        $coins = [];

        if ($startZ < 30.0) {
            return ['obstacles' => [], 'coins' => []];
        }

        $step = 15.0;
        for ($z = $startZ + 8.0; $z < $startZ + $length - 5.0; $z += $step) {
            $wave = $this->generateWave($z);
            foreach ($wave['obstacles'] as $obs) {
                $obstacles[] = $obs;
            }
            foreach ($wave['coins'] as $coin) {
                $coins[] = $coin;
            }
        }

        return [
            'obstacles' => $obstacles,
            'coins' => $coins,
        ];
    }

    /**
     * Generates a single obstacle/coin wave at z.
     *
     * @return array{
     *     obstacles: list<array{type: string, lane: int, x: float, y: float, z: float, min_x: float, max_x: float, min_y: float, max_y: float, min_z: float, max_z: float}>,
     *     coins: list<array{lane: int, x: float, y: float, z: float, min_x: float, max_x: float, min_y: float, max_y: float, min_z: float, max_z: float}>
     * }
     */
    public function generateWave(float $z): array
    {
        $pattern = $this->prng->nextInt(0, 5);
        $lanes = [-1, 0, 1];
        $obstacles = [];
        $coins = [];

        switch ($pattern) {
            case 0:
                $trainLane = (int) $this->prng->choice($lanes);
                $obstacles[] = $this->createObstacle('TRAIN', $trainLane, $z);

                $coinLane = $trainLane === 0 ? (int) $this->prng->choice([-1, 1]) : 0;
                $coins[] = $this->createCoin($coinLane, $z);
                $coins[] = $this->createCoin($coinLane, $z + 2.5);
                break;

            case 1:
                $shuffled = $this->shuffleLanes();
                $obstacles[] = $this->createObstacle('HURDLE', $shuffled[0], $z);
                $obstacles[] = $this->createObstacle('ARCHWAY', $shuffled[1], $z);
                $coins[] = $this->createCoin($shuffled[2], $z);
                break;

            case 2:
                $obstacles[] = $this->createObstacle('HURDLE', -1, $z);
                $obstacles[] = $this->createObstacle('HURDLE', 0, $z);
                $obstacles[] = $this->createObstacle('HURDLE', 1, $z);
                break;

            case 3:
                $openLane = (int) $this->prng->choice($lanes);
                foreach ($lanes as $lane) {
                    if ($lane !== $openLane) {
                        $obstacles[] = $this->createObstacle('ARCHWAY', $lane, $z);
                    } else {
                        $coins[] = $this->createCoin($lane, $z);
                        $coins[] = $this->createCoin($lane, $z + 2.5);
                    }
                }
                break;

            case 4:
                $targetLane = (int) $this->prng->choice($lanes);
                $coins[] = $this->createCoin($targetLane, $z - 2.0);
                $coins[] = $this->createCoin($targetLane, $z);
                $coins[] = $this->createCoin($targetLane, $z + 2.0);
                break;

            default:
                $lane = (int) $this->prng->choice($lanes);
                $type = $this->prng->boolean() ? 'HURDLE' : 'ARCHWAY';
                $obstacles[] = $this->createObstacle($type, $lane, $z);
                break;

        }

        return [
            'obstacles' => $obstacles,
            'coins' => $coins,
        ];
    }

    /**
     * @return list<int>
     */
    protected function shuffleLanes(): array
    {
        $arr = [-1, 0, 1];
        for ($i = count($arr) - 1; $i > 0; $i--) {
            $j = $this->prng->nextInt(0, $i);
            $temp = $arr[$i];
            $arr[$i] = $arr[$j];
            $arr[$j] = $temp;
        }

        return $arr;
    }

    /**
     * @return array{type: string, lane: int, x: float, y: float, z: float, min_x: float, max_x: float, min_y: float, max_y: float, min_z: float, max_z: float}
     */
    protected function createObstacle(string $type, int $lane, float $z): array
    {
        $px = -$lane * self::LANE_WIDTH;
        $py = 0.0;
        $pz = $z;

        if ($type === 'HURDLE') {
            $minX = $px - 0.95;
            $maxX = $px + 0.95;
            $minY = 0.0;
            $maxY = 0.75;
            $minZ = $pz - 0.25;
            $maxZ = $pz + 0.25;
        } elseif ($type === 'ARCHWAY') {
            $minX = $px - 0.95;
            $maxX = $px + 0.95;
            $minY = 1.05;
            $maxY = 2.4;
            $minZ = $pz - 0.3;
            $maxZ = $pz + 0.3;
        } else { // TRAIN
            $minX = $px - 0.95;
            $maxX = $px + 0.95;
            $minY = 0.0;
            $maxY = 3.5;
            $minZ = $pz - 2.5;
            $maxZ = $pz + 2.5;
        }

        return [
            'type' => $type,
            'lane' => $lane,
            'x' => $px,
            'y' => $py,
            'z' => $pz,
            'min_x' => $minX,
            'max_x' => $maxX,
            'min_y' => $minY,
            'max_y' => $maxY,
            'min_z' => $minZ,
            'max_z' => $maxZ,
        ];
    }

    /**
     * @return array{lane: int, x: float, y: float, z: float, min_x: float, max_x: float, min_y: float, max_y: float, min_z: float, max_z: float}
     */
    protected function createCoin(int $lane, float $z): array
    {
        $px = -$lane * self::LANE_WIDTH;
        $py = 1.2;
        $pz = $z;

        return [
            'lane' => $lane,
            'x' => $px,
            'y' => $py,
            'z' => $pz,
            'min_x' => $px - 0.4,
            'max_x' => $px + 0.4,
            'min_y' => $py - 0.4,
            'max_y' => $py + 0.4,
            'min_z' => $pz - 0.4,
            'max_z' => $pz + 0.4,
        ];
    }
}
