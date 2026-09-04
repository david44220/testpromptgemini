<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MatchStatus;
use App\Models\DuelRun;
use App\Models\MatchGame;
use App\Models\User;
use App\Services\AntiCheat\RunAuditService;
use App\Services\AntiCheat\RunSimulator;
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
     * Test POST /matches/{uuid}/start-run issues ticket token and DOES NOT expose session secret.
     */
    public function test_start_run_issues_ticket_and_does_not_expose_session_secret(): void
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
            ->assertJsonMissing(['session_secret'])
            ->assertJsonStructure([
                'ticket_token',
                'started_at',
                'game_seed',
                'match_uuid',
            ]);
    }

    /**
     * Test submission with tampered final_score fails deterministic audit with 403 Forbidden.
     */
    public function test_submission_with_tampered_score_fails_audit_with_403(): void
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

        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $user->id,
            'started_at' => now()->subSeconds(30),
            'submitted_at' => null,
        ]);

        Sanctum::actingAs($user);

        $startRes = $this->postJson("/api/v1/duels/matches/{$match->uuid}/start-run");
        $ticketToken = $startRes->json('ticket_token');

        // Allow temporal integrity check to pass so score kinematic anomaly is isolated
        $run = DuelRun::where('match_id', $match->id)->where('user_id', $user->id)->first();
        $run->started_at = now()->subSeconds(3);
        $run->save();

        $ticks = 120; // 2 seconds
        $tamperedScore = 999999; // Cheater tries submitting 999,999 points
        $inputs = [];

        // Submit with tamperedScore
        $response = $this->postJson("/api/v1/duels/matches/{$match->uuid}/submit-run", [
            'ticket_token' => $ticketToken,
            'ticks_elapsed' => $ticks,
            'final_distance' => 40.00,
            'final_score' => $tamperedScore,
            'inputs' => $inputs,
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('failure_reason', 'SCORE_KINEMATIC_ANOMALY');
    }

    /**
     * Test submission with valid kinematic progression is accepted and verified (202).
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

        DuelRun::factory()->create([
            'match_id' => $match->id,
            'user_id' => $user->id,
            'started_at' => now()->subSeconds(2),
            'submitted_at' => null,
        ]);

        Sanctum::actingAs($user);

        $startRes = $this->postJson("/api/v1/duels/matches/{$match->uuid}/start-run");
        $ticketToken = $startRes->json('ticket_token');

        $ticks = 120;
        $inputs = [];
        $simulator = new RunSimulator;
        $sim = $simulator->simulate($match->game_seed, $inputs, $ticks);

        $response = $this->postJson("/api/v1/duels/matches/{$match->uuid}/submit-run", [
            'ticket_token' => $ticketToken,
            'ticks_elapsed' => $ticks,
            'final_distance' => $sim['authoritative_distance'],
            'final_score' => $sim['authoritative_score'],
            'inputs' => $inputs,
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'ACCEPTED')
            ->assertJsonStructure(['inputs_hash']);
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
     * Test Rule 1: Anti-slow-motion and temporal integrity vectors (60s simulation baseline).
     */
    public function test_temporal_integrity_anti_slow_motion_vectors(): void
    {
        $now = now();
        $simTicks60s = 3600; // 60 simulated seconds = 3600 ticks at 60 Hz

        // Vector 1: 60 simulated seconds / 60 real seconds -> PASS
        $this->assertTrue(
            $this->auditService->verifyTemporalIntegrity($simTicks60s, $now->copy()->subSeconds(60), $now),
            '60s simulation completed in 60s real time must pass.'
        );

        // Vector 2: 60 simulated seconds / 62 real seconds -> PASS (within 16.0s tolerance)
        $this->assertTrue(
            $this->auditService->verifyTemporalIntegrity($simTicks60s, $now->copy()->subSeconds(62), $now),
            '60s simulation completed in 62s real time must pass (within lag+grace buffer).'
        );

        // Vector 3: 60 simulated seconds / 90 real seconds -> FAIL (slow-motion cheater)
        $this->assertFalse(
            $this->auditService->verifyTemporalIntegrity($simTicks60s, $now->copy()->subSeconds(90), $now),
            '60s simulation completed in 90s real time must fail (slow-motion exploit).'
        );

        // Vector 4: 60 simulated seconds / 180 real seconds -> FAIL (slow-motion cheater attempting forfeit window)
        $this->assertFalse(
            $this->auditService->verifyTemporalIntegrity($simTicks60s, $now->copy()->subSeconds(180), $now),
            '60s simulation completed in 180s real time must fail.'
        );

        // Vector 5: 60 simulated seconds / 30 real seconds -> FAIL (speedhack running 2x speed)
        $this->assertFalse(
            $this->auditService->verifyTemporalIntegrity($simTicks60s, $now->copy()->subSeconds(30), $now),
            '60s simulation completed in 30s real time must fail (speedhack).'
        );
    }

    /**
     * Test that duels.forfeit_timeout_seconds configuration does NOT alter simulation clock tolerance.
     */
    public function test_forfeit_timeout_does_not_relax_simulation_tolerance(): void
    {
        config(['duels.forfeit_timeout_seconds' => 300]); // 5 minutes forfeit window

        $now = now();
        $simTicks60s = 3600;

        // Still strictly fails at 90s despite 300s forfeit window
        $this->assertFalse(
            $this->auditService->verifyTemporalIntegrity($simTicks60s, $now->copy()->subSeconds(90), $now),
            'Changing forfeit_timeout_seconds must NOT relax anti-slow-motion simulation tolerance.'
        );
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

    /**
     * Test Rule 4: Input integrity rejects out-of-bounds lane teleportation.
     */
    public function test_input_integrity_rejects_lane_teleportation(): void
    {
        $inputs = [
            ['tick' => 60, 'action' => 'MOVE_RIGHT', 'x' => 9.5], // Far beyond track edge (max 3.2)
        ];

        $result = $this->auditService->verifyInputIntegrity($inputs);
        $this->assertFalse($result->passed);
        $this->assertSame('CHEATER_LANE_TELEPORTATION', $result->failureReason);
    }

    /**
     * Test Rule 4: Input integrity rejects forward coordinate teleportation along Z axis.
     */
    public function test_input_integrity_rejects_z_coordinate_teleportation(): void
    {
        $inputs = [
            ['tick' => 10, 'action' => 'MOVE_LEFT', 'x' => -2.4, 'z' => 950.0], // Tick 10 is ~3.6m, not 950m!
        ];

        $result = $this->auditService->verifyInputIntegrity($inputs);
        $this->assertFalse($result->passed);
        $this->assertSame('CHEATER_COORDINATE_TELEPORTATION', $result->failureReason);
    }
}
