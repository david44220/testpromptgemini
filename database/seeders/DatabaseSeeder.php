<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call(LedgerAccountSeeder::class);
        $this->call(DemoAccountSeeder::class);

        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Cyber Athlete',
                'uuid' => (string) Str::uuid(),
                'status' => UserStatus::Active,
                'password' => Hash::make('password'),
            ]
        );

        Wallet::firstOrCreate(
            ['user_id' => $user->id],
            [
                'currency' => 'USD',
                'balance_cents' => 100000,
                'bonus_balance_cents' => 0,
                'locked_balance_cents' => 0,
            ]
        );
    }
}
