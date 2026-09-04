/**
 * TrackGenerator.js - Pure JavaScript deterministic track & obstacle generator.
 * Produces descriptors identical to App\Services\AntiCheat\TrackGenerator.php.
 * Zero DOM / Three.js dependencies: runs in Node.js, Web Workers, and Browser.
 */
export class TrackGenerator {
    static LANE_WIDTH = 2.4;

    /**
     * @param {import('../core/PRNG.js').PRNG} prng
     */
    constructor(prng) {
        this.prng = prng;
        this.LANE_WIDTH = TrackGenerator.LANE_WIDTH;
    }

    /**
     * Generates all obstacles and coins for a track segment [startZ, startZ + length].
     * @param {number} startZ
     * @param {number} length
     * @returns {{
     *     obstacles: Array<{type: string, lane: number, x: number, y: number, z: number, min_x: number, max_x: number, min_y: number, max_y: number, min_z: number, max_z: number}>,
     *     coins: Array<{lane: number, x: number, y: number, z: number, min_x: number, max_x: number, min_y: number, max_y: number, min_z: number, max_z: number}>
     * }}
     */
    generateSegment(startZ, length) {
        const obstacles = [];
        const coins = [];

        if (startZ < 30.0) {
            return { obstacles: [], coins: [] };
        }

        const step = 15.0;
        for (let z = startZ + 8.0; z < startZ + length - 5.0; z += step) {
            const wave = this.generateWave(z);
            for (let i = 0; i < wave.obstacles.length; i++) {
                obstacles.push(wave.obstacles[i]);
            }
            for (let i = 0; i < wave.coins.length; i++) {
                coins.push(wave.coins[i]);
            }
        }

        return { obstacles, coins };
    }

    /**
     * Generates a single obstacle/coin wave at z.
     * @param {number} z
     * @returns {{ obstacles: Array, coins: Array }}
     */
    generateWave(z) {
        const pattern = this.prng.nextInt(0, 5);
        const lanes = [-1, 0, 1];
        const obstacles = [];
        const coins = [];

        switch (pattern) {
            case 0: {
                const trainLane = this.prng.choice(lanes);
                obstacles.push(this.createObstacle('TRAIN', trainLane, z));

                const coinLane = trainLane === 0 ? this.prng.choice([-1, 1]) : 0;
                coins.push(this.createCoin(coinLane, z));
                coins.push(this.createCoin(coinLane, z + 2.5));
                break;
            }

            case 1: {
                const shuffled = this.shuffleLanes();
                obstacles.push(this.createObstacle('HURDLE', shuffled[0], z));
                obstacles.push(this.createObstacle('ARCHWAY', shuffled[1], z));
                coins.push(this.createCoin(shuffled[2], z));
                break;
            }

            case 2: {
                obstacles.push(this.createObstacle('HURDLE', -1, z));
                obstacles.push(this.createObstacle('HURDLE', 0, z));
                obstacles.push(this.createObstacle('HURDLE', 1, z));
                break;
            }

            case 3: {
                const openLane = this.prng.choice(lanes);
                for (let i = 0; i < lanes.length; i++) {
                    const lane = lanes[i];
                    if (lane !== openLane) {
                        obstacles.push(this.createObstacle('ARCHWAY', lane, z));
                    } else {
                        coins.push(this.createCoin(lane, z));
                        coins.push(this.createCoin(lane, z + 2.5));
                    }
                }
                break;
            }

            case 4: {
                const targetLane = this.prng.choice(lanes);
                coins.push(this.createCoin(targetLane, z - 2.0));
                coins.push(this.createCoin(targetLane, z));
                coins.push(this.createCoin(targetLane, z + 2.0));
                break;
            }

            default: {
                const lane = this.prng.choice(lanes);
                const type = this.prng.boolean() ? 'HURDLE' : 'ARCHWAY';
                obstacles.push(this.createObstacle(type, lane, z));
                break;
            }
        }

        return { obstacles, coins };
    }

    /**
     * @returns {number[]}
     */
    shuffleLanes() {
        const arr = [-1, 0, 1];
        for (let i = arr.length - 1; i > 0; i--) {
            const j = this.prng.nextInt(0, i);
            const temp = arr[i];
            arr[i] = arr[j];
            arr[j] = temp;
        }
        return arr;
    }

    /**
     * @param {string} type
     * @param {number} lane
     * @param {number} z
     */
    createObstacle(type, lane, z) {
        const px = -lane * this.LANE_WIDTH;
        const py = 0.0;
        const pz = z;

        let minX, maxX, minY, maxY, minZ, maxZ;

        if (type === 'HURDLE') {
            minX = px - 0.95;
            maxX = px + 0.95;
            minY = 0.0;
            maxY = 1.25;
            minZ = pz - 0.25;
            maxZ = pz + 0.25;
        } else if (type === 'ARCHWAY') {
            minX = px - 0.95;
            maxX = px + 0.95;
            minY = 1.05;
            maxY = 2.4;
            minZ = pz - 0.3;
            maxZ = pz + 0.3;
        } else { // TRAIN
            minX = px - 0.95;
            maxX = px + 0.95;
            minY = 0.0;
            maxY = 3.5;
            minZ = pz - 2.5;
            maxZ = pz + 2.5;
        }

        return {
            type,
            lane,
            x: px,
            y: py,
            z: pz,
            min_x: minX,
            max_x: maxX,
            min_y: minY,
            max_y: maxY,
            min_z: minZ,
            max_z: maxZ,
        };
    }

    /**
     * @param {number} lane
     * @param {number} z
     */
    createCoin(lane, z) {
        const px = -lane * this.LANE_WIDTH;
        const py = 1.2;
        const pz = z;

        return {
            lane,
            x: px,
            y: py,
            z: pz,
            min_x: px - 0.4,
            max_x: px + 0.4,
            min_y: py - 0.4,
            max_y: py + 0.4,
            min_z: pz - 0.4,
            max_z: pz + 0.4,
        };
    }
}