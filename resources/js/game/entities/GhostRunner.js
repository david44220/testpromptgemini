import * as THREE from 'three';

/**
 * GhostRunner.js - Visual holographic representation of the opposing player.
 * Smoothly interpolates (lerps) between low-frequency (4-5 Hz) telemetry updates
 * to render a fluid, jitter-free 60 FPS opponent ghost on screen.
 */
export class GhostRunner {
    /**
     * @param {THREE.Scene} scene
     * @param {number} [laneWidth=2.4]
     */
    constructor(scene, laneWidth = 2.4) {
        this.scene = scene;
        this.LANE_WIDTH = laneWidth;
        this.GROUND_Y = 0.9;

        // Dynamic targets received via 4-5 Hz WebSockets
        this.targetX = 0;
        this.targetY = this.GROUND_Y;
        this.targetZ = 0;
        this.currentScore = 0;
        this.isAlive = true;
        this.hasReceivedTelemetry = false;

        // Running animation cycle
        this.animPhase = 0;

        // Mesh container
        this.mesh = this._buildGhostMesh();
        this.mesh.visible = false;
        this.scene.add(this.mesh);
    }

    _buildGhostMesh() {
        const group = new THREE.Group();

        // Holographic semi-transparent neon magenta/violet shader-style material
        this.hologramMat = new THREE.MeshStandardMaterial({
            color: 0xff0088,
            emissive: 0xff0088,
            emissiveIntensity: 1.8,
            roughness: 0.1,
            metalness: 0.9,
            transparent: true,
            opacity: 0.72,
            wireframe: false,
        });

        const ghostVisorMat = new THREE.MeshBasicMaterial({
            color: 0x00ffff,
            transparent: true,
            opacity: 0.95,
        });

        // 1. Torso
        const torsoGeo = new THREE.BoxGeometry(0.68, 0.82, 0.42);
        this.torso = new THREE.Mesh(torsoGeo, this.hologramMat);
        this.torso.position.y = 0.86;
        group.add(this.torso);

        // Jetpack unit
        const packGeo = new THREE.BoxGeometry(0.48, 0.58, 0.22);
        const jetpack = new THREE.Mesh(packGeo, this.hologramMat);
        jetpack.position.set(0, 0.06, -0.25);
        this.torso.add(jetpack);

        // 2. Head & Helmet
        const headGeo = new THREE.BoxGeometry(0.44, 0.44, 0.44);
        this.head = new THREE.Mesh(headGeo, this.hologramMat);
        this.head.position.set(0, 1.46, 0);
        group.add(this.head);

        // 3. Glowing Cyber Visor
        const visorGeo = new THREE.BoxGeometry(0.38, 0.16, 0.16);
        const visor = new THREE.Mesh(visorGeo, ghostVisorMat);
        visor.position.set(0, 0.02, 0.2);
        this.head.add(visor);

        // 4. Limbs
        const armGeo = new THREE.BoxGeometry(0.19, 0.65, 0.21);
        this.leftArm = new THREE.Mesh(armGeo, this.hologramMat);
        this.leftArm.position.set(-0.49, 0.84, 0);
        group.add(this.leftArm);

        this.rightArm = new THREE.Mesh(armGeo, this.hologramMat);
        this.rightArm.position.set(0.49, 0.84, 0);
        group.add(this.rightArm);

        const legGeo = new THREE.BoxGeometry(0.22, 0.76, 0.25);
        this.leftLeg = new THREE.Mesh(legGeo, this.hologramMat);
        this.leftLeg.position.set(-0.2, 0.35, 0);
        group.add(this.leftLeg);

        this.rightLeg = new THREE.Mesh(legGeo, this.hologramMat);
        this.rightLeg.position.set(0.2, 0.35, 0);
        group.add(this.rightLeg);

        return group;
    }

    /**
     * Receives a throttled 4-5 Hz telemetry packet from the presence channel.
     * @param {{ distance: number, current_lane: number, score: number, is_alive: boolean }} telemetry
     */
    applyTelemetry(telemetry) {
        this.targetZ = telemetry.distance;
        this.targetX = -telemetry.current_lane * this.LANE_WIDTH;
        this.currentScore = telemetry.score;
        this.isAlive = telemetry.is_alive;

        if (!this.hasReceivedTelemetry) {
            this.hasReceivedTelemetry = true;
            this.mesh.position.set(this.targetX, this.GROUND_Y, this.targetZ);
            this.mesh.visible = true;
        }

        if (!this.isAlive) {
            this.hologramMat.color.setHex(0x555555);
            this.hologramMat.opacity = 0.3;
        }
    }

    /**
     * Smooth per-frame interpolation (lerp) executed in requestAnimationFrame.
     * @param {number} dt Delta time in seconds
     */
    update(dt) {
        if (!this.mesh.visible || !this.hasReceivedTelemetry) return;

        // Smooth Lerp on X (lane switches) and Z (forward progression)
        const lerpFactor = Math.min(10.0 * dt, 1.0);
        this.mesh.position.x += (this.targetX - this.mesh.position.x) * lerpFactor;
        this.mesh.position.z += (this.targetZ - this.mesh.position.z) * lerpFactor;

        // Running limb animation cycle
        this.animPhase += 24.0 * dt * 1.8;
        const swing = Math.sin(this.animPhase) * 0.4;
        this.leftLeg.position.z = swing * 0.4;
        this.rightLeg.position.z = -swing * 0.4;
        this.leftArm.position.z = -swing * 0.4;
        this.rightArm.position.z = swing * 0.4;
    }

    reset() {
        this.targetX = 0;
        this.targetZ = 0;
        this.currentScore = 0;
        this.isAlive = true;
        this.hasReceivedTelemetry = false;
        this.mesh.visible = false;
        this.mesh.position.set(0, this.GROUND_Y, 0);
        this.hologramMat.color.setHex(0xff0077);
        this.hologramMat.opacity = 0.68;
    }

    destroy() {
        if (this.mesh.parent) {
            this.mesh.parent.remove(this.mesh);
        }
    }
}
