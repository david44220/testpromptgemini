<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MatchStatus;
use App\Models\MatchGame;
use App\Models\User;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LedgerAccountSeeder::class);
    }

    /**
     * Test /game redirects unauthenticated guests to login.
     */
    public function test_game_route_redirects_unauthenticated_guest_to_login(): void
    {
        $response = $this->get('/game');

        $response->assertRedirect('/login');
    }

    /**
     * Test /game returns 200 and renders canvas with generated seed and token for authenticated user.
     */
    public function test_game_route_renders_canvas_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/game');

        $response->assertStatus(200);
        $response->assertSee('id="game-canvas"', false);
        $response->assertSee('data-seed=', false);
        $response->assertDontSee('data-token=', false);
        $response->assertSee('name="csrf-token"', false);
        $this->assertSame(0, $user->tokens()->count(), 'Viewing /game must not mint personal_access_tokens.');
    }

    /**
     * Test /game respects custom seed parameter for deterministic duel setup.
     */
    public function test_game_route_respects_custom_seed_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $customSeed = 'a1b2c3d4e5f60718293a4b5c6d7e8f90123456789abcdef0123456789abcdef0';

        $response = $this->actingAs($user)->get("/game?seed={$customSeed}");

        $response->assertStatus(200);
        $response->assertSee($customSeed);
    }

    /**
     * Test /duels/{uuid}/play renders commitment and strictly omits raw seed before start-run.
     */
    public function test_paid_duel_route_renders_commitment_and_omits_raw_seed_before_start_run(): void
    {
        $creator = User::factory()->create();
        $opponent = User::factory()->create();

        $rawSeed = 'c0ffee00112233445566778899aabbccddeeff00112233445566778899aabbcc';
        $commitment = hash('sha256', $rawSeed);

        /** @var MatchGame $match */
        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'status' => MatchStatus::InProgress,
            'game_seed' => $rawSeed,
        ]);

        $response = $this->actingAs($creator)->get("/duels/{$match->uuid}/play");

        $response->assertStatus(200);
        $response->assertDontSee($rawSeed, false);
        $response->assertSee('data-commitment="'.$commitment.'"', false);
        $response->assertDontSee('data-seed=', false);
    }
}
