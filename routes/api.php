<?php

use App\Http\Controllers\Api\AdController;
use App\Http\Controllers\Api\DuelLobbyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1/duels')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function (): void {
    Route::post('/lobbies', [DuelLobbyController::class, 'createLobby'])->name('duels.lobbies.create');
    Route::get('/lobbies', [DuelLobbyController::class, 'listLobbies'])->name('duels.lobbies.list');
    Route::post('/lobbies/{uuid}/join', [DuelLobbyController::class, 'joinLobby'])->name('duels.lobbies.join');
    Route::post('/matches/{uuid}/start-run', [DuelLobbyController::class, 'startRun'])->name('duels.matches.start-run');
    Route::post('/matches/{uuid}/submit-run', [DuelLobbyController::class, 'submitRun'])->name('duels.matches.submit-run');
    Route::post('/matches/{uuid}/telemetry', [DuelLobbyController::class, 'broadcastTelemetry'])->name('duels.matches.telemetry');
});

Route::prefix('v1/ads')->group(function (): void {
    Route::get('/active-creatives', [AdController::class, 'activeCreatives'])->name('ads.creatives');
    Route::post('/impression/{id}', [AdController::class, 'recordImpression'])->name('ads.impression');
    Route::post('/rewarded-complete', [AdController::class, 'completeRewardedAd'])->middleware('auth:sanctum')->name('ads.rewarded-complete');
});
