<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MatchStatus;
use Database\Factories\MatchGameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

#[Fillable([
    'uuid',
    'creator_user_id',
    'opponent_user_id',
    'stake_amount_cents',
    'rake_percentage',
    'total_pot_cents',
    'platform_fee_cents',
    'winner_payout_cents',
    'status',
    'winner_user_id',
    'game_seed',
    'settled_at',
])]
class MatchGame extends Model
{
    /** @use HasFactory<MatchGameFactory> */
    use HasFactory;

    protected $table = 'matches';

    protected static function booted(): void
    {
        static::creating(function (MatchGame $match): void {
            if (empty($match->uuid)) {
                $match->uuid = (string) Str::uuid();
            }

            if (empty($match->game_seed)) {
                $match->game_seed = bin2hex(random_bytes(32));
            }
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
            'status' => MatchStatus::class,
            'stake_amount_cents' => 'integer',
            'rake_percentage' => 'decimal:2',
            'total_pot_cents' => 'integer',
            'platform_fee_cents' => 'integer',
            'winner_payout_cents' => 'integer',
            'settled_at' => 'datetime',
        ];
    }

    /**
     * Scope to find open lobbies waiting for an opponent.
     *
     * @param  Builder<MatchGame>  $query
     * @return Builder<MatchGame>
     */
    public function scopeOpenLobbies(Builder $query): Builder
    {
        return $query->where('status', MatchStatus::WaitingForOpponent)
            ->whereNull('opponent_user_id');
    }

    /**
     * Scope to find matches involving a given user ID as creator or opponent.
     *
     * @param  Builder<MatchGame>  $query
     * @return Builder<MatchGame>
     */
    public function scopeForUser(Builder $query, int|string $userId): Builder
    {
        return $query->where(function (Builder $sub) use ($userId): void {
            $sub->where('creator_user_id', $userId)
                ->orWhere('opponent_user_id', $userId);
        });
    }

    /**
     * The creator of the match.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    /**
     * The opponent user of the match.
     *
     * @return BelongsTo<User, $this>
     */
    public function opponent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opponent_user_id');
    }

    /**
     * The winner of the match.
     *
     * @return BelongsTo<User, $this>
     */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }

    /**
     * Ledger entries referencing this match.
     *
     * @return MorphMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'reference');
    }

    /**
     * Replay runs executed by players for this match.
     *
     * @return HasMany<DuelRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(DuelRun::class, 'match_id');
    }
}
