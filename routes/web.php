<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\UserDashboardController;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

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
        $wallet = $user->wallet ?: Wallet::firstOrCreate(
            ['user_id' => $user->id],
            [
                'currency' => 'USD',
                'balance_cents' => 10000,
                'bonus_balance_cents' => 0,
                'locked_balance_cents' => 0,
            ]
        );

        return view('lobby', [
            'user' => $user,
            'wallet' => $wallet,
        ]);
    })->name('duels.lobby');

    // 3D Combat Arena (Auth Required - Staking with Authenticated Wallet)
    Route::get('/game', function (Request $request) {
        /** @var User $user */
        $user = $request->user();
        $wallet = $user->wallet ?: Wallet::firstOrCreate(
            ['user_id' => $user->id],
            [
                'currency' => 'USD',
                'balance_cents' => 10000,
                'bonus_balance_cents' => 0,
                'locked_balance_cents' => 0,
            ]
        );

        $seed = $request->query('seed') ?: bin2hex(random_bytes(32));
        $stakeCents = (int) ($request->query('stake') ?: 5000);
        $potCents = $stakeCents * 2;
        $matchUuid = $request->query('match') ?: (string) Str::uuid();

        // Generate temporary API token for real-time WebSocket and telemetry submissions
        $apiToken = $user->createToken('cyber-arena-token')->plainTextToken;

        return view('game', [
            'user' => $user,
            'wallet' => $wallet,
            'seed' => $seed,
            'stakeCents' => $stakeCents,
            'potCents' => $potCents,
            'matchUuid' => $matchUuid,
            'apiToken' => $apiToken,
        ]);
    })->name('game.play');
});
