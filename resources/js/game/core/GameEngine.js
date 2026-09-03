import * as THREE from 'three';
import { EffectComposer } from 'three/addons/postprocessing/EffectComposer.js';
import { RenderPass } from 'three/addons/postprocessing/RenderPass.js';
import { UnrealBloomPass } from 'three/addons/postprocessing/UnrealBloomPass.js';
import { OutputPass } from 'three/addons/postprocessing/OutputPass.js';

/**
 * GameEngine.js - High-performance WebGL 2.0 / Three.js engine
 * Implements an accumulator-based fixed-timestep simulation loop (60 Hz)
 * to ensure absolute determinism across high-refresh (144Hz) and low-end devices.
 */
export class GameEngine {
    /**
     * @param {HTMLCanvasElement} canvas
     * @param {Object} options
     * @param {number} [options.fixedTimeStep=1/60] Fixed physics step in seconds
     * @param {Function} [options.onFixedUpdate] (dt, tick) => void
     * @param {Function} [options.onRender] (alpha) => void
     */
    constructor(canvas, options = {}) {
        this.canvas = canvas;
        this.fixedTimeStep = options.fixedTimeStep || (1 / 60);
        this.onFixedUpdate = options.onFixedUpdate || (() => {});
        this.onRender = options.onRender || (() => {});

        // Scene graph with exponential atmospheric depth fog
        this.scene = new THREE.Scene();
        this.scene.background = new THREE.Color(0x04060f);
        this.scene.fog = new THREE.FogExp2(0x04060f, 0.0055);

        // Perspective Camera
        const width = canvas.clientWidth || window.innerWidth || 800;
        const height = canvas.clientHeight || window.innerHeight || 600;
        this.camera = new THREE.PerspectiveCamera(65, width / height, 0.1, 420);
        this.camera.position.set(0, 3.6, -6.2);
        this.camera.lookAt(0, 1.5, 16);

        // WebGL Renderer with High-Performance Settings
        this.renderer = new THREE.WebGLRenderer({
            canvas: this.canvas,
            antialias: true,
            powerPreference: 'high-performance',
            stencil: false,
            depth: true,
        });

        this.renderer.setSize(width, height, false);
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        this.renderer.toneMapping = THREE.ACESFilmicToneMapping;
        this.renderer.toneMappingExposure = 0.95;
        this.renderer.shadowMap.enabled = true;
        this.renderer.shadowMap.type = THREE.PCFSoftShadowMap;

        // Post-processing: Cinematic Unreal Bloom
        this._setupPostProcessing(width, height);

        // Lighting
        this._setupLighting();

        // Loop state
        this.isRunning = false;
        this.animationFrameId = null;
        this.lastTime = 0;
        this.accumulator = 0;
        this.tickCount = 0;

        // Resize
        this._onWindowResize = this._onWindowResize.bind(this);
        this._loop = this._loop.bind(this);
        window.addEventListener('resize', this._onWindowResize);

        if (typeof requestAnimationFrame !== 'undefined') {
            requestAnimationFrame(() => this._onWindowResize());
        }
    }

    _setupPostProcessing(width, height) {
        try {
            const renderPass = new RenderPass(this.scene, this.camera);
            const bloomPass = new UnrealBloomPass(
                new THREE.Vector2(width, height),
                0.38, // strength: elegant neon sheen without screen blowout
                0.3,  // radius
                0.70  // threshold: only high-intensity neon and lights bloom
            );
            const outputPass = new OutputPass();

            this.composer = new EffectComposer(this.renderer);
            this.composer.addPass(renderPass);
            this.composer.addPass(bloomPass);
            this.composer.addPass(outputPass);
        } catch (err) {
            console.warn('Post-processing fallback to standard WebGL renderer:', err);
            this.composer = null;
        }
    }

    _setupLighting() {
        // Soft ambient illumination with deep dark luxury cyberpunk tone
        const ambientLight = new THREE.AmbientLight(0x3a4868, 1.2);
        this.scene.add(ambientLight);

        // Directional key light with real-time shadow casting
        this.sunLight = new THREE.DirectionalLight(0xffeedd, 2.2);
        this.sunLight.position.set(25, 45, -15);
        this.sunLight.castShadow = true;
        this.sunLight.shadow.mapSize.width = 2048;
        this.sunLight.shadow.mapSize.height = 2048;
        this.sunLight.shadow.camera.near = 0.5;
        this.sunLight.shadow.camera.far = 140;
        this.sunLight.shadow.camera.left = -25;
        this.sunLight.shadow.camera.right = 25;
        this.sunLight.shadow.camera.top = 25;
        this.sunLight.shadow.camera.bottom = -25;
        this.sunLight.shadow.bias = -0.0004;
        this.scene.add(this.sunLight);

        // Cyan neon rim light
        const neonRimLight = new THREE.DirectionalLight(0x00f0ff, 1.1);
        neonRimLight.position.set(-30, 20, 40);
        this.scene.add(neonRimLight);

        // Champagne Gold bottom fill bounce
        const goldBounceLight = new THREE.DirectionalLight(0xd4af37, 0.6);
        goldBounceLight.position.set(0, -10, 10);
        this.scene.add(goldBounceLight);
    }

    _onWindowResize() {
        const width = this.canvas.parentElement ? this.canvas.parentElement.clientWidth : window.innerWidth;
        const height = this.canvas.parentElement ? this.canvas.parentElement.clientHeight : window.innerHeight;

        this.camera.aspect = width / height;
        this.camera.updateProjectionMatrix();

        this.renderer.setSize(width, height, false);
        if (this.composer) {
            this.composer.setSize(width, height);
        }
    }

    /**
     * Starts the simulation and render loops.
     */
    start() {
        if (this.isRunning) return;
        this.isRunning = true;
        this.lastTime = performance.now();
        this.accumulator = 0;
        this.animationFrameId = requestAnimationFrame(this._loop);
    }

    /**
     * Pauses the loop.
     */
    stop() {
        this.isRunning = false;
        if (this.animationFrameId !== null) {
            cancelAnimationFrame(this.animationFrameId);
            this.animationFrameId = null;
        }
    }

    /**
     * Main animation loop decoupling fixed updates from rendering.
     * @param {number} currentTime
     */
    _loop(currentTime) {
        if (!this.isRunning) return;

        const frameDelta = (currentTime - this.lastTime) / 1000;
        this.lastTime = currentTime;

        // Clamp delta time to prevent spiral of death during background tab hibernation
        const clampedDelta = Math.min(frameDelta, 0.1);
        this.accumulator += clampedDelta;

        // Execute deterministic fixed physics ticks
        while (this.accumulator >= this.fixedTimeStep) {
            this.onFixedUpdate(this.fixedTimeStep, this.tickCount);
            this.tickCount++;
            this.accumulator -= this.fixedTimeStep;
        }

        // Interpolation factor between ticks for smooth visual rendering
        const alpha = this.accumulator / this.fixedTimeStep;
        this.onRender(alpha);

        // Render scene through cinematic Unreal Bloom composer
        if (this.composer) {
            this.composer.render();
        } else {
            this.renderer.render(this.scene, this.camera);
        }

        this.animationFrameId = requestAnimationFrame(this._loop);
    }

    /**
     * Set pixel ratio for adaptive resolution scaling.
     * @param {number} ratio
     */
    setPixelRatio(ratio) {
        this.renderer.setPixelRatio(ratio);
        if (this.composer) {
            this.composer.setPixelRatio(ratio);
        }
    }

    /**
     * Completely frees GPU and memory resources.
     */
    destroy() {
        this.stop();
        window.removeEventListener('resize', this._onWindowResize);

        if (this.composer) {
            this.composer.dispose();
            this.composer = null;
        }

        // Traverse scene and dispose geometries and materials
        this.scene.traverse((obj) => {
            if (obj.geometry) {
                obj.geometry.dispose();
            }
            if (obj.material) {
                if (Array.isArray(obj.material)) {
                    obj.material.forEach((mat) => mat.dispose());
                } else {
                    obj.material.dispose();
                }
            }
        });

        this.renderer.dispose();
    }
}
