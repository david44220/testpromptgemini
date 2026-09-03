/**
 * CollisionSystem.js - Fast Axis-Aligned Bounding Box (AABB) intersection detector.
 * Performs zero runtime memory allocations while supporting variable-height
 * mechanic clearances (jumping over hurdles, ducking/sliding under archways).
 */
export class CollisionSystem {
    /**
     * @param {import('../entities/Player.js').Player} player
     * @param {import('../entities/ObstaclePool.js').ObstaclePool} obstaclePool
     */
    constructor(player, obstaclePool) {
        this.player = player;
        this.obstaclePool = obstaclePool;
    }

    /**
     * Performs collision testing between the player and active obstacles / coins.
     * @returns {{ hit: boolean, obstacle: Object|null, coinCount: number }}
     */
    checkCollisions() {
        const playerBox = this.player.boundingBox;
        const obstacles = this.obstaclePool.activeObstacles;
        let lethalHit = false;
        let collidingObstacle = null;

        // 1. Test Lethal Obstacles
        for (let i = 0; i < obstacles.length; i++) {
            const obs = obstacles[i];
            if (!obs.active) continue;

            // Fast Z-axis distance culling before testing full 3D AABB
            const dz = obs.mesh.position.z - this.player.mesh.position.z;
            if (dz > 6.0 || dz < -4.0) {
                continue;
            }

            if (playerBox.intersectsBox(obs.boundingBox)) {
                // Determine if clearance mechanics evade collision
                if (obs.type === 'HURDLE') {
                    // Clearance: jumping high enough over hurdle
                    if (this.player.isJumping && this.player.mesh.position.y > 1.25) {
                        continue; // Cleared!
                    }
                } else if (obs.type === 'ARCHWAY') {
                    // Clearance: rolling / ducking under crossbar
                    if (this.player.isRolling && this.player.mesh.position.y <= 1.0) {
                        continue; // Cleared!
                    }
                }

                // Lethal collision occurred
                lethalHit = true;
                collidingObstacle = obs;
                break;
            }
        }

        // 2. Test Collectibles (Coins)
        const coins = this.obstaclePool.activeCoins;
        let collectedThisTick = 0;

        for (let i = 0; i < coins.length; i++) {
            const coin = coins[i];
            if (!coin.active || coin.collected) continue;

            const dz = coin.mesh.position.z - this.player.mesh.position.z;
            if (dz > 4.0 || dz < -2.0) {
                continue;
            }

            if (playerBox.intersectsBox(coin.boundingBox)) {
                coin.collected = true;
                coin.mesh.visible = false;
                coin.mesh.position.set(0, -100, 0);
                collectedThisTick++;
            }
        }

        return {
            hit: lethalHit,
            obstacle: collidingObstacle,
            coinCount: collectedThisTick,
        };
    }
}
