import * as THREE from 'three';

/**
 * Player.js - Low-poly runner entity with 3-lane navigation, jump/roll physics,
 * and zero-allocation Axis-Aligned Bounding Box (Box3) updates.
 */
export class Player {
    /**
     * @param {THREE.Scene} scene
     */
    constructor(scene) {
        this.scene = scene;

        // Lane constants: -1 (Left), 0 (Center), 1 (Right)
        this.LANE_WIDTH = 2.4;
        this.currentLane = 0;
        this.targetX = 0;

        // Physics constants
        this.GROUND_Y = 0.9;
        this.GRAVITY = 38.0;
        this.JUMP_VELOCITY = 14.5;
        this.FAST_FALL_VELOCITY = -24.0;
        this.ROLL_DURATION = 0.55; // seconds

        // Dynamic State
        this.velocityY = 0;
        this.isJumping = false;
        this.isRolling = false;
        this.rollTimer = 0;
        this.baseSpeed = 22.0; // units per second
        this.speed = this.baseSpeed;
        this.maxSpeed = 46.0;

        // Running animation phase
        this.animPhase = 0;

        // Bounding Box (Allocated once, mutated in-place)
        this.boundingBox = new THREE.Box3();
        this.boxMin = new THREE.Vector3();
        this.boxMax = new THREE.Vector3();

        // Mesh container
        this.mesh = this._buildPlayerMesh();
        this.scene.add(this.mesh);

        this.reset();
    }

    /**
     * Builds an ultra-high-definition Cyber Exosuit runner character.
     * @private
     * @returns {THREE.Group}
     */
    _buildPlayerMesh() {
        const group = new THREE.Group();

        // 1. High-Tech PBR Material Palette
        const carbonArmorMat = new THREE.MeshStandardMaterial({
            color: 0x0c0f18,
            roughness: 0.18,
            metalness: 0.95,
        });

        const goldPlatingMat = new THREE.MeshStandardMaterial({
            color: 0xd4af37,
            roughness: 0.12,
            metalness: 0.98,
            emissive: 0xd4af37,
            emissiveIntensity: 0.25,
        });

        const cyanCoreMat = new THREE.MeshStandardMaterial({
            color: 0x00f0ff,
            emissive: 0x00f0ff,
            emissiveIntensity: 2.2, // Vibrant bloom glow
            roughness: 0.1,
            metalness: 0.8,
        });

        const visorMat = new THREE.MeshStandardMaterial({
            color: 0xff0055,
            emissive: 0xff0055,
            emissiveIntensity: 2.6,
            roughness: 0.05,
            metalness: 0.9,
        });

        const thrusterFlameMat = new THREE.MeshBasicMaterial({
            color: 0x00f0ff,
            transparent: true,
            opacity: 0.9,
        });

        // 2. Torso & Armor Vest
        const torsoGeo = new THREE.BoxGeometry(0.68, 0.82, 0.42);
        this.torso = new THREE.Mesh(torsoGeo, carbonArmorMat);
        this.torso.position.y = 0.86;
        this.torso.castShadow = true;
        group.add(this.torso);

        // Gold Chest Plate Accent
        const chestPlateGeo = new THREE.BoxGeometry(0.55, 0.45, 0.12);
        const chestPlate = new THREE.Mesh(chestPlateGeo, goldPlatingMat);
        chestPlate.position.set(0, 0.12, 0.2);
        chestPlate.castShadow = true;
        this.torso.add(chestPlate);

        // Pulsing Hex Arc Reactor Core
        const coreGeo = new THREE.CylinderGeometry(0.1, 0.1, 0.06, 6);
        coreGeo.rotateX(Math.PI / 2);
        this.reactorCore = new THREE.Mesh(coreGeo, cyanCoreMat);
        this.reactorCore.position.set(0, 0.12, 0.26);
        this.torso.add(this.reactorCore);

        // 3. Jetpack Unit (Dual Thrusters on back)
        const packGeo = new THREE.BoxGeometry(0.48, 0.58, 0.22);
        const jetpack = new THREE.Mesh(packGeo, carbonArmorMat);
        jetpack.position.set(0, 0.06, -0.25);
        jetpack.castShadow = true;
        this.torso.add(jetpack);

        // Gold Jetpack Trims
        const thrusterL = new THREE.Mesh(new THREE.CylinderGeometry(0.08, 0.09, 0.45, 8), goldPlatingMat);
        thrusterL.position.set(-0.16, -0.05, -0.28);
        this.torso.add(thrusterL);

        const thrusterR = new THREE.Mesh(new THREE.CylinderGeometry(0.08, 0.09, 0.45, 8), goldPlatingMat);
        thrusterR.position.set(0.16, -0.05, -0.28);
        this.torso.add(thrusterR);

        // Plasma Flame Cones (Pulsed in update loop)
        const flameGeo = new THREE.ConeGeometry(0.075, 0.45, 8);
        flameGeo.rotateX(-Math.PI / 2);
        flameGeo.translate(0, 0, -0.22);

        this.leftFlame = new THREE.Mesh(flameGeo, thrusterFlameMat);
        this.leftFlame.position.set(-0.16, -0.28, -0.28);
        this.torso.add(this.leftFlame);

        this.rightFlame = new THREE.Mesh(flameGeo, thrusterFlameMat.clone());
        this.rightFlame.position.set(0.16, -0.28, -0.28);
        this.torso.add(this.rightFlame);

        // 4. Aerodynamic Cyber Helmet
        const headGeo = new THREE.BoxGeometry(0.44, 0.44, 0.44);
        this.head = new THREE.Mesh(headGeo, carbonArmorMat);
        this.head.position.set(0, 1.46, 0);
        this.head.castShadow = true;
        group.add(this.head);

        // Gold Helmet Fin
        const finGeo = new THREE.BoxGeometry(0.06, 0.12, 0.38);
        const fin = new THREE.Mesh(finGeo, goldPlatingMat);
        fin.position.set(0, 0.25, 0);
        this.head.add(fin);

        // Glowing Panoramic Curved Visor
        const visorGeo = new THREE.BoxGeometry(0.38, 0.16, 0.16);
        const visor = new THREE.Mesh(visorGeo, visorMat);
        visor.position.set(0, 0.02, 0.2);
        this.head.add(visor);

        // 5. Articulated Shoulders & Arms
        const shoulderGeo = new THREE.BoxGeometry(0.24, 0.24, 0.24);
        const armGeo = new THREE.BoxGeometry(0.19, 0.65, 0.21);

        // Left Arm with Gold Shoulder Pauldron
        this.leftArm = new THREE.Group();
        this.leftArm.position.set(-0.49, 0.84, 0);

        const lShoulder = new THREE.Mesh(shoulderGeo, goldPlatingMat);
        lShoulder.position.set(0, 0.24, 0);
        lShoulder.castShadow = true;
        this.leftArm.add(lShoulder);

        const lLimb = new THREE.Mesh(armGeo, carbonArmorMat);
        lLimb.castShadow = true;
        this.leftArm.add(lLimb);
        group.add(this.leftArm);

        // Right Arm with Gold Shoulder Pauldron
        this.rightArm = new THREE.Group();
        this.rightArm.position.set(0.49, 0.84, 0);

        const rShoulder = new THREE.Mesh(shoulderGeo, goldPlatingMat);
        rShoulder.position.set(0, 0.24, 0);
        rShoulder.castShadow = true;
        this.rightArm.add(rShoulder);

        const rLimb = new THREE.Mesh(armGeo, carbonArmorMat);
        rLimb.castShadow = true;
        this.rightArm.add(rLimb);
        group.add(this.rightArm);

        // 6. Armored Cyber Legs
        const legGeo = new THREE.BoxGeometry(0.22, 0.76, 0.25);
        const kneeGeo = new THREE.BoxGeometry(0.24, 0.14, 0.14);

        // Left Leg
        this.leftLeg = new THREE.Group();
        this.leftLeg.position.set(-0.2, 0.35, 0);

        const lLegMesh = new THREE.Mesh(legGeo, carbonArmorMat);
        lLegMesh.castShadow = true;
        this.leftLeg.add(lLegMesh);

        const lKnee = new THREE.Mesh(kneeGeo, goldPlatingMat);
        lKnee.position.set(0, 0.05, 0.12);
        this.leftLeg.add(lKnee);
        group.add(this.leftLeg);

        // Right Leg
        this.rightLeg = new THREE.Group();
        this.rightLeg.position.set(0.2, 0.35, 0);

        const rLegMesh = new THREE.Mesh(legGeo, carbonArmorMat);
        rLegMesh.castShadow = true;
        this.rightLeg.add(rLegMesh);

        const rKnee = new THREE.Mesh(kneeGeo, goldPlatingMat);
        rKnee.position.set(0, 0.05, 0.12);
        this.rightLeg.add(rKnee);
        group.add(this.rightLeg);

        // 7. Dynamic Player Point Light (Headlight illuminating ahead)
        this.headlight = new THREE.PointLight(0x00f0ff, 1.6, 24);
        this.headlight.position.set(0, 1.2, 1.5);
        group.add(this.headlight);

        // 8. Projected Ground Hover Energy Ring
        const ringGeo = new THREE.RingGeometry(0.45, 0.55, 24);
        ringGeo.rotateX(-Math.PI / 2);
        const ringMat = new THREE.MeshBasicMaterial({
            color: 0x00f0ff,
            transparent: true,
            opacity: 0.45,
            side: THREE.DoubleSide,
        });
        this.hoverRing = new THREE.Mesh(ringGeo, ringMat);
        this.hoverRing.position.set(0, -0.96, 0);
        group.add(this.hoverRing);

        return group;
    }

    /**
     * Resets player state to initial coordinates and parameters.
     */
    reset() {
        this.currentLane = 0;
        this.targetX = 0;
        this.speed = this.baseSpeed;
        this.velocityY = 0;
        this.isJumping = false;
        this.isRolling = false;
        this.rollTimer = 0;
        this.animPhase = 0;

        this.mesh.position.set(0, this.GROUND_Y, 0);
        this.mesh.rotation.set(0, 0, 0);
        this.mesh.scale.set(1, 1, 1);

        this.updateBounds();
    }

    /**
     * Attempts to shift left (-1) or right (+1).
     * @param {-1 | 1} dir
     */
    changeLane(dir) {
        const nextLane = this.currentLane + dir;
        if (nextLane >= -1 && nextLane <= 1) {
            this.currentLane = nextLane;
            this.targetX = -this.currentLane * this.LANE_WIDTH;
        }
    }

    /**
     * Triggers vertical jump.
     */
    jump() {
        if (!this.isJumping) {
            this.isJumping = true;
            this.velocityY = this.JUMP_VELOCITY;
            if (this.isRolling) {
                this.endRoll();
            }
        }
    }

    /**
     * Triggers duck / roll maneuver.
     * If currently airborne, forces a rapid downward dive.
     */
    roll() {
        if (this.isJumping) {
            this.velocityY = this.FAST_FALL_VELOCITY;
        }

        this.isRolling = true;
        this.rollTimer = this.ROLL_DURATION;

        // Compress mesh height for crouch / roll profile
        this.mesh.scale.set(1.1, 0.5, 1.1);
    }

    /**
     * Ends roll state and restores normal standing geometry.
     */
    endRoll() {
        this.isRolling = false;
        this.rollTimer = 0;
        this.mesh.scale.set(1, 1, 1);
    }

    /**
     * Fixed-timestep physics update.
     * @param {number} dt Delta time in seconds
     */
    update(dt) {
        // 1. Advance forward distance
        this.mesh.position.z += this.speed * dt;

        // Gradual speed scaling over time
        if (this.speed < this.maxSpeed) {
            this.speed += 0.08 * dt;
        }

        // 2. Smooth lane interpolation (Lerp with banking tilt)
        const dx = this.targetX - this.mesh.position.x;
        const laneLerpSpeed = 16.0;
        this.mesh.position.x += dx * Math.min(laneLerpSpeed * dt, 1.0);

        // Subtle banking tilt when changing lanes
        const maxTilt = 0.28;
        const targetTilt = -dx * 0.25;
        this.mesh.rotation.z += (THREE.MathUtils.clamp(targetTilt, -maxTilt, maxTilt) - this.mesh.rotation.z) * 10 * dt;

        // 3. Vertical jump / gravity physics
        if (this.isJumping) {
            this.velocityY -= this.GRAVITY * dt;
            this.mesh.position.y += this.velocityY * dt;

            // Leg tuck animation during jump
            this.leftLeg.position.y = 0.55;
            this.rightLeg.position.y = 0.55;

            if (this.mesh.position.y <= this.GROUND_Y) {
                this.mesh.position.y = this.GROUND_Y;
                this.velocityY = 0;
                this.isJumping = false;
                this.leftLeg.position.y = 0.35;
                this.rightLeg.position.y = 0.35;
            }
        }

        // 4. Roll timer countdown
        if (this.isRolling) {
            this.rollTimer -= dt;
            this.mesh.rotation.x += 16 * dt; // Rapid forward tumble

            if (this.rollTimer <= 0) {
                this.endRoll();
                this.mesh.rotation.x = 0;
            }
        } else if (!this.isJumping) {
            // Running limb cycle
            this.animPhase += this.speed * dt * 1.8;
            const swing = Math.sin(this.animPhase) * 0.45;
            this.leftLeg.position.z = swing * 0.4;
            this.rightLeg.position.z = -swing * 0.4;
            this.leftArm.position.z = -swing * 0.4;
            this.rightArm.position.z = swing * 0.4;
            this.mesh.rotation.x = 0;
        }

        // 5. Dynamic Jetpack Thruster Pulse & Hover Ring
        const flamePulse = 0.85 + Math.sin(performance.now() * 0.025) * 0.25;
        if (this.leftFlame && this.rightFlame) {
            const speedScale = Math.min(1.8, this.speed / this.baseSpeed);
            this.leftFlame.scale.set(1, 1, flamePulse * speedScale);
            this.rightFlame.scale.set(1, 1, flamePulse * speedScale);
        }

        if (this.hoverRing) {
            this.hoverRing.rotation.z += 2.4 * dt;
        }

        if (this.reactorCore) {
            this.reactorCore.material.emissiveIntensity = 1.8 + Math.sin(performance.now() * 0.008) * 0.6;
        }

        // 6. Update bounding box in-place (ZERO object allocations)
        this.updateBounds();
    }

    /**
     * Updates Axis-Aligned Bounding Box (Box3) without instantiating any objects.
     */
    updateBounds() {
        const px = this.mesh.position.x;
        const py = this.mesh.position.y;
        const pz = this.mesh.position.z;

        const halfWidth = 0.36;
        const halfDepth = 0.36;
        const height = this.isRolling ? 0.75 : 1.7;

        this.boxMin.set(px - halfWidth, py - 0.05, pz - halfDepth);
        this.boxMax.set(px + halfWidth, py + height, pz + halfDepth);

        this.boundingBox.set(this.boxMin, this.boxMax);
    }
}
