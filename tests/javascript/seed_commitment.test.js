import test from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';

test('Cryptographic Seed Commitment Contract: SHA-256 verification matches pre-start commitment', async () => {
    async function computeSha256WebCrypto(str) {
        const encoder = new TextEncoder();
        const data = encoder.encode(str);
        const hashBuffer = await crypto.webcrypto.subtle.digest('SHA-256', data);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
    }

    const testSeeds = [
        'cyber-rail-seed-alpha-001-authoritative-random-64-char-string-0001',
        '3f78b109c31405e492f2c4a87b1c3e5d0a9b8c7d6e5f4a3b2c1d0e9f8a7b6c5d',
        'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    ];

    for (const seed of testSeeds) {
        const expectedHash = crypto.createHash('sha256').update(seed).digest('hex');
        const computedHash = await computeSha256WebCrypto(seed);

        assert.equal(computedHash, expectedHash, `Web Crypto SHA-256 must match Node crypto for seed: ${seed}`);
    }

    // Tampered seed detection
    const validSeed = '3f78b109c31405e492f2c4a87b1c3e5d0a9b8c7d6e5f4a3b2c1d0e9f8a7b6c5d';
    const preStartCommitment = crypto.createHash('sha256').update(validSeed).digest('hex');

    const tamperedSeed = '3f78b109c31405e492f2c4a87b1c3e5d0a9b8c7d6e5f4a3b2c1d0e9f8a7b6c5e';
    const tamperedHash = await computeSha256WebCrypto(tamperedSeed);

    assert.notEqual(tamperedHash, preStartCommitment, 'Tampered seed must NOT match pre-start commitment.');

    function verifySeedCommitment(receivedSeed, commitment) {
        const hash = crypto.createHash('sha256').update(receivedSeed).digest('hex');
        return hash.toLowerCase() === commitment.toLowerCase();
    }

    assert.equal(verifySeedCommitment(validSeed, preStartCommitment), true);
    assert.equal(verifySeedCommitment(tamperedSeed, preStartCommitment), false);
});
