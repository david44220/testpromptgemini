<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        $demoAccounts = [
            [
                'name' => 'Apex Titan',
                'email' => 'apex@cyber-rail.gg',
                'balance' => '$2,500.00',
                'role' => 'High-Roller Champion',
                'avatar' => 'https://ui-avatars.com/api/?name=Apex+Titan&background=D4AF37&color=000',
            ],
            [
                'name' => 'Viper 99',
                'email' => 'viper@cyber-rail.gg',
                'balance' => '$1,000.00',
                'role' => 'Pro Duelist',
                'avatar' => 'https://ui-avatars.com/api/?name=Viper+99&background=FF0055&color=fff',
            ],
            [
                'name' => 'Cyber Ghost',
                'email' => 'ghost@cyber-rail.gg',
                'balance' => '$750.00',
                'role' => 'Speed Specialist',
                'avatar' => 'https://ui-avatars.com/api/?name=Cyber+Ghost&background=00F0FF&color=000',
            ],
            [
                'name' => 'Neon Rookie',
                'email' => 'rookie@cyber-rail.gg',
                'balance' => '$250.00',
                'role' => 'Contender',
                'avatar' => 'https://ui-avatars.com/api/?name=Neon+Rookie&background=10B981&color=000',
            ],
        ];

        $isDemoMode = (bool) config('duels.demo_mode', false);

        return view('auth.login', [
            'demoAccounts' => $isDemoMode ? $demoAccounts : [],
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    /**
     * Handle instant one-click login for demo accounts.
     */
    public function loginAsDemo(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        if (! config('duels.demo_mode', false)) {
            abort(403, 'Demo login is disabled in production mode.');
        }

        $allowedDemoEmails = [
            'apex@cyber-rail.gg',
            'viper@cyber-rail.gg',
            'ghost@cyber-rail.gg',
            'rookie@cyber-rail.gg',
            'test@example.com',
        ];

        $email = (string) $request->input('email');
        if (! in_array($email, $allowedDemoEmails, true)) {
            return back()->withErrors(['email' => 'Unauthorized: Only predefined demo accounts can use one-click demo login.']);
        }

        /** @var User|null $user */
        $user = User::where('email', $email)->first();

        if (! $user) {
            return back()->withErrors(['email' => 'Demo account not found in database. Run db:seed first.']);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
