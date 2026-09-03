import * as THREE from 'three';

/**
 * TextureGenerator.js - Generates procedural Ultra HD textures on offscreen canvases.
 * Zero external asset downloads; instantaneous load time; crisp PBR detail.
 */
export class TextureGenerator {
    /**
     * High-tech carbon fiber road texture with glowing embedded data circuitry.
     * @returns {THREE.CanvasTexture|null}
     */
    static createCyberRoadTexture() {
        if (typeof document === 'undefined') return null;
        const size = 1024;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');

        // 1. Dark obsidian asphalt base
        ctx.fillStyle = '#0a0d14';
        ctx.fillRect(0, 0, size, size);

        // 2. Micro carbon weave pattern
        ctx.fillStyle = '#0e131f';
        const tileSize = 8;
        for (let y = 0; y < size; y += tileSize * 2) {
            for (let x = 0; x < size; x += tileSize * 2) {
                ctx.fillRect(x, y, tileSize, tileSize);
                ctx.fillRect(x + tileSize, y + tileSize, tileSize, tileSize);
            }
        }

        // 3. Subtle technological hex grid
        ctx.strokeStyle = 'rgba(24, 38, 64, 0.45)';
        ctx.lineWidth = 1.5;
        const hexRadius = 32;
        const hexHeight = Math.sqrt(3) * hexRadius;
        for (let y = 0; y < size + hexHeight; y += hexHeight) {
            for (let x = 0; x < size + hexRadius * 3; x += hexRadius * 3) {
                this._drawHex(ctx, x, y, hexRadius);
                this._drawHex(ctx, x + hexRadius * 1.5, y + hexHeight / 2, hexRadius);
            }
        }

        // 4. Glowing embedded cybernetic circuit lines
        ctx.strokeStyle = '#00f0ff';
        ctx.shadowColor = '#00f0ff';
        ctx.shadowBlur = 12;
        ctx.lineWidth = 2.5;

        // Long longitudinal energy traces
        const traceX = [size * 0.25, size * 0.5, size * 0.75];
        traceX.forEach((tx) => {
            ctx.beginPath();
            ctx.moveTo(tx, 0);
            ctx.lineTo(tx, size);
            ctx.stroke();

            // Circuit nodes
            for (let ny = 64; ny < size; ny += 192) {
                ctx.fillStyle = '#d4af37';
                ctx.beginPath();
                ctx.arc(tx, ny, 4, 0, Math.PI * 2);
                ctx.fill();
            }
        });

        const texture = new THREE.CanvasTexture(canvas);
        texture.wrapS = THREE.RepeatWrapping;
        texture.wrapT = THREE.RepeatWrapping;
        texture.repeat.set(1, 4);
        return texture;
    }

    /**
     * @private
     */
    static _drawHex(ctx, x, y, r) {
        ctx.beginPath();
        for (let i = 0; i < 6; i++) {
            const angle = (Math.PI / 3) * i;
            const hx = x + r * Math.cos(angle);
            const hy = y + r * Math.sin(angle);
            if (i === 0) ctx.moveTo(hx, hy);
            else ctx.lineTo(hx, hy);
        }
        ctx.closePath();
        ctx.stroke();
    }

    /**
     * High-visibility electric hazard caution stripes.
     * @returns {THREE.CanvasTexture|null}
     */
    static createHazardTexture() {
        if (typeof document === 'undefined') return null;
        const canvas = document.createElement('canvas');
        canvas.width = 256;
        canvas.height = 64;
        const ctx = canvas.getContext('2d');

        // Black background
        ctx.fillStyle = '#111317';
        ctx.fillRect(0, 0, 256, 64);

        // Electric Amber/Orange stripes
        ctx.fillStyle = '#ffaa00';
        const stripeWidth = 24;
        for (let x = -64; x < 320; x += stripeWidth * 2) {
            ctx.beginPath();
            ctx.moveTo(x, 0);
            ctx.lineTo(x + stripeWidth, 0);
            ctx.lineTo(x + stripeWidth - 40, 64);
            ctx.lineTo(x - 40, 64);
            ctx.closePath();
            ctx.fill();
        }

        const texture = new THREE.CanvasTexture(canvas);
        texture.wrapS = THREE.RepeatWrapping;
        texture.wrapT = THREE.RepeatWrapping;
        texture.repeat.set(2, 1);
        return texture;
    }

    /**
     * Soft radial glow sprite for particle sparks, dust motes, and thrusters.
     * @returns {THREE.CanvasTexture|null}
     */
    static createParticleTexture() {
        if (typeof document === 'undefined') return null;
        const canvas = document.createElement('canvas');
        canvas.width = 64;
        canvas.height = 64;
        const ctx = canvas.getContext('2d');

        const gradient = ctx.createRadialGradient(32, 32, 0, 32, 32, 32);
        gradient.addColorStop(0, 'rgba(255, 255, 255, 1)');
        gradient.addColorStop(0.25, 'rgba(0, 240, 255, 0.8)');
        gradient.addColorStop(0.6, 'rgba(0, 150, 255, 0.25)');
        gradient.addColorStop(1, 'rgba(0, 0, 0, 0)');

        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, 64, 64);

        return new THREE.CanvasTexture(canvas);
    }

    /**
     * Procedural skyscraper facade texture with thousands of illuminated cyber windows and structural beams.
     * @returns {THREE.CanvasTexture|null}
     */
    static createSkyscraperWindowTexture() {
        if (typeof document === 'undefined') return null;
        const width = 512;
        const height = 1024;
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');

        // Dark obsidian metallic glass base
        ctx.fillStyle = '#05070f';
        ctx.fillRect(0, 0, width, height);

        // High-density micro-windows (32 cols x 128 floors)
        const cols = 32;
        const colWidth = width / cols;
        const rows = 128;
        const rowHeight = height / rows;

        // Structural mullions
        ctx.fillStyle = '#080c18';
        for (let c = 0; c < cols; c += 4) {
            ctx.fillRect(c * colWidth, 0, 2, height);
        }

        // Horizontal floor spandrels
        for (let r = 0; r < rows; r += 8) {
            ctx.fillStyle = '#0a0e1c';
            ctx.fillRect(0, r * rowHeight, width, 2);
        }

        // Windows with dynamic elegant illumination
        const colors = [
            '#00f0ff', // Cyber cyan
            '#ffd24d', // Warm luxury gold
            '#ffffff', // Cool white
            '#a855f7', // Neon violet
        ];

        for (let r = 0; r < rows; r++) {
            for (let c = 0; c < cols; c++) {
                // Determine window state (~16% lit)
                const rand = (Math.sin(r * 34.567 + c * 89.123) * 43758.5453) % 1;
                const positiveRand = Math.abs(rand);

                if (positiveRand < 0.16) {
                    const colorIdx = Math.floor(positiveRand * 10) % colors.length;
                    ctx.fillStyle = colors[colorIdx];
                    ctx.fillRect(
                        c * colWidth + 2,
                        r * rowHeight + 2,
                        colWidth - 4,
                        rowHeight - 3
                    );
                }
            }
        }

        // Vertical illuminated neon accent lightbars
        const lightbarCols = [4, 16, 28];
        lightbarCols.forEach((col, idx) => {
            ctx.fillStyle = (idx % 2 === 0) ? '#00f0ff' : '#d4af37';
            ctx.fillRect(col * colWidth + 2, 0, 2, height);
        });

        const texture = new THREE.CanvasTexture(canvas);
        texture.wrapS = THREE.RepeatWrapping;
        texture.wrapT = THREE.RepeatWrapping;
        texture.repeat.set(2, 4);
        return texture;
    }

    /**
     * Giant glowing holographic cyberpunk brand billboard / kanji marquee texture.
     * @param {string} text
     * @param {string} color
     * @param {string} accent
     * @returns {THREE.CanvasTexture|null}
     */
    static createHologramSignTexture(text = 'CYBER-RAIL', color = '#00f0ff', accent = '#d4af37') {
        if (typeof document === 'undefined') return null;
        const canvas = document.createElement('canvas');
        canvas.width = 512;
        canvas.height = 160;
        const ctx = canvas.getContext('2d');

        // Dark translucent glass backing
        ctx.fillStyle = 'rgba(6, 8, 16, 0.9)';
        ctx.fillRect(0, 0, 512, 160);

        // Neon border with corner notches
        ctx.strokeStyle = color;
        ctx.lineWidth = 4;
        ctx.shadowColor = color;
        ctx.shadowBlur = 16;
        ctx.strokeRect(10, 10, 492, 140);

        // Corner accents
        ctx.fillStyle = accent;
        ctx.fillRect(6, 6, 18, 18);
        ctx.fillRect(488, 6, 18, 18);
        ctx.fillRect(6, 136, 18, 18);
        ctx.fillRect(488, 136, 18, 18);

        // Horizontal scanlines
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.08)';
        ctx.lineWidth = 1;
        for (let y = 14; y < 146; y += 4) {
            ctx.beginPath();
            ctx.moveTo(14, y);
            ctx.lineTo(498, y);
            ctx.stroke();
        }

        // Bold futuristic typography
        ctx.font = '900 46px monospace';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#ffffff';
        ctx.shadowColor = color;
        ctx.shadowBlur = 24;
        ctx.fillText(text, 256, 75);

        // Subtitle
        ctx.font = '700 13px monospace';
        ctx.fillStyle = accent;
        ctx.letterSpacing = '6px';
        ctx.fillText('ESCROW ARENA TOURNAMENT', 256, 120);

        const texture = new THREE.CanvasTexture(canvas);
        return texture;
    }

    /**
     * Massive Digital Cyber-Moon texture with craters, glowing latitude/longitude wireframe, and corona.
     * @returns {THREE.CanvasTexture|null}
     */
    static createDigitalMoonTexture() {
        if (typeof document === 'undefined') return null;
        const size = 512;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');

        // Deep space void
        ctx.fillStyle = '#060814';
        ctx.fillRect(0, 0, size, size);

        // Lunar spherical base gradient
        const grad = ctx.createRadialGradient(size * 0.35, size * 0.35, size * 0.05, size * 0.5, size * 0.5, size * 0.48);
        grad.addColorStop(0, '#384260');
        grad.addColorStop(0.4, '#1c2236');
        grad.addColorStop(0.8, '#0b0f1d');
        grad.addColorStop(1, '#05070e');
        ctx.fillStyle = grad;
        ctx.beginPath();
        ctx.arc(size * 0.5, size * 0.5, size * 0.48, 0, Math.PI * 2);
        ctx.fill();

        // High-tech digital latitude and longitude wireframe rings
        ctx.strokeStyle = 'rgba(0, 240, 255, 0.45)';
        ctx.lineWidth = 1.5;
        for (let r = 32; r < size * 0.48; r += 32) {
            ctx.beginPath();
            ctx.arc(size * 0.5, size * 0.5, r, 0, Math.PI * 2);
            ctx.stroke();
        }

        // Radials
        for (let a = 0; a < Math.PI * 2; a += Math.PI / 8) {
            ctx.beginPath();
            ctx.moveTo(size * 0.5, size * 0.5);
            ctx.lineTo(
                size * 0.5 + Math.cos(a) * size * 0.48,
                size * 0.5 + Math.sin(a) * size * 0.48
            );
            ctx.stroke();
        }

        // Glowing golden / cyan crescent rim
        ctx.strokeStyle = '#00f0ff';
        ctx.shadowColor = '#00f0ff';
        ctx.shadowBlur = 20;
        ctx.lineWidth = 6;
        ctx.beginPath();
        ctx.arc(size * 0.5, size * 0.5, size * 0.47, -Math.PI * 0.5, Math.PI * 0.5);
        ctx.stroke();

        return new THREE.CanvasTexture(canvas);
    }

    /**
     * Glowing subterranean cyberpunk city underbelly grid.
     * @returns {THREE.CanvasTexture|null}
     */
    static createAbyssGridTexture() {
        if (typeof document === 'undefined') return null;
        const size = 512;
        const canvas = document.createElement('canvas');
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');

        // Dark abyss floor
        ctx.fillStyle = '#030408';
        ctx.fillRect(0, 0, size, size);

        // Glowing molten amber and cyan data streams
        ctx.strokeStyle = 'rgba(255, 120, 0, 0.35)';
        ctx.lineWidth = 2;
        for (let x = 0; x < size; x += 32) {
            ctx.beginPath();
            ctx.moveTo(x, 0);
            ctx.lineTo(x, size);
            ctx.stroke();
        }

        ctx.strokeStyle = 'rgba(0, 240, 255, 0.4)';
        ctx.lineWidth = 2;
        for (let y = 0; y < size; y += 32) {
            ctx.beginPath();
            ctx.moveTo(0, y);
            ctx.lineTo(size, y);
            ctx.stroke();
        }

        const texture = new THREE.CanvasTexture(canvas);
        texture.wrapS = THREE.RepeatWrapping;
        texture.wrapT = THREE.RepeatWrapping;
        texture.repeat.set(8, 8);
        return texture;
    }
}