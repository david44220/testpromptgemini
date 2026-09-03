<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'currency',
    'balance_cents',
    'bonus_balance_cents',
    'locked_balance_cents',
])]
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance_cents' => 'integer',
            'bonus_balance_cents' => 'integer',
            'locked_balance_cents' => 'integer',
        ];
    }

    /**
     * Get available balance (spendable amount) in cents.
     */
    public function getAvailableBalanceAttribute(): int
    {
        return (int) $this->balance_cents;
    }

    /**
     * Get total balance (available + locked) in cents.
     */
    public function getTotalBalanceAttribute(): int
    {
        return (int) ($this->balance_cents + $this->locked_balance_cents);
    }

    /**
     * Get the owning user.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get ledger entries referencing this wallet.
     *
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
