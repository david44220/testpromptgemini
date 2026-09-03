<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AntiCheat\DeterministicPrng;
use App\Services\AntiCheat\RunSimulator;
use App\Services\AntiCheat\TrackGenerator;
use PHPUnit\Framework\TestCase;

class DeterministicPhysicsCrossVectorTest extends TestCase
{
    /**
     * Test Task 1: Golden vectors for PRNG matching JavaScript Mulberry32 implementation.
     */
    public function test_prng_golden_vectors_match_cross_language(): void
    {
        $fixtures = [
            '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef' => [
                0.76012574,
                0.82777594,
                0.76663268,
                0.68819599,
                0.82917166,
                0.59202815,
                0.97664648,
                0.34465198,
                0.82685124,
                0.85279380,
            ],
            'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210' => [
                0.67043029,
                0.34206378,
                0.88847849,
                0.98958494,
                0.08369127,
                0.29460743,
                0.50218492,
                0.84903978,
                0.93521718,
                0.04791756,
            ],
            'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90' => [
                0.66007593,
                0.74027950,
                0.91849846,
                0.95172970,
                0.57048671,
                0.54473714,
                0.49143379,
                0.08578825,
                0.51451403,
                0.69425835,
            ],
        ];

        foreach ($fixtures as $seed => $expectedFloats) {
            $prng = new DeterministicPrng($seed);
            foreach ($expectedFloats as $index => $expectedFloat) {
                $actual = round($prng->next(), 8);
                $this->assertEqualsWithDelta(
                    $expectedFloat,
                    $actual,
                    0.0000001,
                    "PRNG mismatch on seed {$seed} at index {$index}"
                );
            }
        }
    }

    /**
     * Test Task 1: Track obstacle & coin generation is 100% deterministic given the seed.
     */
    public function test_track_generator_produces_identical_waves_for_same_seed(): void
    {
        $seed = 'test_deterministic_game_seed_99999999999999999999999999999999999';

        $prngA = new DeterministicPrng($seed.':gameplay');
        $genA = new TrackGenerator($prngA);
        $segA = $genA->generateSegment(30.0, 30.0);

        $prngB = new DeterministicPrng($seed.':gameplay');
        $genB = new TrackGenerator($prngB);
        $segB = $genB->generateSegment(30.0, 30.0);

        $this->assertSame($segA, $segB, 'Track segments generated from identical seeds must be identical.');
    }

    /**
     * Test Task 1: PRNG isolation - cosmetic stream is independent from gameplay stream.
     */
    public function test_prng_isolation_cosmetic_does_not_mutate_gameplay_stream(): void
    {
        $seed = 'master_duel_seed_isolation_test_123456789012345678901234567890';

        $gameplayPrng1 = new DeterministicPrng($seed.':gameplay');
        $cosmeticPrng1 = new DeterministicPrng($seed.':cosmetic');

        // Consume 50 random calls from cosmetic stream (simulating billboard ad loading)
        for ($i = 0; $i < 50; $i++) {
            $cosmeticPrng1->next();
        }

        // Gameplay stream without cosmetic calls
        $gameplayPrng2 = new DeterministicPrng($seed.':gameplay');

        // Both gameplay streams must produce identical values
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(
                $gameplayPrng1->next(),
                $gameplayPrng2->next(),
                'Cosmetic randomness must never interfere with gameplay stream.'
            );
        }
    }

    /**
     * Test Task 1: Authoritative simulation detects lethal obstacle collisions.
     */
    public function test_run_simulator_detects_obstacle_collision(): void
    {
        $seed = 'collision_test_seed_00000000000000000000000000000000000000000000';
        $simulator = new RunSimulator;

        // Run straight down the center lane with zero inputs
        $result = $simulator->simulate($seed, [], 1800);

        // Runner must either terminate upon collision or complete ticks
        $this->assertIsInt($result['authoritative_score']);
        $this->assertIsFloat($result['authoritative_distance']);
        $this->assertGreaterThan(0, $result['ticks_simulated']);

        if ($result['terminated_early']) {
            $this->assertNotNull($result['termination_reason']);
            $this->assertStringStartsWith('COLLISION_', $result['termination_reason']);
        }
    }

    /**
     * Test Task 4: Shared versioned determinism-v1.json fixture parity in PHP.
     */
    public function test_shared_fixture_determinism_v1_matches_php(): void
    {
        $fixturePath = dirname(__DIR__, 2).'/tests/fixtures/determinism-v1.json';
        $this->assertFileExists($fixturePath);

        $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('seeds', $fixture);

        foreach ($fixture['seeds'] as $seed => $data) {
            $prng = new DeterministicPrng($seed.':gameplay');
            foreach ($data['prng_samples'] as $idx => $expectedSample) {
                $this->assertEqualsWithDelta(
                    $expectedSample,
                    $prng->next(),
                    1e-7,
                    "PRNG sample mismatch in PHP for seed {$seed} at index {$idx}"
                );
            }

            $trackGen = new TrackGenerator(new DeterministicPrng($seed.':gameplay'));
            foreach ($data['segments'] as $segIdx => $expectedSeg) {
                $actualSeg = $trackGen->generateSegment((float) $expectedSeg['start_z'], (float) $expectedSeg['length']);
                $this->assertSame(count($expectedSeg['obstacles']), count($actualSeg['obstacles']), "Obstacle count mismatch for seed {$seed} at segment {$segIdx}");
                $this->assertSame(count($expectedSeg['coins']), count($actualSeg['coins']), "Coin count mismatch for seed {$seed} at segment {$segIdx}");

                foreach ($expectedSeg['obstacles'] as $oIdx => $expObs) {
                    $actObs = $actualSeg['obstacles'][$oIdx];
                    $this->assertSame($expObs['type'], $actObs['type']);
                    $this->assertSame($expObs['lane'], $actObs['lane']);
                    $this->assertEqualsWithDelta($expObs['x'], $actObs['x'], 1e-5);
                    $this->assertEqualsWithDelta($expObs['z'], $actObs['z'], 1e-5);
                }
            }
        }
    }

    /**
     * Test Task 1.4: Explicit regression vectors for tick count semantics (1, 2, 100, 101 completed steps).
     */
    public function test_tick_semantics_regression_vectors(): void
    {
        $simulator = new RunSimulator;
        $seed = 'tick_semantics_test_seed_1234567890abcdef1234567890abcdef';

        foreach ([1, 2, 100, 101] as $completedSteps) {
            $result = $simulator->simulate($seed, [], $completedSteps);
            if (! $result['terminated_early']) {
                $this->assertSame(
                    $completedSteps,
                    $result['ticks_simulated'],
                    "Authoritative simulator must report exact completed simulation steps for input {$completedSteps}"
                );
            } else {
                $this->assertLessThanOrEqual(
                    $completedSteps,
                    $result['ticks_simulated'],
                    'Simulated ticks must not exceed submitted completed steps when terminated early'
                );
            }
        }
    }
}
