<?php

use App\Enums\MatchStatus;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\UserDashboardController;
use App\Models\MatchGame;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Authentication Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
    Route::post('/login/demo', [AuthenticatedSessionController::class, 'loginAsDemo'])->name('login.demo');

    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// Authenticated User Area & Arena Combat
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [UserDashboardController::class, 'index'])->name('dashboard');
    Route::post('/user/wallet/deposit', [UserDashboardController::class, 'deposit'])->name('wallet.deposit');

    // Multiplayer Duel Lobbies (Auth Required)
    Route::get('/lobby', function (Request $request) {
        /** @var User $user */
        $user = $request->user();
        $initialBalance = config('duels.demo_mode', false) ? 10000 : 0;
        $wallet = $user->wallet ?: Wallet::firstOrCreate(
            ['user_id' => $user->id],
            [
                'currency' => 'USD',
                'balance_cents' => $initialBalance,
                'bonus_balance_cents' => 0,
                'locked_balance_cents' => 0,
            ]
        );

        return view('lobby', [
            'user' => $user,
            'wallet' => $wallet,
        ]);
    })->name('duels.lobby');

    // Practice / Solo Simulation Mode (No real stake, no match UUID)
    Route::get('/game', function (Request $request) {
        /** @var User $user */
        $user = $request->user();
        $initialBalance = config('duels.demo_mode', false) ? 10000 : 0;
        $wallet = $user->wallet ?: Wallet::firstOrCreate(
            ['user_id' => $user->id],
            [
                'currency' => 'USD',
                'balance_cents' => $initialBalance,
                'bonus_balance_cents' => 0,
                'locked_balance_cents' => 0,
            ]
        );

        $seed = $request->query('seed') ?: bin2hex(random_bytes(32));

        // Delete previous session tokens to prevent token table accumulation
        $user->tokens()->where('name', 'cyber-arena-session')->delete();
        $apiToken = $user->createToken('cyber-arena-session')->plainTextToken;

        return view('game', [
            'user' => $user,
            'wallet' => $wallet,
            'seed' => $seed,
            'stakeCents' => 0,
            'potCents' => 0,
            'matchUuid' => null,
            'isPractice' => true,
            'apiToken' => $apiToken,
        ]);
    })->name('game.play');

    // Authoritative Paid Duel Runner (Auth Required - Validated against Match Record)
    Route::get('/duels/{uuid}/play', function (string $uuid, Request $request) {
        /** @var User $user */
        $user = $request->user();

        /** @var MatchGame $match */
        $match = MatchGame::where('uuid', $uuid)->firstOrFail();

        // Verify caller is a verified participant in this match
        if ($match->creator_user_id !== $user->id && $match->opponent_user_id !== $user->id) {
            abort(403, 'Unauthorized: You are not a participant in this duel.');
        }

        // Verify match is in a playable state
        $playableStates = [
            MatchStatus::WaitingForOpponent,
            MatchStatus::InProgress,
            MatchStatus::Ready,
        ];
        if (! in_array($match->status, $playableStates, true)) {
            return redirect()->route('dashboard')->withErrors([
                'error' => 'This duel has concluded and is no longer playable.',
            ]);
        }

        $initialBalance = config('duels.demo_mode', false) ? 10000 : 0;
        $wallet = $user->wallet ?: Wallet::firstOrCreate(
            ['user_id' => $user->id],
            [
                'currency' => 'USD',
                'balance_cents' => $initialBalance,
                'bonus_balance_cents' => 0,
                'locked_balance_cents' => 0,
            ]
        );

        // Delete old session tokens to maintain exactly 1 active token per user
        $user->tokens()->where('name', 'cyber-arena-session')->delete();
        $apiToken = $user->createToken('cyber-arena-session')->plainTextToken;

        return view('game', [
            'user' => $user,
            'wallet' => $wallet,
            'seed' => $match->game_seed,
            'stakeCents' => $match->stake_amount_cents,
            'potCents' => $match->stake_amount_cents * 2,
            'matchUuid' => $match->uuid,
            'isPractice' => false,
            'apiToken' => $apiToken,
        ]);
    })->name('duels.play');
});
