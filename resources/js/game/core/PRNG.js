/**
 * PRNG.js - Deterministic Seeded Pseudo-Random Number Generator
 * Uses 64-bit entropy mixing + Mulberry32 algorithm for ultra-fast,
 * mathematically deterministic random number sequences across all platforms.
 */
export class PRNG {
    /**
     * @param {string} seedString 64-character hex string or alphanumeric seed
     */
    constructor(seedString = '') {
        this.originalSeed = seedString;
        this.state = this._hashSeed(seedString);
    }

    /**
     * Hashes a 64-character seed string into a 32-bit unsigned integer state.
     * Incorporates all 64 characters using FNV-1a / Murmur-style mixing.
     * @param {string} seed
     * @returns {number} 32-bit unsigned integer
     * @private
     */
    _hashSeed(seed) {
        if (!seed || typeof seed !== 'string') {
            return 0x12345678;
        }

        let h = 0x811c9dc5; // 32-bit FNV offset basis
        for (let i = 0; i < seed.length; i++) {
            h ^= seed.charCodeAt(i);
            // 32-bit FNV prime: 16777619
            h = Math.imul(h, 0x01000193);
        }

        // Avalanche finalizer
        h ^= h >>> 16;
        h = Math.imul(h, 0x85ebca6b);
        h ^= h >>> 13;
        h = Math.imul(h, 0xc2b2ae35);
        h ^= h >>> 16;

        return (h >>> 0) || 1; // Ensure non-zero
    }

    /**
     * Generates a deterministic float in range [0, 1).
     * Mulberry32 algorithm.
     * @returns {number}
     */
    next() {
        let t = (this.state += 0x6d2b79f5);
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    }

    /**
     * Alias for next() to generate a deterministic float in range [0, 1).
     * @returns {number}
     */
    nextFloat() {
        return this.next();
    }

    /**
     * Generates a deterministic integer in range [min, max] (inclusive).
     * @param {number} min
     * @param {number} max
     * @returns {number}
     */
    nextInt(min, max) {
        if (min >= max) return min;
        return Math.floor(this.next() * (max - min + 1)) + min;
    }

    /**
     * Picks a random element from an array deterministically.
     * @template T
     * @param {T[]} array
     * @returns {T}
     */
    choice(array) {
        if (!array || array.length === 0) return null;
        const index = this.nextInt(0, array.length - 1);
        return array[index];
    }

    /**
     * Generates a deterministic boolean based on probability.
     * @param {number} chance Probability of returning true [0.0 - 1.0]
     * @returns {boolean}
     */
    boolean(chance = 0.5) {
        return this.next() < chance;
    }

    /**
     * Retrieves the current internal state value for serialization/checkpointing.
     * @returns {number}
     */
    getState() {
        return this.state;
    }

    /**
     * Restores internal state from a checkpoint.
     * @param {number} state
     */
    setState(state) {
        this.state = state >>> 0;
    }

    /**
     * Returns the original seed string.
     * @returns {string}
     */
    getSeed() {
        return this.originalSeed;
    }
}
