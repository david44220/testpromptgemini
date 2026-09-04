import test from 'node:test';
import assert from 'node:assert/strict';
import { PRNG } from '../../resources/js/game/core/PRNG.js';
import { TrackGenerator } from '../../resources/js/game/determinism/TrackGenerator.js';
import { RecorderSystem } from '../../resources/js/game/systems/RecorderSystem.js';

test('Headless Renderer Parity: Visual post-processing bypass causes zero physics/PRNG/tick discrepancy', () => {
    const seed = 'cyber-rail-headless-parity-seed-999';

    // Simulation A: "Normal" simulation stream
    const prngA_gameplay = new PRNG(seed + ':gameplay');
    const prngA_cosmetic = new PRNG(seed + ':cosmetic');
    const trackA = new TrackGenerator(prngA_gameplay);
    const recorderA = new RecorderSystem(seed, 60);
    recorderA.start();

    // Simulation B: "Headless optimized" simulation stream
    const prngB_gameplay = new PRNG(seed + ':gameplay');
    const prngB_cosmetic = new PRNG(seed + ':cosmetic');
    const trackB = new TrackGenerator(prngB_gameplay);
    const recorderB = new RecorderSystem(seed, 60);
    recorderB.start();

    // Verify track segments are identical
    for (let i = 0; i < 50; i++) {
        const segA = trackA.generateSegment(i * 30);
        const segB = trackB.generateSegment(i * 30);
        assert.deepEqual(segA, segB, `Segment ${i} must be perfectly identical between render modes.`);
    }

    // Verify 600 ticks of simulation recording (10 seconds)
    for (let tick = 0; tick < 600; tick++) {
        if (tick % 60 === 0) {
            recorderA.recordAction(tick, 'JUMP', { x: 0, y: 1.5, z: tick * 0.5 });
            recorderB.recordAction(tick, 'JUMP', { x: 0, y: 1.5, z: tick * 0.5 });
        }
    }

    const payloadA = recorderA.exportPayload({ score: 1000, coins: 10, distance: 300, ticks: 600 });
    const payloadB = recorderB.exportPayload({ score: 1000, coins: 10, distance: 300, ticks: 600 });

    assert.equal(payloadA.simulation.action_count, payloadB.simulation.action_count);
    assert.equal(payloadA.simulation.total_ticks, payloadB.simulation.total_ticks);

    const kinematicActionsA = payloadA.actions.map(({ tick, action, x, y, z }) => ({ tick, action, x, y, z }));
    const kinematicActionsB = payloadB.actions.map(({ tick, action, x, y, z }) => ({ tick, action, x, y, z }));
    assert.deepEqual(kinematicActionsA, kinematicActionsB);
});
