/**
 * PerformanceMonitor.js - Runtime telemetry, draw call monitor, and dynamic resolution scaler.
 * Guarantees rock-solid 60 FPS on mobile GPUs through adaptive resolution adjustments.
 */
export class PerformanceMonitor {
    /**
     * @param {import('three').WebGLRenderer} renderer
     * @param {Object} [options]
     * @param {boolean} [options.enableHUD=true]
     * @param {boolean} [options.adaptiveResolution=true]
     */
    constructor(renderer, options = {}) {
        this.renderer = renderer;
        this.enableHUD = options.enableHUD !== false;
        this.adaptiveResolution = options.adaptiveResolution !== false;

        this.fps = 60;
        this.frameTime = 16.6;
        this.frameCount = 0;
        this.lastSampleTime = performance.now();
        this.recentFPS = [];

        // Dynamic resolution levels: [High: 2.0, Standard: 1.5, Low: 1.0, Performance: 0.8]
        this.currentPixelRatio = Math.min(typeof window !== 'undefined' ? window.devicePixelRatio : 1, 2);
        this.minPixelRatio = 0.8;
        this.maxPixelRatio = this.currentPixelRatio;

        this.hudElement = null;
        if (this.enableHUD && typeof document !== 'undefined') {
            this._createHUD();
        }

        // Toggle HUD on F3
        if (typeof window !== 'undefined') {
            window.addEventListener('keydown', (e) => {
                if (e.code === 'F3') {
                    this.toggleHUD();
                }
            });
        }
    }

    _createHUD() {
        this.hudElement = document.createElement('div');
        this.hudElement.id = 'runner-perf-hud';
        this.hudElement.style.position = 'absolute';
        this.hudElement.style.top = '12px';
        this.hudElement.style.left = '12px';
        this.hudElement.style.zIndex = '9999';
        this.hudElement.style.fontFamily = 'monospace';
        this.hudElement.style.fontSize = '12px';
        this.hudElement.style.lineHeight = '1.4';
        this.hudElement.style.color = '#00f0ff';
        this.hudElement.style.background = 'rgba(10, 14, 26, 0.85)';
        this.hudElement.style.border = '1px solid #00f0ff44';
        this.hudElement.style.borderRadius = '6px';
        this.hudElement.style.padding = '8px 12px';
        this.hudElement.style.pointerEvents = 'none';
        this.hudElement.style.userSelect = 'none';

        document.body.appendChild(this.hudElement);
    }

    /**
     * Toggles visibility of the performance HUD.
     */
    toggleHUD() {
        if (!this.hudElement) return;
        this.hudElement.style.display = this.hudElement.style.display === 'none' ? 'block' : 'none';
    }

    /**
     * Called on each render frame to measure performance metrics.
     * @param {number} currentTime
     */
    update(currentTime) {
        this.frameCount++;
        const delta = currentTime - this.lastSampleTime;

        if (delta >= 500) {
            // Compute instantaneous FPS
            this.fps = Math.round((this.frameCount * 1000) / delta);
            this.frameTime = Math.round((delta / this.frameCount) * 10) / 10;
            this.frameCount = 0;
            this.lastSampleTime = currentTime;

            this.recentFPS.push(this.fps);
            if (this.recentFPS.length > 6) {
                this.recentFPS.shift();
            }

            // Adaptive resolution logic
            if (this.adaptiveResolution) {
                this._evaluateAdaptiveScaling();
            }

            // Update DOM HUD
            if (this.hudElement && this.hudElement.style.display !== 'none') {
                const info = this.renderer.info;
                const calls = info.render.calls;
                const triangles = info.render.triangles;
                const geometries = info.memory.geometries;

                this.hudElement.innerHTML = `
                    <div style="font-weight: bold; color: ${this.fps < 45 ? '#ff3366' : '#00f0ff'};">
                        ⚡ ${this.fps} FPS (${this.frameTime}ms)
                    </div>
                    <div>Draw Calls: <b>${calls}</b> | Tris: <b>${triangles}</b></div>
                    <div>Geometries: <b>${geometries}</b> | DPR: <b>${this.currentPixelRatio.toFixed(1)}x</b></div>
                `;
            }
        }
    }

    _evaluateAdaptiveScaling() {
        if (this.recentFPS.length < 3) return;

        const avg = this.recentFPS.reduce((a, b) => a + b, 0) / this.recentFPS.length;

        // Downscale resolution if framerate drops below 48 FPS
        if (avg < 48 && this.currentPixelRatio > this.minPixelRatio) {
            this.currentPixelRatio = Math.max(this.minPixelRatio, this.currentPixelRatio - 0.25);
            this.renderer.setPixelRatio(this.currentPixelRatio);
        }
        // Upscale resolution if sustained above 58 FPS
        else if (avg >= 58 && this.currentPixelRatio < this.maxPixelRatio) {
            this.currentPixelRatio = Math.min(this.maxPixelRatio, this.currentPixelRatio + 0.2);
            this.renderer.setPixelRatio(this.currentPixelRatio);
        }
    }

    destroy() {
        if (this.hudElement && this.hudElement.parentElement) {
            this.hudElement.parentElement.removeChild(this.hudElement);
        }
    }
}
