/**
 * InputManager.js - Cross-platform input listener for Keyboard & Mobile Touch Swipes.
 * Normalizes inputs into discrete game actions: MOVE_LEFT, MOVE_RIGHT, JUMP, ROLL.
 */
export class InputManager {
    /**
     * @param {HTMLElement} [domElement=window]
     */
    constructor(domElement = window) {
        this.domElement = domElement;
        this.actionQueue = [];

        // Swipe tracking
        this.touchStartX = 0;
        this.touchStartY = 0;
        this.touchStartTime = 0;
        this.minSwipeDistance = 30; // pixels
        this.maxSwipeTime = 500; // ms

        // Bound event handlers
        this._onKeyDown = this._onKeyDown.bind(this);
        this._onTouchStart = this._onTouchStart.bind(this);
        this._onTouchEnd = this._onTouchEnd.bind(this);

        this._attachListeners();
    }

    _attachListeners() {
        window.addEventListener('keydown', this._onKeyDown, { passive: false });

        const target = this.domElement === window ? document : this.domElement;
        target.addEventListener('touchstart', this._onTouchStart, { passive: true });
        target.addEventListener('touchend', this._onTouchEnd, { passive: false });

        // On-Screen D-Pad / Touch Buttons
        const bindButton = (id, action) => {
            const btn = document.getElementById(id);
            if (!btn) return;
            btn.addEventListener('pointerdown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.queueAction(action);
            });
        };

        bindButton('btn-touch-left', 'MOVE_LEFT');
        bindButton('btn-touch-right', 'MOVE_RIGHT');
        bindButton('btn-touch-jump', 'JUMP');
        bindButton('btn-touch-roll', 'ROLL');
    }

    /**
     * Handles keyboard events.
     * Supports Arrow keys, WASD, and French AZERTY (Q/D).
     * @param {KeyboardEvent} e
     */
    _onKeyDown(e) {
        // Prevent page scrolling on game controls
        const controlKeys = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Space', 'KeyW', 'KeyS', 'KeyA', 'KeyD', 'KeyQ'];
        if (controlKeys.includes(e.code)) {
            e.preventDefault();
        }

        switch (e.code) {
            case 'ArrowLeft':
            case 'KeyA':
            case 'KeyQ':
                this.queueAction('MOVE_LEFT');
                break;

            case 'ArrowRight':
            case 'KeyD':
                this.queueAction('MOVE_RIGHT');
                break;

            case 'ArrowUp':
            case 'KeyW':
            case 'Space':
                this.queueAction('JUMP');
                break;

            case 'ArrowDown':
            case 'KeyS':
                this.queueAction('ROLL');
                break;
        }
    }

    /**
     * Records initial touch coordinates.
     * @param {TouchEvent} e
     */
    _onTouchStart(e) {
        if (e.touches.length > 0) {
            this.touchStartX = e.touches[0].clientX;
            this.touchStartY = e.touches[0].clientY;
            this.touchStartTime = performance.now();
        }
    }

    /**
     * Analyzes touch displacement and duration to register a directional swipe.
     * @param {TouchEvent} e
     */
    _onTouchEnd(e) {
        if (e.changedTouches.length === 0) return;

        const deltaX = e.changedTouches[0].clientX - this.touchStartX;
        const deltaY = e.changedTouches[0].clientY - this.touchStartY;
        const deltaTime = performance.now() - this.touchStartTime;

        if (deltaTime > this.maxSwipeTime) {
            return; // Too slow for a swipe gesture
        }

        const absX = Math.abs(deltaX);
        const absY = Math.abs(deltaY);

        if (Math.max(absX, absY) < this.minSwipeDistance) {
            return; // Movement below minimum threshold
        }

        if (e.cancelable) {
            e.preventDefault();
        }

        if (absX > absY) {
            // Horizontal swipe
            if (deltaX > 0) {
                this.queueAction('MOVE_RIGHT');
            } else {
                this.queueAction('MOVE_LEFT');
            }
        } else {
            // Vertical swipe
            if (deltaY > 0) {
                this.queueAction('ROLL');
            } else {
                this.queueAction('JUMP');
            }
        }
    }

    /**
     * Enqueues a normalized action for the simulation tick.
     * @param {'MOVE_LEFT' | 'MOVE_RIGHT' | 'JUMP' | 'ROLL'} action
     */
    queueAction(action) {
        this.actionQueue.push(action);
    }

    /**
     * Consumes and clears all pending actions queued since the last tick.
     * @returns {string[]}
     */
    consumeActions() {
        if (this.actionQueue.length === 0) {
            return [];
        }
        const actions = this.actionQueue.slice();
        this.actionQueue.length = 0;
        return actions;
    }

    /**
     * Cleans up all DOM listeners.
     */
    destroy() {
        window.removeEventListener('keydown', this._onKeyDown);
        const target = this.domElement === window ? document : this.domElement;
        target.removeEventListener('touchstart', this._onTouchStart);
        target.removeEventListener('touchend', this._onTouchEnd);
        this.actionQueue.length = 0;
    }
}
