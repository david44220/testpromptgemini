<?php

namespace App\Providers;

use App\Contracts\RewardedAdVerifier;
use App\Services\Ads\NullRewardedAdVerifier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            RewardedAdVerifier::class,
            NullRewardedAdVerifier::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
