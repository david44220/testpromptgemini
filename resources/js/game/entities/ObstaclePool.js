import * as THREE from 'three';
import { PRNG } from '../core/PRNG.js';
import { BillboardMesh } from './BillboardMesh.js';
import { TextureGenerator } from '../core/TextureGenerator.js';

/**
 * ObstaclePool.js - Pre-allocated, deterministic obstacle & collectible manager.
 * Spawns Hurdles, Archways, Trains, Coins, and 3D Dynamic Billboards strictly governed by a PRNG.
 * Enforces zero dynamic instantiations inside the game loop.
 */
export class ObstaclePool {
    /**
     * @param {THREE.Scene} scene
     * @param {string} [seed='']
     */
    constructor(scene, seed = '') {
        this.scene = scene;
        this.prng = new PRNG(seed);
        this.LANE_WIDTH = 2.4;

        // Pool capacities
        this.HURDLE_COUNT = 8;
        this.ARCHWAY_COUNT = 6;
        this.TRAIN_COUNT = 6;
        this.COIN_COUNT = 30;
        this.BILLBOARD_COUNT = 6;

        // Pools
        this.hurdles = [];
        this.archways = [];
        this.trains = [];
        this.coins = [];
        this.billboards = [];

        // Unified list of all active items for fast iteration
        this.activeObstacles = [];
        this.activeCoins = [];
        this.activeBillboards = [];

        this.nextSpawnZ = 30;
        this.minSpawnInterval = 18;
        this.maxSpawnInterval = 32;

        // Ad creatives repository
        this.creatives = [];
        this._loadCreatives();

        this._initMaterials();
        this._buildPools();
    }

    _loadCreatives() {
        if (typeof fetch !== 'undefined') {
            fetch('/api/v1/ads/active-creatives')
                .then((res) => res.json())
                .then((data) => {
                    if (data?.billboards_3d) {
                        this.creatives = data.billboards_3d;
                    }
                })
                .catch(() => {});
        }
    }

    _initMaterials() {
        this.hazardTexture = TextureGenerator.createHazardTexture();

        // 1. High-Voltage Laser Hurdle Materials
        this.hurdleMat = new THREE.MeshStandardMaterial({
            map: this.hazardTexture,
            color: 0xffffff,
            roughness: 0.25,
            metalness: 0.85,
        });

        this.laserBeamMat = new THREE.MeshBasicMaterial({
            color: 0xff3300,
            transparent: true,
            opacity: 0.92,
        });

        this.strobeMat = new THREE.MeshBasicMaterial({
            color: 0xffaa00,
        });

        // 2. Cyber Security Archway Materials
        this.archwayMat = new THREE.MeshStandardMaterial({
            color: 0x090d16,
            roughness: 0.15,
            metalness: 0.95,
            emissive: 0x00f0ff,
            emissiveIntensity: 0.45,
        });

        this.scannerLaserMat = new THREE.MeshBasicMaterial({
            color: 0x00f0ff,
            transparent: true,
            opacity: 0.75,
        });

        // 3. Bullet Maglev Train Materials
        this.trainBodyMat = new THREE.MeshStandardMaterial({
            color: 0x0a0c16,
            roughness: 0.15,
            metalness: 0.95,
        });

        this.trainCockpitMat = new THREE.MeshStandardMaterial({
            color: 0x001524,
            emissive: 0x00d4ff,
            emissiveIntensity: 0.85,
            roughness: 0.05,
            metalness: 0.95,
        });

        this.trainHeadlightMat = new THREE.MeshBasicMaterial({
            color: 0xffeedd,
        });

        this.trainTailLightMat = new THREE.MeshStandardMaterial({
            color: 0xff0055,
            emissive: 0xff0055,
            emissiveIntensity: 2.2,
        });

        // 4. Multifaceted 3D Diamond Coin Materials
        this.coinDiamondMat = new THREE.MeshStandardMaterial({
            color: 0xffd700,
            emissive: 0xd4af37,
            emissiveIntensity: 0.9,
            metalness: 0.98,
            roughness: 0.06,
        });

        this.coinCoreMat = new THREE.MeshBasicMaterial({
            color: 0xffffff,
        });
    }

    _buildPools() {
        // 1. Build High-Voltage Laser Hurdles (Requires JUMP - height: 0.65m)
        const hurdleBarGeo = new THREE.BoxGeometry(2.1, 0.38, 0.35);
        const hurdlePostGeo = new THREE.CylinderGeometry(0.09, 0.12, 0.75, 8);
        const laserGeo = new THREE.CylinderGeometry(0.035, 0.035, 2.0, 8);
        laserGeo.rotateZ(Math.PI / 2);
        const strobeGeo = new THREE.SphereGeometry(0.08, 8, 8);

        for (let i = 0; i < this.HURDLE_COUNT; i++) {
            const group = new THREE.Group();

            const bar = new THREE.Mesh(hurdleBarGeo, this.hurdleMat);
            bar.position.y = 0.38;
            bar.castShadow = true;
            group.add(bar);

            const laser = new THREE.Mesh(laserGeo, this.laserBeamMat);
            laser.position.y = 0.58;
            group.add(laser);

            const postL = new THREE.Mesh(hurdlePostGeo, this.trainBodyMat);
            postL.position.set(-0.95, 0.38, 0);
            postL.castShadow = true;
            group.add(postL);

            const strobeL = new THREE.Mesh(strobeGeo, this.strobeMat);
            strobeL.position.set(-0.95, 0.75, 0);
            group.add(strobeL);

            const postR = new THREE.Mesh(hurdlePostGeo, this.trainBodyMat);
            postR.position.set(0.95, 0.38, 0);
            postR.castShadow = true;
            group.add(postR);

            const strobeR = new THREE.Mesh(strobeGeo, this.strobeMat);
            strobeR.position.set(0.95, 0.75, 0);
            group.add(strobeR);

            group.visible = false;
            this.scene.add(group);

            this.hurdles.push({
                type: 'HURDLE',
                mesh: group,
                boundingBox: new THREE.Box3(),
                lane: 0,
                active: false,
            });
        }

        // 2. Build Cyber Security Archways (Requires ROLL/DUCK - open bottom Y: 0.0 - 0.85m)
        const archTopGeo = new THREE.BoxGeometry(2.3, 0.55, 0.45);
        const archPostGeo = new THREE.BoxGeometry(0.22, 2.6, 0.28);
        const scannerBeamGeo = new THREE.BoxGeometry(1.9, 0.06, 0.06);

        for (let i = 0; i < this.ARCHWAY_COUNT; i++) {
            const group = new THREE.Group();

            const topBar = new THREE.Mesh(archTopGeo, this.archwayMat);
            topBar.position.y = 1.62;
            topBar.castShadow = true;
            group.add(topBar);

            const scanner = new THREE.Mesh(scannerBeamGeo, this.scannerLaserMat);
            scanner.position.y = 1.25;
            group.add(scanner);

            const legL = new THREE.Mesh(archPostGeo, this.archwayMat);
            legL.position.set(-1.05, 1.25, 0);
            legL.castShadow = true;
            group.add(legL);

            const legR = new THREE.Mesh(archPostGeo, this.archwayMat);
            legR.position.set(1.05, 1.25, 0);
            legR.castShadow = true;
            group.add(legR);

            group.visible = false;
            this.scene.add(group);

            this.archways.push({
                type: 'ARCHWAY',
                mesh: group,
                boundingBox: new THREE.Box3(),
                lane: 0,
                active: false,
            });
        }

        // 3. Build Aerodynamic Bullet Maglev Trains (Must CHANGE LANE)
        const trainGeo = new THREE.BoxGeometry(2.1, 3.2, 5.0);
        const cockpitGeo = new THREE.BoxGeometry(1.7, 0.7, 1.4);
        const headlightGeo = new THREE.CylinderGeometry(0.18, 0.18, 0.1, 12);
        headlightGeo.rotateX(Math.PI / 2);
        const bumperGeo = new THREE.BoxGeometry(2.15, 0.4, 0.2);

        for (let i = 0; i < this.TRAIN_COUNT; i++) {
            const group = new THREE.Group();

            const body = new THREE.Mesh(trainGeo, this.trainBodyMat);
            body.position.y = 1.6;
            body.castShadow = true;
            group.add(body);

            const cockpit = new THREE.Mesh(cockpitGeo, this.trainCockpitMat);
            cockpit.position.set(0, 2.4, -1.8);
            group.add(cockpit);

            const lightL = new THREE.Mesh(headlightGeo, this.trainHeadlightMat);
            lightL.position.set(-0.6, 1.0, -2.51);
            group.add(lightL);

            const lightR = new THREE.Mesh(headlightGeo, this.trainHeadlightMat);
            lightR.position.set(0.6, 1.0, -2.51);
            group.add(lightR);

            const bumper = new THREE.Mesh(bumperGeo, this.trainTailLightMat);
            bumper.position.set(0, 0.4, -2.5);
            group.add(bumper);

            group.visible = false;
            this.scene.add(group);

            this.trains.push({
                type: 'TRAIN',
                mesh: group,
                boundingBox: new THREE.Box3(),
                lane: 0,
                active: false,
            });
        }

        // 4. Build Multifaceted 3D Diamond Coins
        const diamondGeo = new THREE.OctahedronGeometry(0.38, 0);
        const innerOrbGeo = new THREE.SphereGeometry(0.16, 12, 12);

        for (let i = 0; i < this.COIN_COUNT; i++) {
            const coinGroup = new THREE.Group();

            const diamond = new THREE.Mesh(diamondGeo, this.coinDiamondMat);
            diamond.castShadow = true;
            coinGroup.add(diamond);

            const core = new THREE.Mesh(innerOrbGeo, this.coinCoreMat);
            coinGroup.add(core);

            coinGroup.visible = false;
            this.scene.add(coinGroup);

            this.coins.push({
                type: 'COIN',
                mesh: coinGroup,
                diamondMesh: diamond,
                boundingBox: new THREE.Box3(),
                lane: 0,
                active: false,
                collected: false,
            });
        }

        // 5. Build 3D Trackside Billboards
        for (let i = 0; i < this.BILLBOARD_COUNT; i++) {
            this.billboards.push(new BillboardMesh(this.scene));
        }
    }

    /**
     * Resets all pools and deactivates all active obstacles and billboards.
     */
    reset() {
        this.activeObstacles.length = 0;
        this.activeCoins.length = 0;
        this.activeBillboards.length = 0;

        for (let i = 0; i < this.billboards.length; i++) {
            this.billboards[i].recycle();
        }

        const all = [...this.hurdles, ...this.archways, ...this.trains, ...this.coins];
        for (let i = 0; i < all.length; i++) {
            const item = all[i];
            item.active = false;
            item.mesh.visible = false;
            item.mesh.position.set(0, -100, 0);
            if (item.collected !== undefined) {
                item.collected = false;
            }
        }
    }

    /**
     * Spawns obstacles deterministically along a recycled track segment.
     * Guaranteed Solvability: always leaves at least 1 viable escape route.
     * @param {number} startZ Start Z position of track segment
     * @param {number} length Length of track segment
     */
    spawnSegmentObstacles(startZ, length) {
        // Do not spawn obstacles in the first safety zone
        if (startZ < 30) return;

        // Step through segment at interval intervals (e.g. 14 units)
        const step = 15;
        for (let z = startZ + 8; z < startZ + length - 5; z += step) {
            this._generateWave(z);
        }

        // Spawn trackside billboard along lateral boundaries (x = ±6.0m)
        if (this.prng.next() < 0.65) {
            const side = this.prng.next() < 0.5 ? -6.0 : 6.0;
            this._spawnBillboard(side, startZ + length * 0.5);
        }
    }

    /**
     * Generates a single wave across 3 lanes (-1, 0, 1) using PRNG.
     * @private
     * @param {number} z
     */
    _generateWave(z) {
        const pattern = this.prng.nextInt(0, 5);
        const lanes = [-1, 0, 1];

        switch (pattern) {
            case 0: {
                // 1 Train in random lane, coins in other lanes
                const trainLane = this.prng.choice(lanes);
                this._spawnObstacle('TRAIN', trainLane, z);

                const coinLane = trainLane === 0 ? this.prng.choice([-1, 1]) : 0;
                this._spawnCoin(coinLane, z);
                this._spawnCoin(coinLane, z + 2.5);
                break;
            }

            case 1: {
                // 1 Hurdle in lane A, 1 Archway in lane B, lane C open with coins
                const shuffled = this._shuffleLanes();
                this._spawnObstacle('HURDLE', shuffled[0], z);
                this._spawnObstacle('ARCHWAY', shuffled[1], z);
                this._spawnCoin(shuffled[2], z);
                break;
            }

            case 2: {
                // All 3 lanes have hurdles (Jumpable across all 3)
                this._spawnObstacle('HURDLE', -1, z);
                this._spawnObstacle('HURDLE', 0, z);
                this._spawnObstacle('HURDLE', 1, z);
                break;
            }

            case 3: {
                // 2 Archways (Duckable) + Coins in center
                const openLane = this.prng.choice(lanes);
                for (const lane of lanes) {
                    if (lane !== openLane) {
                        this._spawnObstacle('ARCHWAY', lane, z);
                    } else {
                        this._spawnCoin(lane, z);
                        this._spawnCoin(lane, z + 2.5);
                    }
                }
                break;
            }

            case 4: {
                // High-value Coin Trail (Arcade sprint)
                const targetLane = this.prng.choice(lanes);
                this._spawnCoin(targetLane, z - 2);
                this._spawnCoin(targetLane, z);
                this._spawnCoin(targetLane, z + 2);
                break;
            }

            default: {
                // Single hurdle or single archway
                const lane = this.prng.choice(lanes);
                const type = this.prng.boolean() ? 'HURDLE' : 'ARCHWAY';
                this._spawnObstacle(type, lane, z);
                break;
            }
        }
    }

    _shuffleLanes() {
        const arr = [-1, 0, 1];
        for (let i = arr.length - 1; i > 0; i--) {
            const j = this.prng.nextInt(0, i);
            const temp = arr[i];
            arr[i] = arr[j];
            arr[j] = temp;
        }
        return arr;
    }

    _spawnObstacle(type, lane, z) {
        let pool = null;
        if (type === 'HURDLE') pool = this.hurdles;
        else if (type === 'ARCHWAY') pool = this.archways;
        else if (type === 'TRAIN') pool = this.trains;

        if (!pool) return;

        // Find available inactive item from pool
        for (let i = 0; i < pool.length; i++) {
            const item = pool[i];
            if (!item.active) {
                item.active = true;
                item.lane = lane;
                item.mesh.position.set(-lane * this.LANE_WIDTH, 0, z);
                item.mesh.visible = true;

                // Update bounding box in-place
                this._updateObstacleBounds(item);

                this.activeObstacles.push(item);
                return;
            }
        }
    }

    _spawnCoin(lane, z) {
        for (let i = 0; i < this.coins.length; i++) {
            const coin = this.coins[i];
            if (!coin.active) {
                coin.active = true;
                coin.collected = false;
                coin.lane = lane;
                coin.mesh.position.set(-lane * this.LANE_WIDTH, 1.2, z);
                coin.mesh.visible = true;

                // Coin bounding box
                const px = coin.mesh.position.x;
                const py = coin.mesh.position.y;
                const pz = coin.mesh.position.z;
                coin.boundingBox.min.set(px - 0.4, py - 0.4, pz - 0.4);
                coin.boundingBox.max.set(px + 0.4, py + 0.4, pz + 0.4);

                this.activeCoins.push(coin);
                return;
            }
        }
    }

    _updateObstacleBounds(item) {
        const px = item.mesh.position.x;
        const pz = item.mesh.position.z;

        if (item.type === 'HURDLE') {
            // Hurdle: height 0.0 to 0.75m
            item.boundingBox.min.set(px - 0.95, 0.0, pz - 0.25);
            item.boundingBox.max.set(px + 0.95, 0.75, pz + 0.25);
        } else if (item.type === 'ARCHWAY') {
            // Archway: crossbar is at Y: 1.1 to 2.4m (allows ducking under 0.85m)
            item.boundingBox.min.set(px - 0.95, 1.05, pz - 0.3);
            item.boundingBox.max.set(px + 0.95, 2.4, pz + 0.3);
        } else if (item.type === 'TRAIN') {
            // Train: full solid block
            item.boundingBox.min.set(px - 0.95, 0.0, pz - 2.5);
            item.boundingBox.max.set(px + 0.95, 3.5, pz + 2.5);
        }
    }

    /**
     * Updates active obstacles & coins, recycling items that passed behind player.
     * @param {number} playerZ
     * @param {number} dt Delta time
     */
    update(playerZ, dt) {
        const cullZ = playerZ - 12;

        // Spin and float multifaceted 3D diamond coins
        const time = performance.now() * 0.003;
        const coinRotation = 3.8 * dt;
        for (let i = 0; i < this.activeCoins.length; i++) {
            const coin = this.activeCoins[i];
            coin.mesh.rotation.y += coinRotation;
            coin.mesh.rotation.x = Math.sin(time + i) * 0.18;
            if (coin.diamondMesh) {
                coin.diamondMesh.rotation.z += 2.2 * dt;
            }
        }

        // Recycle obstacles behind player
        for (let i = this.activeObstacles.length - 1; i >= 0; i--) {
            const obs = this.activeObstacles[i];
            if (obs.mesh.position.z < cullZ) {
                obs.active = false;
                obs.mesh.visible = false;
                obs.mesh.position.set(0, -100, 0);
                this.activeObstacles.splice(i, 1);
            }
        }

        // Recycle coins behind player or collected
        for (let i = this.activeCoins.length - 1; i >= 0; i--) {
            const coin = this.activeCoins[i];
            if (coin.mesh.position.z < cullZ || coin.collected) {
                coin.active = false;
                coin.mesh.visible = false;
                coin.mesh.position.set(0, -100, 0);
                this.activeCoins.splice(i, 1);
            }
        }

        // Update 3D Trackside Billboards and recycle
        for (let i = this.activeBillboards.length - 1; i >= 0; i--) {
            const b = this.activeBillboards[i];
            b.update(dt, playerZ);
            if (b.group.position.z < cullZ) {
                b.recycle();
                this.activeBillboards.splice(i, 1);
            }
        }
    }

    _spawnBillboard(sideX, z) {
        for (let i = 0; i < this.billboards.length; i++) {
            const b = this.billboards[i];
            if (!b.isActive) {
                const creative = this.creatives.length > 0
                    ? this.creatives[this.prng.nextInt(0, this.creatives.length - 1)]
                    : null;
                b.spawn(sideX, z, creative);
                this.activeBillboards.push(b);
                return;
            }
        }
    }
}
