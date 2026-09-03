/**
 * RecorderSystem.js - Frame-accurate Action & Tick Recording Stream.
 * Produces an exportable, tamper-evident anti-cheat audit trail JSON payload
 * containing simulation ticks, player inputs, and state snapshots for server verification.
 */
export class RecorderSystem {
    /**
     * @param {string} gameSeed Server-supplied 64-character deterministic seed
     * @param {number} [tickRate=60] Fixed simulation frequency
     */
    constructor(gameSeed = '', tickRate = 60) {
        this.gameSeed = gameSeed;
        this.tickRate = tickRate;

        this.startTime = 0;
        this.endTime = 0;

        /** @type {Array<{ tick: number, timestamp: number, action: string, x: number, y: number, z: number }>} */
        this.actionLog = [];

        /** @type {Array<{ tick: number, score: number, coins: number, distance: number }>} */
        this.checkpoints = [];

        this.checkpointInterval = 60; // Every 1 second of simulation
    }

    /**
     * Initializes a new recording session.
     * @param {string} [newSeed]
     */
    start(newSeed) {
        if (newSeed) {
            this.gameSeed = newSeed;
        }
        this.startTime = Date.now();
        this.endTime = 0;
        this.actionLog.length = 0;
        this.checkpoints.length = 0;
    }

    /**
     * Records a validated player action event.
     * @param {number} tick Simulation tick count
     * @param {string} action 'MOVE_LEFT' | 'MOVE_RIGHT' | 'JUMP' | 'ROLL'
     * @param {{ x: number, y: number, z: number }} playerPosition
     */
    recordAction(tick, action, playerPosition) {
        this.actionLog.push({
            tick,
            timestamp: Date.now() - this.startTime,
            action,
            x: Math.round(playerPosition.x * 100) / 100,
            y: Math.round(playerPosition.y * 100) / 100,
            z: Math.round(playerPosition.z * 100) / 100,
        });
    }

    /**
     * Periodic state checkpointing for anti-cheat verification.
     * @param {number} tick
     * @param {number} score
     * @param {number} coins
     * @param {number} distance
     */
    recordTick(tick, score, coins, distance) {
        if (tick > 0 && tick % this.checkpointInterval === 0) {
            this.checkpoints.push({
                tick,
                score: Math.floor(score),
                coins,
                distance: Math.floor(distance),
            });
        }
    }

    /**
     * Finalizes recording and produces the complete audit payload.
     * @param {Object} finalStats
     * @param {number} finalStats.score
     * @param {number} finalStats.coins
     * @param {number} finalStats.distance
     * @param {number} finalStats.ticks
     * @returns {Object} JSON-serializable replay and audit contract
     */
    exportPayload(finalStats = {}) {
        this.endTime = Date.now();
        const durationMs = this.endTime - this.startTime;

        return {
            version: '1.0.0',
            game_seed: this.gameSeed,
            client_metadata: {
                user_agent: typeof navigator !== 'undefined' ? navigator.userAgent : 'Server/Node',
                screen_width: typeof window !== 'undefined' ? window.innerWidth : 1920,
                screen_height: typeof window !== 'undefined' ? window.innerHeight : 1080,
                recorded_at: new Date().toISOString(),
                duration_ms: durationMs,
            },
            simulation: {
                tick_rate: this.tickRate,
                total_ticks: finalStats.ticks || 0,
                final_distance: Math.floor(finalStats.distance || 0),
                final_score: Math.floor(finalStats.score || 0),
                total_coins: finalStats.coins || 0,
            },
            actions: this.actionLog,
            checkpoints: this.checkpoints,
        };
    }

    /**
     * Exports formatted JSON string.
     * @param {Object} finalStats
     * @returns {string}
     */
    exportJSON(finalStats) {
        return JSON.stringify(this.exportPayload(finalStats), null, 2);
    }
}
