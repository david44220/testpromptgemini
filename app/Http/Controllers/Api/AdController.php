<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Ads\AdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdController extends Controller
{
    public function __construct(
        protected AdService $adService
    ) {}

    /**
     * GET /api/v1/ads/active-creatives
     * Delivers active sponsor creatives for 3D trackside billboards and web banner components.
     */
    public function activeCreatives(): JsonResponse
    {
        return response()->json($this->adService->getActiveCreatives());
    }

    /**
     * POST /api/v1/ads/impression/{id}
     * High-speed beacon receiver for tracking 3D billboard and banner impressions.
     */
    public function recordImpression(string $id, Request $request): JsonResponse
    {
        $result = $this->adService->recordImpression($id, $request->ip());

        return response()->json([
            'status' => 'OK',
            'data' => $result,
        ]);
    }

    /**
     * POST /api/v1/ads/rewarded-complete
     * Grants rewards (e.g. 2% rake discount on next duel) upon completing a sponsor clip.
     */
    public function completeRewardedAd(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'creative_id' => ['required', 'string'],
            'provider_event_id' => ['required', 'string', 'min:16', 'max:128'],
            'verification_token' => ['nullable', 'string', 'max:256'],
        ]);

        try {
            $reward = $this->adService->claimRewardedAd(
                $user,
                $validated['creative_id'],
                $validated['provider_event_id'],
                $validated['verification_token'] ?? null
            );

            return response()->json([
                'message' => 'Sponsor reward claimed successfully.',
                'reward' => $reward,
            ]);
        } catch (\DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
