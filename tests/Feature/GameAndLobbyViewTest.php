<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameAndLobbyViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LedgerAccountSeeder::class);
    }

    /**
     * Test / root route renders Dark Luxury Cyber-Rail landing page with hero, live duels, and ad banner.
     */
    public function test_root_landing_page_renders_premium_cyber_rail_hero_and_arenas(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200)
            ->assertSee('CYBER-RAIL')
            ->assertSee('HIGH-STAKES')
            ->assertSee('SIGN IN TO COMPETE')
            ->assertSee('FEATURED DUEL LOBBIES')
            ->assertSee('DETERMINISTIC SEEDS');
    }

    /**
     * Test / root route renders user area and arena CTAs when user is authenticated.
     */
    public function test_root_landing_page_renders_arena_cta_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/');

        $response->assertStatus(200)
            ->assertSee('COMMAND CENTER (USER AREA)')
            ->assertSee('ENTER 3D ARENA NOW');
    }

    /**
     * Test /lobby and /game redirect unauthenticated guests to login.
     */
    public function test_lobby_and_game_redirect_unauthenticated_guests_to_login(): void
    {
        $this->get('/lobby')->assertRedirect('/login');
        $this->get('/game')->assertRedirect('/login');
    }

    /**
     * Test /lobby view renders Dark Luxury Cyber-Rail layout, sponsor banner, and duels grid for authenticated user.
     */
    public function test_lobby_view_renders_luxury_layout_and_ad_banner(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/lobby');

        $response->assertStatus(200)
            ->assertSee('CYBER-RAIL DUELS')
            ->assertSee('ACTIVE DUEL ARENAS')
            ->assertSee('Official Arena Sponsor')
            ->assertSee('ACCEPT DUEL');
    }

    /**
     * Test /game renders ArenaView, Three.js canvas, HUD overlay, and PostMatchModal for authenticated user.
     */
    public function test_game_arena_renders_canvas_and_hud_overlay(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/game?stake=5000');

        $response->assertStatus(200)
            ->assertSee('arena-viewport')
            ->assertSee('game-canvas')
            ->assertSee('duel-hud')
            ->assertSee('hud-pot')
            ->assertSee('post-match-modal')
            ->assertSee('modal-payout-amount')
            ->assertSee('SPONSOR BOOST AVAILABLE');
    }
}
