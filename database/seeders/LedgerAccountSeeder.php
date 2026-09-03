<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LedgerAccount;
use Illuminate\Database\Seeder;

class LedgerAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        LedgerAccount::escrowHolding();
        LedgerAccount::platformRake();
        LedgerAccount::userLiabilities();
    }
}
