@props([
    'creativeId' => 'banner-gold-exchange',
    'sponsor' => 'Aegis Reserve',
    'headline' => 'INSTANT SETTLEMENTS. ZERO PLATFORM SLIPPAGE.',
    'cta' => 'CLAIM $50 MATCH BONUS',
    'accent' => '#D4AF37',
])

<div class="w-full max-w-4xl mx-auto my-4 overflow-hidden rounded-xl bg-[#121316]/90 border border-[#D4AF37]/30 shadow-gold-glow backdrop-blur-md transition-all duration-300 hover:border-[#D4AF37]/60">
    <div class="flex flex-col sm:flex-row items-center justify-between px-5 py-3 gap-3">
        <!-- Sponsor Tag & Visual -->
        <div class="flex items-center space-x-3">
            <div class="w-2.5 h-2.5 rounded-full bg-[#D4AF37] animate-pulse"></div>
            <div class="flex flex-col">
                <div class="flex items-center space-x-2">
                    <span class="text-[10px] font-mono tracking-widest uppercase text-[#D4AF37] font-bold">Official Arena Sponsor</span>
                    <span class="text-[9px] font-mono px-1.5 py-0.5 rounded bg-white/10 text-slate-300">728x90</span>
                </div>
                <span class="text-sm sm:text-base font-sans font-bold text-white tracking-wide">
                    {{ $sponsor }} <span class="text-slate-400 font-normal">— {{ $headline }}</span>
                </span>
            </div>
        </div>

        <!-- Action Button -->
        <div class="flex items-center space-x-3 flex-shrink-0">
            <button 
                type="button"
                onclick="fetch('/api/v1/ads/impression/{{ $creativeId }}', { method: 'POST' }); window.open('https://example.com/sponsor', '_blank');"
                class="px-4 py-2 text-xs font-mono font-bold uppercase tracking-wider rounded-lg bg-gold-metallic text-black transition-all duration-200 hover:shadow-gold-glow hover:scale-[1.02] cursor-pointer"
            >
                {{ $cta }}
            </button>
        </div>
    </div>
</div>
