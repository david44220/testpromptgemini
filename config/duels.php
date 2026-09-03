<?php

declare(strict_types=1);

return [
    'default_rake_bps' => (int) env('DEFAULT_RAKE_BPS', 1000),
    'abandon_timeout_minutes' => (int) env('DUEL_ABANDON_TIMEOUT_MINUTES', 10),
    'forfeit_timeout_seconds' => (int) env('DUEL_FORFEIT_TIMEOUT_SECONDS', 180),
    'demo_mode' => (bool) env('DEMO_MODE', false),
    'rewarded_ads_enabled' => (bool) env('REWARDED_ADS_ENABLED', false),
];
