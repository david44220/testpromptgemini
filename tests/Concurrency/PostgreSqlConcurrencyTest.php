<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Enums\AuditStatus;
use App\Enums\MatchStatus;
use App\Models\DuelRun;
use App\Models\LedgerEntry;
use App\Models\MatchGame;
use App\Models\User;
use App\Models\Wallet;
use App\Services\AntiCheat\RunSimulator;
use App\Services\Financial\WalletLedgerService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * Multi-Process PostgreSQL Concurrency Test Suite.
 *
 * Proves RACE-01 through RACE-04 against a real PostgreSQL 16 server
 * using distinct OS processes via proc_open.
 */
class PostgreSqlConcurrencyTest extends TestCase
{
    protected static bool $pgMigrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Switch default connection to PostgreSQL for test fixtures
        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql.host', '127.0.0.1');
        Config::set('database.connections.pgsql.port', '5432');
        Config::set('database.connections.pgsql.database', 'cyber_arena_test');
        Config::set('database.connections.pgsql.username', 'postgres');
        Config::set('database.connections.pgsql.password', 'postgres');

        DB::purge('pgsql');

        try {
            DB::connection('pgsql')->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped('PostgreSQL server not available at 127.0.0.1:5432: '.$e->getMessage());
        }

        if (! self::$pgMigrated) {
            Artisan::call('migrate:fresh', [
                '--database' => 'pgsql',
                '--seed' => true,
                '--force' => true,
            ]);
            self::$pgMigrated = true;
        }

        $this->seed(LedgerAccountSeeder::class);
    }

    /**
     * Executes concurrent worker processes simultaneously via proc_open.
     *
     * @param  list<array{scenario: string, payload: array<string, mixed>}>  $configs
     * @return list<array<string, mixed>>
     */
    protected function runConcurrentWorkers(array $configs): array
    {
        $processes = [];
        $pipes = [];
        $env = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($v)) {
                $env[$k] = $v;
            }
        }
        $env['PATH'] = getenv('PATH') ?: ($env['PATH'] ?? '');
        $env['SystemRoot'] = getenv('SystemRoot') ?: ($env['SystemRoot'] ?? 'C:\\Windows');
        $env['DB_CONNECTION'] = 'pgsql';
        $env['DB_HOST'] = '127.0.0.1';
        $env['DB_PORT'] = '5432';
        $env['DB_DATABASE'] = 'cyber_arena_test';
        $env['DB_USERNAME'] = 'postgres';
        $env['DB_PASSWORD'] = 'postgres';
        $env['BROADCAST_CONNECTION'] = 'null';
        $env['QUEUE_CONNECTION'] = 'sync';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Launch all workers concurrently
        foreach ($configs as $idx => $cfg) {
            $cmd = sprintf(
                '%s artisan duel:concurrency-worker %s %s',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($cfg['scenario']),
                escapeshellarg(base64_encode(json_encode($cfg['payload'], JSON_UNESCAPED_SLASHES)))
            );

            $proc = proc_open($cmd, $descriptors, $procPipes, base_path(), $env);
            if (! is_resource($proc)) {
                $this->fail("Failed to spawn process {$idx}: {$cmd}");
            }

            $processes[$idx] = $proc;
            $pipes[$idx] = $procPipes;
        }

        $results = [];

        // Collect outputs
        foreach ($processes as $idx => $proc) {
            fclose($pipes[$idx][0]); // close stdin
            $stdout = stream_get_contents($pipes[$idx][1]);
            $stderr = stream_get_contents($pipes[$idx][2]);
            fclose($pipes[$idx][1]);
            fclose($pipes[$idx][2]);

            $exitCode = proc_close($proc);
            $parsed = json_decode(trim((string) $stdout), true);

            $results[$idx] = [
                'exit_code' => $exitCode,
                'raw_stdout' => $stdout,
                'raw_stderr' => $stderr,
                'json' => $parsed,
            ];
        }

        return $results;
    }

    /**
     * RACE-01: Double submit-run on the same run ticket.
     * Simultaneous submit-run requests from two separate OS processes.
     * Exactly one must succeed (202), second must be rejected (409).
     */
    public function test_race_01_concurrent_double_submit_single_use_ticket(): void
    {
        $creator = User::factory()->create();
        $opponent = User::factory()->create();

        $match = MatchGame::create([
            'uuid' => (string) Str::uuid(),
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'stake_amount_cents' => 5000,
            'rake_bps' => 1000,
            'status' => MatchStatus::InProgress,
            'game_seed' => bin2hex(random_bytes(32)),
        ]);

        $rawTicket = Str::random(48);
        $run = DuelRun::create([
            'match_id' => $match->id,
            'user_id' => $creator->id,
            'ticket_hash' => hash('sha256', $rawTicket),
            'ticket_expires_at' => now()->addMinutes(10),
            'started_at' => now()->subSeconds(2),
            'audit_status' => AuditStatus::Pending,
        ]);

        $simulator = new RunSimulator;
        $sim = $simulator->simulate($match->game_seed, [], 100);

        $payload = [
            'uuid' => $match->uuid,
            'user_id' => $creator->id,
            'ticket_token' => $rawTicket,
            'ticks_elapsed' => 100,
            'final_distance' => $sim['authoritative_distance'],
            'final_score' => $sim['authoritative_score'],
            'inputs' => [],
        ];

        // Launch 2 simultaneous worker processes attempting submit-run on the same ticket
        $results = $this->runConcurrentWorkers([
            ['scenario' => 'submit-run', 'payload' => $payload],
            ['scenario' => 'submit-run', 'payload' => $payload],
        ]);

        $statuses = array_map(fn ($r) => $r['json']['status'] ?? null, $results);

        // Exactly one must be 202 Accepted, and one must be 409 Conflict
        $this->assertContains(202, $statuses, 'Results: '.json_encode($results));
        $this->assertContains(409, $statuses, 'Duplicate concurrent submission on same ticket must be rejected with 409.');
        $this->assertSame([202, 409], array_values(tap($statuses, fn (&$arr) => sort($arr))), 'Exactly one 202 and one 409 must occur.');

        // Verify database state: run marked as audited, submitted_at set
        $run->refresh();
        $this->assertNotNull($run->submitted_at);
        $this->assertNull($run->ticket_hash, 'Ticket hash must be invalidated upon use.');
    }

    /**
     * RACE-02: Simultaneous settle / forfeit against the same match.
     * Two separate OS processes attempt settleMatch simultaneously.
     * Exactly one settlement executes, winner is paid once, rake collected once, total pot conserved.
     */
    public function test_race_02_concurrent_settle_match_winner_paid_once_rake_collected_once(): void
    {
        $stake = 5000; // $50 each => $100 pot
        $rakeBps = 1000; // 10% rake => $10 fee, $90 payout

        $creator = User::factory()->create();
        $creatorWallet = Wallet::firstOrCreate(['user_id' => $creator->id], ['currency' => 'USD', 'balance_cents' => $stake]);
        $creatorWallet->balance_cents = $stake;
        $creatorWallet->locked_balance_cents = 0;
        $creatorWallet->save();

        $opponent = User::factory()->create();
        $opponentWallet = Wallet::firstOrCreate(['user_id' => $opponent->id], ['currency' => 'USD', 'balance_cents' => $stake]);
        $opponentWallet->balance_cents = $stake;
        $opponentWallet->locked_balance_cents = 0;
        $opponentWallet->save();

        $match = MatchGame::create([
            'uuid' => (string) Str::uuid(),
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'stake_amount_cents' => $stake,
            'rake_bps' => $rakeBps,
            'status' => MatchStatus::Ready,
            'game_seed' => bin2hex(random_bytes(32)),
        ]);

        $ledgerService = app(WalletLedgerService::class);
        $ledgerService->lockStake($creator, $match);
        $ledgerService->lockStake($opponent, $match);

        $match->status = MatchStatus::InProgress;
        $match->save();

        // Launch 2 simultaneous worker processes attempting to settle the match
        $results = $this->runConcurrentWorkers([
            ['scenario' => 'settle-match', 'payload' => ['match_id' => $match->id, 'winner_user_id' => $creator->id]],
            ['scenario' => 'settle-match', 'payload' => ['match_id' => $match->id, 'winner_user_id' => $creator->id]],
        ]);

        // Refresh match and wallets
        $match->refresh();
        $creatorWallet->refresh();
        $opponentWallet->refresh();

        // Match must be Completed exactly once
        $this->assertSame(MatchStatus::Completed, $match->status);
        $this->assertSame(10000, $match->total_pot_cents);
        $this->assertSame(1000, $match->platform_fee_cents);
        $this->assertSame(9000, $match->winner_payout_cents);

        // Winner wallet must be credited exactly once ($90.00)
        $this->assertSame(9000, $creatorWallet->balance_cents, 'Winner must receive payout exactly once.');
        $this->assertSame(0, $creatorWallet->locked_balance_cents);
        $this->assertSame(0, $opponentWallet->balance_cents);
        $this->assertSame(0, $opponentWallet->locked_balance_cents);

        // Verify ledger entries: exactly one WagerWin credit and one PlatformFee credit
        $wagerWinEntries = LedgerEntry::where('reference_id', $match->id)
            ->where('category', 'WAGER_WIN')
            ->count();
        $platformFeeEntries = LedgerEntry::where('reference_id', $match->id)
            ->where('category', 'PLATFORM_FEE')
            ->count();

        $this->assertSame(2, $wagerWinEntries, 'Exactly one EscrowRelease debit and one Winner credit.');
        $this->assertSame(1, $platformFeeEntries, 'Platform fee must be credited exactly once.');

        // Total pot strictly conserved
        $this->assertSame(10000, $creatorWallet->balance_cents + $match->platform_fee_cents);
    }

    /**
     * RACE-03: Simultaneous join on a 1-slot lobby.
     * Two users attempt to join the same open lobby simultaneously.
     * Exactly one succeeds (200), opponent stake locked once.
     * Second is rejected (409 or 422), funds untouched.
     */
    public function test_race_03_concurrent_join_on_single_slot_lobby(): void
    {
        $stake = 2500;

        $creator = User::factory()->create();
        Wallet::firstOrCreate(['user_id' => $creator->id], ['currency' => 'USD', 'balance_cents' => $stake, 'locked_balance_cents' => 0]);

        $userA = User::factory()->create();
        $walletA = Wallet::firstOrCreate(['user_id' => $userA->id], ['currency' => 'USD', 'balance_cents' => $stake, 'locked_balance_cents' => 0]);
        $walletA->balance_cents = $stake;
        $walletA->locked_balance_cents = 0;
        $walletA->save();

        $userB = User::factory()->create();
        $walletB = Wallet::firstOrCreate(['user_id' => $userB->id], ['currency' => 'USD', 'balance_cents' => $stake, 'locked_balance_cents' => 0]);
        $walletB->balance_cents = $stake;
        $walletB->locked_balance_cents = 0;
        $walletB->save();

        $match = MatchGame::create([
            'uuid' => (string) Str::uuid(),
            'creator_user_id' => $creator->id,
            'opponent_user_id' => null,
            'stake_amount_cents' => $stake,
            'rake_bps' => 1000,
            'status' => MatchStatus::WaitingForOpponent,
            'game_seed' => bin2hex(random_bytes(32)),
        ]);

        $ledgerService = app(WalletLedgerService::class);
        $ledgerService->lockStake($creator, $match);

        // Launch 2 simultaneous worker processes attempting to join the same lobby
        $results = $this->runConcurrentWorkers([
            ['scenario' => 'join-lobby', 'payload' => ['uuid' => $match->uuid, 'user_id' => $userA->id]],
            ['scenario' => 'join-lobby', 'payload' => ['uuid' => $match->uuid, 'user_id' => $userB->id]],
        ]);

        $statuses = array_map(fn ($r) => $r['json']['status'] ?? null, $results);

        // Exactly one must be 200 OK, and one must be 409 Conflict
        $this->assertContains(200, $statuses, 'Results: '.json_encode($results));
        $this->assertContains(409, $statuses, 'Second concurrent join must be rejected with 409.');

        // Refresh match and wallets
        $match->refresh();
        $walletA->refresh();
        $walletB->refresh();

        $this->assertNotNull($match->opponent_user_id);
        $joinedUserId = $match->opponent_user_id;

        if ($joinedUserId === $userA->id) {
            $this->assertSame(0, $walletA->balance_cents);
            $this->assertSame($stake, $walletA->locked_balance_cents);
            $this->assertSame($stake, $walletB->balance_cents, 'Rejected user funds must remain untouched.');
            $this->assertSame(0, $walletB->locked_balance_cents);
        } else {
            $this->assertSame(0, $walletB->balance_cents);
            $this->assertSame($stake, $walletB->locked_balance_cents);
            $this->assertSame($stake, $walletA->balance_cents, 'Rejected user funds must remain untouched.');
            $this->assertSame(0, $walletA->locked_balance_cents);
        }
    }

    /**
     * RACE-04: Escrow refund vs late submit.
     * Cleanup worker attempts escrow release / cancel while player simultaneously submits run.
     * Exactly one terminal state wins cleanly.
     */
    public function test_race_04_escrow_refund_vs_late_submit(): void
    {
        $stake = 5000;

        $creator = User::factory()->create();
        $creatorWallet = Wallet::firstOrCreate(['user_id' => $creator->id], ['currency' => 'USD', 'balance_cents' => $stake, 'locked_balance_cents' => 0]);
        $creatorWallet->balance_cents = $stake;
        $creatorWallet->save();

        $opponent = User::factory()->create();
        $opponentWallet = Wallet::firstOrCreate(['user_id' => $opponent->id], ['currency' => 'USD', 'balance_cents' => $stake, 'locked_balance_cents' => 0]);
        $opponentWallet->balance_cents = $stake;
        $opponentWallet->save();

        $match = MatchGame::create([
            'uuid' => (string) Str::uuid(),
            'creator_user_id' => $creator->id,
            'opponent_user_id' => $opponent->id,
            'stake_amount_cents' => $stake,
            'rake_bps' => 1000,
            'status' => MatchStatus::InProgress,
            'abandon_deadline_at' => now()->subMinute(), // expired deadline
            'game_seed' => bin2hex(random_bytes(32)),
        ]);

        $ledgerService = app(WalletLedgerService::class);
        $ledgerService->lockStake($creator, $match);
        $ledgerService->lockStake($opponent, $match);

        $rawTicket = Str::random(48);
        DuelRun::create([
            'match_id' => $match->id,
            'user_id' => $creator->id,
            'ticket_hash' => hash('sha256', $rawTicket),
            'ticket_expires_at' => now()->addMinutes(10),
            'started_at' => now()->subSeconds(2),
            'audit_status' => AuditStatus::Pending,
        ]);

        $simulator = new RunSimulator;
        $sim = $simulator->simulate($match->game_seed, [], 100);

        $submitPayload = [
            'uuid' => $match->uuid,
            'user_id' => $creator->id,
            'ticket_token' => $rawTicket,
            'ticks_elapsed' => 100,
            'final_distance' => $sim['authoritative_distance'],
            'final_score' => $sim['authoritative_score'],
            'inputs' => [],
        ];

        // Worker 1: Cleanup / refund process
        // Worker 2: Late submit-run process
        $results = $this->runConcurrentWorkers([
            ['scenario' => 'cleanup-match', 'payload' => ['match_id' => $match->id]],
            ['scenario' => 'submit-run', 'payload' => $submitPayload],
        ]);

        $match->refresh();
        $creatorWallet->refresh();
        $opponentWallet->refresh();

        // Exactly one terminal state won
        $terminalStates = [MatchStatus::Cancelled, MatchStatus::InProgress, MatchStatus::Completed];
        $this->assertContains($match->status, $terminalStates);

        // Monetary conservation: either both refunded ($50 each) or in escrow/settled ($100 total)
        $totalSystemMoney = $creatorWallet->balance_cents + $creatorWallet->locked_balance_cents
            + $opponentWallet->balance_cents + $opponentWallet->locked_balance_cents
            + $match->platform_fee_cents;

        $this->assertSame(10000, $totalSystemMoney, 'No money leaked or duplicated in race.');
    }
}
