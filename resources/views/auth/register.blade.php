<!DOCTYPE html>
<html lang="en" class="h-full bg-[#0A0A0C]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Combatant — Cyber-Rail Duels</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-[#0A0A0C] text-slate-200 antialiased font-sans flex flex-col justify-center py-12 sm:px-6 lg:px-8 relative overflow-x-hidden">

    <!-- Ambient Glows -->
    <div class="fixed top-1/4 left-1/2 -translate-x-1/2 w-[600px] h-[350px] bg-[#10B981]/10 rounded-full blur-[140px] pointer-events-none"></div>

    <div class="sm:mx-auto sm:w-full sm:max-w-md relative z-10 text-center">
        <a href="/" class="inline-flex items-center space-x-3 group">
            <div class="w-12 h-12 rounded-2xl bg-gold-metallic flex items-center justify-center font-mono font-black text-black text-2xl shadow-gold-glow group-hover:scale-105 transition-transform">
                Ω
            </div>
        </a>
        <h2 class="mt-4 text-3xl font-black tracking-tight text-white uppercase italic">
            JOIN CYBER-RAIL ARENA
        </h2>
        <p class="mt-1 text-xs font-mono text-[#10B981] uppercase tracking-wider font-bold">
            + $100.00 Welcome Escrow Bonus Automatically Funded
        </p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md px-4 relative z-10">
        <div class="bg-[#121316]/90 backdrop-blur-xl py-8 px-6 sm:px-10 rounded-3xl border border-white/5 shadow-2xl space-y-6">
            
            @if ($errors->any())
                <div class="p-4 rounded-xl bg-red-950/40 border border-red-500/40 text-xs font-mono text-red-400">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="/register" class="space-y-5">
                @csrf

                <div>
                    <label for="name" class="block text-xs font-mono uppercase tracking-wider text-slate-300">Player Call-Sign / Name</label>
                    <div class="mt-1.5">
                        <input id="name" name="name" type="text" autocomplete="name" required value="{{ old('name') }}" class="w-full rounded-xl bg-[#0A0A0C] border border-white/10 px-4 py-3 text-sm text-white focus:border-[#D4AF37] focus:ring-1 focus:ring-[#D4AF37] outline-none font-mono transition-colors" placeholder="ViperStrike" />
                    </div>
                </div>

                <div>
                    <label for="email" class="block text-xs font-mono uppercase tracking-wider text-slate-300">Email Address</label>
                    <div class="mt-1.5">
                        <input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}" class="w-full rounded-xl bg-[#0A0A0C] border border-white/10 px-4 py-3 text-sm text-white focus:border-[#D4AF37] focus:ring-1 focus:ring-[#D4AF37] outline-none font-mono transition-colors" placeholder="player@cyber-rail.gg" />
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-xs font-mono uppercase tracking-wider text-slate-300">Secret Passkey</label>
                    <div class="mt-1.5">
                        <input id="password" name="password" type="password" autocomplete="new-password" required class="w-full rounded-xl bg-[#0A0A0C] border border-white/10 px-4 py-3 text-sm text-white focus:border-[#D4AF37] focus:ring-1 focus:ring-[#D4AF37] outline-none font-mono transition-colors" placeholder="••••••••" />
                    </div>
                </div>

                <div>
                    <label for="password_confirmation" class="block text-xs font-mono uppercase tracking-wider text-slate-300">Confirm Passkey</label>
                    <div class="mt-1.5">
                        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="w-full rounded-xl bg-[#0A0A0C] border border-white/10 px-4 py-3 text-sm text-white focus:border-[#D4AF37] focus:ring-1 focus:ring-[#D4AF37] outline-none font-mono transition-colors" placeholder="••••••••" />
                    </div>
                </div>

                <div>
                    <button type="submit" class="w-full py-3.5 rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow hover:scale-[1.02] transition-transform cursor-pointer">
                        CREATE ACCOUNT & CLAIM $100
                    </button>
                </div>
            </form>

            <div class="pt-4 border-t border-white/5 text-center">
                <span class="text-xs text-slate-400">Already registered?</span>
                <a href="/login" class="text-xs font-mono font-bold text-[#D4AF37] hover:underline ml-1 uppercase">
                    Sign In
                </a>
            </div>
        </div>
    </div>

</body>
</html>
