<!DOCTYPE html>
<html lang="en" class="h-full bg-[#0A0A0C]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>High-Stakes Duel Lobby — Dark Luxury Cyber-Rail</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-[#0A0A0C] text-slate-200 antialiased font-sans flex flex-col">

    <!-- Top Navigation Bar -->
    <nav class="w-full bg-[#121316]/90 border-b border-white/5 backdrop-blur-md sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <a href="/dashboard" class="flex items-center space-x-3 group">
                    <div class="w-9 h-9 rounded-xl bg-gold-metallic flex items-center justify-center font-mono font-black text-black text-lg shadow-gold-glow group-hover:scale-105 transition-transform">
                        Ω
                    </div>
                    <div class="flex flex-col">
                        <span class="text-base font-black tracking-tight text-white uppercase italic">CYBER-RAIL DUELS</span>
                        <span class="text-[10px] font-mono tracking-widest text-[#D4AF37] uppercase">ESCROW TOURNAMENT ENGINE</span>
                    </div>
                </a>
            </div>

            <!-- Nav Links -->
            <div class="hidden sm:flex items-center space-x-6 text-xs font-mono">
                <a href="/dashboard" class="hover:text-[#D4AF37] text-slate-300 uppercase font-semibold flex items-center gap-1">
                    &larr; COMMAND CENTER
                </a>
                <a href="/lobby" class="text-[#D4AF37] uppercase font-bold border-b border-[#D4AF37] pb-0.5">MULTIPLAYER LOBBIES</a>
                <a href="/game" class="hover:text-[#D4AF37] text-slate-300 uppercase font-semibold">DIRECT ARENA</a>
            </div>

            <!-- User Balance & Profile -->
            <div class="flex items-center space-x-4">
                @auth
                    <a href="/dashboard" class="hidden sm:flex flex-col text-right group">
                        <span class="text-[10px] font-mono text-slate-400 uppercase tracking-wider">{{ Auth::user()->name }}</span>
                        <span class="text-sm font-mono font-black text-gold-metallic group-hover:underline">
                            ${{ number_format((Auth::user()->wallet->balance_cents ?? 0) / 100, 2) }}
                        </span>
                    </a>
                    <a href="/dashboard" class="w-10 h-10 rounded-xl bg-luxury-glass border border-[#D4AF37]/30 hover:border-[#D4AF37] flex items-center justify-center transition-all" title="Open Dashboard">
                        <img src="https://ui-avatars.com/api/?name={{ urlencode(Auth::user()->name) }}&background=D4AF37&color=000" class="w-8 h-8 rounded-lg object-cover" alt="User" />
                    </a>
                    <form method="POST" action="/logout" class="inline">
                        @csrf
                        <button type="submit" class="text-xs font-mono text-slate-400 hover:text-red-400 uppercase cursor-pointer">
                            LOGOUT
                        </button>
                    </form>
                @else
                    <a href="/login" class="text-xs font-mono font-bold text-slate-300 hover:text-[#D4AF37] uppercase">
                        SIGN IN
                    </a>
                    <a href="/register" class="px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 border border-white/10 text-white font-mono font-bold text-xs uppercase">
                        JOIN (+$100)
                    </a>
                @endauth
            </div>
        </div>
    </nav>

    <!-- Main Container -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

        <!-- Responsive Ad Banner -->
        <x-ad-banner />

        <!-- Header Actions: Active Duels & Create Duel -->
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pt-2">
            <div>
                <h1 class="text-2xl sm:text-3xl font-black text-white uppercase tracking-tight flex items-center gap-2">
                    ACTIVE DUEL ARENAS
                    <span class="text-xs font-mono font-bold px-2 py-0.5 rounded-full bg-emerald-500/10 text-[#10B981] border border-emerald-500/30">
                        LIVE 1v1
                    </span>
                </h1>
                <p class="text-xs font-mono text-slate-400 mt-1">
                    Zero latency, server-verified deterministic replay escrow duels.
                </p>
            </div>

            <button 
                id="btn-open-create-modal"
                type="button"
                onclick="document.getElementById('create-lobby-modal').classList.remove('hidden');"
                class="px-5 py-2.5 rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow hover:scale-[1.02] transition-transform cursor-pointer"
            >
                + CREATE HIGH-STAKES LOBBY
            </button>
        </div>

        <!-- Duels Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            
            <!-- Match Card 1 ($50.00 Stake) -->
            <div class="rounded-2xl bg-[#121316] border border-white/5 hover:border-[#D4AF37]/40 p-5 space-y-4 shadow-lg transition-all duration-300 hover:shadow-gold-glow group">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <img src="https://ui-avatars.com/api/?name=Viper&background=FF0055&color=fff" class="w-10 h-10 rounded-xl object-cover ring-1 ring-white/10" alt="Viper" />
                        <div>
                            <div class="text-sm font-bold text-white uppercase font-mono">VIPER_99</div>
                            <div class="text-[11px] font-mono text-slate-400">WIN RATE: <span class="text-[#10B981]">74%</span> (142 DUELS)</div>
                        </div>
                    </div>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-white/5 text-[#D4AF37] border border-[#D4AF37]/20 uppercase">
                        WAITING
                    </span>
                </div>

                <div class="p-3.5 rounded-xl bg-[#0A0A0C] border border-white/5 flex items-center justify-between">
                    <div>
                        <div class="text-[10px] font-mono text-slate-400 uppercase">STAKE PER RUN</div>
                        <div class="text-lg font-mono font-black text-white">$50.00</div>
                    </div>
                    <div class="text-right">
                        <div class="text-[10px] font-mono text-[#D4AF37] uppercase">TOTAL ESCROW POT</div>
                        <div class="text-lg font-mono font-black text-gold-metallic">$100.00</div>
                    </div>
                </div>

                <a 
                    href="/game?seed=e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855&stake=5000" 
                    class="block w-full py-2.5 text-center rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow hover:scale-[1.01] transition-all"
                >
                    ACCEPT DUEL ($50.00)
                </a>
            </div>

            <!-- Match Card 2 ($25.00 Stake) -->
            <div class="rounded-2xl bg-[#121316] border border-white/5 hover:border-[#D4AF37]/40 p-5 space-y-4 shadow-lg transition-all duration-300 hover:shadow-gold-glow group">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <img src="https://ui-avatars.com/api/?name=CyberGhost&background=00F0FF&color=000" class="w-10 h-10 rounded-xl object-cover ring-1 ring-white/10" alt="CyberGhost" />
                        <div>
                            <div class="text-sm font-bold text-white uppercase font-mono">CYBER_GHOST</div>
                            <div class="text-[11px] font-mono text-slate-400">WIN RATE: <span class="text-[#10B981]">68%</span> (89 DUELS)</div>
                        </div>
                    </div>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-white/5 text-[#D4AF37] border border-[#D4AF37]/20 uppercase">
                        WAITING
                    </span>
                </div>

                <div class="p-3.5 rounded-xl bg-[#0A0A0C] border border-white/5 flex items-center justify-between">
                    <div>
                        <div class="text-[10px] font-mono text-slate-400 uppercase">STAKE PER RUN</div>
                        <div class="text-lg font-mono font-black text-white">$25.00</div>
                    </div>
                    <div class="text-right">
                        <div class="text-[10px] font-mono text-[#D4AF37] uppercase">TOTAL ESCROW POT</div>
                        <div class="text-lg font-mono font-black text-gold-metallic">$50.00</div>
                    </div>
                </div>

                <a 
                    href="/game?seed=8f4a13b62c7e09d5a1b3c8e4f6a7d9021b3c4d5e6f7a8b9c0d1e2f3a4b5c6d7e&stake=2500" 
                    class="block w-full py-2.5 text-center rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow hover:scale-[1.01] transition-all"
                >
                    ACCEPT DUEL ($25.00)
                </a>
            </div>

            <!-- Match Card 3 ($100.00 High-Roller Stake) -->
            <div class="rounded-2xl bg-[#121316] border border-[#D4AF37]/30 hover:border-[#D4AF37]/60 p-5 space-y-4 shadow-gold-glow transition-all duration-300 group relative overflow-hidden">
                <div class="absolute -top-3 -right-8 bg-[#D4AF37] text-black text-[9px] font-mono font-bold uppercase py-1 px-8 rotate-45">
                    HIGH ROLLER
                </div>

                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <img src="https://ui-avatars.com/api/?name=ApexTitan&background=D4AF37&color=000" class="w-10 h-10 rounded-xl object-cover ring-2 ring-[#D4AF37]/50" alt="ApexTitan" />
                        <div>
                            <div class="text-sm font-bold text-white uppercase font-mono">APEX_TITAN</div>
                            <div class="text-[11px] font-mono text-slate-400">WIN RATE: <span class="text-[#10B981]">82%</span> (310 DUELS)</div>
                        </div>
                    </div>
                </div>

                <div class="p-3.5 rounded-xl bg-[#0A0A0C] border border-[#D4AF37]/20 flex items-center justify-between">
                    <div>
                        <div class="text-[10px] font-mono text-slate-400 uppercase">STAKE PER RUN</div>
                        <div class="text-lg font-mono font-black text-white">$100.00</div>
                    </div>
                    <div class="text-right">
                        <div class="text-[10px] font-mono text-[#D4AF37] uppercase">TOTAL ESCROW POT</div>
                        <div class="text-lg font-mono font-black text-gold-metallic">$200.00</div>
                    </div>
                </div>

                <a 
                    href="/game?seed=c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4&stake=10000" 
                    class="block w-full py-2.5 text-center rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow-lg hover:scale-[1.01] transition-all"
                >
                    ACCEPT DUEL ($100.00)
                </a>
            </div>

        </div>

    </main>

    <!-- Create Lobby Modal -->
    <div id="create-lobby-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-md hidden">
        <div class="w-full max-w-md rounded-3xl bg-[#121316] border border-[#D4AF37]/30 shadow-gold-glow p-6 space-y-5">
            <div class="flex items-center justify-between border-b border-white/10 pb-3">
                <h3 class="text-lg font-black text-white uppercase tracking-tight">CREATE DUEL LOBBY</h3>
                <button type="button" onclick="document.getElementById('create-lobby-modal').classList.add('hidden');" class="text-slate-400 hover:text-white cursor-pointer">&times;</button>
            </div>

            <div class="space-y-3">
                <label class="block text-xs font-mono uppercase text-slate-300">SELECT ESCROW STAKE</label>
                <div class="grid grid-cols-3 gap-3">
                    <button type="button" class="py-2.5 rounded-xl border border-[#D4AF37] bg-[#D4AF37]/10 text-white font-mono font-bold text-sm hover:bg-[#D4AF37]/20">$25.00</button>
                    <button type="button" class="py-2.5 rounded-xl border border-white/10 bg-white/5 text-white font-mono font-bold text-sm hover:border-[#D4AF37]">$50.00</button>
                    <button type="button" class="py-2.5 rounded-xl border border-white/10 bg-white/5 text-white font-mono font-bold text-sm hover:border-[#D4AF37]">$100.00</button>
                </div>
            </div>

            <div class="pt-2">
                <a 
                    href="/game?stake=5000" 
                    class="block w-full py-3 text-center rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow cursor-pointer"
                >
                    CONFIRM & LOCK STAKE
                </a>
            </div>
        </div>
    </div>

</body>
</html>
