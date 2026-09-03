<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MatchStatus;
use App\Models\MatchGame;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Financial\WalletLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class UserDashboardController extends Controller
{
    /**
     * Display the authenticated user's duel management dashboard.
     */
    public function index(): View
    {
        /** @var User $user */
        $user = Auth::user();

        // Ensure wallet exists with demo mode isolation
        $isDemoMode = (bool) config('duels.demo_mode', false);
        $initialBalance = $isDemoMode ? 10000 : 0;
        $wallet = $user->wallet ?: Wallet::create([
            'user_id' => $user->id,
            'currency' => 'USD',
            'balance_cents' => $initialBalance,
            'bonus_balance_cents' => 0,
            'locked_balance_cents' => 0,
        ]);

        // Active duels (waiting for opponent or currently live)
        $activeMatches = MatchGame::with(['creator', 'opponent'])
            ->where(function ($q) use ($user) {
                $q->where('creator_user_id', $user->id)
                    ->orWhere('opponent_user_id', $user->id);
            })
            ->whereIn('status', [MatchStatus::WaitingForOpponent, MatchStatus::InProgress])
            ->latest('created_at')
            ->get();

        // Resolved duel history
        $historyMatches = MatchGame::with(['creator', 'opponent', 'winner'])
            ->where(function ($q) use ($user) {
                $q->where('creator_user_id', $user->id)
                    ->orWhere('opponent_user_id', $user->id);
            })
            ->whereIn('status', [MatchStatus::Completed, MatchStatus::Disputed, MatchStatus::Cancelled])
            ->latest('updated_at')
            ->limit(15)
            ->get();

        // Calculate performance metrics
        $totalMatches = MatchGame::where(function ($q) use ($user) {
            $q->where('creator_user_id', $user->id)
                ->orWhere('opponent_user_id', $user->id);
        })->where('status', MatchStatus::Completed)->count();

        $wins = MatchGame::where('winner_user_id', $user->id)
            ->where('status', MatchStatus::Completed)
            ->count();

        $winRate = $totalMatches > 0 ? (int) round(($wins / $totalMatches) * 100) : 0;

        $totalWinningsCents = MatchGame::where('winner_user_id', $user->id)
            ->where('status', MatchStatus::Completed)
            ->sum('winner_payout_cents');

        return view('dashboard', [
            'user' => $user,
            'wallet' => $wallet,
            'activeMatches' => $activeMatches,
            'historyMatches' => $historyMatches,
            'totalMatches' => $totalMatches,
            'wins' => $wins,
            'winRate' => $winRate,
            'totalWinningsCents' => $totalWinningsCents,
        ]);
    }

    /**
     * Simulate depositing funds into user wallet for testing.
     */
    public function deposit(Request $request): RedirectResponse
    {
        if (! config('duels.demo_mode', false)) {
            abort(403, 'Simulated deposits are disabled in production.');
        }

        $validated = $request->validate([
            'amount_dollars' => ['required', 'numeric', 'min:10', 'max:5000'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        $cents = (int) round(((float) $validated['amount_dollars']) * 100);

        $ledgerService = app(WalletLedgerService::class);
        $ledgerService->deposit($user, $cents, 'Dashboard simulated deposit');

        return back()->with('status', 'Deposited $'.number_format($cents / 100, 2).' successfully into your wallet!');
    }
}
