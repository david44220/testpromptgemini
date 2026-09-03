import * as THREE from 'three';
import { TextureGenerator } from '../core/TextureGenerator.js';

/**
 * SkylineBackground.js - Procedural Ultra HD Cyberpunk Megacity Horizon & Atmosphere.
 * Features:
 * - Giant Holographic Digital Moon with animated orbiting wireframe rings
 * - 800-Star Deep Space Constellation Sky Dome
 * - 64 Procedural Skyscraper Monoliths with illuminated window grids & rooftop antennas
 * - Giant 3D Holographic Brand Billboards ('CYBER-RAIL', 'ESCROW', 'APEX') atop mega-towers
 * - Flying Aerial Sky Traffic (hover-cruisers with cyan headlights & ruby tail thrusters)
 * - Subterranean Megacity Abyss underbelly with support mega-pylons & molten data grid
 * - 6 Volumetric Sweeping Sky Searchlights
 * - 350 Drifting Cyberpunk Embers with additive particle blending
 * - Infinite Seamless Parallax Recycling (zero memory allocations in render loop)
 */
export class SkylineBackground {
    /**
     * @param {THREE.Scene} scene
     * @param {THREE.Camera} camera
     */
    constructor(scene, camera) {
        this.scene = scene;
        this.camera = camera;
        this.group = new THREE.Group();
        this.scene.add(this.group);

        this.towers = [];
        this.spires = [];
        this.holograms = [];
        this.searchlights = [];
        this.skyVehicles = [];
        this.pylons = [];

        // Build all visual layers
        this._buildCelestialSky();
        this._buildUnderbellyAbyss();
        this._buildSkyline();
        this._buildSkyTraffic();
        this._buildParticleAtmosphere();
    }

    /**
     * 1. Celestial Sky: Starfield, Glowing Digital Moon & Planetary Orbit Rings.
     * @private
     */
    _buildCelestialSky() {
        this.celestialGroup = new THREE.Group();
        this.group.add(this.celestialGroup);

        // 800 Stars Starfield
        const starCount = 800;
        const starGeo = new THREE.BufferGeometry();
        const starPositions = new Float32Array(starCount * 3);
        const starColors = new Float32Array(starCount * 3);

        for (let i = 0; i < starCount; i++) {
            const theta = Math.random() * Math.PI * 2;
            const phi = Math.acos((Math.random() * 0.9) + 0.1); // Upper hemisphere
            const radius = 260 + Math.random() * 20;

            starPositions[i * 3] = radius * Math.sin(phi) * Math.cos(theta);
            starPositions[i * 3 + 1] = Math.max(25, radius * Math.cos(phi));
            starPositions[i * 3 + 2] = radius * Math.sin(phi) * Math.sin(theta);

            // Varied star brightness: icy cyan, gold, cool white
            const starType = Math.random();
            if (starType > 0.8) {
                starColors[i * 3] = 0.0;
                starColors[i * 3 + 1] = 0.94;
                starColors[i * 3 + 2] = 1.0;
            } else if (starType > 0.65) {
                starColors[i * 3] = 0.83;
                starColors[i * 3 + 1] = 0.68;
                starColors[i * 3 + 2] = 0.21;
            } else {
                starColors[i * 3] = 0.9;
                starColors[i * 3 + 1] = 0.95;
                starColors[i * 3 + 2] = 1.0;
            }
        }

        starGeo.setAttribute('position', new THREE.BufferAttribute(starPositions, 3));
        starGeo.setAttribute('color', new THREE.BufferAttribute(starColors, 3));

        const starMat = new THREE.PointsMaterial({
            size: 1.2,
            vertexColors: true,
            transparent: true,
            opacity: 0.92,
            blending: THREE.AdditiveBlending,
            depthWrite: false,
        });

        this.starfield = new THREE.Points(starGeo, starMat);
        this.celestialGroup.add(this.starfield);

        // Giant Digital Cyber-Moon
        const moonGeo = new THREE.SphereGeometry(26, 32, 32);
        const moonTex = TextureGenerator.createDigitalMoonTexture();
        const moonMat = new THREE.MeshBasicMaterial({
            map: moonTex,
            transparent: true,
            opacity: 0.95,
        });

        this.moon = new THREE.Mesh(moonGeo, moonMat);
        this.moon.position.set(45, 65, 190);
        this.celestialGroup.add(this.moon);

        // Moon Orbiting Hologram Rings
        const ringGeo1 = new THREE.RingGeometry(33, 35, 48);
        const ringMat1 = new THREE.MeshBasicMaterial({
            color: 0x00f0ff,
            side: THREE.DoubleSide,
            transparent: true,
            opacity: 0.5,
            blending: THREE.AdditiveBlending,
        });
        this.moonRing1 = new THREE.Mesh(ringGeo1, ringMat1);
        this.moonRing1.rotation.set(0.6, 0.3, 0.4);
        this.moon.add(this.moonRing1);

        const ringGeo2 = new THREE.RingGeometry(40, 41.5, 48);
        const ringMat2 = new THREE.MeshBasicMaterial({
            color: 0xd4af37,
            side: THREE.DoubleSide,
            transparent: true,
            opacity: 0.4,
            blending: THREE.AdditiveBlending,
        });
        this.moonRing2 = new THREE.Mesh(ringGeo2, ringMat2);
        this.moonRing2.rotation.set(-0.5, -0.4, 0.2);
        this.moon.add(this.moonRing2);
    }

    /**
     * 2. Subterranean Megacity Abyss & Structural Track Mega-Pylons.
     * @private
     */
    _buildUnderbellyAbyss() {
        this.abyssGroup = new THREE.Group();
        this.group.add(this.abyssGroup);

        // Molten data grid floor deep below the track
        const abyssGeo = new THREE.PlaneGeometry(350, 450);
        const abyssTex = TextureGenerator.createAbyssGridTexture();
        const abyssMat = new THREE.MeshBasicMaterial({
            map: abyssTex,
            transparent: true,
            opacity: 0.65,
        });
        this.abyssFloor = new THREE.Mesh(abyssGeo, abyssMat);
        this.abyssFloor.rotation.x = -Math.PI / 2;
        this.abyssFloor.position.set(0, -18, 120);
        this.abyssGroup.add(this.abyssFloor);

        // Heavy concrete mega-pylons anchoring the track into the abyss
        const pylonGeo = new THREE.CylinderGeometry(1.2, 2.2, 24, 8);
        const pylonMat = new THREE.MeshStandardMaterial({
            color: 0x0a0e18,
            metalness: 0.8,
            roughness: 0.3,
        });

        const ringGeo = new THREE.CylinderGeometry(1.5, 1.5, 0.6, 8);
        const ringMat = new THREE.MeshBasicMaterial({ color: 0xffaa00 }); // Amber hazard band

        for (let i = 0; i < 8; i++) {
            const pGroup = new THREE.Group();
            const col = new THREE.Mesh(pylonGeo, pylonMat);
            pGroup.add(col);

            const band = new THREE.Mesh(ringGeo, ringMat);
            band.position.y = 4;
            pGroup.add(band);

            pGroup.position.set(0, -12, (i * 45) - 20);
            this.abyssGroup.add(pGroup);
            this.pylons.push(pGroup);
        }
    }

    /**
     * 3. Skyscraper Monoliths with Illuminated Windows, Spire Beacons & Hologram Billboards.
     * @private
     */
    _buildSkyline() {
        const buildingCount = 64;
        const towerGeo = new THREE.BoxGeometry(1, 1, 1);
        const windowTex = TextureGenerator.createSkyscraperWindowTexture();

        // High-tech skyscraper facade materials with glowing window emissive maps
        const towerMatA = new THREE.MeshStandardMaterial({
            map: windowTex,
            emissiveMap: windowTex,
            emissive: 0xffffff,
            emissiveIntensity: 0.35,
            color: 0x080c18,
            roughness: 0.25,
            metalness: 0.85,
        });

        const towerMatB = new THREE.MeshStandardMaterial({
            map: windowTex,
            emissiveMap: windowTex,
            emissive: 0x00f0ff,
            emissiveIntensity: 0.25,
            color: 0x060812,
            roughness: 0.3,
            metalness: 0.9,
        });

        const towerMatC = new THREE.MeshStandardMaterial({
            map: windowTex,
            emissiveMap: windowTex,
            emissive: 0xd4af37,
            emissiveIntensity: 0.3,
            color: 0x090d16,
            roughness: 0.2,
            metalness: 0.95,
        });

        const materials = [towerMatA, towerMatB, towerMatC];

        // Rooftop antenna spires & strobes
        const spireGeo = new THREE.CylinderGeometry(0.12, 0.5, 16, 4);
        const spireMatCyan = new THREE.MeshBasicMaterial({ color: 0x00f0ff });
        const spireMatGold = new THREE.MeshBasicMaterial({ color: 0xd4af37 });
        const beaconGeo = new THREE.SphereGeometry(0.6, 8, 8);
        const beaconMatRed = new THREE.MeshBasicMaterial({ color: 0xff0055 });

        // Holographic brand signs
        const holoTitles = ['CYBER-RAIL', 'Ω ESCROW', 'APEX TITAN', 'HYPERION'];

        for (let i = 0; i < buildingCount; i++) {
            const side = (i % 2 === 0) ? -1 : 1;
            const tier = i % 3; // 0 = near flank, 1 = mid, 2 = distant monolith

            let distanceX, width, depth, height;

            if (tier === 0) {
                // Near flank (spacious clearance from track, majestic height)
                distanceX = side * (32 + Math.abs(Math.sin(i * 2.3) * 8));
                width = 12 + (Math.sin(i * 3.1) * 4);
                depth = 16 + (Math.cos(i * 1.7) * 5);
                height = 45 + (Math.sin(i * 4.2) * 22);
            } else if (tier === 1) {
                // Mid flank (tall mega-towers)
                distanceX = side * (52 + Math.abs(Math.cos(i * 1.9) * 15));
                width = 18 + (Math.sin(i * 2.7) * 6);
                depth = 24 + (Math.cos(i * 3.4) * 8);
                height = 85 + (Math.sin(i * 5.1) * 35);
            } else {
                // Distant horizon monoliths (monumental scale)
                distanceX = side * (85 + Math.abs(Math.sin(i * 1.2) * 30));
                width = 32 + (Math.sin(i * 4.4) * 10);
                depth = 38 + (Math.cos(i * 2.1) * 12);
                height = 140 + (Math.sin(i * 1.5) * 55);
            }

            const mat = materials[i % materials.length];
            const mesh = new THREE.Mesh(towerGeo, mat);
            mesh.scale.set(width, height, depth);

            const z = ((i % 16) * 20) - 40;
            mesh.position.set(distanceX, height / 2 - 5, z);
            this.group.add(mesh);

            this.towers.push({
                mesh,
                baseHeight: height,
                side,
                tier,
                initialZ: z,
            });

            // Rooftop spire on selected towers
            if (i % 2 === 0) {
                const spireGroup = new THREE.Group();
                const spire = new THREE.Mesh(spireGeo, (i % 4 === 0) ? spireMatGold : spireMatCyan);
                spireGroup.add(spire);

                // Flashing red warning beacon atop spire
                const beacon = new THREE.Mesh(beaconGeo, beaconMatRed);
                beacon.position.y = 8;
                spireGroup.add(beacon);

                spireGroup.position.set(distanceX, height + 3, z);
                this.group.add(spireGroup);
                this.spires.push(spireGroup);
            }

            // Holographic Billboard atop select mega-towers
            if (i % 7 === 0 && tier === 1) {
                const holoTex = TextureGenerator.createHologramSignTexture(
                    holoTitles[Math.floor(i / 7) % holoTitles.length],
                    (i % 2 === 0) ? '#00f0ff' : '#ff0055',
                    '#d4af37'
                );
                const holoGeo = new THREE.PlaneGeometry(16, 5);
                const holoMat = new THREE.MeshBasicMaterial({
                    map: holoTex,
                    side: THREE.DoubleSide,
                    transparent: true,
                    opacity: 0.92,
                    blending: THREE.AdditiveBlending,
                });
                const holoMesh = new THREE.Mesh(holoGeo, holoMat);
                holoMesh.position.set(distanceX, height + 4, z);
                holoMesh.rotation.y = (side === -1) ? 0.35 : -0.35;
                this.group.add(holoMesh);
                this.holograms.push(holoMesh);
            }
        }

        // 6 Volumetric Searchlights
        const beamGeo = new THREE.CylinderGeometry(0.4, 7.5, 130, 8);
        beamGeo.translate(0, 65, 0);
        const beamColors = [0x00f0ff, 0xd4af37, 0x8b5cf6, 0x00f0ff, 0xff0055, 0x00f0ff];

        for (let s = 0; s < 6; s++) {
            const beamMat = new THREE.MeshBasicMaterial({
                color: beamColors[s],
                transparent: true,
                opacity: 0.16,
                blending: THREE.AdditiveBlending,
                depthWrite: false,
            });
            const beam = new THREE.Mesh(beamGeo, beamMat);
            const side = (s % 2 === 0) ? -38 : 38;
            beam.position.set(side, -2, (s * 40) - 10);
            this.group.add(beam);
            this.searchlights.push({
                mesh: beam,
                speed: 0.3 + (s * 0.08),
                phase: s * 1.3,
            });
        }
    }

    /**
     * 4. High-Altitude Skyways & Flying Cyber Traffic (Hover Cars / Transports).
     * @private
     */
    _buildSkyTraffic() {
        this.trafficGroup = new THREE.Group();
        this.group.add(this.trafficGroup);

        const vehicleCount = 32;
        const cruiserGeo = new THREE.BoxGeometry(1.6, 0.4, 3.2);

        // Headlight (Cyan/White) & Tail Thruster (Ruby Red) materials
        const headlightMat = new THREE.MeshBasicMaterial({ color: 0x00f0ff });
        const taillightMat = new THREE.MeshBasicMaterial({ color: 0xff0055 });
        const chassisMat = new THREE.MeshStandardMaterial({
            color: 0x0a0e18,
            metalness: 0.9,
            roughness: 0.2,
        });

        const lightGeo = new THREE.BoxGeometry(0.35, 0.15, 0.1);

        for (let i = 0; i < vehicleCount; i++) {
            const carGroup = new THREE.Group();
            const chassis = new THREE.Mesh(cruiserGeo, chassisMat);
            carGroup.add(chassis);

            const lightL = new THREE.Mesh(lightGeo, headlightMat);
            lightL.position.set(-0.55, 0, 1.62);
            carGroup.add(lightL);

            const lightR = new THREE.Mesh(lightGeo, headlightMat);
            lightR.position.set(0.55, 0, 1.62);
            carGroup.add(lightR);

            const tailL = new THREE.Mesh(lightGeo, taillightMat);
            tailL.position.set(-0.55, 0, -1.62);
            carGroup.add(tailL);

            const tailR = new THREE.Mesh(lightGeo, taillightMat);
            tailR.position.set(0.55, 0, -1.62);
            carGroup.add(tailR);

            const side = (i % 2 === 0) ? -1 : 1;
            const laneX = side * (26 + (Math.sin(i * 2.1) * 8));
            const laneY = 22 + ((i % 4) * 4.5);
            const z = (i * 12) - 30;

            carGroup.position.set(laneX, laneY, z);
            this.trafficGroup.add(carGroup);

            this.skyVehicles.push({
                group: carGroup,
                speed: (side === -1) ? (28 + (i % 3) * 6) : -(26 + (i % 3) * 5),
                laneX,
                laneY,
            });
        }
    }

    /**
     * 5. Volumetric Cyber Dust & Glowing Particle Embers.
     * @private
     */
    _buildParticleAtmosphere() {
        const particleCount = 350;
        const geometry = new THREE.BufferGeometry();
        const positions = new Float32Array(particleCount * 3);
        const colors = new Float32Array(particleCount * 3);

        const colorA = new THREE.Color(0x00f0ff); // Neon Cyan
        const colorB = new THREE.Color(0xd4af37); // Champagne Gold
        const colorC = new THREE.Color(0xff0055); // Crimson
        const colorD = new THREE.Color(0xa855f7); // Violet

        for (let i = 0; i < particleCount; i++) {
            positions[i * 3] = (Math.random() - 0.5) * 55;
            positions[i * 3 + 1] = Math.random() * 26 - 4; // Above and below track
            positions[i * 3 + 2] = Math.random() * 200 - 30;

            const c = (i % 4 === 0) ? colorA : (i % 4 === 1 ? colorB : (i % 4 === 2 ? colorC : colorD));
            colors[i * 3] = c.r;
            colors[i * 3 + 1] = c.g;
            colors[i * 3 + 2] = c.b;
        }

        geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));

        const sprite = TextureGenerator.createParticleTexture();
        const material = new THREE.PointsMaterial({
            size: 0.85,
            map: sprite,
            vertexColors: true,
            transparent: true,
            opacity: 0.88,
            blending: THREE.AdditiveBlending,
            depthWrite: false,
        });

        this.particles = new THREE.Points(geometry, material);
        this.group.add(this.particles);
    }

    /**
     * Updates animated elements and seamlessly recycles background depth.
     * @param {number} playerZ Current runner Z position
     * @param {number} dt Delta time
     */
    update(playerZ, dt) {
        const time = performance.now() * 0.001;

        // 1. Celestial group locks exactly with player camera Z for true infinity horizon
        if (this.celestialGroup) {
            this.celestialGroup.position.z = playerZ;
        }

        // Rotate Digital Moon Orbiting Rings
        if (this.moonRing1) this.moonRing1.rotation.z += 0.35 * dt;
        if (this.moonRing2) this.moonRing2.rotation.z -= 0.28 * dt;

        // 2. Sweeping Volumetric Searchlights
        for (let s = 0; s < this.searchlights.length; s++) {
            const sl = this.searchlights[s];
            sl.mesh.rotation.z = Math.sin(time * sl.speed + sl.phase) * 0.45;
            sl.mesh.rotation.x = Math.cos(time * sl.speed * 0.7 + sl.phase) * 0.3;
        }

        // 3. Sky Traffic Cruise Simulation
        for (let v = 0; v < this.skyVehicles.length; v++) {
            const veh = this.skyVehicles[v];
            veh.group.position.z += veh.speed * dt;

            // Loop vehicles within view frustum
            if (veh.group.position.z < playerZ - 40) {
                veh.group.position.z = playerZ + 220 + (Math.random() * 20);
            } else if (veh.group.position.z > playerZ + 240) {
                veh.group.position.z = playerZ - 35 - (Math.random() * 20);
            }
        }

        // 4. Recycle Support Mega-Pylons
        for (let p = 0; p < this.pylons.length; p++) {
            const pylon = this.pylons[p];
            if (pylon.position.z < playerZ - 30) {
                pylon.position.z += 8 * 45; // 360 units ahead
            }
        }

        // 5. Update Abyss Floor position
        if (this.abyssFloor) {
            this.abyssFloor.position.z = playerZ + 100;
        }

        // 6. Recycle Skyscraper Monoliths ahead of runner
        for (let t = 0; t < this.towers.length; t++) {
            const tower = this.towers[t];
            if (tower.mesh.position.z < playerZ - 50) {
                tower.mesh.position.z += 16 * 20; // 320 units cycle ahead
            }
        }

        // Recycle Spires
        for (let sp = 0; sp < this.spires.length; sp++) {
            const spire = this.spires[sp];
            if (spire.position.z < playerZ - 50) {
                spire.position.z += 16 * 20;
            }
        }

        // 7. Pulse Hologram Billboards
        for (let h = 0; h < this.holograms.length; h++) {
            const holo = this.holograms[h];
            holo.position.y += Math.sin(time * 2 + h) * 0.003;
            if (holo.position.z < playerZ - 50) {
                holo.position.z += 16 * 20;
            }
        }

        // 8. Cyber Particles drift & recycle
        if (this.particles) {
            const positions = this.particles.geometry.attributes.position.array;
            for (let i = 0; i < positions.length; i += 3) {
                positions[i + 1] += Math.sin(time + i) * 0.008;
                if (positions[i + 2] < playerZ - 25) {
                    positions[i + 2] = playerZ + 150 + Math.random() * 30;
                }
            }
            this.particles.geometry.attributes.position.needsUpdate = true;
        }
    }
}
