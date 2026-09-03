<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerAccountType;
use Database\Factories\LedgerAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'account_code',
    'name',
    'type',
])]
class LedgerAccount extends Model
{
    /** @use HasFactory<LedgerAccountFactory> */
    use HasFactory;

    public const CODE_ESCROW_HOLDING = 'ESCROW_HOLDING';

    public const CODE_PLATFORM_REVENUE_RAKE = 'PLATFORM_REVENUE_RAKE';

    public const CODE_USER_LIABILITIES = 'USER_LIABILITIES';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'type' => LedgerAccountType::class,
        ];
    }

    /**
     * Resolve the Escrow Holding liability account.
     */
    public static function escrowHolding(): self
    {
        return static::firstOrCreate(
            ['account_code' => self::CODE_ESCROW_HOLDING],
            [
                'name' => 'Escrow Holding Pool',
                'type' => LedgerAccountType::Liability,
            ]
        );
    }

    /**
     * Resolve the Platform Revenue / Rake account.
     */
    public static function platformRake(): self
    {
        return static::firstOrCreate(
            ['account_code' => self::CODE_PLATFORM_REVENUE_RAKE],
            [
                'name' => 'Platform Revenue Rake',
                'type' => LedgerAccountType::Revenue,
            ]
        );
    }

    /**
     * Alias for platform revenue account.
     */
    public static function platformRevenue(): self
    {
        return static::platformRake();
    }

    /**
     * Resolve the User Liabilities account.
     */
    public static function userLiabilities(): self
    {
        return static::firstOrCreate(
            ['account_code' => self::CODE_USER_LIABILITIES],
            [
                'name' => 'User Balance Liabilities',
                'type' => LedgerAccountType::Liability,
            ]
        );
    }

    /**
     * Get ledger entries for this account.
     *
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
