<!DOCTYPE html>
<html lang="en" class="h-full bg-[#0A0A0C]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Combatant Command Center — Cyber-Rail Duels</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-[#0A0A0C] text-slate-200 antialiased font-sans flex flex-col">

    <!-- Top Navigation Bar -->
    <nav class="w-full bg-[#121316]/90 border-b border-white/5 backdrop-blur-md sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-6">
                <a href="/" class="flex items-center space-x-3 group">
                    <div class="w-9 h-9 rounded-xl bg-gold-metallic flex items-center justify-center font-mono font-black text-black text-lg shadow-gold-glow group-hover:scale-105 transition-transform">
                        Ω
                    </div>
                    <div class="flex flex-col">
                        <span class="text-base font-black tracking-tight text-white uppercase italic">CYBER-RAIL</span>
                        <span class="text-[9px] font-mono tracking-widest text-[#D4AF37] uppercase">COMMAND CENTER</span>
                    </div>
                </a>

                <div class="hidden sm:flex items-center space-x-4 text-xs font-mono">
                    <a href="/game" class="hover:text-[#D4AF37] text-slate-300 uppercase font-semibold">ARENA</a>
                    <a href="/lobby" class="hover:text-[#D4AF37] text-slate-300 uppercase font-semibold">LOBBIES</a>
                    <a href="/dashboard" class="text-[#D4AF37] uppercase font-bold border-b border-[#D4AF37] pb-0.5">DASHBOARD</a>
                </div>
            </div>

            <!-- Profile & Logout -->
            <div class="flex items-center space-x-4">
                <div class="flex flex-col text-right">
                    <span class="text-xs font-mono font-bold text-white uppercase">{{ $user->name }}</span>
                    <span class="text-[10px] font-mono text-[#D4AF37]">${{ number_format(($wallet->balance_cents ?? 0) / 100, 2) }}</span>
                </div>
                <form method="POST" action="/logout">
                    @csrf
                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-white/5 hover:bg-red-950/40 border border-white/10 hover:border-red-500/40 text-[11px] font-mono uppercase tracking-wider text-slate-400 hover:text-red-400 transition-colors cursor-pointer">
                        LOGOUT
                    </button>
                </form>
            </div>
        </div>
    </nav>

    <!-- Main Container -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

        @if (session('status'))
            <div class="p-4 rounded-2xl bg-emerald-950/40 border border-emerald-500/40 text-xs font-mono text-emerald-300 flex items-center justify-between">
                <span>{{ session('status') }}</span>
                <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
            </div>
        @endif

        <!-- 1. WALLET & CAREER FINANCIALS -->
        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-black text-white uppercase italic tracking-tight flex items-center gap-2">
                    VAULT BALANCES & ESCROW
                </h2>
                <button 
                    type="button" 
                    onclick="document.getElementById('deposit-modal').classList.remove('hidden');" 
                    class="px-4 py-2 rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow hover:scale-[1.02] transition-transform cursor-pointer"
                >
                    + DEPOSIT FUNDS
                </button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <!-- Spendable Balance -->
                <div class="p-6 rounded-2xl bg-[#121316] border border-white/5 relative overflow-hidden flex flex-col justify-between shadow-lg">
                    <span class="text-xs font-mono uppercase tracking-wider text-slate-400">AVAILABLE SPENDABLE</span>
                    <div class="text-3xl sm:text-4xl font-mono font-black text-gold-metallic my-2">
                        ${{ number_format(($wallet->balance_cents ?? 0) / 100, 2) }}
                    </div>
                    <span class="text-[10px] font-mono text-slate-500 uppercase">Double-Entry Pessimistic Lock Protected</span>
                </div>

                <!-- Escrow Locked -->
                <div class="p-6 rounded-2xl bg-[#121316] border border-white/5 relative overflow-hidden flex flex-col justify-between shadow-lg">
                    <span class="text-xs font-mono uppercase tracking-wider text-slate-400">IN ACTIVE ESCROW</span>
                    <div class="text-3xl sm:text-4xl font-mono font-black text-white my-2">
                        ${{ number_format(($wallet->locked_balance_cents ?? 0) / 100, 2) }}
                    </div>
                    <span class="text-[10px] font-mono text-slate-500 uppercase">Committed To Live Arena Stakes</span>
                </div>

                <!-- Career Net Winnings -->
                <div class="p-6 rounded-2xl bg-[#121316] border border-[#10B981]/20 relative overflow-hidden flex flex-col justify-between shadow-lg">
                    <span class="text-xs font-mono uppercase tracking-wider text-[#10B981]">TOTAL CAREER PAYOUTS</span>
                    <div class="text-3xl sm:text-4xl font-mono font-black text-[#10B981] my-2">
                        ${{ number_format(($totalWinningsCents ?? 0) / 100, 2) }}
                    </div>
                    <span class="text-[10px] font-mono text-slate-500 uppercase">Settled Run Earnings</span>
                </div>
            </div>
        </section>

        <!-- 2. COMBAT METRICS -->
        <section class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="p-4 rounded-xl bg-white/5 border border-white/5 text-center">
                <span class="text-2xl sm:text-3xl font-mono font-black text-white">{{ $totalMatches ?? 0 }}</span>
                <div class="text-[10px] font-mono uppercase text-slate-400 mt-1">DUELS COMPLETED</div>
            </div>
            <div class="p-4 rounded-xl bg-white/5 border border-white/5 text-center">
                <span class="text-2xl sm:text-3xl font-mono font-black text-[#10B981]">{{ $wins ?? 0 }}</span>
                <div class="text-[10px] font-mono uppercase text-slate-400 mt-1">VICTORIES</div>
            </div>
            <div class="p-4 rounded-xl bg-white/5 border border-white/5 text-center">
                <span class="text-2xl sm:text-3xl font-mono font-black text-[#D4AF37]">{{ $winRate ?? 0 }}%</span>
                <div class="text-[10px] font-mono uppercase text-slate-400 mt-1">WIN RATE</div>
            </div>
            <div class="p-4 rounded-xl bg-white/5 border border-white/5 text-center">
                <span class="text-2xl sm:text-3xl font-mono font-black text-[#00F0FF]">ACTIVE</span>
                <div class="text-[10px] font-mono uppercase text-slate-400 mt-1">STATUS</div>
            </div>
        </section>

        <!-- INSTANT COMBAT LAUNCHER (USER AREA EXCLUSIVE) -->
        <section class="p-6 sm:p-8 rounded-3xl bg-gradient-to-br from-[#16181F] to-[#0D0E12] border border-[#D4AF37]/30 shadow-gold-glow relative overflow-hidden">
            <div class="absolute -right-20 -top-20 w-80 h-80 bg-[#D4AF37]/10 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute -left-20 -bottom-20 w-80 h-80 bg-[#00F0FF]/10 rounded-full blur-3xl pointer-events-none"></div>

            <div class="relative z-10 space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div>
                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-[#D4AF37]/15 border border-[#D4AF37]/30 text-[10px] font-mono font-bold text-[#D4AF37] uppercase tracking-wider mb-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-[#10B981] animate-ping"></span>
                            AUTHENTICATED COMBATANT PRIVILEGE
                        </div>
                        <h2 class="text-2xl sm:text-3xl font-sans font-black text-white uppercase italic tracking-tight">
                            LAUNCH INSTANT 1v1 DUEL ARENA
                        </h2>
                        <p class="text-xs font-mono text-slate-400 mt-1">
                            Lock your wager in double-entry escrow and enter the Ultra HD 3D Cyber-Rail.
                        </p>
                    </div>

                    <div class="text-right sm:self-center">
                        <span class="text-[10px] font-mono text-slate-400 uppercase">AVAILABLE BALANCE</span>
                        <div class="text-xl font-mono font-black text-gold-metallic">
                            ${{ number_format(($wallet->balance_cents ?? 0) / 100, 2) }}
                        </div>
                    </div>
                </div>

                <!-- Stake Selector Form -->
                <form id="instant-duel-form" method="GET" action="/game" class="space-y-4">
                    <div>
                        <label class="block text-xs font-mono uppercase text-slate-400 mb-2">CHOOSE STAKE TIER</label>
                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3" id="stake-tier-group">
                            <button type="button" data-stake="1000" data-pot="2000" class="stake-tier-btn p-3.5 rounded-2xl bg-[#0A0A0C] border border-white/10 hover:border-[#D4AF37] text-left transition-all cursor-pointer">
                                <div class="text-xs font-mono text-slate-400">TIER I</div>
                                <div class="text-lg font-mono font-black text-white">$10.00</div>
                                <div class="text-[10px] font-mono text-[#D4AF37] mt-0.5">Pot: $20.00</div>
                            </button>
                            <button type="button" data-stake="2500" data-pot="5000" class="stake-tier-btn p-3.5 rounded-2xl bg-[#0A0A0C] border border-white/10 hover:border-[#D4AF37] text-left transition-all cursor-pointer">
                                <div class="text-xs font-mono text-slate-400">TIER II</div>
                                <div class="text-lg font-mono font-black text-white">$25.00</div>
                                <div class="text-[10px] font-mono text-[#D4AF37] mt-0.5">Pot: $50.00</div>
                            </button>
                            <button type="button" data-stake="5000" data-pot="10000" class="stake-tier-btn active-stake p-3.5 rounded-2xl bg-[#0A0A0C] border-2 border-[#D4AF37] text-left shadow-gold-glow transition-all cursor-pointer">
                                <div class="text-xs font-mono text-[#D4AF37] font-bold">POPULAR</div>
                                <div class="text-lg font-mono font-black text-gold-metallic">$50.00</div>
                                <div class="text-[10px] font-mono text-[#D4AF37] mt-0.5">Pot: $100.00</div>
                            </button>
                            <button type="button" data-stake="10000" data-pot="20000" class="stake-tier-btn p-3.5 rounded-2xl bg-[#0A0A0C] border border-white/10 hover:border-[#D4AF37] text-left transition-all cursor-pointer">
                                <div class="text-xs font-mono text-slate-400">HIGH ROLLER</div>
                                <div class="text-lg font-mono font-black text-white">$100.00</div>
                                <div class="text-[10px] font-mono text-[#D4AF37] mt-0.5">Pot: $200.00</div>
                            </button>
                            <button type="button" data-stake="25000" data-pot="50000" class="stake-tier-btn p-3.5 rounded-2xl bg-[#0A0A0C] border border-white/10 hover:border-[#D4AF37] text-left transition-all cursor-pointer">
                                <div class="text-xs font-mono text-[#FF0055] font-bold">TITAN</div>
                                <div class="text-lg font-mono font-black text-white">$250.00</div>
                                <div class="text-[10px] font-mono text-[#D4AF37] mt-0.5">Pot: $500.00</div>
                            </button>
                        </div>
                        <input type="hidden" name="stake" id="selected-stake-input" value="5000" />
                    </div>

                    <div class="flex flex-col sm:flex-row items-center gap-4 pt-2">
                        <button 
                            type="submit" 
                            class="w-full sm:w-auto px-8 py-4 rounded-2xl bg-gold-metallic text-black font-mono font-black text-sm uppercase tracking-wider shadow-gold-glow hover:scale-[1.02] transition-transform flex items-center justify-center gap-2 cursor-pointer"
                        >
                            <svg class="w-5 h-5 text-black" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            ENTER 3D ARENA NOW
                        </button>

                        <a 
                            href="/lobby" 
                            class="w-full sm:w-auto px-6 py-4 rounded-2xl bg-luxury-glass border border-white/15 hover:border-[#D4AF37] text-white font-mono font-bold text-xs uppercase tracking-wider text-center transition-all cursor-pointer"
                        >
                            BROWSE MULTIPLAYER LOBBIES &rarr;
                        </a>
                    </div>
                </form>
            </div>

            <script>
                document.addEventListener('DOMContentLoaded', () => {
                    const buttons = document.querySelectorAll('.stake-tier-btn');
                    const stakeInput = document.getElementById('selected-stake-input');
                    buttons.forEach(btn => {
                        btn.addEventListener('click', () => {
                            buttons.forEach(b => {
                                b.classList.remove('border-2', 'border-[#D4AF37]', 'shadow-gold-glow', 'active-stake');
                                b.classList.add('border', 'border-white/10');
                            });
                            btn.classList.remove('border', 'border-white/10');
                            btn.classList.add('border-2', 'border-[#D4AF37]', 'shadow-gold-glow', 'active-stake');
                            if (stakeInput) stakeInput.value = btn.dataset.stake;
                        });
                    });
                });
            </script>
        </section>

        <!-- 3. ACTIVE DUELS -->
        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-black text-white uppercase italic tracking-tight flex items-center gap-2">
                    ACTIVE DUELS
                    <span class="text-xs font-mono font-bold px-2 py-0.5 rounded-full bg-emerald-500/10 text-[#10B981] border border-emerald-500/30">
                        {{ count($activeMatches) }} PENDING
                    </span>
                </h2>
                <a href="/lobby" class="text-xs font-mono font-bold text-[#D4AF37] hover:underline uppercase">
                    + CREATE NEW ARENA &rarr;
                </a>
            </div>

            @if (count($activeMatches) === 0)
                <div class="p-8 rounded-2xl bg-[#121316] border border-white/5 text-center space-y-3">
                    <p class="text-sm font-mono text-slate-400">You currently have no active or waiting duels.</p>
                    <a href="/lobby" class="inline-block px-5 py-2.5 rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow">
                        JOIN AN OPEN ARENA
                    </a>
                </div>
            @else
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach ($activeMatches as $match)
                        <div class="p-5 rounded-2xl bg-[#121316] border border-white/5 hover:border-[#D4AF37]/40 shadow-lg space-y-4 transition-all">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-[#10B981] animate-ping"></span>
                                    <span class="text-xs font-mono font-bold text-white uppercase">
                                        {{ $match->status->value }}
                                    </span>
                                </div>
                                <span class="text-[10px] font-mono text-slate-400">
                                    MATCH ID: {{ substr($match->uuid, 0, 8) }}...
                                </span>
                            </div>

                            <div class="flex items-center justify-between p-3.5 rounded-xl bg-[#0A0A0C] border border-white/5">
                                <div>
                                    <span class="text-[10px] font-mono text-slate-400 uppercase">STAKE</span>
                                    <div class="text-base font-mono font-black text-white">${{ number_format($match->stake_amount_cents / 100, 2) }}</div>
                                </div>
                                <div class="text-right">
                                    <span class="text-[10px] font-mono text-[#D4AF37] uppercase">ESCROW POT</span>
                                    <div class="text-base font-mono font-black text-gold-metallic">${{ number_format(($match->stake_amount_cents * 2) / 100, 2) }}</div>
                                </div>
                            </div>

                            <a 
                                href="/game?match={{ $match->uuid }}&seed={{ $match->game_seed }}&stake={{ $match->stake_amount_cents }}" 
                                class="block w-full py-2.5 text-center rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow hover:scale-[1.01] transition-transform"
                            >
                                ENTER ARENA (RESUME)
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <!-- 4. RESOLVED DUEL HISTORY -->
        <section class="space-y-4">
            <h2 class="text-xl font-black text-white uppercase italic tracking-tight">
                RESOLVED DUEL HISTORY & AUDIT REPLAYS
            </h2>

            @if (count($historyMatches) === 0)
                <div class="p-6 rounded-2xl bg-[#121316] border border-white/5 text-center text-xs font-mono text-slate-400">
                    No resolved duels recorded on your account yet. Complete a match in the arena to view audit telemetry.
                </div>
            @else
                <div class="overflow-x-auto rounded-2xl border border-white/5 bg-[#121316]">
                    <table class="w-full text-left text-xs font-mono">
                        <thead class="bg-black/40 text-slate-400 uppercase border-b border-white/5">
                            <tr>
                                <th class="px-5 py-3.5">Outcome</th>
                                <th class="px-5 py-3.5">Rival</th>
                                <th class="px-5 py-3.5">Stake</th>
                                <th class="px-5 py-3.5">Net Payout</th>
                                <th class="px-5 py-3.5">Date</th>
                                <th class="px-5 py-3.5 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/5 text-slate-200">
                            @foreach ($historyMatches as $hMatch)
                                @php
                                    $isWinner = $hMatch->winner_user_id === $user->id;
                                    $isDisputed = $hMatch->status->value === 'DISPUTED';
                                    $rival = $hMatch->creator_user_id === $user->id ? $hMatch->opponent : $hMatch->creator;
                                @endphp
                                <tr class="hover:bg-white/5 transition-colors">
                                    <td class="px-5 py-4">
                                        @if ($isDisputed)
                                            <span class="px-2 py-0.5 rounded bg-amber-500/10 text-amber-400 border border-amber-500/30 font-bold uppercase">
                                                DISPUTED
                                            </span>
                                        @elseif ($isWinner)
                                            <span class="px-2 py-0.5 rounded bg-emerald-500/10 text-[#10B981] border border-emerald-500/30 font-bold uppercase">
                                                VICTORY
                                            </span>
                                        @else
                                            <span class="px-2 py-0.5 rounded bg-red-500/10 text-red-400 border border-red-500/30 font-bold uppercase">
                                                DEFEAT
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-4 font-bold text-white">
                                        {{ $rival ? $rival->name : 'GHOST RUNNER' }}
                                    </td>
                                    <td class="px-5 py-4 text-slate-300">
                                        ${{ number_format($hMatch->stake_amount_cents / 100, 2) }}
                                    </td>
                                    <td class="px-5 py-4 font-bold {{ $isWinner ? 'text-gold-metallic' : 'text-slate-400' }}">
                                        {{ $isWinner ? '+$' . number_format($hMatch->winner_payout_cents / 100, 2) : '$0.00' }}
                                    </td>
                                    <td class="px-5 py-4 text-slate-500">
                                        {{ $hMatch->updated_at->format('M d, H:i') }}
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <a 
                                            href="/game?stake={{ $hMatch->stake_amount_cents }}" 
                                            class="px-3 py-1.5 rounded-lg bg-gold-metallic text-black font-black uppercase text-[10px] shadow-gold-glow hover:scale-105 transition-transform inline-block"
                                        >
                                            REMATCH
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

    </main>

    <!-- Deposit Simulation Modal -->
    <div id="deposit-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-md hidden">
        <div class="w-full max-w-md rounded-3xl bg-[#121316] border border-[#D4AF37]/40 shadow-gold-glow p-6 space-y-5">
            <div class="flex items-center justify-between border-b border-white/10 pb-3">
                <h3 class="text-lg font-black text-white uppercase tracking-tight">SIMULATE FUNDS DEPOSIT</h3>
                <button type="button" onclick="document.getElementById('deposit-modal').classList.add('hidden');" class="text-slate-400 hover:text-white cursor-pointer">&times;</button>
            </div>

            <form method="POST" action="/user/wallet/deposit" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-mono uppercase text-slate-300 mb-2">Select Amount to Credit</label>
                    <div class="grid grid-cols-3 gap-2">
                        <label class="cursor-pointer">
                            <input type="radio" name="amount_dollars" value="50" class="peer sr-only" checked />
                            <div class="p-3 text-center rounded-xl bg-[#0A0A0C] border border-white/10 peer-checked:border-[#D4AF37] peer-checked:bg-[#D4AF37]/10 text-white font-mono font-bold text-sm">
                                $50.00
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="amount_dollars" value="100" class="peer sr-only" />
                            <div class="p-3 text-center rounded-xl bg-[#0A0A0C] border border-white/10 peer-checked:border-[#D4AF37] peer-checked:bg-[#D4AF37]/10 text-white font-mono font-bold text-sm">
                                $100.00
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="amount_dollars" value="500" class="peer sr-only" />
                            <div class="p-3 text-center rounded-xl bg-[#0A0A0C] border border-white/10 peer-checked:border-[#D4AF37] peer-checked:bg-[#D4AF37]/10 text-white font-mono font-bold text-sm">
                                $500.00
                            </div>
                        </label>
                    </div>
                </div>

                <button type="submit" class="w-full py-3 text-center rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow cursor-pointer">
                    CONFIRM SIMULATED DEPOSIT
                </button>
            </form>
        </div>
    </div>

</body>
</html>
