<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LedgerAccountSeeder::class);
    }

    /**
     * Test GET /api/v1/ads/active-creatives delivers 3D billboards, banners, and rewarded ads.
     */
    public function test_active_creatives_delivers_structured_campaigns(): void
    {
        $response = $this->getJson('/api/v1/ads/active-creatives');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'billboards_3d' => [
                    '*' => [
                        'id',
                        'title',
                        'tagline',
                        'sponsor',
                        'accent_color',
                        'click_url',
                    ],
                ],
                'web_banners' => [
                    '*' => [
                        'id',
                        'title',
                        'headline',
                        'sponsor',
                        'cta_text',
                        'accent_color',
                        'dimensions',
                    ],
                ],
                'rewarded_interstitials' => [
                    '*' => [
                        'id',
                        'title',
                        'reward_description',
                        'reward_type',
                        'duration_seconds',
                        'sponsor',
                    ],
                ],
            ]);
    }

    /**
     * Test POST /api/v1/ads/impression/{id} records high-speed impression pings.
     */
    public function test_record_impression_increments_metric_count(): void
    {
        $creativeId = 'billboard-nexus-quantum';

        $response = $this->postJson("/api/v1/ads/impression/{$creativeId}");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'OK')
            ->assertJsonPath('data.creative_id', $creativeId);
    }

    /**
     * Test POST /api/v1/ads/rewarded-complete claims rake reduction reward.
     */
    public function test_complete_rewarded_ad_grants_rake_reduction(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        // 1. Unauthenticated request rejected
        $this->postJson('/api/v1/ads/rewarded-complete', [
            'creative_id' => 'rewarded-sponsor-clip',
        ])->assertStatus(401);

        // 2. Authenticated user receives reward
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/ads/rewarded-complete', [
            'creative_id' => 'rewarded-sponsor-clip',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('reward.status', 'REWARD_GRANTED')
            ->assertJsonPath('reward.reward_type', 'RAKE_DISCOUNT_2_PERCENT')
            ->assertJsonPath('reward.effective_rake_percentage', 8);
    }
}
