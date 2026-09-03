<?php

declare(strict_types=1);

namespace App\Services\Ads;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class AdService
{
    /**
     * Return active creatives available for in-game 3D billboards, lobby banners, and rewarded interstitials.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getActiveCreatives(): array
    {
        return [
            'billboards_3d' => [
                [
                    'id' => 'billboard-nexus-quantum',
                    'title' => 'NEXUS QUANTUM',
                    'tagline' => 'SUB-MILLISECOND ESPORTS TELEMETRY',
                    'sponsor' => 'Nexus Hardware',
                    'accent_color' => '#00F0FF',
                    'bg_gradient' => ['#0A0A14', '#003344'],
                    'click_url' => 'https://example.com/nexus',
                ],
                [
                    'id' => 'billboard-aegis-defi',
                    'title' => 'AEGIS VAULT',
                    'tagline' => 'INSTANT NON-CUSTODIAL ESCROW',
                    'sponsor' => 'Aegis Capital',
                    'accent_color' => '#D4AF37',
                    'bg_gradient' => ['#14110A', '#332600'],
                    'click_url' => 'https://example.com/aegis',
                ],
                [
                    'id' => 'billboard-hyperion-energy',
                    'title' => 'HYPERION SYNERGY',
                    'tagline' => 'ZERO-CRASH REACTION NOOTROPICS',
                    'sponsor' => 'Hyperion Labs',
                    'accent_color' => '#FF0055',
                    'bg_gradient' => ['#140A0F', '#44001A'],
                    'click_url' => 'https://example.com/hyperion',
                ],
            ],
            'web_banners' => [
                [
                    'id' => 'banner-gold-exchange',
                    'title' => 'GOLD STANDARD DUELING',
                    'headline' => 'INSTANT SETTLEMENTS. ZERO PLATFORM SLIPPAGE.',
                    'sponsor' => 'Aegis Reserve',
                    'cta_text' => 'CLAIM $50 MATCH BONUS',
                    'accent_color' => '#D4AF37',
                    'dimensions' => '728x90',
                ],
            ],
            'rewarded_interstitials' => [
                [
                    'id' => 'rewarded-sponsor-clip',
                    'title' => 'NEXUS ULTRA SPONSOR BREAK',
                    'reward_description' => 'Reduce platform rake on your next duel from 10% to 8%',
                    'reward_type' => 'RAKE_DISCOUNT_2_PERCENT',
                    'duration_seconds' => 15,
                    'sponsor' => 'Nexus Systems',
                ],
            ],
        ];
    }

    /**
     * Record an impression ping dispatched via navigator.sendBeacon from 3D billboard or web banner.
     *
     * @return array<string, mixed>
     */
    public function recordImpression(string $creativeId, ?string $ipAddress = null): array
    {
        $cacheKey = "ad_impressions:{$creativeId}";
        $count = Cache::increment($cacheKey);

        return [
            'creative_id' => $creativeId,
            'total_impressions' => $count,
            'recorded_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Grants reward to user after successfully completing a rewarded sponsor clip.
     *
     * @return array<string, mixed>
     */
    public function claimRewardedAd(User $user, string $creativeId): array
    {
        // Tag user with active rake discount token for next duel
        $discountKey = "user_rake_discount:{$user->id}";
        Cache::put($discountKey, 2.0, now()->addHours(24));

        return [
            'status' => 'REWARD_GRANTED',
            'reward_type' => 'RAKE_DISCOUNT_2_PERCENT',
            'effective_rake_percentage' => 8.0,
            'expires_at' => now()->addHours(24)->toIso8601String(),
        ];
    }
}
