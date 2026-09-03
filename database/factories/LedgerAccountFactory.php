<?php

namespace Database\Factories;

use App\Enums\LedgerAccountType;
use App\Models\LedgerAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerAccount>
 */
class LedgerAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_code' => 'CUSTOM_'.strtoupper(fake()->unique()->lexify('??????')),
            'name' => fake()->words(3, true),
            'type' => LedgerAccountType::Liability,
        ];
    }
}
