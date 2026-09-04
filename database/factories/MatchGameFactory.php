<?php

namespace Database\Factories;

use App\Enums\MatchStatus;
use App\Models\MatchGame;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MatchGame>
 */
class MatchGameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'creator_user_id' => User::factory(),
            'opponent_user_id' => null,
            'stake_amount_cents' => 1000,
            'rake_bps' => 1000,
            'rake_percentage' => '10.00',
            'total_pot_cents' => 0,
            'platform_fee_cents' => 0,
            'winner_payout_cents' => 0,
            'status' => MatchStatus::WaitingForOpponent,
            'winner_user_id' => null,
            'game_seed' => bin2hex(random_bytes(32)),
            'settled_at' => null,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MatchStatus::Ready,
            'opponent_user_id' => User::factory(),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MatchStatus::InProgress,
            'opponent_user_id' => User::factory(),
        ]);
    }
}
