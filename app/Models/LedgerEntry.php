<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerEntryType;
use App\Enums\TransactionCategory;
use App\Exceptions\ImmutableModelException;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'transaction_group_id',
    'wallet_id',
    'ledger_account_id',
    'type',
    'amount_cents',
    'category',
    'reference_type',
    'reference_id',
    'description',
    'balance_after_cents',
])]
class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (LedgerEntry $entry): never {
            throw new ImmutableModelException('Ledger entries are strictly immutable and cannot be updated.');
        });

        static::deleting(function (LedgerEntry $entry): never {
            throw new ImmutableModelException('Ledger entries are strictly immutable and cannot be deleted.');
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_cents' => 'integer',
            'category' => TransactionCategory::class,
            'balance_after_cents' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Prevent save/update if model is already persisted.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new ImmutableModelException('Ledger entries are strictly immutable and cannot be modified.');
        }

        return parent::save($options);
    }

    /**
     * Prevent deletion.
     */
    public function delete(): ?bool
    {
        throw new ImmutableModelException('Ledger entries are strictly immutable and cannot be deleted.');
    }

    /**
     * Get the related wallet, if any.
     *
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * Get the related ledger account, if any.
     *
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    /**
     * Get the morph reference model (e.g. MatchGame).
     *
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
