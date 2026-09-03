import * as THREE from 'three';

/**
 * BillboardMesh.js - In-Game 3D Trackside Dynamic Billboard.
 * Features:
 * - Lateral placement along track boundaries (x = ±6.0m) atop metallic support pillars.
 * - Dynamic texture loading from /api/v1/ads/active-creatives with procedural fallback canvases.
 * - CRT scanline & neon emissive monitor shader.
 * - Automated impression dispatch via navigator.sendBeacon when crossing the player camera Z threshold.
 */
export class BillboardMesh {
    /**
     * @param {THREE.Scene} scene
     * @param {Object} [options]
     * @param {number} [options.width=4.8]
     * @param {number} [options.height=2.6]
     */
    constructor(scene, options = {}) {
        this.scene = scene;
        this.width = options.width || 4.8;
        this.height = options.height || 2.6;

        this.creative = null;
        this.hasRecordedImpression = false;
        this.isActive = false;

        // Custom CRT Scanline Shader Material
        this.screenMaterial = this._createShaderMaterial();

        // Build container group
        this.group = this._buildMeshHierarchy();
        this.group.visible = false;
        this.scene.add(this.group);
    }

    _createShaderMaterial() {
        return new THREE.ShaderMaterial({
            uniforms: {
                uTexture: { value: this._createFallbackTexture('NEXUS SYSTEMS', 'CYBER TELEMETRY 240Hz', '#00F0FF') },
                uTime: { value: 0 },
                uGlowColor: { value: new THREE.Color(0x00f0ff) },
            },
            vertexShader: `
                varying vec2 vUv;
                void main() {
                    vUv = uv;
                    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
                }
            `,
            fragmentShader: `
                uniform sampler2D uTexture;
                uniform float uTime;
                uniform vec3 uGlowColor;
                varying vec2 vUv;

                void main() {
                    vec4 texColor = texture2D(uTexture, vUv);

                    // Scanline CRT raster effect
                    float scanline = sin(vUv.y * 180.0 + uTime * 4.0) * 0.08;
                    texColor.rgb -= scanline;

                    // Vignette & edge neon glow
                    float edgeDist = distance(vUv, vec2(0.5));
                    float edgeGlow = smoothstep(0.35, 0.55, edgeDist) * 0.45;
                    texColor.rgb += uGlowColor * edgeGlow * 0.5;

                    gl_FragColor = texColor;
                }
            `,
            side: THREE.DoubleSide,
        });
    }

    _buildMeshHierarchy() {
        const group = new THREE.Group();

        // 1. Concrete / Metallic Foundation Pillar
        const pillarGeo = new THREE.CylinderGeometry(0.2, 0.28, 4.0, 12);
        const pillarMat = new THREE.MeshStandardMaterial({
            color: 0x1a1c24,
            metalness: 0.85,
            roughness: 0.35,
        });
        const pillar = new THREE.Mesh(pillarGeo, pillarMat);
        pillar.position.set(0, 2.0, 0);
        group.add(pillar);

        // 2. Bezel Screen Frame
        const bezelGeo = new THREE.BoxGeometry(this.width + 0.3, this.height + 0.3, 0.25);
        const bezelMat = new THREE.MeshStandardMaterial({
            color: 0x0a0a0e,
            metalness: 0.9,
            roughness: 0.2,
        });
        const bezel = new THREE.Mesh(bezelGeo, bezelMat);
        bezel.position.set(0, 4.0, 0);
        group.add(bezel);

        // 3. Illuminated Screen Plane
        const screenGeo = new THREE.PlaneGeometry(this.width, this.height);
        this.screenMesh = new THREE.Mesh(screenGeo, this.screenMaterial);
        this.screenMesh.position.set(0, 4.0, 0.14);
        group.add(this.screenMesh);

        // 4. Ambient Neon Underlight Strip
        const stripGeo = new THREE.BoxGeometry(this.width, 0.08, 0.08);
        this.neonStripMat = new THREE.MeshBasicMaterial({ color: 0x00f0ff });
        const neonStrip = new THREE.Mesh(stripGeo, this.neonStripMat);
        neonStrip.position.set(0, 2.75, 0.14);
        group.add(neonStrip);

        return group;
    }

    _createFallbackTexture(title, subtitle, accentHex) {
        const canvas = document.createElement('canvas');
        canvas.width = 1024;
        canvas.height = 512;
        const ctx = canvas.getContext('2d');

        // Dark Luxury cyber gradient
        const grad = ctx.createLinearGradient(0, 0, 1024, 512);
        grad.addColorStop(0, '#06070a');
        grad.addColorStop(0.5, '#12141a');
        grad.addColorStop(1, '#020305');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, 1024, 512);

        // Border frame
        ctx.strokeStyle = accentHex || '#D4AF37';
        ctx.lineWidth = 14;
        ctx.strokeRect(10, 10, 1004, 492);

        // Header
        ctx.fillStyle = accentHex || '#D4AF37';
        ctx.font = 'bold 44px "Outfit", sans-serif';
        ctx.fillText('OFFICIAL ARENA SPONSOR', 60, 90);

        // Title
        ctx.fillStyle = '#FFFFFF';
        ctx.font = '900 78px "Outfit", sans-serif';
        ctx.fillText(title, 60, 230);

        // Subtitle / Tagline
        ctx.fillStyle = '#94A3B8';
        ctx.font = '600 36px "JetBrains Mono", monospace';
        ctx.fillText(subtitle, 60, 310);

        // Accent geometric badge
        ctx.fillStyle = accentHex || '#D4AF37';
        ctx.fillRect(60, 360, 320, 60);
        ctx.fillStyle = '#000000';
        ctx.font = 'bold 28px "JetBrains Mono", monospace';
        ctx.fillText('VERIFIED PARTNER', 80, 402);

        const tex = new THREE.CanvasTexture(canvas);
        tex.needsUpdate = true;
        return tex;
    }

    /**
     * Activates and positions the billboard at trackside (x = ±6.0m).
     * @param {number} x
     * @param {number} z
     * @param {Object} [creative]
     */
    spawn(x, z, creative = null) {
        this.creative = creative || {
            id: 'sponsor-aegis-escrow',
            title: 'AEGIS ESCROW',
            tagline: 'INSTANT ON-CHAIN SETTLEMENTS',
            accent_color: '#D4AF37',
        };

        this.hasRecordedImpression = false;
        this.isActive = true;

        // Position on lateral edge
        this.group.position.set(x, 0, z);

        // Angle billboard slightly towards track center for optimal visibility
        this.group.rotation.y = x > 0 ? -0.22 : 0.22;

        // Apply creative colors
        if (this.creative.accent_color) {
            const color = new THREE.Color(this.creative.accent_color);
            this.screenMaterial.uniforms.uGlowColor.value = color;
            this.neonStripMat.color = color;
            this.screenMaterial.uniforms.uTexture.value = this._createFallbackTexture(
                this.creative.title || 'SPONSOR',
                this.creative.tagline || 'OFFICIAL PARTNER',
                this.creative.accent_color
            );
        }

        this.group.visible = true;
    }

    /**
     * Called in render loop to update scanline time uniform and track impression.
     * @param {number} dt Delta time
     * @param {number} cameraZ Player camera Z position
     */
    update(dt, cameraZ) {
        if (!this.isActive) return;

        this.screenMaterial.uniforms.uTime.value += dt;

        // Impression Trigger: when player camera crosses the billboard Z coordinate
        if (!this.hasRecordedImpression && cameraZ >= this.group.position.z - 2.0) {
            this.hasRecordedImpression = true;
            this._dispatchImpressionBeacon();
        }
    }

    _dispatchImpressionBeacon() {
        if (!this.creative || !this.creative.id) return;
        const endpoint = `/api/v1/ads/impression/${this.creative.id}`;

        if (typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
            navigator.sendBeacon(endpoint);
        } else if (typeof fetch === 'function') {
            fetch(endpoint, { method: 'POST', keepalive: true }).catch(() => {});
        }
    }

    recycle() {
        this.isActive = false;
        this.group.visible = false;
        this.hasRecordedImpression = false;
    }

    destroy() {
        if (this.group.parent) {
            this.group.parent.remove(this.group);
        }
        this.screenMaterial.dispose();
    }
}
