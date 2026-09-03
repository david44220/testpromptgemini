<?php

namespace Database\Factories;

use App\Enums\LedgerEntryType;
use App\Enums\TransactionCategory;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LedgerEntry>
 */
class LedgerEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_group_id' => (string) Str::uuid(),
            'wallet_id' => Wallet::factory(),
            'ledger_account_id' => null,
            'type' => LedgerEntryType::Credit,
            'amount_cents' => 1000,
            'category' => TransactionCategory::Deposit,
            'reference_type' => null,
            'reference_id' => null,
            'description' => 'Test deposit',
            'balance_after_cents' => 1000,
        ];
    }
}
