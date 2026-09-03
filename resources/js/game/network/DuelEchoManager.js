import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * DuelEchoManager.js - Real-time WebSocket synchronization manager powered by Laravel Reverb.
 * Manages presence channels, presence lifecycle events, 4-5 Hz throttled telemetry,
 * and synchronized match countdown triggers.
 */
export class DuelEchoManager {
    /**
     * @param {Object} config
     * @param {string} config.matchUuid
     * @param {string} [config.apiToken]
     * @param {Function} [config.onOpponentJoined] (data) => void
     * @param {Function} [config.onTelemetry] (telemetry) => void
     * @param {Function} [config.onDuelResolved] (result) => void
     * @param {Function} [config.onPresenceChange] (event, member) => void
     */
    constructor(config) {
        this.matchUuid = config.matchUuid;
        this.apiToken = config.apiToken || '';
        this.onOpponentJoined = config.onOpponentJoined || (() => {});
        this.onTelemetry = config.onTelemetry || (() => {});
        this.onDuelResolved = config.onDuelResolved || (() => {});
        this.onPresenceChange = config.onPresenceChange || (() => {});

        // Bandwidth Throttling (4.5 Hz => ~220ms interval)
        this.TELEMETRY_INTERVAL_MS = 220;
        this.lastTelemetrySendTime = 0;
        this.isSending = false;
        this.pendingTelemetry = null;

        // Initialize Echo
        this.echo = this._initEcho();
        this.channel = null;

        if (this.matchUuid) {
            this.subscribeToMatch(this.matchUuid);
        }
    }

    _initEcho() {
        if (typeof window === 'undefined') return null;

        window.Pusher = Pusher;

        const host = import.meta.env?.VITE_REVERB_HOST || window.location.hostname || 'localhost';
        const port = Number(import.meta.env?.VITE_REVERB_PORT) || 8080;
        const scheme = import.meta.env?.VITE_REVERB_SCHEME || 'http';
        const key = import.meta.env?.VITE_REVERB_APP_KEY || 'reverb_duel_key_antigravity';

        const csrfToken = typeof document !== 'undefined'
            ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            : '';

        return new Echo({
            broadcaster: 'reverb',
            key: key,
            wsHost: host,
            wsPort: port,
            wssPort: port,
            forceTLS: scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(this.apiToken ? { Authorization: `Bearer ${this.apiToken}` } : {}),
                },
            },
        });
    }

    /**
     * Subscribes to the match presence channel: presence-duel.{matchUuid}
     * @param {string} matchUuid
     */
    subscribeToMatch(matchUuid) {
        if (!this.echo) return;

        this.channel = this.echo.join(`duel.${matchUuid}`)
            .here((members) => {
                this.onPresenceChange('here', members);
            })
            .joining((member) => {
                this.onPresenceChange('joining', member);
            })
            .leaving((member) => {
                this.onPresenceChange('leaving', member);
            })
            .listen('.DuelOpponentJoined', (e) => {
                this.onOpponentJoined(e);
            })
            .listen('.DuelTelemetryUpdated', (e) => {
                this.onTelemetry(e);
            })
            .listen('.DuelResolved', (e) => {
                this.onDuelResolved(e);
            });
    }

    /**
     * Transmits throttled (4-5 Hz) telemetry packet with in-flight coalescing.
     * Guaranteed never to saturate the network or queue redundant requests.
     * @param {{ distance: number, score: number, current_lane: number, is_alive: boolean }} data
     */
    sendTelemetry(data) {
        if (!this.matchUuid) return;

        const now = performance.now();
        if (now - this.lastTelemetrySendTime < this.TELEMETRY_INTERVAL_MS) {
            this.pendingTelemetry = data;
            return;
        }

        if (this.isSending) {
            this.pendingTelemetry = data;
            return;
        }

        this._dispatchTelemetry(data);
    }

    async _dispatchTelemetry(data) {
        this.isSending = true;
        this.lastTelemetrySendTime = performance.now();

        try {
            const csrfToken = typeof document !== 'undefined'
                ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                : '';

            await fetch(`/api/v1/duels/matches/${this.matchUuid}/telemetry`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(this.apiToken ? { Authorization: `Bearer ${this.apiToken}` } : {}),
                },
                body: JSON.stringify({
                    distance: data.distance,
                    score: data.score,
                    current_lane: data.current_lane,
                    is_alive: data.is_alive,
                    timestamp: Date.now(),
                }),
            });
        } catch {
            // Non-blocking catch for network drops
        } finally {
            this.isSending = false;

            if (this.pendingTelemetry !== null) {
                const nextData = this.pendingTelemetry;
                this.pendingTelemetry = null;

                const elapsed = performance.now() - this.lastTelemetrySendTime;
                if (elapsed >= this.TELEMETRY_INTERVAL_MS) {
                    this._dispatchTelemetry(nextData);
                } else {
                    setTimeout(() => {
                        if (!this.isSending && this.pendingTelemetry === null) {
                            this._dispatchTelemetry(nextData);
                        }
                    }, this.TELEMETRY_INTERVAL_MS - elapsed);
                }
            }
        }
    }

    disconnect() {
        if (this.echo && this.matchUuid) {
            this.echo.leave(`duel.${this.matchUuid}`);
            this.echo.disconnect();
            this.channel = null;
        }
    }
}
