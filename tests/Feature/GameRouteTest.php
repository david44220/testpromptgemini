<?php

declare(strict_types=1);

namespace Tests\Feature;

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
        $response->assertSee('data-token=', false);
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
}
