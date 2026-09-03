import * as THREE from 'three';
import { TextureGenerator } from '../core/TextureGenerator.js';

/**
 * TrackPool.js - Recycled procedural track segments.
 * Pre-allocates a fixed pool of modular track meshes to ensure
 * ZERO memory allocations / garbage collection pauses during gameplay.
 */
export class TrackPool {
    /**
     * @param {THREE.Scene} scene
     * @param {number} [segmentCount=10]
     * @param {number} [segmentLength=30]
     */
    constructor(scene, segmentCount = 10, segmentLength = 30) {
        this.scene = scene;
        this.segmentCount = segmentCount;
        this.segmentLength = segmentLength;

        this.pool = [];
        this.furthestZ = 0;

        // Shared geometries & materials to minimize GPU memory & draw calls
        this._initSharedAssets();
        this._buildPool();
        this.reset();
    }

    _initSharedAssets() {
        // Track Road with Procedural Ultra HD Cyber Asphalt & Circuitry Texture
        this.roadGeo = new THREE.PlaneGeometry(8.2, this.segmentLength, 1, 1);
        this.roadGeo.rotateX(-Math.PI / 2);

        const roadTexture = TextureGenerator.createCyberRoadTexture();
        this.roadMat = new THREE.MeshStandardMaterial({
            map: roadTexture,
            color: 0xffffff,
            roughness: 0.18, // Ultra-slick reflective sheen
            metalness: 0.85,
        });

        // Neon Lane Divider Strip (2 dividers for 3 lanes)
        this.dividerGeo = new THREE.BoxGeometry(0.12, 0.04, this.segmentLength);
        this.dividerMat = new THREE.MeshStandardMaterial({
            color: 0x00f0ff,
            emissive: 0x00f0ff,
            emissiveIntensity: 0.5,
            roughness: 0.2,
        });

        // Luxury Outer Guardrails (Obsidian & Champagne Gold)
        this.railGeo = new THREE.BoxGeometry(0.35, 0.7, this.segmentLength);
        this.railMat = new THREE.MeshStandardMaterial({
            color: 0xd4af37,
            emissive: 0xd4af37,
            emissiveIntensity: 0.25,
            metalness: 0.95,
            roughness: 0.12,
        });

        // Glowing Energy Conduit Pipes running along track shoulders
        this.conduitGeo = new THREE.CylinderGeometry(0.08, 0.08, this.segmentLength, 8);
        this.conduitGeo.rotateX(Math.PI / 2);
        this.conduitMat = new THREE.MeshStandardMaterial({
            color: 0x00f0ff,
            emissive: 0x00f0ff,
            emissiveIntensity: 0.75,
            roughness: 0.1,
        });

        // Side pylons / pillars
        this.pylonGeo = new THREE.CylinderGeometry(0.18, 0.28, 4.0, 8);
        this.pylonMat = new THREE.MeshStandardMaterial({
            color: 0x141828,
            metalness: 0.9,
            roughness: 0.25,
            emissive: 0x002244,
            emissiveIntensity: 0.3,
        });

        // Overhead Speed Telemetry Gantry Frame
        this.gantryCrossGeo = new THREE.BoxGeometry(9.4, 0.4, 0.6);
        this.gantryMat = new THREE.MeshStandardMaterial({
            color: 0x0a0f1d,
            metalness: 0.95,
            roughness: 0.2,
            emissive: 0x00f0ff,
            emissiveIntensity: 0.25,
        });
    }

    _buildPool() {
        for (let i = 0; i < this.segmentCount; i++) {
            const segment = new THREE.Group();

            // 1. Road mesh with real-time shadow receiving
            const road = new THREE.Mesh(this.roadGeo, this.roadMat);
            road.position.set(0, 0, this.segmentLength / 2);
            road.receiveShadow = true;
            segment.add(road);

            // 2. Left and Right Lane Divider Lines
            const leftDivider = new THREE.Mesh(this.dividerGeo, this.dividerMat);
            leftDivider.position.set(-1.2, 0.02, this.segmentLength / 2);
            segment.add(leftDivider);

            const rightDivider = new THREE.Mesh(this.dividerGeo, this.dividerMat);
            rightDivider.position.set(1.2, 0.02, this.segmentLength / 2);
            segment.add(rightDivider);

            // 3. Left & Right Guard Rails with dynamic shadow casting
            const leftRail = new THREE.Mesh(this.railGeo, this.railMat);
            leftRail.position.set(-4.1, 0.35, this.segmentLength / 2);
            leftRail.castShadow = true;
            segment.add(leftRail);

            const rightRail = new THREE.Mesh(this.railGeo, this.railMat);
            rightRail.position.set(4.1, 0.35, this.segmentLength / 2);
            rightRail.castShadow = true;
            segment.add(rightRail);

            // 4. Glowing Fiber-Optic Energy Conduits
            const leftConduit = new THREE.Mesh(this.conduitGeo, this.conduitMat);
            leftConduit.position.set(-3.85, 0.75, this.segmentLength / 2);
            segment.add(leftConduit);

            const rightConduit = new THREE.Mesh(this.conduitGeo, this.conduitMat);
            rightConduit.position.set(3.85, 0.75, this.segmentLength / 2);
            segment.add(rightConduit);

            // 5. Boundary Pylons
            for (let pz = 5; pz <= this.segmentLength; pz += 15) {
                const pylonL = new THREE.Mesh(this.pylonGeo, this.pylonMat);
                pylonL.position.set(-4.3, 2.0, pz);
                pylonL.castShadow = true;
                segment.add(pylonL);

                const pylonR = new THREE.Mesh(this.pylonGeo, this.pylonMat);
                pylonR.position.set(4.3, 2.0, pz);
                pylonR.castShadow = true;
                segment.add(pylonR);
            }

            // 6. Occasional Overhead Cyber Gantry
            if (i % 2 === 0) {
                const gantry = new THREE.Mesh(this.gantryCrossGeo, this.gantryMat);
                gantry.position.set(0, 4.0, this.segmentLength * 0.75);
                gantry.castShadow = true;
                segment.add(gantry);
            }

            this.scene.add(segment);
            this.pool.push(segment);
        }
    }

    /**
     * Resets track sequence starting from initial canonical z offset.
     * @param {number} [startZ=-30]
     */
    reset(startZ = -30) {
        this.furthestZ = startZ;
        for (let i = 0; i < this.segmentCount; i++) {
            const segment = this.pool[i];
            segment.position.set(0, 0, this.furthestZ);
            this.furthestZ += this.segmentLength;
        }
    }

    /**
     * Checks if segments have fallen behind camera and recycles them forward.
     * Canonical recycle order: 270, 300, 330, 360...
     * @param {number} playerZ Current player position on Z axis
     * @param {Function} [onRecycleSegment] Callback invoked with (segmentZ, segmentLength)
     */
    update(playerZ, onRecycleSegment) {
        const recycleThreshold = playerZ - this.segmentLength;

        for (let i = 0; i < this.segmentCount; i++) {
            const segment = this.pool[i];

            if (segment.position.z < recycleThreshold) {
                // Shift this segment to the furthest position ahead
                const newStartZ = this.furthestZ;
                segment.position.z = newStartZ;
                this.furthestZ += this.segmentLength;

                if (onRecycleSegment) {
                    onRecycleSegment(newStartZ, this.segmentLength);
                }
            }
        }
    }
}
