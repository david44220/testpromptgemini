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
        this.expectedSeedCommitment = config.seedCommitment || (this.canvas && this.canvas.dataset ? this.canvas.dataset.commitment : '') || '';
        this.matchUuid = config.matchUuid || null;
        this.currentUserId = config.currentUserId || (this.canvas && this.canvas.dataset && this.canvas.dataset.userId ? Number(this.canvas.dataset.userId) : null);
        this.onGameOverCallback = config.onGameOver || null;
        this.onDuelResolvedCallback = config.onDuelResolved || null;
        this.opponentJoinedData = null;
        this.duelResolvedData = null;

        // State
        this.state = 'READY'; // 'READY' | 'PLAYING' | 'GAME_OVER'
        this.score = 0;
        this.coins = 0;
        this.distance = 0;
        this.multiplier = 1;
        this.opponentScore = 0;
        this.opponentDistance = 0;

        // 1. Core Systems with Strict PRNG Isolation
        this.gameplayPrng = new PRNG(this.gameSeed + ':gameplay');
        this.cosmeticPrng = new PRNG(this.gameSeed + ':cosmetic');
        this.ticketToken = null;
        this.isStarting = false;

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
        this.obstaclePool = new ObstaclePool(this.engine.scene, this.gameplayPrng, this.cosmeticPrng);
        this.skyline = new SkylineBackground(this.engine.scene, this.engine.camera);

        // 4. Real-time Reverb Synchronization
        this.network = null;
        if (this.matchUuid) {
            this.network = new DuelEchoManager({
                matchUuid: this.matchUuid,
                onOpponentJoined: (data) => this._onOpponentJoined(data),
                onTelemetry: (telemetry) => {
                    this.opponentScore = telemetry.score;
                    this.opponentDistance = telemetry.distance;
                    this.ghostRunner.applyTelemetry(telemetry);
                },
                onDuelResolved: (result) => {
                    this.duelResolvedData = result;
                    if (this.onDuelResolvedCallback) this.onDuelResolvedCallback(result);
                    this._applyAuthoritativeResult(result);
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

    async _computeSha256(message) {
        if (typeof crypto !== 'undefined' && crypto.subtle) {
            const encoder = new TextEncoder();
            const data = encoder.encode(message);
            const hashBuffer = await crypto.subtle.digest('SHA-256', data);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');
        }
        return '';
    }

    _prepopulateTrack() {
        this.trackPool.reset(-30);
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
     * Begins the run. Authenticates ticket in paid mode before entering PLAYING state.
     */
    async startGame() {
        if (this.state === 'PLAYING' || this.isStarting) return;

        const startBanner = document.getElementById('hud-start-banner');
        const bannerTitle = startBanner ? (startBanner.querySelector('h2') || startBanner.querySelector('h1')) : null;
        const bannerSub = startBanner ? startBanner.querySelector('p') : null;

        // Authoritative Duel: Negotiate ticket with server before simulation starts
        if (this.matchUuid) {
            this.isStarting = true;
            if (bannerSub) bannerSub.textContent = 'CONNECTING TO AUTHORITATIVE NEURAL ARENA...';

            const csrfToken = typeof document !== 'undefined'
                ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                : '';

            try {
                const res = await fetch(`/api/v1/duels/matches/${this.matchUuid}/start-run`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                const data = await res.json();
                if (!res.ok) {
                    this.isStarting = false;
                    if (bannerTitle) bannerTitle.textContent = 'ACCESS REJECTED';
                    if (bannerSub) bannerSub.textContent = data.message || 'Match no longer accepting runs.';
                    return;
                }

                // Cryptographic commitment verification before entering PLAYING state
                if (this.expectedSeedCommitment && data.game_seed) {
                    const computedHash = await this._computeSha256(data.game_seed);
                    if (computedHash.toLowerCase() !== this.expectedSeedCommitment.toLowerCase()) {
                        console.error('[AntiCheat] Seed commitment integrity mismatch!', {
                            expected: this.expectedSeedCommitment,
                            computed: computedHash,
                        });
                        this.ticketToken = null;
                        this.isStarting = false;
                        if (bannerTitle) bannerTitle.textContent = 'INTEGRITY FAILURE';
                        if (bannerSub) bannerSub.textContent = 'Cryptographic seed commitment mismatch. Run aborted.';
                        return;
                    }
                }

                this.ticketToken = data.ticket_token;
                if (data.game_seed) {
                    this.gameSeed = data.game_seed;
                }
            } catch (err) {
                this.isStarting = false;
                if (bannerTitle) bannerTitle.textContent = 'CONNECTION ERROR';
                if (bannerSub) bannerSub.textContent = 'Failed to obtain run ticket. Please check network.';
                return;
            } finally {
                this.isStarting = false;
            }
        }

        this.resetRun(this.gameSeed);
        this.state = 'PLAYING';

        if (startBanner) {
            startBanner.style.display = 'none';
        }

        const postModal = document.getElementById('post-match-modal');
        if (postModal) {
            postModal.classList.add('opacity-0', 'pointer-events-none');
            postModal.classList.remove('opacity-100', 'pointer-events-auto');
        }

        this.engine.start();
    }

    /**
     * Resets engine, simulation state, isolated PRNGs, and physics runner.
     * @param {string} seed
     */
    resetRun(seed) {
        this.gameSeed = seed;
        this.gameplayPrng = new PRNG(this.gameSeed + ':gameplay');
        this.cosmeticPrng = new PRNG(this.gameSeed + ':cosmetic');
        this.obstaclePool.resetDeterministicStreams(this.gameplayPrng, this.cosmeticPrng);

        this.score = 0;
        this.coins = 0;
        this.distance = 0;
        this.multiplier = 1;

        this.engine.reset();
        this.player.reset();
        this.recorder.reset();
        this.recorder.start(this.gameSeed);
        this._prepopulateTrack();
    }

    /**
     * Restarts with either the same or a newly generated seed.
     */
    restartGame(newSeed = null) {
        const seed = newSeed || this.gameSeed;
        this.resetRun(seed);
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
        this.opponentJoinedData = data;
        const opponentName = data.opponent?.username || data.opponent?.name || 'RIVAL';

        const rivalNameEl = document.getElementById('hud-rival-name');
        if (rivalNameEl) {
            rivalNameEl.textContent = opponentName.toUpperCase();
        }

        const rivalAvatarEl = document.getElementById('hud-rival-avatar');
        if (rivalAvatarEl && data.opponent?.avatar_url) {
            rivalAvatarEl.src = data.opponent.avatar_url;
        }

        const statusEl = document.getElementById('hud-duel-status');
        if (statusEl) {
            statusEl.textContent = `OPPONENT CONNECTED: ${opponentName.toUpperCase()}`;
            statusEl.style.display = 'block';
        }

        if (typeof window !== 'undefined') {
            window.dispatchEvent(new CustomEvent('duel:opponent-joined', { detail: data }));
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

    _handleGameOver(tickIndex) {
        this.state = 'GAME_OVER';
        this.engine.stop();

        const completedSteps = tickIndex + 1;

        const payload = this.recorder.exportPayload({
            score: this.score,
            coins: this.coins,
            distance: this.distance,
            ticks: completedSteps,
        });

        this.lastExportPayload = payload;

        const csrfToken = typeof document !== 'undefined'
            ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            : '';

        // Automatically submit run payload with single-use ticket token for deterministic server-side audit
        if (this.matchUuid && this.ticketToken) {
            const tokenToSubmit = this.ticketToken;
            this.ticketToken = null;

            fetch(`/api/v1/duels/matches/${this.matchUuid}/submit-run`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    ticket_token: tokenToSubmit,
                    ticks_elapsed: completedSteps,
                    final_distance: Math.floor(this.distance * 100) / 100,
                    final_score: Math.floor(this.score),
                    inputs: this.recorder.actionLog.map(act => ({
                        tick: act.tick,
                        action: act.action,
                    })),
                }),
            }).catch(() => {});
        }

        // Post-Match Settlement Modal (Authoritative Flow)
        const postModal = document.getElementById('post-match-modal');
        if (postModal) {
            const titleEl = postModal.querySelector('#modal-title');
            const statusBadge = postModal.querySelector('#modal-status-badge');
            const payoutEl = postModal.querySelector('#modal-payout-amount');
            const grossEl = postModal.querySelector('#modal-gross-pot');
            const rakeEl = postModal.querySelector('#modal-rake');
            const yourDistEl = postModal.querySelector('#modal-your-distance');
            const yourScoreEl = postModal.querySelector('#modal-your-score');
            const rivalDistEl = postModal.querySelector('#modal-rival-distance');
            const rivalScoreEl = postModal.querySelector('#modal-rival-score');

            if (yourDistEl) yourDistEl.textContent = `${Math.floor(this.distance)} m`;
            if (yourScoreEl) yourScoreEl.textContent = `${Math.floor(this.score)} PTS`;

            if (!this.matchUuid) {
                // Practice / Solo Simulation Mode
                if (titleEl) {
                    titleEl.textContent = 'PRACTICE CONCLUDED';
                    titleEl.className = 'text-3xl sm:text-4xl font-sans font-black tracking-tight text-white uppercase italic';
                }
                if (statusBadge) statusBadge.textContent = 'SOLO SIMULATION';
                if (payoutEl) payoutEl.textContent = '$0.00';
                if (grossEl) grossEl.textContent = '$0.00';
                if (rakeEl) rakeEl.textContent = '$0.00';
                if (rivalDistEl) rivalDistEl.textContent = 'N/A';
                if (rivalScoreEl) rivalScoreEl.textContent = 'N/A';
            } else {
                // Authoritative Paid Duel Mode: strictly display verifying state until server resolution
                if (titleEl) {
                    titleEl.textContent = 'LOCAL RUN FINISHED';
                    titleEl.className = 'text-3xl sm:text-4xl font-sans font-black tracking-tight text-white uppercase italic';
                }
                if (statusBadge) statusBadge.textContent = 'VERIFYING RESULT...';
                if (payoutEl) payoutEl.textContent = '$0.00';
                if (grossEl) grossEl.textContent = 'VERIFYING...';
                if (rakeEl) rakeEl.textContent = 'VERIFYING...';
                if (rivalDistEl) rivalDistEl.textContent = 'AWAITING ARBITER...';
                if (rivalScoreEl) rivalScoreEl.textContent = '...';

                // Listen for onDuelResolved callback from Reverb presence channel
                this.onDuelResolvedCallback = (result) => {
                    this._applyAuthoritativeResult(result);
                };

                // Polling recovery fallback in case WebSocket was interrupted
                this._pollAuthoritativeResult();
            }

            // Rewarded Ad CTA Trigger (Fail-Closed)
            const rewardedSection = postModal.querySelector('#modal-rewarded-ad');
            const watchAdBtn = postModal.querySelector('#btn-watch-ad');
            const isAdAvailable = rewardedSection?.dataset?.available === '1';

            if (watchAdBtn && isAdAvailable) {
                watchAdBtn.onclick = async () => {
                    watchAdBtn.disabled = true;
                    watchAdBtn.textContent = 'VERIFYING SPONSOR...';
                    try {
                        const res = await fetch('/api/v1/ads/rewarded-complete', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify({ creative_id: 'rewarded-sponsor-clip' }),
                        });
                        if (res.ok) {
                            watchAdBtn.textContent = '2% RAKE DISCOUNT ACTIVE';
                            watchAdBtn.classList.remove('text-[#00F0FF]');
                            watchAdBtn.classList.add('text-[#10B981]');
                        } else {
                            watchAdBtn.disabled = false;
                            watchAdBtn.textContent = 'SPONSOR REWARD FAILED';
                        }
                    } catch {
                        watchAdBtn.disabled = false;
                        watchAdBtn.textContent = 'SPONSOR REWARD FAILED';
                    }
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
     * Polls authoritative match result endpoint until settled.
     */
    _pollAuthoritativeResult() {
        if (!this.matchUuid) return;

        const csrfToken = typeof document !== 'undefined'
            ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            : '';

        const poll = async () => {
            if (this.authoritativeSettled) return;
            try {
                const res = await fetch(`/api/v1/duels/matches/${this.matchUuid}/result`, {
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (res.status === 200) {
                    const data = await res.json();
                    this._applyAuthoritativeResult(data);
                } else if (res.status === 202) {
                    // Match still in progress, schedule next poll
                    this.resultPollTimeout = setTimeout(poll, 1000);
                }
            } catch {
                this.resultPollTimeout = setTimeout(poll, 1500);
            }
        };

        this.resultPollTimeout = setTimeout(poll, 1000);
    }

    /**
     * Applies server-authoritative settlement payload to modal UI.
     * @param {Object} result
     */
    _applyAuthoritativeResult(result) {
        if (this.authoritativeSettled) return;
        this.authoritativeSettled = true;

        if (this.resultPollTimeout) {
            clearTimeout(this.resultPollTimeout);
            this.resultPollTimeout = null;
        }

        const postModal = document.getElementById('post-match-modal');
        if (!postModal) return;

        const titleEl = postModal.querySelector('#modal-title');
        const statusBadge = postModal.querySelector('#modal-status-badge');
        const payoutEl = postModal.querySelector('#modal-payout-amount');
        const grossEl = postModal.querySelector('#modal-gross-pot');
        const rakeEl = postModal.querySelector('#modal-rake');
        const yourDistEl = postModal.querySelector('#modal-your-distance');
        const yourScoreEl = postModal.querySelector('#modal-your-score');
        const rivalDistEl = postModal.querySelector('#modal-rival-distance');
        const rivalScoreEl = postModal.querySelector('#modal-rival-score');

        const myUserId = this.currentUserId || (this.canvas && this.canvas.dataset && this.canvas.dataset.userId ? Number(this.canvas.dataset.userId) : null) || (result.player ? Number(result.player.user_id) : null);

        let isVictory = false;
        let isDefeat = false;
        let isDisputed = false;
        let isCancelled = false;
        let state = 'COMPLETED';

        if (result.resolution_state) {
            // Personalized resolution_state from /matches/{uuid}/result endpoint
            state = result.resolution_state;
            isVictory = state === 'VICTORY' || state === 'FORFEIT WIN';
            isDefeat = state === 'DEFEAT' || state === 'FORFEIT LOSS';
            isDisputed = state === 'DISPUTED';
            isCancelled = state === 'CANCELLED' || state === 'ABANDONED_CANCELLED';
        } else {
            // Match-level resolution payload from .DuelResolved broadcast
            const rawType = result.resolution_type || result.status || 'COMPLETED';
            if (rawType === 'CANCELLED' || rawType === 'ABANDONED_CANCELLED' || result.status === 'cancelled') {
                isCancelled = true;
                state = 'CANCELLED';
            } else if (rawType === 'DISPUTED' || result.status === 'disputed') {
                isDisputed = true;
                state = 'DISPUTED';
            } else if (result.winner_user_id !== undefined && result.winner_user_id !== null && myUserId !== null) {
                if (Number(result.winner_user_id) === Number(myUserId)) {
                    isVictory = true;
                    state = (rawType === 'FORFEIT' || rawType === 'FORFEIT WIN') ? 'FORFEIT WIN' : 'VICTORY';
                } else {
                    isDefeat = true;
                    state = (rawType === 'FORFEIT' || rawType === 'FORFEIT LOSS') ? 'FORFEIT LOSS' : 'DEFEAT';
                }
            } else {
                state = rawType;
                isVictory = state === 'VICTORY' || state === 'FORFEIT WIN';
                isDefeat = state === 'DEFEAT' || state === 'FORFEIT LOSS';
                isDisputed = state === 'DISPUTED';
                isCancelled = state === 'CANCELLED' || state === 'ABANDONED_CANCELLED';
            }
        }

        if (titleEl) {
            if (isVictory) {
                titleEl.textContent = state === 'FORFEIT WIN' ? 'FORFEIT VICTORY!' : 'VICTORY!';
                titleEl.className = 'text-3xl sm:text-4xl font-sans font-black tracking-tight text-[#10B981] uppercase italic';
            } else if (isDefeat) {
                titleEl.textContent = state === 'FORFEIT LOSS' ? 'FORFEIT LOSS' : 'DEFEAT';
                titleEl.className = 'text-3xl sm:text-4xl font-sans font-black tracking-tight text-red-500 uppercase italic';
            } else if (isDisputed) {
                titleEl.textContent = 'DISPUTED';
                titleEl.className = 'text-3xl sm:text-4xl font-sans font-black tracking-tight text-amber-500 uppercase italic';
            } else {
                titleEl.textContent = 'CANCELLED';
                titleEl.className = 'text-3xl sm:text-4xl font-sans font-black tracking-tight text-slate-400 uppercase italic';
            }
        }

        if (statusBadge) {
            if (isVictory) statusBadge.textContent = 'AUTHORITATIVE WINNER';
            else if (isDefeat) statusBadge.textContent = 'MATCH SETTLED';
            else if (isDisputed) statusBadge.textContent = 'ESCROW REFUNDED';
            else statusBadge.textContent = 'ESCROW REFUNDED';
        }

        const fallbackPot = this.canvas && this.canvas.dataset && this.canvas.dataset.pot ? Number(this.canvas.dataset.pot) : 10000;
        const totalPotCents = (result.total_pot_cents && result.total_pot_cents > 0) ? result.total_pot_cents : fallbackPot;
        const feeCents = (result.platform_fee_cents !== undefined && result.platform_fee_cents !== null && result.platform_fee_cents > 0) ? result.platform_fee_cents : Math.floor(totalPotCents * 0.10);
        const payoutCents = (result.winner_payout_cents && result.winner_payout_cents > 0) ? result.winner_payout_cents : Math.max(0, totalPotCents - feeCents);

        if (grossEl) grossEl.textContent = `$${(totalPotCents / 100).toFixed(2)}`;
        if (rakeEl) rakeEl.textContent = `$${(feeCents / 100).toFixed(2)}`;

        // Authoritative metrics (supports /result player/rival keys and broadcast creator/opponent keys)
        const playerMetrics = result.player || (myUserId && result.creator && Number(result.creator.user_id) === Number(myUserId) ? result.creator : (myUserId && result.opponent && Number(result.opponent.user_id) === Number(myUserId) ? result.opponent : null));
        const rivalMetrics = result.rival || (myUserId && result.creator && Number(result.creator.user_id) === Number(myUserId) ? result.opponent : (myUserId && result.opponent && Number(result.opponent.user_id) === Number(myUserId) ? result.creator : null));

        if (playerMetrics) {
            if (yourDistEl) yourDistEl.textContent = `${Math.floor(playerMetrics.authoritative_distance || 0)} m`;
            if (yourScoreEl) yourScoreEl.textContent = `${Math.floor(playerMetrics.authoritative_score || 0)} PTS`;
        }
        if (rivalMetrics) {
            if (rivalDistEl) rivalDistEl.textContent = `${Math.floor(rivalMetrics.authoritative_distance || 0)} m`;
            if (rivalScoreEl) rivalScoreEl.textContent = `${Math.floor(rivalMetrics.authoritative_score || 0)} PTS`;
        }

        if (payoutEl) {
            if (isVictory) {
                const targetPayout = payoutCents / 100;
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
            } else if (isDisputed || isCancelled) {
                payoutEl.textContent = 'REFUNDED';
            } else {
                payoutEl.textContent = '$0.00';
            }
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
