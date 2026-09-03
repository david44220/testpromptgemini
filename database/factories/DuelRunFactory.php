<?php

namespace Database\Factories;

use App\Enums\AuditStatus;
use App\Models\DuelRun;
use App\Models\MatchGame;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DuelRun>
 */
class DuelRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'match_id' => MatchGame::factory(),
            'user_id' => User::factory(),
            'session_secret' => bin2hex(random_bytes(32)),
            'ticket_token' => Str::random(40),
            'started_at' => now()->subSeconds(30),
            'submitted_at' => null,
            'ticks_elapsed' => 1800,
            'final_distance' => 540.00,
            'final_score' => 6500,
            'inputs_hash' => hash('sha256', '[]'),
            'input_log' => [],
            'client_signature' => hash('sha256', 'sig'),
            'audit_status' => AuditStatus::Pending,
        ];
    }
}
