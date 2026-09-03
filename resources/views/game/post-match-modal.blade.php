<!-- Post-Match Settlement Modal (Dark Luxury Cyber-Rail) -->
<div id="post-match-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-lg transition-opacity duration-300 opacity-0 pointer-events-none">
    
    <div id="post-match-card" class="w-full max-w-lg rounded-3xl bg-[#121316] border border-[#D4AF37]/40 shadow-gold-glow-lg overflow-hidden flex flex-col transform transition-all duration-300 scale-95">
        
        <!-- Header Banner -->
        <div id="modal-header-bg" class="px-6 py-6 bg-gradient-to-r from-[#1a1708] via-[#2a2208] to-[#1a1708] border-b border-[#D4AF37]/20 text-center relative overflow-hidden">
            <div class="absolute inset-0 animate-shimmer pointer-events-none"></div>
            
            <span id="modal-status-badge" class="inline-flex items-center px-3 py-1 rounded-full text-xs font-mono font-bold uppercase tracking-widest bg-[#D4AF37]/20 text-[#D4AF37] border border-[#D4AF37]/40 mb-2">
                MATCH SETTLED
            </span>

            <h2 id="modal-title" class="text-3xl sm:text-4xl font-sans font-black tracking-tight text-white uppercase italic">
                VICTORY!
            </h2>
            <p id="modal-subtitle" class="text-xs font-mono text-slate-400 mt-1 uppercase tracking-wider">
                Cryptographic Run Verification Complete
            </p>
        </div>

        <!-- Body & Settlement Financials -->
        <div class="p-6 space-y-6">
            
            <!-- Net Payout Counter -->
            <div class="text-center p-5 rounded-2xl bg-[#0A0A0C] border border-white/5 relative overflow-hidden">
                <span class="text-xs font-mono font-bold uppercase tracking-widest text-[#D4AF37]">
                    NET WINNINGS CREDITED
                </span>
                <div id="modal-payout-amount" class="text-4xl sm:text-5xl font-mono font-black text-gold-metallic my-1 tracking-tight">
                    $0.00
                </div>
                <div class="text-[11px] font-mono text-slate-400">
                    Gross Pot: <span id="modal-gross-pot" class="text-white font-semibold">$100.00</span> 
                    — Rake (10%): <span id="modal-rake" class="text-red-400 font-semibold">$10.00</span>
                </div>
            </div>

            <!-- Distance & Performance Comparison -->
            <div class="grid grid-cols-2 gap-3">
                <div class="p-4 rounded-xl bg-white/5 border border-white/5 flex flex-col">
                    <span class="text-[11px] font-mono text-slate-400 uppercase">Your Distance</span>
                    <span id="modal-your-distance" class="text-xl font-mono font-bold text-white mt-1">0 m</span>
                    <span id="modal-your-score" class="text-xs font-mono text-[#D4AF37]">0 PTS</span>
                </div>
                <div class="p-4 rounded-xl bg-white/5 border border-white/5 flex flex-col text-right">
                    <span class="text-[11px] font-mono text-slate-400 uppercase">Rival Distance</span>
                    <span id="modal-rival-distance" class="text-xl font-mono font-bold text-slate-300 mt-1">0 m</span>
                    <span id="modal-rival-score" class="text-xs font-mono text-slate-500">0 PTS</span>
                </div>
            </div>

            <!-- Rewarded Sponsor Ad Section -->
            <div id="modal-rewarded-ad" class="p-4 rounded-xl bg-gradient-to-r from-[#121316] to-[#181a20] border border-[#00F0FF]/30 flex flex-col sm:flex-row items-center justify-between gap-3 {{ empty($rewardedAdsAvailable) ? 'opacity-50 pointer-events-none' : '' }}" data-available="{{ !empty($rewardedAdsAvailable) ? '1' : '0' }}">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 rounded-lg bg-[#00F0FF]/20 border border-[#00F0FF]/40 flex items-center justify-center text-[#00F0FF] flex-shrink-0">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M4 4a2 2 0 00-2 2v8a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2H4zm8 6.5l-4 2.5V7l4 2.5z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="text-xs font-mono font-bold text-white">{{ !empty($rewardedAdsAvailable) ? 'SPONSOR BOOST AVAILABLE' : 'SPONSOR REWARDS UNAVAILABLE' }}</div>
                        <div class="text-[11px] text-slate-400">{{ !empty($rewardedAdsAvailable) ? 'Watch 15s clip to reduce platform rake to 8% on next duel' : 'No ad sponsor provider configured for this environment' }}</div>
                    </div>
                </div>
                <button 
                    id="btn-watch-ad" 
                    type="button"
                    {{ empty($rewardedAdsAvailable) ? 'disabled' : '' }}
                    class="w-full sm:w-auto px-3.5 py-2 rounded-lg {{ !empty($rewardedAdsAvailable) ? 'bg-[#00F0FF]/20 border border-[#00F0FF]/40 text-[#00F0FF] hover:bg-[#00F0FF] hover:text-black cursor-pointer' : 'bg-white/5 border border-white/10 text-slate-500 cursor-not-allowed' }} text-xs font-mono font-bold uppercase tracking-wider transition-all whitespace-nowrap"
                >
                    {{ !empty($rewardedAdsAvailable) ? 'WATCH CLIP' : 'UNAVAILABLE' }}
                </button>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center space-x-3 pt-2">
                <a 
                    href="/lobby" 
                    class="flex-1 py-3 text-center rounded-xl bg-white/10 hover:bg-white/15 text-white font-mono font-bold text-xs uppercase tracking-wider transition-colors cursor-pointer"
                >
                    RETURN TO LOBBY
                </a>
                <button 
                    id="btn-rematch" 
                    type="button" 
                    class="flex-1 py-3 text-center rounded-xl bg-gold-metallic text-black font-mono font-black text-xs uppercase tracking-wider shadow-gold-glow hover:scale-[1.02] transition-transform cursor-pointer"
                >
                    REMATCH / DOUBLE
                </button>
            </div>

        </div>

    </div>

</div>
