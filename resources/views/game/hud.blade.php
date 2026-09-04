<!-- Real-Time Duel HUD Overlay (Hardware-accelerated, pointer-events-none) -->
<div id="duel-hud" class="absolute inset-0 pointer-events-none z-30 flex flex-col justify-between p-4 sm:p-6 select-none">
    
    <!-- 1. TOP BAR: Split Telemetry -->
    <header class="w-full flex items-start justify-between gap-4">
        
        <!-- Left: Authenticated Player Profile & Live Metrics -->
        <div class="flex items-center space-x-3 bg-luxury-glass px-4 py-2.5 rounded-2xl border border-white/10 shadow-lg pointer-events-auto">
            <div class="relative">
                <img id="hud-player-avatar" src="https://ui-avatars.com/api/?name={{ urlencode($user->name ?? 'Player') }}&background=D4AF37&color=000" alt="{{ $user->name ?? 'Player' }}" class="w-11 h-11 rounded-xl ring-2 ring-[#D4AF37]/50 object-cover" />
                <span id="hud-player-multiplier" class="absolute -bottom-1 -right-1 text-[10px] font-mono font-bold bg-[#D4AF37] text-black px-1.5 py-0.2 rounded-md shadow">x1</span>
            </div>
            <div class="flex flex-col">
                <div class="flex items-center gap-2">
                    <span id="hud-player-name" class="text-xs font-mono font-bold text-white tracking-wider uppercase truncate max-w-[110px]">{{ $user->name ?? 'YOU' }}</span>
                    <span class="text-[10px] font-mono text-[#D4AF37] font-black bg-[#D4AF37]/10 px-1.5 py-0.5 rounded border border-[#D4AF37]/30">${{ number_format(($wallet->balance_cents ?? 0) / 100, 2) }}</span>
                </div>
                <div class="flex items-baseline space-x-1.5 mt-0.5">
                    <span id="hud-distance" class="text-xl font-mono font-black text-white tracking-tight">0 m</span>
                    <span id="hud-score" class="text-xs font-mono text-[#D4AF37] font-bold">0 PTS</span>
                </div>
            </div>
        </div>

        <!-- Center: Match Pot & Synchronized Chronometer -->
        <div class="flex flex-col items-center bg-luxury-glass px-6 py-2.5 rounded-2xl border border-[#D4AF37]/40 shadow-gold-glow">
            <span class="text-[10px] font-mono tracking-widest uppercase text-[#D4AF37] font-bold flex items-center gap-1">
                <span class="w-2 h-2 rounded-full bg-[#D4AF37] animate-ping"></span>
                TOTAL ESCROW POT
            </span>
            <div id="hud-pot" class="text-2xl sm:text-3xl font-mono font-black text-gold-metallic tracking-wider">
                ${{ number_format(($potCents ?? 10000) / 100, 2) }}
            </div>
            <span id="hud-timer" class="text-xs font-mono text-slate-400 font-semibold tracking-widest mt-0.5">00:00.0</span>
        </div>

        <!-- Right: Rival Ghost Telemetry & Delta Indicator -->
        <div class="flex items-center space-x-3 bg-luxury-glass px-4 py-2.5 rounded-2xl border border-white/10 shadow-lg pointer-events-auto">
            <div class="flex flex-col text-right">
                <span id="hud-rival-name" class="text-xs font-mono font-semibold text-slate-300 tracking-wider uppercase truncate max-w-[110px]">RIVAL GHOST</span>
                <div class="flex items-baseline justify-end space-x-2">
                    <span id="hud-rival-distance" class="text-lg font-mono font-bold text-slate-200">0 m</span>
                    <!-- Dynamic Delta: positive = green lead, negative = red lag -->
                    <span id="hud-delta" class="text-xs font-mono font-bold px-1.5 py-0.5 rounded bg-emerald-500/20 text-[#10B981] border border-emerald-500/30">
                        +0.0m
                    </span>
                </div>
            </div>
            <div class="relative">
                <img id="hud-rival-avatar" src="https://ui-avatars.com/api/?name=Rival&background=FF0055&color=fff" alt="Rival" class="w-11 h-11 rounded-xl ring-2 ring-[#FF0055]/50 object-cover" />
                <div class="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-[#00F0FF] shadow-cyan-glow"></div>
            </div>
        </div>

    </header>

    <!-- 2. CENTER SCREEN: Dynamic SVG Streak Alerts -->
    <div id="hud-streak-container" class="self-center flex flex-col items-center justify-center transition-all duration-300 opacity-0 pointer-events-none transform -translate-y-4">
        <div id="hud-streak-badge" class="px-6 py-2 rounded-2xl bg-gradient-to-r from-[#FFB800]/90 to-[#FF0055]/90 border border-white/30 shadow-crimson-glow text-white font-sans font-black text-xl sm:text-2xl tracking-widest uppercase italic flex items-center space-x-2">
            <svg class="w-6 h-6 text-white animate-bounce" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            <span id="hud-streak-text">SPEED BOOST!</span>
        </div>
    </div>

    <!-- 2.5. CENTER START BANNER & CONTROLS (Dark Luxury Cyber-Rail) -->
    <div id="hud-start-banner" class="self-center flex flex-col items-center justify-center p-8 rounded-3xl bg-[#121316]/95 border border-[#D4AF37]/40 shadow-gold-glow-lg text-center pointer-events-auto max-w-md w-full mx-auto backdrop-blur-xl transition-all duration-300">
        <div class="w-12 h-12 rounded-2xl bg-gold-metallic flex items-center justify-center font-mono font-black text-black text-2xl shadow-gold-glow mb-3">
            Ω
        </div>
        <h2 class="text-2xl sm:text-3xl font-black text-white uppercase italic tracking-tight">
            READY FOR COMBAT
        </h2>
        <p class="text-xs font-mono text-slate-400 mt-1 uppercase tracking-wider">
            Deterministic Track & Stake Locked
        </p>

        <!-- Controls Reminder -->
        <div class="grid grid-cols-3 gap-2 my-5 text-[11px] font-mono text-slate-300 w-full">
            <span class="px-2 py-1.5 rounded-lg bg-white/5 border border-white/10 text-center">⬅️ ➡️ Lane</span>
            <span class="px-2 py-1.5 rounded-lg bg-white/5 border border-white/10 text-center">⬆️ Jump</span>
            <span class="px-2 py-1.5 rounded-lg bg-white/5 border border-white/10 text-center">⬇️ Roll</span>
        </div>

        <!-- Launch Button -->
        <button 
            id="hud-btn-start" 
            type="button" 
            class="w-full py-4 rounded-2xl bg-gold-metallic text-black font-mono font-black text-sm uppercase tracking-wider shadow-gold-glow hover:scale-[1.03] active:scale-95 transition-all cursor-pointer flex items-center justify-center gap-2"
        >
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"/>
            </svg>
            LAUNCH RUN (SPACEBAR)
        </button>
    </div>

    <!-- 2.8. TOUCH / ON-SCREEN D-PAD CONTROLS -->
    <div id="hud-touch-controls" class="w-full flex items-end justify-between pointer-events-none px-2 sm:px-4 pb-2 z-30">
        <!-- Steering: Left / Right -->
        <div class="flex items-center space-x-3 pointer-events-auto">
            <button id="btn-touch-left" type="button" class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-luxury-glass border border-white/20 text-[#D4AF37] active:bg-[#D4AF37] active:text-black flex items-center justify-center shadow-lg transition-all active:scale-90 select-none cursor-pointer hover:border-[#D4AF37]" title="Move Left (A / ⬅️)">
                <svg class="w-8 h-8 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
                </svg>
            </button>
            <button id="btn-touch-right" type="button" class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-luxury-glass border border-white/20 text-[#D4AF37] active:bg-[#D4AF37] active:text-black flex items-center justify-center shadow-lg transition-all active:scale-90 select-none cursor-pointer hover:border-[#D4AF37]" title="Move Right (D / ➡️)">
                <svg class="w-8 h-8 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                </svg>
            </button>
        </div>

        <!-- Actions: Jump / Slide -->
        <div class="flex items-center space-x-3 pointer-events-auto">
            <button id="btn-touch-roll" type="button" class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-luxury-glass border border-white/20 text-slate-300 active:bg-white active:text-black flex flex-col items-center justify-center shadow-lg transition-all active:scale-90 select-none cursor-pointer hover:border-white/50" title="Roll / Duck (S / ⬇️)">
                <svg class="w-6 h-6 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                </svg>
                <span class="text-[9px] font-mono font-bold uppercase pointer-events-none">ROLL</span>
            </button>
            <button id="btn-touch-jump" type="button" class="w-14 h-14 sm:w-16 sm:h-16 rounded-2xl bg-gold-metallic border border-[#D4AF37] text-black active:scale-90 flex flex-col items-center justify-center shadow-gold-glow transition-all select-none cursor-pointer" title="Jump (W / ⬆️ / Space)">
                <svg class="w-6 h-6 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 15l7-7 7 7"/>
                </svg>
                <span class="text-[9px] font-mono font-black uppercase pointer-events-none">JUMP</span>
            </button>
        </div>
    </div>

    <!-- 3. BOTTOM BAR: Quick Controls & Diagnostics -->
    <footer class="w-full flex items-center justify-between pointer-events-auto">
        <!-- Audio & Settings -->
        <div class="flex items-center space-x-2">
            <button id="btn-audio-toggle" type="button" class="w-10 h-10 rounded-xl bg-luxury-glass border border-white/10 flex items-center justify-center text-slate-300 hover:text-white hover:border-[#D4AF37]/50 transition-colors cursor-pointer" title="Toggle Sound">
                <svg id="icon-sound-on" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072M18.364 5.636a9 9 0 010 12.728M11 5L6 9H2v6h4l5 4V5z"/>
                </svg>
            </button>
            <span class="text-[11px] font-mono text-slate-400 bg-luxury-glass px-2.5 py-1.5 rounded-lg border border-white/5">
                FPS: <span id="hud-fps" class="text-[#10B981] font-bold">60</span>
            </span>
        </div>

        <!-- Connection & Seed info -->
        <div class="hidden sm:flex items-center space-x-2 bg-luxury-glass px-3 py-1.5 rounded-xl border border-white/5 text-[11px] font-mono text-slate-400">
            <span class="w-2 h-2 rounded-full bg-[#10B981]"></span>
            <span>REVERB: CONNECTED</span>
            <span class="text-slate-600">|</span>
            <span id="hud-seed-info" class="text-slate-400">{{ !empty($isPaid) ? 'COMMIT: '.substr($seedCommitment ?? '', 0, 8).'...' : 'SEED: '.substr($seed ?? 'e3b0c442', 0, 8).'...' }}</span>
        </div>

        <!-- Action Triggers -->
        <div class="flex items-center space-x-2">
            <a href="/dashboard" class="px-3 py-1.5 text-xs font-mono font-bold uppercase tracking-wider rounded-xl bg-luxury-glass border border-white/10 text-slate-300 hover:text-white hover:border-[#D4AF37] transition-all flex items-center gap-1">
                &larr; USER AREA
            </a>
            <button id="btn-forfeit" type="button" class="px-3.5 py-1.5 text-xs font-mono font-semibold uppercase tracking-wider rounded-xl bg-red-950/40 border border-red-500/30 text-red-400 hover:bg-red-900/60 hover:text-white transition-all cursor-pointer">
                FORFEIT MATCH
            </button>
        </div>
    </footer>
</div>
