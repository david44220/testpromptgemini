import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { PRNG } from '../../resources/js/game/core/PRNG.js';
import { TrackGenerator } from '../../resources/js/game/determinism/TrackGenerator.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const fixturePath = path.resolve(__dirname, '../fixtures/determinism-v1.json');
const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));

test('Cross-Language Deterministic Fixture Parity (10 Seeds, 36 Segments each)', async (t) => {
    const seeds = Object.keys(fixture.seeds);
    assert.ok(seeds.length >= 10, `Expected at least 10 seeds, found ${seeds.length}`);

    for (const seed of seeds) {
        await t.test(`Seed: ${seed}`, (t2) => {
            const seedData = fixture.seeds[seed];

            // 1. Verify PRNG sample stream bit-for-bit
            const prng = new PRNG(seed + ':gameplay');
            for (let i = 0; i < seedData.prng_samples.length; i++) {
                const actual = prng.next();
                const expected = seedData.prng_samples[i];
                assert.ok(
                    Math.abs(actual - expected) < 1e-9,
                    `PRNG sample mismatch at index ${i} for seed ${seed}: actual=${actual}, expected=${expected}`
                );
            }

            // 2. Verify TrackGenerator segments matching PHP fixture exactly
            const gameplayPrng = new PRNG(seed + ':gameplay');
            const trackGen = new TrackGenerator(gameplayPrng);

            for (let segIdx = 0; segIdx < seedData.segments.length; segIdx++) {
                const expectedSeg = seedData.segments[segIdx];
                const actualSeg = trackGen.generateSegment(expectedSeg.start_z, expectedSeg.length);

                assert.equal(
                    actualSeg.obstacles.length,
                    expectedSeg.obstacles.length,
                    `Obstacle count mismatch in segment start_z=${expectedSeg.start_z}`
                );

                for (let o = 0; o < expectedSeg.obstacles.length; o++) {
                    const actualObs = actualSeg.obstacles[o];
                    const expectedObs = expectedSeg.obstacles[o];

                    assert.equal(actualObs.type, expectedObs.type, `Obstacle type mismatch at seg ${segIdx}, obs ${o}`);
                    assert.equal(actualObs.lane, expectedObs.lane, `Obstacle lane mismatch at seg ${segIdx}, obs ${o}`);
                    assert.ok(Math.abs(actualObs.x - expectedObs.x) < 1e-6, `Obstacle X mismatch`);
                    assert.ok(Math.abs(actualObs.y - expectedObs.y) < 1e-6, `Obstacle Y mismatch`);
                    assert.ok(Math.abs(actualObs.z - expectedObs.z) < 1e-6, `Obstacle Z mismatch`);
                    assert.ok(Math.abs(actualObs.min_x - expectedObs.min_x) < 1e-6, `Obstacle min_x mismatch`);
                    assert.ok(Math.abs(actualObs.max_x - expectedObs.max_x) < 1e-6, `Obstacle max_x mismatch`);
                    assert.ok(Math.abs(actualObs.min_z - expectedObs.min_z) < 1e-6, `Obstacle min_z mismatch`);
                    assert.ok(Math.abs(actualObs.max_z - expectedObs.max_z) < 1e-6, `Obstacle max_z mismatch`);
                }

                assert.equal(
                    actualSeg.coins.length,
                    expectedSeg.coins.length,
                    `Coin count mismatch in segment start_z=${expectedSeg.start_z}`
                );

                for (let c = 0; c < expectedSeg.coins.length; c++) {
                    const actualCoin = actualSeg.coins[c];
                    const expectedCoin = expectedSeg.coins[c];

                    assert.equal(actualCoin.lane, expectedCoin.lane, `Coin lane mismatch at seg ${segIdx}, coin ${c}`);
                    assert.ok(Math.abs(actualCoin.x - expectedCoin.x) < 1e-6, `Coin X mismatch`);
                    assert.ok(Math.abs(actualCoin.z - expectedCoin.z) < 1e-6, `Coin Z mismatch`);
                }
            }
        });
    }
});

test('Cosmetic Stream Isolation: Heavy cosmetic consumption does NOT alter gameplay descriptors', () => {
    const seed = 'cyber-rail-seed-alpha';

    // Stream A: Zero cosmetic calls
    const prngA = new PRNG(seed + ':gameplay');
    const genA = new TrackGenerator(prngA);
    const segsA = [];
    for (let z = 30.0; z <= 240.0; z += 30.0) {
        segsA.push(genA.generateSegment(z, 30.0));
    }

    // Stream B: Heavy interleaved cosmetic calls
    const prngB = new PRNG(seed + ':gameplay');
    const cosmeticPrng = new PRNG(seed + ':cosmetic');
    const genB = new TrackGenerator(prngB);
    const segsB = [];
    for (let z = 30.0; z <= 240.0; z += 30.0) {
        // Heavy cosmetic consumption (50 calls per segment)
        for (let i = 0; i < 50; i++) {
            cosmeticPrng.next();
        }
        segsB.push(genB.generateSegment(z, 30.0));
    }

    assert.deepEqual(segsA, segsB, 'Gameplay segments must be strictly identical regardless of cosmetic consumption');
});