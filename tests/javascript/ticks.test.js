import test from 'node:test';
import assert from 'node:assert/strict';
import { RecorderSystem } from '../../resources/js/game/systems/RecorderSystem.js';

test('Client Tick Off-By-One Contract: simulation tick index N maps to completed steps N + 1', () => {
    // Regression vectors specified by production ticket
    const vectors = [
        { tickIndex: 0, expectedCompletedSteps: 1 },
        { tickIndex: 1, expectedCompletedSteps: 2 },
        { tickIndex: 99, expectedCompletedSteps: 100 },
        { tickIndex: 100, expectedCompletedSteps: 101 },
    ];

    for (const { tickIndex, expectedCompletedSteps } of vectors) {
        // 1. Semantic conversion invariant
        const completedSteps = tickIndex + 1;
        assert.equal(completedSteps, expectedCompletedSteps, `Tick index ${tickIndex} must map to ${expectedCompletedSteps} completed steps.`);

        // 2. Recorder export payload contract
        const recorder = new RecorderSystem('test-seed-vector');
        recorder.start();
        recorder.recordAction(tickIndex, 'JUMP', { x: 0, y: 0, z: 10 });
        
        // Actions remain zero-based
        assert.equal(recorder.actionLog[0].tick, tickIndex, `action.tick must remain zero-based index (${tickIndex}).`);

        const payload = recorder.exportPayload({
            score: 500,
            coins: 5,
            distance: 120.5,
            ticks: completedSteps,
        });

        // Ticks in exported simulation metadata must equal completed steps (N + 1)
        assert.equal(payload.simulation.total_ticks, expectedCompletedSteps, `Simulation total_ticks must equal completed steps (${expectedCompletedSteps}).`);
    }
});