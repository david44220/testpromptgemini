<!DOCTYPE html>
<html lang="en" class="h-full bg-[#0A0A0C]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CYBER-RAIL 3D — Real-Money Deterministic Esports Duel Platform</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        .grid-bg {
            background-size: 40px 40px;
            background-image: 
                linear-gradient(to right, rgba(212, 175, 55, 0.04) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(212, 175, 55, 0.04) 1px, transparent 1px);
        }
    </style>
</head>
<body class="min-h-full bg-[#0A0A0C] text-slate-200 antialiased font-sans flex flex-col relative overflow-x-hidden selection:bg-[#D4AF37] selection:text-black">

    <!-- Ambient Glow Orbs -->
    <div class="fixed top-[-10%] left-1/2 transform -translate-x-1/2 w-[800px] h-[450px] bg-[#D4AF37]/10 rounded-full blur-[140px] pointer-events-none z-0"></div>
    <div class="fixed bottom-[-10%] right-[-10%] w-[600px] h-[400px] bg-[#FF0055]/8 rounded-full blur-[160px] pointer-events-none z-0"></div>
    <div class="fixed bottom-[-10%] left-[-10%] w-[600px] h-[400px] bg-[#00F0FF]/8 rounded-full blur-[160px] pointer-events-none z-0"></div>

    <!-- Background Grid Pattern -->
    <div class="fixed inset-0 grid-bg pointer-events-none z-0"></div>

    <!-- Navigation Bar -->
    <nav class="w-full bg-[#121316]/90 border-b border-white/5 backdrop-blur-md sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
            <!-- Brand -->
            <a href="/" class="flex items-center space-x-3 group">
                <div class="w-10 h-10 rounded-xl bg-gold-metallic flex items-center justify-center font-mono font-black text-black text-xl shadow-gold-glow transition-transform group-hover:scale-105">
                    Ω
                </div>
                <div class="flex flex-col">
                    <span class="text-lg font-black tracking-tight text-white uppercase italic flex items-center gap-1.5">
                        CYBER-RAIL
                        <span class="text-xs font-mono font-bold px-1.5 py-0.5 rounded bg-[#D4AF37]/20 text-[#D4AF37] border border-[#D4AF37]/40 not-italic">3D DUELS</span>
                    </span>
                    <span class="text-[10px] font-mono tracking-widest text-[#D4AF37] uppercase">CRYPTOGRAPHIC ESCROW ENGINE</span>
                </div>
            </a>

            <!-- Navigation Links -->
            <div class="hidden md:flex items-center space-x-8 text-xs font-mono tracking-wider text-slate-300">
                <a href="/game" class="hover:text-[#D4AF37] transition-colors uppercase font-bold flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full bg-[#10B981] animate-ping"></span>
                    LIVE ARENA
                </a>
                <a href="/lobby" class="hover:text-[#D4AF37] transition-colors uppercase font-bold">DUEL LOBBIES</a>
                <a href="#features" class="hover:text-[#D4AF37] transition-colors uppercase">ARCHITECTURE</a>
                <a href="#rules" class="hover:text-[#D4AF37] transition-colors uppercase">ANTI-CHEAT</a>
            </div>

            <!-- Header CTAs -->
            <div class="flex items-center space-x-4">
                @auth
                    <a href="/dashboard" class="hidden sm:flex flex-col text-right group">
                        <span class="text-[10px] font-mono text-slate-400 uppercase">{{ Auth::user()->name }}</span>
                        <span class="text-sm font-mono font-black text-gold-metallic group-hover:underline">
                            ${{ number_format((Auth::user()->wallet->balance_cents ?? 0) / 100, 2) }}
                        </span>
                    </a>
                    <a 
                        href="/dashboard" 
                        class="px-4 py-2 rounded-xl bg-luxury-glass border border-[#D4AF37]/30 hover:border-[#D4AF37] text-white font-mono font-bold text-xs uppercase tracking-wider transition-all"
                    >
                        DASHBOARD
                    </a>
                    <form method="POST" action="/logout" class="inline">
                        @csrf
                        <button type="submit" class="text-xs font-mono text-slate-400 hover:text-red-400 uppercase cursor-pointer">
                            LOGOUT
                        </button>
                    </form>
                @else
                    <a href="/login" class="text-xs font-mono font-bold text-slate-300 hover:text-[#D4AF37] uppercase tracking-wider">
                        SIGN IN
                    </a>
                    <a 
                        href="/register" 
                        class="px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 border border-white/10 text-white font-mono font-bold text-xs uppercase tracking-wider transition-all"
                    >
                        JOIN (+$100)
                    </a>
                    <a 
                        href="/login" 
                        class="px-5 py-2.5 rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow hover:scale-[1.03] transition-all duration-200 cursor-pointer"
                    >
                        LOGIN TO PLAY
                    </a>
                @endauth
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="relative z-10 pt-16 pb-20 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full flex flex-col items-center text-center">
        
        <!-- Live Badge -->
        <div class="inline-flex items-center space-x-2 px-4 py-1.5 rounded-full bg-luxury-glass border border-[#D4AF37]/30 shadow-gold-glow mb-8 animate-shimmer">
            <span class="w-2 h-2 rounded-full bg-[#10B981] shadow-[0_0_8px_#10B981]"></span>
            <span class="text-xs font-mono font-bold tracking-widest text-slate-200 uppercase">
                LARAVEL REVERB WEBSOCKET SERVER ACTIVE • 60HZ REPLAY VERIFIED
            </span>
        </div>

        <!-- Main Headline -->
        <h1 class="text-4xl sm:text-6xl lg:text-7xl font-sans font-black tracking-tight text-white uppercase italic max-w-5xl leading-[1.08]">
            HIGH-STAKES <span class="text-gold-metallic not-italic">1v1 SKILL RUNNER</span> ON THE CYBER-RAIL
        </h1>

        <!-- Subtitle -->
        <p class="mt-6 text-base sm:text-xl font-sans text-slate-400 max-w-3xl leading-relaxed">
            Head-to-head competitive 3D endless runner powered by deterministic WebGL physics, double-entry financial ledger escrow, and live opponent ghost runner telemetry.
        </p>

        <!-- Primary Actions -->
        <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4 w-full max-w-md">
            @auth
                <a 
                    href="/dashboard" 
                    class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-gold-metallic text-black font-mono font-black text-sm uppercase tracking-wider shadow-gold-glow-lg hover:scale-105 transition-all text-center flex items-center justify-center gap-2 cursor-pointer"
                >
                    <svg class="w-5 h-5 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                    </svg>
                    COMMAND CENTER (USER AREA)
                </a>
                
                <a 
                    href="/game" 
                    class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-luxury-glass border border-[#D4AF37]/40 hover:border-[#D4AF37] text-white font-mono font-bold text-sm uppercase tracking-wider transition-all text-center hover:shadow-gold-glow cursor-pointer"
                >
                    ENTER 3D ARENA NOW
                </a>
            @else
                <a 
                    href="/login" 
                    class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-gold-metallic text-black font-mono font-black text-sm uppercase tracking-wider shadow-gold-glow-lg hover:scale-105 transition-all text-center flex items-center justify-center gap-2 cursor-pointer"
                >
                    <svg class="w-5 h-5 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                    </svg>
                    SIGN IN TO COMPETE
                </a>
                
                <a 
                    href="/register" 
                    class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-luxury-glass border border-white/10 hover:border-[#D4AF37]/50 text-white font-mono font-bold text-sm uppercase tracking-wider transition-all text-center hover:shadow-cyan-glow cursor-pointer"
                >
                    JOIN & GET $100 BONUS
                </a>
            @endauth
        </div>

        <!-- Sponsor Placement -->
        <div class="w-full max-w-4xl mt-12">
            <x-ad-banner />
        </div>

        <!-- Performance & Financial Metric Counters -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 w-full max-w-5xl mt-14">
            <div class="p-5 rounded-2xl bg-luxury-glass border border-white/5 text-center flex flex-col justify-center">
                <span class="text-3xl sm:text-4xl font-mono font-black text-gold-metallic">$450,000+</span>
                <span class="text-[11px] font-mono uppercase tracking-widest text-slate-400 mt-1">Escrow Settled</span>
            </div>
            <div class="p-5 rounded-2xl bg-luxury-glass border border-white/5 text-center flex flex-col justify-center">
                <span class="text-3xl sm:text-4xl font-mono font-black text-[#10B981]">0.00 ms</span>
                <span class="text-[11px] font-mono uppercase tracking-widest text-slate-400 mt-1">PRNG Seed Drift</span>
            </div>
            <div class="p-5 rounded-2xl bg-luxury-glass border border-white/5 text-center flex flex-col justify-center">
                <span class="text-3xl sm:text-4xl font-mono font-black text-[#00F0FF]">4–5 Hz</span>
                <span class="text-[11px] font-mono uppercase tracking-widest text-slate-400 mt-1">Bandwidth Throttle</span>
            </div>
            <div class="p-5 rounded-2xl bg-luxury-glass border border-white/5 text-center flex flex-col justify-center">
                <span class="text-3xl sm:text-4xl font-mono font-black text-[#FFB800]">60 FPS</span>
                <span class="text-[11px] font-mono uppercase tracking-widest text-slate-400 mt-1">Zero-Alloc Engine</span>
            </div>
        </div>

    </section>

    <!-- Live Open Duels Section -->
    <section class="relative z-10 py-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full border-t border-white/5">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-8">
            <div>
                <h2 class="text-2xl sm:text-3xl font-black text-white uppercase italic tracking-tight">
                    FEATURED DUEL LOBBIES
                </h2>
                <p class="text-xs font-mono text-slate-400 mt-1">
                    Select a match to lock your stake into escrow and challenge live opponents.
                </p>
            </div>
            <a href="/lobby" class="text-xs font-mono font-bold text-[#D4AF37] hover:underline uppercase tracking-wider flex items-center gap-1">
                VIEW ALL LOBBIES &rarr;
            </a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <!-- Card 1 -->
            <div class="p-6 rounded-3xl bg-[#121316] border border-white/5 hover:border-[#D4AF37]/40 shadow-lg hover:shadow-gold-glow transition-all duration-300 flex flex-col justify-between space-y-5">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <img src="https://ui-avatars.com/api/?name=CyberBlade&background=FF0055&color=fff" class="w-11 h-11 rounded-xl object-cover ring-1 ring-white/10" alt="CyberBlade" />
                        <div>
                            <span class="text-sm font-bold text-white font-mono uppercase">CYBER_BLADE</span>
                            <div class="text-[11px] font-mono text-slate-400">WIN RATE: <span class="text-[#10B981]">76%</span> (180 RUNS)</div>
                        </div>
                    </div>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-emerald-500/10 text-[#10B981] border border-emerald-500/30 uppercase">
                        OPEN
                    </span>
                </div>

                <div class="p-4 rounded-xl bg-[#0A0A0C] border border-white/5 flex items-center justify-between">
                    <div>
                        <span class="text-[10px] font-mono text-slate-400 uppercase">STAKE</span>
                        <div class="text-lg font-mono font-black text-white">$25.00</div>
                    </div>
                    <div class="text-right">
                        <span class="text-[10px] font-mono text-[#D4AF37] uppercase">ESCROW POT</span>
                        <div class="text-lg font-mono font-black text-gold-metallic">$50.00</div>
                    </div>
                </div>

                <a 
                    href="/game?stake=2500" 
                    class="block w-full py-3 text-center rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow hover:scale-[1.01] transition-transform"
                >
                    ACCEPT DUEL ($25.00)
                </a>
            </div>

            <!-- Card 2 -->
            <div class="p-6 rounded-3xl bg-[#121316] border border-[#D4AF37]/30 shadow-gold-glow transition-all duration-300 flex flex-col justify-between space-y-5 relative overflow-hidden">
                <div class="absolute -top-3 -right-8 bg-[#D4AF37] text-black text-[9px] font-mono font-bold uppercase py-1 px-8 rotate-45">
                    HIGH ROLLER
                </div>
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <img src="https://ui-avatars.com/api/?name=Viper&background=D4AF37&color=000" class="w-11 h-11 rounded-xl object-cover ring-2 ring-[#D4AF37]/50" alt="Viper" />
                        <div>
                            <span class="text-sm font-bold text-white font-mono uppercase">VIPER_ELITE</span>
                            <div class="text-[11px] font-mono text-slate-400">WIN RATE: <span class="text-[#10B981]">84%</span> (420 RUNS)</div>
                        </div>
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-[#0A0A0C] border border-[#D4AF37]/20 flex items-center justify-between">
                    <div>
                        <span class="text-[10px] font-mono text-slate-400 uppercase">STAKE</span>
                        <div class="text-lg font-mono font-black text-white">$100.00</div>
                    </div>
                    <div class="text-right">
                        <span class="text-[10px] font-mono text-[#D4AF37] uppercase">ESCROW POT</span>
                        <div class="text-lg font-mono font-black text-gold-metallic">$200.00</div>
                    </div>
                </div>

                <a 
                    href="/game?stake=10000" 
                    class="block w-full py-3 text-center rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow-lg hover:scale-[1.01] transition-transform"
                >
                    ACCEPT DUEL ($100.00)
                </a>
            </div>

            <!-- Card 3 -->
            <div class="p-6 rounded-3xl bg-[#121316] border border-white/5 hover:border-[#D4AF37]/40 shadow-lg hover:shadow-gold-glow transition-all duration-300 flex flex-col justify-between space-y-5">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <img src="https://ui-avatars.com/api/?name=GhostRider&background=00F0FF&color=000" class="w-11 h-11 rounded-xl object-cover ring-1 ring-white/10" alt="GhostRider" />
                        <div>
                            <span class="text-sm font-bold text-white font-mono uppercase">GHOST_RIDER</span>
                            <div class="text-[11px] font-mono text-slate-400">WIN RATE: <span class="text-[#10B981]">71%</span> (95 RUNS)</div>
                        </div>
                    </div>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-emerald-500/10 text-[#10B981] border border-emerald-500/30 uppercase">
                        OPEN
                    </span>
                </div>

                <div class="p-4 rounded-xl bg-[#0A0A0C] border border-white/5 flex items-center justify-between">
                    <div>
                        <span class="text-[10px] font-mono text-slate-400 uppercase">STAKE</span>
                        <div class="text-lg font-mono font-black text-white">$50.00</div>
                    </div>
                    <div class="text-right">
                        <span class="text-[10px] font-mono text-[#D4AF37] uppercase">ESCROW POT</span>
                        <div class="text-lg font-mono font-black text-gold-metallic">$100.00</div>
                    </div>
                </div>

                <a 
                    href="/game?stake=5000" 
                    class="block w-full py-3 text-center rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow hover:scale-[1.01] transition-transform"
                >
                    ACCEPT DUEL ($50.00)
                </a>
            </div>
        </div>
    </section>

    <!-- Platform Architecture & Tech Highlights -->
    <section id="features" class="relative z-10 py-16 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto w-full border-t border-white/5">
        <div class="text-center max-w-3xl mx-auto mb-14">
            <span class="text-xs font-mono font-bold tracking-widest text-[#D4AF37] uppercase">CORE ARCHITECTURE</span>
            <h2 class="text-3xl sm:text-4xl font-black text-white uppercase italic tracking-tight mt-2">
                FOUR PILLARS OF COMPETITIVE INTEGRITY
            </h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div class="p-6 rounded-3xl bg-luxury-glass border border-white/5 space-y-3">
                <div class="w-10 h-10 rounded-xl bg-[#D4AF37]/20 border border-[#D4AF37]/40 flex items-center justify-center text-[#D4AF37] font-mono font-bold">
                    01
                </div>
                <h3 class="text-base font-bold text-white uppercase font-mono">DETERMINISTIC SEEDS</h3>
                <p class="text-xs text-slate-400 leading-relaxed">
                    Server-supplied 64-char seeds drive Mulberry32 PRNG. Two clients spawn identical track tiles, hurdles, trains, and collectibles with zero RNG deviation.
                </p>
            </div>

            <div class="p-6 rounded-3xl bg-luxury-glass border border-white/5 space-y-3">
                <div class="w-10 h-10 rounded-xl bg-[#10B981]/20 border border-[#10B981]/40 flex items-center justify-center text-[#10B981] font-mono font-bold">
                    02
                </div>
                <h3 class="text-base font-bold text-white uppercase font-mono">DOUBLE-ENTRY LEDGER</h3>
                <p class="text-xs text-slate-400 leading-relaxed">
                    Zero floating-point balance calculations. Escrow locking and winner settlements use pessimistic row locks (`lockForUpdate()`) and atomic transactions.
                </p>
            </div>

            <div class="p-6 rounded-3xl bg-luxury-glass border border-white/5 space-y-3">
                <div class="w-10 h-10 rounded-xl bg-[#00F0FF]/20 border border-[#00F0FF]/40 flex items-center justify-center text-[#00F0FF] font-mono font-bold">
                    03
                </div>
                <h3 class="text-base font-bold text-white uppercase font-mono">LARAVEL REVERB WEBSOCKETS</h3>
                <p class="text-xs text-slate-400 leading-relaxed">
                    Live duel presence channels with 4-5 Hz throttled telemetry and 60 FPS smooth Three.js ghost runner interpolation. Zero bandwidth saturation.
                </p>
            </div>

            <div class="p-6 rounded-3xl bg-luxury-glass border border-white/5 space-y-3">
                <div class="w-10 h-10 rounded-xl bg-[#FF0055]/20 border border-[#FF0055]/40 flex items-center justify-center text-[#FF0055] font-mono font-bold">
                    04
                </div>
                <h3 class="text-base font-bold text-white uppercase font-mono">HMAC & KINEMATIC AUDIT</h3>
                <p class="text-xs text-slate-400 leading-relaxed">
                    Single-use ephemeral run tickets. Every run payload is HMAC-SHA256 signed and verified against temporal, speed limit, and lane-switch physics models.
                </p>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="mt-auto border-t border-white/5 bg-[#08080A] py-12 px-4 sm:px-6 lg:px-8 text-xs font-mono text-slate-400">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center space-x-3">
                <div class="w-7 h-7 rounded-lg bg-gold-metallic flex items-center justify-center font-black text-black text-xs">
                    Ω
                </div>
                <span class="text-white font-bold tracking-wider uppercase">CYBER-RAIL ESCROW DUEL ENGINE</span>
            </div>

            <div class="flex items-center space-x-6 text-slate-400">
                <a href="/game" class="hover:text-[#D4AF37] transition-colors">PLAY ARENA</a>
                <a href="/lobby" class="hover:text-[#D4AF37] transition-colors">LOBBY</a>
                <a href="/api/v1/ads/active-creatives" class="hover:text-[#D4AF37] transition-colors" target="_blank">SPONSOR API</a>
            </div>

            <div class="flex items-center space-x-2 text-slate-400">
                <span class="w-2 h-2 rounded-full bg-[#10B981]"></span>
                <span>SYSTEM STATUS: 100% OPERATIONAL</span>
            </div>
        </div>
    </footer>

</body>
</html>
