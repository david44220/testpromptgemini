<!DOCTYPE html>
<html lang="en" class="h-full bg-[#0A0A0C]">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Cyber-Rail 3D Duel Arena — Dark Luxury Esports</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        * {
            box-sizing: border-box;
            user-select: none;
            -webkit-user-select: none;
        }
        body, html {
            width: 100%;
            height: 100%;
            overflow: hidden;
            background-color: #0A0A0C;
        }
    </style>
</head>
<body class="w-full h-full relative overflow-hidden bg-[#0A0A0C] font-sans text-slate-100">

    <!-- ARENA VIEW CONTAINER (100dvw, 100dvh) -->
    <div id="arena-viewport" class="w-[100dvw] h-[100dvh] relative overflow-hidden flex items-center justify-center">
        
        <!-- Three.js Background Canvas Layer -->
        <canvas 
            id="game-canvas" 
            class="w-full h-full block absolute inset-0 z-10"
            @if(empty($matchUuid)) data-seed="{{ $seed ?? '' }}" @endif
            data-commitment="{{ !empty($seed) ? hash('sha256', $seed) : '' }}"
            data-match="{{ $matchUuid ?? '' }}"
            data-stake="{{ $stakeCents ?? 5000 }}"
            data-pot="{{ $potCents ?? 10000 }}"
        ></canvas>

        <!-- Real-Time Decoupled HUD Overlay Layer (pointer-events-none) -->
        @include('game.hud', [
            'potCents' => $potCents ?? 10000,
            'seed' => $seed ?? 'e3b0c442',
            'isPaid' => !empty($matchUuid),
            'user' => $user,
            'wallet' => $wallet,
        ])

        <!-- Post-Match Settlement Modal Layer -->
        @include('game.post-match-modal', [
            'rewardedAdsAvailable' => config('duels.rewarded_ads_enabled', false),
        ])

    </div>

</body>
</html>

