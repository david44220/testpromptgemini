<!DOCTYPE html>
<html lang="en" class="h-full bg-[#0A0A0C]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
                <a href="/game" class="hover:text-[#D4AF37] text-slate-300 uppercase font-semibold">PRACTICE ARENA</a>
            </div>

            <!-- User Balance & Profile -->
            <div class="flex items-center space-x-4">
                @auth
                    <a href="/dashboard" class="hidden sm:flex flex-col text-right group">
                        <span class="text-[10px] font-mono text-slate-400 uppercase tracking-wider">{{ Auth::user()->name }}</span>
                        <span id="nav-user-balance" class="text-sm font-mono font-black text-gold-metallic group-hover:underline">
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
                onclick="openCreateModal()"
                class="px-5 py-2.5 rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow hover:scale-[1.02] transition-transform cursor-pointer"
            >
                + CREATE HIGH-STAKES LOBBY
            </button>
        </div>

        <!-- Alert Notification Box -->
        <div id="lobby-alert-box" class="hidden p-4 rounded-xl font-mono text-xs font-semibold"></div>

        <!-- Duels Grid (Dynamic) -->
        <div id="duels-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
            <div id="lobbies-loading-state" class="col-span-full py-12 text-center text-slate-500 font-mono text-sm">
                SYNCHRONIZING PERSISTED ARENAS...
            </div>
        </div>

    </main>

    <!-- Create Lobby Modal -->
    <div id="create-lobby-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-md hidden">
        <div class="w-full max-w-md rounded-3xl bg-[#121316] border border-[#D4AF37]/30 shadow-gold-glow p-6 space-y-5">
            
            <div id="modal-create-step">
                <div class="flex items-center justify-between border-b border-white/10 pb-3">
                    <h3 class="text-lg font-black text-white uppercase tracking-tight">CREATE DUEL LOBBY</h3>
                    <button type="button" onclick="closeCreateModal()" class="text-slate-400 hover:text-white cursor-pointer">&times;</button>
                </div>

                <div class="space-y-4 pt-3">
                    <label class="block text-xs font-mono uppercase text-slate-300">SELECT ESCROW STAKE</label>
                    <div class="grid grid-cols-3 gap-3" id="stake-selector">
                        <button type="button" data-stake="2500" onclick="selectStake(2500, this)" class="stake-btn py-2.5 rounded-xl border border-white/10 bg-white/5 text-white font-mono font-bold text-sm hover:border-[#D4AF37]">$25.00</button>
                        <button type="button" data-stake="5000" onclick="selectStake(5000, this)" class="stake-btn py-2.5 rounded-xl border border-[#D4AF37] bg-[#D4AF37]/10 text-white font-mono font-bold text-sm hover:bg-[#D4AF37]/20">$50.00</button>
                        <button type="button" data-stake="10000" onclick="selectStake(10000, this)" class="stake-btn py-2.5 rounded-xl border border-white/10 bg-white/5 text-white font-mono font-bold text-sm hover:border-[#D4AF37]">$100.00</button>
                    </div>

                    <div id="create-error" class="hidden text-xs font-mono text-red-400 bg-red-950/40 p-3 rounded-xl border border-red-500/30"></div>
                </div>

                <div class="pt-4">
                    <button 
                        id="btn-confirm-create"
                        type="button"
                        onclick="submitCreateLobby()"
                        class="block w-full py-3 text-center rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow cursor-pointer hover:scale-[1.01] transition-transform"
                    >
                        CONFIRM & LOCK STAKE
                    </button>
                </div>
            </div>

            <!-- Waiting for Opponent Step -->
            <div id="modal-waiting-step" class="hidden space-y-5 text-center py-4">
                <div class="w-14 h-14 mx-auto rounded-full bg-[#D4AF37]/10 border border-[#D4AF37]/40 flex items-center justify-center animate-pulse">
                    <span class="text-2xl text-[#D4AF37]">Ω</span>
                </div>
                <div>
                    <h4 class="text-lg font-black text-white uppercase tracking-tight">LOBBY READY & LOCKED</h4>
                    <p class="text-xs font-mono text-slate-400 mt-1">Escrow locked. Waiting for rival runner to accept...</p>
                </div>
                <div class="p-3 bg-black/40 rounded-xl border border-white/10 font-mono text-xs text-slate-300 flex items-center justify-between">
                    <span>MATCH UUID:</span>
                    <span id="created-match-uuid" class="text-[#D4AF37] font-bold text-[11px]"></span>
                </div>
                <div class="flex gap-3">
                    <button 
                        id="btn-enter-arena"
                        type="button" 
                        class="w-full py-3 rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow cursor-pointer"
                    >
                        ENTER ARENA RUNNER
                    </button>
                </div>
            </div>

        </div>
    </div>

    <script>
        let selectedStakeCents = 5000;
        let createdMatchUuid = null;
        let waitingPollInterval = null;
        const isDemoMode = @json($demoMode ?? false);

        function getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        }

        function showAlert(msg, isError = false) {
            const box = document.getElementById('lobby-alert-box');
            if (!box) return;
            box.textContent = msg;
            box.className = isError 
                ? 'p-4 rounded-xl font-mono text-xs font-semibold bg-red-950/40 text-red-400 border border-red-500/30'
                : 'p-4 rounded-xl font-mono text-xs font-semibold bg-emerald-950/40 text-emerald-400 border border-emerald-500/30';
            box.classList.remove('hidden');
            setTimeout(() => { box.classList.add('hidden'); }, 5000);
        }

        function selectStake(cents, btn) {
            selectedStakeCents = cents;
            document.querySelectorAll('.stake-btn').forEach(b => {
                b.className = 'stake-btn py-2.5 rounded-xl border border-white/10 bg-white/5 text-white font-mono font-bold text-sm hover:border-[#D4AF37]';
            });
            btn.className = 'stake-btn py-2.5 rounded-xl border border-[#D4AF37] bg-[#D4AF37]/10 text-white font-mono font-bold text-sm hover:bg-[#D4AF37]/20';
        }

        function openCreateModal() {
            document.getElementById('modal-create-step').classList.remove('hidden');
            document.getElementById('modal-waiting-step').classList.add('hidden');
            document.getElementById('create-error').classList.add('hidden');
            document.getElementById('create-lobby-modal').classList.remove('hidden');
        }

        function closeCreateModal() {
            if (waitingPollInterval) {
                clearInterval(waitingPollInterval);
                waitingPollInterval = null;
            }
            document.getElementById('create-lobby-modal').classList.add('hidden');
        }

        async function submitCreateLobby() {
            const btn = document.getElementById('btn-confirm-create');
            const errBox = document.getElementById('create-error');
            btn.disabled = true;
            btn.textContent = 'LOCKING ESCROW...';
            errBox.classList.add('hidden');

            try {
                const res = await fetch('/api/v1/duels/lobbies', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ stake_amount_cents: selectedStakeCents }),
                });

                const data = await res.json();
                if (!res.ok) {
                    errBox.textContent = data.message || 'Failed to create lobby.';
                    errBox.classList.remove('hidden');
                    btn.disabled = false;
                    btn.textContent = 'CONFIRM & LOCK STAKE';
                    return;
                }

                createdMatchUuid = data.lobby.uuid;
                document.getElementById('modal-create-step').classList.add('hidden');
                document.getElementById('modal-waiting-step').classList.remove('hidden');
                document.getElementById('created-match-uuid').textContent = createdMatchUuid.substring(0, 16) + '...';
                document.getElementById('created-match-uuid').setAttribute('data-uuid', createdMatchUuid);

                const enterBtn = document.getElementById('btn-enter-arena');
                enterBtn.setAttribute('data-uuid', createdMatchUuid);
                enterBtn.onclick = () => {
                    window.location.href = `/duels/${createdMatchUuid}/play`;
                };

                // Poll for opponent joining
                waitingPollInterval = setInterval(async () => {
                    try {
                        const check = await fetch(`/api/v1/duels/matches/${createdMatchUuid}/result`, {
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() }
                        });
                        const matchData = await check.json();
                        if (matchData.status === 'in_progress' || matchData.status === 'ready') {
                            clearInterval(waitingPollInterval);
                            window.location.href = `/duels/${createdMatchUuid}/play`;
                        }
                    } catch {}
                }, 2000);

            } catch (err) {
                errBox.textContent = 'Network communication failure.';
                errBox.classList.remove('hidden');
                btn.disabled = false;
                btn.textContent = 'CONFIRM & LOCK STAKE';
            }
        }

        async function joinDuel(uuid) {
            try {
                const res = await fetch(`/api/v1/duels/lobbies/${uuid}/join`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                const data = await res.json();
                if (!res.ok) {
                    showAlert(data.message || 'Could not join duel.', true);
                    loadLobbies();
                    return;
                }

                window.location.href = `/duels/${uuid}/play`;
            } catch {
                showAlert('Network error while joining duel.', true);
            }
        }

        async function loadLobbies() {
            const grid = document.getElementById('duels-grid');

            try {
                const res = await fetch('/api/v1/duels/lobbies', {
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getCsrfToken(),
                        'X-Requested-With': 'XMLHttpRequest',
                    }
                });

                if (!res.ok) {
                    grid.innerHTML = '<div class="col-span-full py-12 text-center text-slate-500 font-mono text-sm">FAILED TO LOAD LOBBIES.</div>';
                    return;
                }

                const data = await res.json();
                const lobbies = data.data || data || [];

                if (lobbies.length === 0) {
                    let emptyHtml = '<div class="col-span-full py-12 text-center text-slate-500 font-mono text-sm">NO ACTIVE DUEL LOBBIES WAITING FOR OPPONENTS.<br><span class="text-[#D4AF37] mt-2 inline-block">CREATE A LOBBY ABOVE TO START.</span></div>';
                    
                    if (isDemoMode) {
                        emptyHtml += `
                        <div class="col-span-full pt-4">
                            <span class="text-xs font-mono text-[#D4AF37] uppercase tracking-wider">[DEMO MODE] SIMULATED OPPONENT ARENAS:</span>
                        </div>
                        <div class="rounded-2xl bg-[#121316] border border-white/5 p-5 space-y-4 shadow-lg">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <img src="https://ui-avatars.com/api/?name=Viper&background=FF0055&color=fff" class="w-10 h-10 rounded-xl" />
                                    <div>
                                        <div class="text-sm font-bold text-white uppercase font-mono">DEMO_VIPER</div>
                                        <div class="text-[11px] font-mono text-slate-400">WIN RATE: <span class="text-[#10B981]">74%</span></div>
                                    </div>
                                </div>
                                <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-white/5 text-[#D4AF37] border border-[#D4AF37]/20 uppercase">DEMO WAITING</span>
                            </div>
                            <div class="p-3.5 rounded-xl bg-[#0A0A0C] border border-white/5 flex items-center justify-between">
                                <div>
                                    <div class="text-[10px] font-mono text-slate-400 uppercase">STAKE PER RUN</div>
                                    <div class="text-lg font-mono font-black text-white">$50.00</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-[10px] font-mono text-[#D4AF37] uppercase">ESCROW POT</div>
                                    <div class="text-lg font-mono font-black text-gold-metallic">$100.00</div>
                                </div>
                            </div>
                            <button onclick="showAlert('Demo match simulation - create a real lobby to play!')" class="block w-full py-2.5 text-center rounded-xl bg-white/10 text-white font-mono font-black text-xs uppercase tracking-wider hover:bg-white/20">
                                PRACTICE DUEL ($50.00)
                            </button>
                        </div>`;
                    }
                    grid.innerHTML = emptyHtml;
                    return;
                }

                grid.innerHTML = lobbies.map(match => {
                    const stakeFormatted = '$' + (match.stake_amount_cents / 100).toFixed(2);
                    const potFormatted = '$' + ((match.stake_amount_cents * 2) / 100).toFixed(2);
                    const creatorName = match.creator?.name || 'RIVAL_RUNNER';

                    return `
                    <div class="rounded-2xl bg-[#121316] border border-white/5 hover:border-[#D4AF37]/40 p-5 space-y-4 shadow-lg transition-all duration-300 hover:shadow-gold-glow group">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-3">
                                <img src="https://ui-avatars.com/api/?name=${encodeURIComponent(creatorName)}&background=00F0FF&color=000" class="w-10 h-10 rounded-xl object-cover ring-1 ring-white/10" alt="${creatorName}" />
                                <div>
                                    <div class="text-sm font-bold text-white uppercase font-mono">${creatorName}</div>
                                    <div class="text-[11px] font-mono text-slate-400">RAKE: <span class="text-[#10B981]">${(match.rake_bps / 100).toFixed(1)}%</span></div>
                                </div>
                            </div>
                            <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-emerald-500/10 text-[#10B981] border border-emerald-500/30 uppercase">
                                WAITING
                            </span>
                        </div>

                        <div class="p-3.5 rounded-xl bg-[#0A0A0C] border border-white/5 flex items-center justify-between">
                            <div>
                                <div class="text-[10px] font-mono text-slate-400 uppercase">STAKE PER RUN</div>
                                <div class="text-lg font-mono font-black text-white">${stakeFormatted}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-[10px] font-mono text-[#D4AF37] uppercase">TOTAL ESCROW POT</div>
                                <div class="text-lg font-mono font-black text-gold-metallic">${potFormatted}</div>
                            </div>
                        </div>

                        <button 
                            type="button"
                            onclick="joinDuel('${match.uuid}')"
                            class="block w-full py-2.5 text-center rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow hover:scale-[1.01] transition-all cursor-pointer"
                        >
                            ACCEPT DUEL (${stakeFormatted})
                        </button>
                    </div>`;
                }).join('');

            } catch {
                grid.innerHTML = '<div class="col-span-full py-12 text-center text-red-400 font-mono text-sm">NETWORK DISCONNECTED. RETRYING...</div>';
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadLobbies();
            setInterval(loadLobbies, 8000);
        });
    </script>

</body>
</html>