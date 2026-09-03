import { PRNG } from './core/PRNG.js';
import { InputManager } from './core/InputManager.js';
import { GameEngine } from './core/GameEngine.js';
import { Player } from './entities/Player.js';
import { TrackPool } from './entities/TrackPool.js';
import { ObstaclePool } from './entities/ObstaclePool.js';
import { CollisionSystem } from './systems/CollisionSystem.js';
import { RecorderSystem } from './systems/RecorderSystem.js';
import { PerformanceMonitor } from './systems/PerformanceMonitor.js';
import { GhostRunner } from './entities/GhostRunner.js';
import { DuelEchoManager } from './network/DuelEchoManager.js';
import { SkylineBackground } from './entities/SkylineBackground.js';

/**
 * GameApp.js - Master Orchestrator for the 3D Endless Runner Engine.
 * Integrates procedural deterministic generation, input recording,
 * zero-allocation loop execution, and responsive overlay HUD.
 */
export class GameApp {
    /**
     * @param {Object} config
     * @param {HTMLCanvasElement} config.canvas
     * @param {string} [config.gameSeed] 64-character server seed
     * @param {Function} [config.onGameOver] (payload) => void
     */
    constructor(config) {
        this.canvas = config.canvas;
        this.gameSeed = config.gameSeed || this._generateRandomSeed();
        this.matchUuid = config.matchUuid || null;
        this.apiToken = config.apiToken || '';
        this.onGameOverCallback = config.onGameOver || null;
        this.onDuelResolvedCallback = config.onDuelResolved || null;

        // State
        this.state = 'READY'; // 'READY' | 'PLAYING' | 'GAME_OVER'
        this.score = 0;
        this.coins = 0;
        this.distance = 0;
        this.multiplier = 1;
        this.opponentScore = 0;
        this.opponentDistance = 0;

        // 1. Core Systems
        this.prng = new PRNG(this.gameSeed);
        this.inputManager = new InputManager(this.canvas);
        this.recorder = new RecorderSystem(this.gameSeed, 60);

        // 2. Engine
        this.engine = new GameEngine(this.canvas, {
            fixedTimeStep: 1 / 60,
            onFixedUpdate: this._onFixedUpdate.bind(this),
            onRender: this._onRender.bind(this),
        });

        // 3. Entities
        this.player = new Player(this.engine.scene);
        this.ghostRunner = new GhostRunner(this.engine.scene);
        this.trackPool = new TrackPool(this.engine.scene, 10, 30);
        this.obstaclePool = new ObstaclePool(this.engine.scene, this.prng);
        this.skyline = new SkylineBackground(this.engine.scene, this.engine.camera);

        // 4. Real-time Reverb Synchronization
        this.network = null;
        if (this.matchUuid) {
            this.network = new DuelEchoManager({
                matchUuid: this.matchUuid,
                apiToken: this.apiToken,
                onOpponentJoined: (data) => this._onOpponentJoined(data),
                onTelemetry: (telemetry) => {
                    this.opponentScore = telemetry.score;
                    this.opponentDistance = telemetry.distance;
                    this.ghostRunner.applyTelemetry(telemetry);
                },
                onDuelResolved: (result) => {
                    if (this.onDuelResolvedCallback) this.onDuelResolvedCallback(result);
                },
            });
        }

        // 5. Game Systems
        this.collisionSystem = new CollisionSystem(this.player, this.obstaclePool);
        this.perfMonitor = new PerformanceMonitor(this.engine.renderer, { enableHUD: false });

        // Initial track pre-population
        this._prepopulateTrack();

        // 6. DOM HUD & Controls
        this._initHUD();

        // 7. Start Engine Render Loop (renders idle preview at 60 FPS)
        this.engine.start();
    }

    _generateRandomSeed() {
        const chars = '0123456789abcdef';
        let res = '';
        for (let i = 0; i < 64; i++) {
            res += chars[Math.floor(Math.random() * chars.length)];
        }
        return res;
    }

    _prepopulateTrack() {
        this.trackPool.reset(-20);
        this.obstaclePool.reset();

        // Populate initial obstacles ahead of player
        for (let z = 30; z <= 240; z += 30) {
            this.obstaclePool.spawnSegmentObstacles(z, 30);
        }
    }

    _initHUD() {
        if (typeof document === 'undefined') return;

        // Wire Blade HUD Start Button
        const startBtn = document.getElementById('hud-btn-start');
        if (startBtn) {
            startBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.startGame();
            });
        }

        // Wire spacebar / enter to launch
        window.addEventListener('keydown', (e) => {
            if ((e.code === 'Space' || e.code === 'Enter') && this.state === 'READY') {
                e.preventDefault();
                this.startGame();
            }
        });

        // Wire forfeit button
        const forfeitBtn = document.getElementById('btn-forfeit');
        if (forfeitBtn) {
            forfeitBtn.addEventListener('click', () => {
                if (confirm('Forfeit this duel match? Your stake will be conceded.')) {
                    window.location.href = '/lobby';
                }
            });
        }

        // Wire audio toggle button
        const audioBtn = document.getElementById('btn-audio-toggle');
        if (audioBtn) {
            audioBtn.addEventListener('click', () => {
                audioBtn.classList.toggle('opacity-50');
            });
        }
    }

    /**
     * Begins the run.
     */
    startGame() {
        if (this.state === 'PLAYING') return;

        this.state = 'PLAYING';

        const startBanner = document.getElementById('hud-start-banner');
        if (startBanner) {
            startBanner.style.display = 'none';
        }

        const postModal = document.getElementById('post-match-modal');
        if (postModal) {
            postModal.classList.add('opacity-0', 'pointer-events-none');
            postModal.classList.remove('opacity-100', 'pointer-events-auto');
        }

        this.recorder.start(this.gameSeed);
        this.engine.start();
    }

    /**
     * Restarts with either the same or a newly generated seed.
     */
    restartGame(newSeed = null) {
        if (newSeed) {
            this.gameSeed = newSeed;
            this.prng = new PRNG(this.gameSeed);
        } else {
            // Re-seed PRNG with the initial seed for exact deterministic retry
            this.prng = new PRNG(this.gameSeed);
        }

        this.score = 0;
        this.coins = 0;
        this.distance = 0;
        this.multiplier = 1;

        this.player.reset();
        this._prepopulateTrack();
        this.startGame();
    }

    /**
     * Fixed-timestep physics update (60 Hz).
     * @param {number} dt Delta time in seconds (1/60)
     * @param {number} tick Current tick count
     */
    _onFixedUpdate(dt, tick) {
        if (this.state !== 'PLAYING') return;

        // 1. Process and record player inputs
        const actions = this.inputManager.consumeActions();
        for (let i = 0; i < actions.length; i++) {
            const act = actions[i];

            if (act === 'MOVE_LEFT') this.player.changeLane(-1);
            else if (act === 'MOVE_RIGHT') this.player.changeLane(1);
            else if (act === 'JUMP') this.player.jump();
            else if (act === 'ROLL') this.player.roll();

            // Record action into anti-cheat stream
            this.recorder.recordAction(tick, act, this.player.mesh.position);
        }

        // 2. Advance player
        this.player.update(dt);
        this.distance = this.player.mesh.position.z;

        // 3. Track recycling
        this.trackPool.update(this.distance, (segmentZ, segmentLength) => {
            // Procedurally spawn obstacles along new segment
            this.obstaclePool.spawnSegmentObstacles(segmentZ, segmentLength);
        });

        // 4. Obstacle updates
        this.obstaclePool.update(this.distance, dt);

        // 5. Collision checks
        const result = this.collisionSystem.checkCollisions();

        if (result.coinCount > 0) {
            this.coins += result.coinCount;
            this.score += result.coinCount * 100;
        }

        // Continuous score for distance
        this.score += Math.floor(this.player.speed * dt * 2);

        // Anti-cheat snapshot
        this.recorder.recordTick(tick, this.score, this.coins, this.distance);

        // Lethal collision handling
        if (result.hit) {
            this._handleGameOver(tick);
        }

        // 6. Throttled Live Telemetry Broadcast (4-5 Hz: every 15 ticks = 250ms)
        if (this.network && tick % 15 === 0) {
            this.network.sendTelemetry({
                distance: this.distance,
                score: this.score,
                current_lane: this.player.currentLane,
                is_alive: this.state === 'PLAYING',
            });
        }
    }

    /**
     * Smooth visual rendering loop.
     * @param {number} alpha
     */
    _onRender(alpha) {
        // Camera smooth third-person follow (Cinematic low-angle sports cam)
        const targetCamX = this.player.mesh.position.x * 0.5;
        const targetCamZ = this.player.mesh.position.z - 6.2;
        const targetCamY = 3.6;

        this.engine.camera.position.x += (targetCamX - this.engine.camera.position.x) * 0.18;
        this.engine.camera.position.z = targetCamZ;
        this.engine.camera.position.y = targetCamY;

        this.engine.camera.lookAt(
            this.player.mesh.position.x * 0.3,
            1.5,
            this.player.mesh.position.z + 16
        );

        // Dynamic Speed FOV Warp
        const speedRatio = Math.min(1.0, (this.player.speed - this.player.baseSpeed) / (this.player.maxSpeed - this.player.baseSpeed));
        const targetFov = 65 + (speedRatio * 10);
        if (Math.abs(this.engine.camera.fov - targetFov) > 0.05) {
            this.engine.camera.fov += (targetFov - this.engine.camera.fov) * 0.08;
            this.engine.camera.updateProjectionMatrix();
        }

        // Subtle Camera Banking into lane shifts
        const targetRoll = (this.player.mesh.position.x - this.player.targetX) * 0.018;
        this.engine.camera.rotation.z += (targetRoll - this.engine.camera.rotation.z) * 0.12;

        // Dynamic headlight follows runner
        if (this.player.headlight) {
            this.player.headlight.position.set(
                this.player.mesh.position.x,
                this.player.mesh.position.y + 1.2,
                this.player.mesh.position.z + 1.6
            );
        }

        // Update Cyberpunk Megacity Skyline & Particle Atmosphere
        if (this.skyline) {
            this.skyline.update(this.player.mesh.position.z, 1 / 60);
        }

        // Update Ghost Runner (Smooth 60 FPS lerp from 4-5 Hz packets)
        this.ghostRunner.update(1 / 60);

        // Update Performance Monitor
        this.perfMonitor.update(performance.now());

        // Update HUD
        this._updateHUD();
    }

    _onOpponentJoined(data) {
        if (!this.hudContainer) return;
        const statusEl = this.hudContainer.querySelector('#hud-duel-status');
        if (statusEl) {
            statusEl.textContent = `OPPONENT CONNECTED: ${data.opponent?.username || 'PLAYER 2'}`;
            statusEl.style.display = 'block';
        }
    }

    _updateHUD() {
        // 1. Blade Dark Luxury HUD Overlay integration
        const duelHud = document.getElementById('duel-hud');
        if (duelHud) {
            const distEl = duelHud.querySelector('#hud-distance');
            const scoreEl = duelHud.querySelector('#hud-score');
            const rivalDistEl = duelHud.querySelector('#hud-rival-distance');
            const deltaEl = duelHud.querySelector('#hud-delta');
            const timerEl = duelHud.querySelector('#hud-timer');
            const multiplierEl = duelHud.querySelector('#hud-player-multiplier');
            const fpsEl = duelHud.querySelector('#hud-fps');

            if (distEl) distEl.textContent = `${Math.floor(this.distance)} m`;
            if (scoreEl) scoreEl.textContent = `${Math.floor(this.score)} PTS`;
            if (rivalDistEl) rivalDistEl.textContent = `${Math.floor(this.opponentDistance)} m`;

            // Dynamic Relative Delta Indicator
            if (deltaEl) {
                const delta = this.distance - this.opponentDistance;
                if (delta >= 0) {
                    deltaEl.textContent = `+${delta.toFixed(1)}m`;
                    deltaEl.className = 'text-xs font-mono font-bold px-1.5 py-0.5 rounded bg-emerald-500/20 text-[#10B981] border border-emerald-500/30';
                } else {
                    deltaEl.textContent = `${delta.toFixed(1)}m`;
                    deltaEl.className = 'text-xs font-mono font-bold px-1.5 py-0.5 rounded bg-red-500/20 text-[#FF0055] border border-red-500/30';
                }
            }

            // Digital Match Chronometer
            if (timerEl && this.engine) {
                const totalSec = this.distance / Math.max(1, this.player.speed);
                const m = Math.floor(totalSec / 60);
                const s = Math.floor(totalSec % 60);
                const ms = Math.floor((totalSec % 1) * 10);
                timerEl.textContent = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}.${ms}`;
            }

            // Multiplier badge
            if (multiplierEl) {
                const mult = 1 + Math.floor(this.player.speed / 12);
                multiplierEl.textContent = `x${mult}`;
            }

            // FPS Diagnostic
            if (fpsEl && this.perfMonitor) {
                fpsEl.textContent = String(this.perfMonitor.fps);
            }
        }

        // 2. Legacy fallback container
        if (this.hudContainer) {
            const scoreEl = this.hudContainer.querySelector('#hud-score');
            const distEl = this.hudContainer.querySelector('#hud-distance');
            const coinsEl = this.hudContainer.querySelector('#hud-coins');

            if (scoreEl) scoreEl.textContent = String(Math.floor(this.score)).padStart(6, '0');
            if (distEl) distEl.textContent = `${Math.floor(this.distance)} m`;
            if (coinsEl) coinsEl.textContent = String(this.coins);
        }
    }

    /**
     * Center-Screen Dynamic SVG Streak Alerts ('DODGE!', 'SPEED BOOST!', 'OVERTAKE!')
     * @param {string} text
     */
    triggerStreakAlert(text) {
        const streakEl = document.getElementById('hud-streak-container');
        const textEl = document.getElementById('hud-streak-text');
        if (!streakEl || !textEl) return;

        textEl.textContent = text;
        streakEl.classList.remove('opacity-0', '-translate-y-4');
        streakEl.classList.add('opacity-100', 'translate-y-0');

        clearTimeout(this._streakTimer);
        this._streakTimer = setTimeout(() => {
            streakEl.classList.remove('opacity-100', 'translate-y-0');
            streakEl.classList.add('opacity-0', '-translate-y-4');
        }, 1200);
    }

    _handleGameOver(tick) {
        this.state = 'GAME_OVER';
        this.engine.stop();

        const payload = this.recorder.exportPayload({
            score: this.score,
            coins: this.coins,
            distance: this.distance,
            ticks: tick,
        });

        this.lastExportPayload = payload;

        // Post-Match Settlement Modal
        const postModal = document.getElementById('post-match-modal');
        if (postModal) {
            const isVictory = this.distance >= this.opponentDistance;
            const potCents = Number(this.canvas?.dataset?.pot) || 10000;
            const rakeCents = Math.floor(potCents * 0.10);
            const netWinningsCents = potCents - rakeCents;

            const titleEl = postModal.querySelector('#modal-title');
            const statusBadge = postModal.querySelector('#modal-status-badge');
            const payoutEl = postModal.querySelector('#modal-payout-amount');
            const grossEl = postModal.querySelector('#modal-gross-pot');
            const rakeEl = postModal.querySelector('#modal-rake');
            const yourDistEl = postModal.querySelector('#modal-your-distance');
            const yourScoreEl = postModal.querySelector('#modal-your-score');
            const rivalDistEl = postModal.querySelector('#modal-rival-distance');
            const rivalScoreEl = postModal.querySelector('#modal-rival-score');

            if (titleEl) {
                titleEl.textContent = isVictory ? 'VICTORY!' : 'DEFEAT';
                titleEl.className = isVictory
                    ? 'text-3xl sm:text-4xl font-sans font-black tracking-tight text-white uppercase italic'
                    : 'text-3xl sm:text-4xl font-sans font-black tracking-tight text-red-500 uppercase italic';
            }

            if (statusBadge) {
                statusBadge.textContent = isVictory ? 'WINNER DECLARED' : 'DUEL CONCLUDED';
            }

            if (grossEl) grossEl.textContent = `$${(potCents / 100).toFixed(2)}`;
            if (rakeEl) rakeEl.textContent = `$${(rakeCents / 100).toFixed(2)}`;
            if (yourDistEl) yourDistEl.textContent = `${Math.floor(this.distance)} m`;
            if (yourScoreEl) yourScoreEl.textContent = `${Math.floor(this.score)} PTS`;
            if (rivalDistEl) rivalDistEl.textContent = `${Math.floor(this.opponentDistance)} m`;
            if (rivalScoreEl) rivalScoreEl.textContent = `${Math.floor(this.opponentScore)} PTS`;

            // Counter animation ticking up from $0.00 to net winnings
            if (payoutEl) {
                const targetPayout = isVictory ? (netWinningsCents / 100) : 0;
                let currentVal = 0;
                const duration = 1200;
                const start = performance.now();

                const step = (now) => {
                    const progress = Math.min((now - start) / duration, 1.0);
                    currentVal = targetPayout * progress;
                    payoutEl.textContent = `$${currentVal.toFixed(2)}`;
                    if (progress < 1.0) requestAnimationFrame(step);
                };
                requestAnimationFrame(step);
            }

            // Rewarded Ad CTA Trigger
            const watchAdBtn = postModal.querySelector('#btn-watch-ad');
            if (watchAdBtn) {
                watchAdBtn.onclick = () => {
                    watchAdBtn.textContent = 'SPONSOR REWARD ACTIVATED (-2% RAKE)';
                    watchAdBtn.classList.remove('text-[#00F0FF]');
                    watchAdBtn.classList.add('text-[#10B981]');
                    fetch('/api/v1/ads/rewarded-complete', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            ...(this.apiToken ? { Authorization: `Bearer ${this.apiToken}` } : {}),
                        },
                        body: JSON.stringify({ creative_id: 'rewarded-sponsor-clip' }),
                    }).catch(() => {});
                };
            }

            // Rematch button
            const rematchBtn = postModal.querySelector('#btn-rematch');
            if (rematchBtn) {
                rematchBtn.onclick = () => {
                    window.location.reload();
                };
            }

            // Reveal modal
            postModal.classList.remove('opacity-0', 'pointer-events-none');
            postModal.classList.add('opacity-100', 'pointer-events-auto');
        }

        // Show Legacy Modal if exists
        if (this.gameOverModal) {
            this.gameOverModal.querySelector('#final-score').textContent = String(Math.floor(this.score));
            this.gameOverModal.querySelector('#final-coins').textContent = String(this.coins);
            this.gameOverModal.querySelector('#final-distance').textContent = `${Math.floor(this.distance)} m`;
            this.gameOverModal.style.display = 'block';
        }

        if (this.onGameOverCallback) {
            this.onGameOverCallback(payload);
        }
    }

    /**
     * Triggers client-side browser download of the tamper-evident JSON audit stream.
     */
    downloadReplayJSON() {
        if (!this.lastExportPayload) return;

        const dataStr = 'data:text/json;charset=utf-8,' + encodeURIComponent(JSON.stringify(this.lastExportPayload, null, 2));
        const downloadAnchor = document.createElement('a');
        downloadAnchor.setAttribute('href', dataStr);
        downloadAnchor.setAttribute('download', `duel-audit-${this.gameSeed.substring(0, 8)}-${Date.now()}.json`);
        document.body.appendChild(downloadAnchor);
        downloadAnchor.click();
        downloadAnchor.remove();
    }

    /**
     * Clean destruction of all engine and UI resources.
     */
    destroy() {
        if (this.ghostRunner) {
            this.ghostRunner.destroy();
        }
        if (this.network) {
            this.network.disconnect();
        }
        this.engine.destroy();
        this.inputManager.destroy();
        this.perfMonitor.destroy();
        if (this.hudContainer && this.hudContainer.parentElement) {
            this.hudContainer.parentElement.removeChild(this.hudContainer);
        }
    }
}
