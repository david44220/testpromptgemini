<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MatchStatus;
use App\Models\DuelRun;
use App\Models\MatchGame;
use App\Models\User;
use App\Services\AntiCheat\RunAuditService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AntiCheatAuditTest extends TestCase
{
    use RefreshDatabase;

    protected RunAuditService $auditService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LedgerAccountSeeder::class);
        $this->auditService = app(RunAuditService::class);
    }

    /**
     * Test POST /matches/{uuid}/start-run issues ticket token and session secret.
     */
    public function test_start_run_issues_ticket_and_cryptographic_secret(): void
    {
        /** @var User $creator */
        $creator = User::factory()->create();
        /** @var User $opponent */
        $opponent = User::factory()->create();

        $match = MatchGame::factory()->create([
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'status' => MatchStatus::InProgress,
        ]);

        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $creator->id,
            'ticket_token' => null,
            'started_at' => null,
        ]);

        Sanctum::actingAs($creator);

        $response = $this->postJson("/api/v1/duels/matches/{$match->uuid}/start-run");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'ticket_token',
                'session_secret',
                'started_at',
                'game_seed',
                'match_uuid',
            ]);
    }

    /**
     * Test submission with modified final_score fails HMAC check with 403 Forbidden.
     */
    public function test_submission_with_modified_score_fails_hmac_check_with_403(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        /** @var User $opponent */
        $opponent = User::factory()->create();

        $match = MatchGame::factory()->create([
            'creator_user_id' => $user->id,
            'opponent_user_id' => $opponent->id,
            'status' => MatchStatus::InProgress,
        ]);

        $sessionSecret = bin2hex(random_bytes(32));
        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $user->id,
            'session_secret' => $sessionSecret,
            'started_at' => now()->subSeconds(30),
            'submitted_at' => null,
        ]);

        Sanctum::actingAs($user);

        $ticks = 1800; // 30 seconds
        $realScore = 1500;
        $tamperedScore = 999999; // Cheater tries submitting 999,999 points
        $inputs = [
            ['tick' => 60, 'action' => 'MOVE_RIGHT', 'x' => 2.4],
        ];
        $inputsHash = hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));

        // Signature signed with original realScore (1500)
        $signature = hash_hmac('sha256', "{$match->game_seed}:{$realScore}:{$ticks}:{$inputsHash}", $sessionSecret);

        // Submit with tamperedScore
        $response = $this->postJson("/api/v1/duels/matches/{$match->uuid}/submit-run", [
            'ticks_elapsed' => $ticks,
            'final_distance' => 540.00,
            'final_score' => $tamperedScore,
            'inputs' => $inputs,
            'signature' => $signature,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Run rejected: Cryptographic signature verification failed.');
    }

    /**
     * Test submission with valid HMAC signature is accepted and queued (202).
     */
    public function test_valid_submission_is_accepted_with_202(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        /** @var User $opponent */
        $opponent = User::factory()->create();

        $match = MatchGame::factory()->create([
            'creator_user_id' => $user->id,
            'opponent_user_id' => $opponent->id,
            'status' => MatchStatus::InProgress,
        ]);

        $sessionSecret = bin2hex(random_bytes(32));
        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $user->id,
            'session_secret' => $sessionSecret,
            'started_at' => now()->subSeconds(20),
            'submitted_at' => null,
        ]);

        Sanctum::actingAs($user);

        $ticks = 1200; // 20s
        $score = 2400;
        $inputs = [
            ['tick' => 60, 'action' => 'MOVE_RIGHT', 'x' => 2.4],
        ];
        $inputsHash = hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', "{$match->game_seed}:{$score}:{$ticks}:{$inputsHash}", $sessionSecret);

        $response = $this->postJson("/api/v1/duels/matches/{$match->uuid}/submit-run", [
            'ticks_elapsed' => $ticks,
            'final_distance' => 420.00,
            'final_score' => $score,
            'inputs' => $inputs,
            'signature' => $signature,
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'QUEUED');
    }

    /**
     * Test Rule 1: Temporal speedhack detection (40s simulation completed in 10s real time).
     */
    public function test_temporal_integrity_detects_speedhacks(): void
    {
        $startedAt = now()->subSeconds(10);
        $submittedAt = now();
        $ticksElapsed = 2400; // 40 seconds worth of physics ticks (2400/60)

        $passes = $this->auditService->verifyTemporalIntegrity($ticksElapsed, $startedAt, $submittedAt);
        $this->assertFalse($passes, 'Speedhack running 40s ticks in 10s real time must fail temporal check.');
    }

    /**
     * Test Rule 2: Kinematic feasibility rejects unrealistic distance exceeding 38 m/s speed limit.
     */
    public function test_kinematic_feasibility_rejects_excessive_distance(): void
    {
        $ticksElapsed = 1800; // 30 seconds
        // Max theoretical distance: 38 m/s * 30s = 1,140m (+2% buffer = 1,162.8m)
        $impossibleDistance = 2500.0; // Cheater reporting 2,500m in 30 seconds

        $passes = $this->auditService->verifyKinematics($ticksElapsed, $impossibleDistance);
        $this->assertFalse($passes, 'Distance exceeding maximum physical velocity limit must fail kinematics check.');

        $plausibleDistance = 650.0; // Normal run averaging ~21 m/s
        $this->assertTrue($this->auditService->verifyKinematics($ticksElapsed, $plausibleDistance));
    }

    /**
     * Test Rule 3: Input frequency rejects impossible lane switch frequency (<7 ticks / 120ms).
     */
    public function test_input_integrity_rejects_excessive_lane_switch_frequency(): void
    {
        $inputs = [
            ['tick' => 10, 'action' => 'MOVE_LEFT'],
            ['tick' => 12, 'action' => 'MOVE_RIGHT'], // Only 2 ticks later! Physically impossible (min 7)
        ];

        $result = $this->auditService->verifyInputIntegrity($inputs);
        $this->assertFalse($result->passed);
        $this->assertSame('CHEATER_EXCESSIVE_LANE_SWITCH_FREQUENCY', $result->failureReason);
    }

    /**
     * Test Rule 3: Input frequency rejects illegal mid-air jump spam (<36 ticks).
     */
    public function test_input_integrity_rejects_illegal_jump_spam(): void
    {
        $inputs = [
            ['tick' => 50, 'action' => 'JUMP'],
            ['tick' => 60, 'action' => 'JUMP'], // Only 10 ticks later! Still airborne (min 36)
        ];

        $result = $this->auditService->verifyInputIntegrity($inputs);
        $this->assertFalse($result->passed);
        $this->assertSame('CHEATER_INVALID_JUMP_CURVE', $result->failureReason);
    }
}
