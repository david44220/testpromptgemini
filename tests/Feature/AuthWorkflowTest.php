<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LedgerAccountSeeder::class);
    }

    /**
     * Test login view renders correctly with demo account quick switcher.
     */
    public function test_login_screen_can_be_rendered_with_demo_accounts(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200)
            ->assertSee('SIGN IN TO CYBER-RAIL')
            ->assertSee('ONE-CLICK DEMO LOGIN')
            ->assertSee('Apex Titan')
            ->assertSee('Viper 99');
    }

    /**
     * Test register view renders correctly.
     */
    public function test_register_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200)
            ->assertSee('JOIN CYBER-RAIL ARENA')
            ->assertSee('$100.00 Welcome Escrow Bonus');
    }

    /**
     * Test registration creates user, wallet with $100 bonus, and logs in.
     */
    public function test_users_can_register_and_receive_funded_wallet(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ghost Hunter',
            'email' => 'hunter@cyber-rail.gg',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');

        /** @var User $user */
        $user = User::where('email', 'hunter@cyber-rail.gg')->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->wallet);
        $this->assertEquals(10000, $user->wallet->balance_cents); // $100.00
    }

    /**
     * Test users can authenticate with credentials.
     */
    public function test_users_can_authenticate_using_login_screen(): void
    {
        /** @var User $user */
        $user = User::factory()->create([
            'password' => bcrypt('secret-pass-123'),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-pass-123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/dashboard');
    }

    /**
     * Test one-click demo login authenticates demo account.
     */
    public function test_users_can_authenticate_using_one_click_demo_login(): void
    {
        /** @var User $user */
        $user = User::factory()->create([
            'email' => 'apex@cyber-rail.gg',
            'name' => 'Apex Titan',
        ]);

        $response = $this->post('/login/demo', [
            'email' => 'apex@cyber-rail.gg',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/dashboard');
    }

    /**
     * Test invalid password rejects authentication.
     */
    public function test_users_cannot_authenticate_with_invalid_password(): void
    {
        /** @var User $user */
        $user = User::factory()->create([
            'password' => bcrypt('correct-password'),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    /**
     * Test logout terminates session.
     */
    public function test_users_can_logout(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    /**
     * Test dashboard requires authentication.
     */
    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    /**
     * Test dashboard displays wallet and active duels.
     */
    public function test_dashboard_displays_user_wallet_and_duels(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        Wallet::create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'balance_cents' => 50000, // $500.00
            'bonus_balance_cents' => 0,
            'locked_balance_cents' => 5000,
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertStatus(200)
            ->assertSee('VAULT BALANCES')
            ->assertSee('$500.00')
            ->assertSee('$50.00')
            ->assertSee('ACTIVE DUELS');
    }

    /**
     * Test simulating wallet deposit credits funds.
     */
    public function test_user_can_simulate_wallet_deposit(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'balance_cents' => 10000,
            'bonus_balance_cents' => 0,
            'locked_balance_cents' => 0,
        ]);

        $response = $this->actingAs($user)->post('/user/wallet/deposit', [
            'amount_dollars' => 100,
        ]);

        $response->assertSessionHas('status');
        $this->assertEquals(20000, $wallet->fresh()->balance_cents); // $200.00
    }
}
