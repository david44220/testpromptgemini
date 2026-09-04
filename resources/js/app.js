import { GameApp } from './game/GameApp.js';

document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('game-canvas');
    if (canvas) {
        const seed = canvas.dataset.seed || '';
        const matchUuid = canvas.dataset.match || '';
        const seedCommitment = canvas.dataset.commitment || '';
        const currentUserId = canvas.dataset.userId ? Number(canvas.dataset.userId) : null;
        const app = new GameApp({
            canvas,
            gameSeed: seed,
            matchUuid,
            seedCommitment,
            currentUserId,
            onGameOver: (payload) => {
                console.log('[NeonRunner] Game Over Audit Payload:', payload);
            },
        });
        window.neonRunnerApp = app;
    }
});

export { GameApp };

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
