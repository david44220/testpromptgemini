<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MatchStatus;
use App\Enums\UserStatus;
use App\Models\MatchGame;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $accounts = [
            [
                'name' => 'Apex Titan',
                'email' => 'apex@cyber-rail.gg',
                'password' => 'password123',
                'balance_cents' => 250000, // $2,500.00
                'role' => 'High-Roller Champion',
                'avatar' => 'https://ui-avatars.com/api/?name=Apex+Titan&background=D4AF37&color=000',
            ],
            [
                'name' => 'Viper 99',
                'email' => 'viper@cyber-rail.gg',
                'password' => 'password123',
                'balance_cents' => 100000, // $1,000.00
                'role' => 'Pro Duelist',
                'avatar' => 'https://ui-avatars.com/api/?name=Viper+99&background=FF0055&color=fff',
            ],
            [
                'name' => 'Cyber Ghost',
                'email' => 'ghost@cyber-rail.gg',
                'password' => 'password123',
                'balance_cents' => 75000,  // $750.00
                'role' => 'Speed Specialist',
                'avatar' => 'https://ui-avatars.com/api/?name=Cyber+Ghost&background=00F0FF&color=000',
            ],
            [
                'name' => 'Neon Rookie',
                'email' => 'rookie@cyber-rail.gg',
                'password' => 'password123',
                'balance_cents' => 25000,  // $250.00
                'role' => 'Contender',
                'avatar' => 'https://ui-avatars.com/api/?name=Neon+Rookie&background=10B981&color=000',
            ],
        ];

        $seededUsers = [];

        foreach ($accounts as $acc) {
            /** @var User $user */
            $user = User::firstOrCreate(
                ['email' => $acc['email']],
                [
                    'name' => $acc['name'],
                    'uuid' => (string) Str::uuid(),
                    'status' => UserStatus::Active,
                    'risk_score' => 0,
                    'password' => Hash::make($acc['password']),
                ]
            );

            /** @var Wallet $wallet */
            $wallet = Wallet::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'currency' => 'USD',
                    'balance_cents' => $acc['balance_cents'],
                    'bonus_balance_cents' => 0,
                    'locked_balance_cents' => 0,
                ]
            );

            // Create persistent Sanctum token
            $user->tokens()->delete();
            $token = $user->createToken('demo-web-token')->plainTextToken;

            $seededUsers[] = [
                'name' => $user->name,
                'email' => $user->email,
                'password' => $acc['password'],
                'balance' => '$'.number_format($wallet->balance_cents / 100, 2),
                'role' => $acc['role'],
                'token' => $token,
                'user' => $user,
            ];
        }

        // Seed 2 active open matches in database
        if (isset($seededUsers[0], $seededUsers[1])) {
            MatchGame::firstOrCreate(
                ['uuid' => 'd0000000-0000-4000-a000-000000000001'],
                [
                    'creator_user_id' => $seededUsers[0]['user']->id,
                    'opponent_user_id' => null,
                    'stake_amount_cents' => 5000,
                    'rake_percentage' => 10.00,
                    'total_pot_cents' => 0,
                    'platform_fee_cents' => 0,
                    'winner_payout_cents' => 0,
                    'status' => MatchStatus::WaitingForOpponent,
                    'game_seed' => bin2hex(random_bytes(32)),
                ]
            );

            MatchGame::firstOrCreate(
                ['uuid' => 'd0000000-0000-4000-a000-000000000002'],
                [
                    'creator_user_id' => $seededUsers[1]['user']->id,
                    'opponent_user_id' => null,
                    'stake_amount_cents' => 2500,
                    'rake_percentage' => 10.00,
                    'total_pot_cents' => 0,
                    'platform_fee_cents' => 0,
                    'winner_payout_cents' => 0,
                    'status' => MatchStatus::WaitingForOpponent,
                    'game_seed' => bin2hex(random_bytes(32)),
                ]
            );
        }
    }
}
