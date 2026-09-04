<!DOCTYPE html>
<html lang="en" class="h-full bg-[#0A0A0C]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — Cyber-Rail Duels</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-[#0A0A0C] text-slate-200 antialiased font-sans flex flex-col justify-center py-12 sm:px-6 lg:px-8 relative overflow-x-hidden">

    <!-- Ambient Glows -->
    <div class="fixed top-1/4 left-1/2 -translate-x-1/2 w-[600px] h-[350px] bg-[#D4AF37]/10 rounded-full blur-[140px] pointer-events-none"></div>

    <div class="sm:mx-auto sm:w-full sm:max-w-md relative z-10 text-center">
        <a href="/" class="inline-flex items-center space-x-3 group">
            <div class="w-12 h-12 rounded-2xl bg-gold-metallic flex items-center justify-center font-mono font-black text-black text-2xl shadow-gold-glow group-hover:scale-105 transition-transform">
                Ω
            </div>
        </a>
        <h2 class="mt-4 text-3xl font-black tracking-tight text-white uppercase italic">
            SIGN IN TO CYBER-RAIL
        </h2>
        <p class="mt-1 text-xs font-mono text-slate-400 uppercase tracking-wider">
            High-Stakes Escrow Duel Arena
        </p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-xl px-4 relative z-10 space-y-6">

        <!-- Login Form Card -->
        <div class="bg-[#121316]/90 backdrop-blur-xl py-8 px-6 sm:px-10 rounded-3xl border border-white/5 shadow-2xl space-y-6">
            
            @if ($errors->any())
                <div class="p-4 rounded-xl bg-red-950/40 border border-red-500/40 text-xs font-mono text-red-400">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="/login" class="space-y-5">
                @csrf

                <div>
                    <label for="email" class="block text-xs font-mono uppercase tracking-wider text-slate-300">Email Address</label>
                    <div class="mt-1.5">
                        <input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}" class="w-full rounded-xl bg-[#0A0A0C] border border-white/10 px-4 py-3 text-sm text-white focus:border-[#D4AF37] focus:ring-1 focus:ring-[#D4AF37] outline-none font-mono transition-colors" placeholder="player@cyber-rail.gg" />
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-xs font-mono uppercase tracking-wider text-slate-300">Password</label>
                    <div class="mt-1.5">
                        <input id="password" name="password" type="password" autocomplete="current-password" required class="w-full rounded-xl bg-[#0A0A0C] border border-white/10 px-4 py-3 text-sm text-white focus:border-[#D4AF37] focus:ring-1 focus:ring-[#D4AF37] outline-none font-mono transition-colors" placeholder="••••••••" />
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <label class="flex items-center space-x-2 text-xs font-mono text-slate-400">
                        <input type="checkbox" name="remember" class="rounded bg-[#0A0A0C] border-white/10 text-[#D4AF37] focus:ring-[#D4AF37]" />
                        <span>Remember session</span>
                    </label>
                </div>

                <div>
                    <button id="btn-login-submit" type="submit" class="w-full py-3.5 rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow hover:scale-[1.02] transition-transform cursor-pointer">
                        ENTER DUEL ARENA
                    </button>
                </div>
            </form>

            <div class="pt-4 border-t border-white/5 text-center">
                <span class="text-xs text-slate-400">Don't have a combatant account?</span>
                <a href="/register" class="text-xs font-mono font-bold text-[#D4AF37] hover:underline ml-1 uppercase">
                    Register + Get $100 Bonus
                </a>
            </div>
        </div>

        <!-- One-Click Demo Accounts Switcher -->
        <div class="bg-[#121316]/70 backdrop-blur-md p-6 rounded-3xl border border-[#D4AF37]/20 space-y-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-2">
                    <span class="w-2 h-2 rounded-full bg-[#10B981] animate-ping"></span>
                    <span class="text-xs font-mono font-bold uppercase tracking-wider text-[#D4AF37]">
                        ONE-CLICK DEMO LOGIN
                    </span>
                </div>
                <span class="text-[10px] font-mono text-slate-400 uppercase">Pre-Funded Wallets</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach ($demoAccounts ?? [] as $demo)
                    <form method="POST" action="/login/demo">
                        @csrf
                        <input type="hidden" name="email" value="{{ $demo['email'] }}" />
                        <button type="submit" class="w-full p-3 rounded-2xl bg-[#0A0A0C] border border-white/5 hover:border-[#D4AF37]/50 flex items-center space-x-3 transition-all duration-200 hover:shadow-gold-glow group text-left cursor-pointer">
                            <img src="{{ $demo['avatar'] }}" alt="{{ $demo['name'] }}" class="w-10 h-10 rounded-xl object-cover ring-1 ring-white/10" />
                            <div class="flex-1 min-w-0">
                                <div class="text-xs font-bold text-white uppercase font-mono truncate group-hover:text-[#D4AF37]">
                                    {{ $demo['name'] }}
                                </div>
                                <div class="text-[10px] font-mono text-slate-400 truncate">
                                    {{ $demo['role'] }}
                                </div>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <span class="text-xs font-mono font-black text-gold-metallic">{{ $demo['balance'] }}</span>
                            </div>
                        </button>
                    </form>
                @endforeach
            </div>
        </div>

    </div>

</body>
</html>
