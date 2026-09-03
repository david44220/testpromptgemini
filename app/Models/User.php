<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'uuid', 'status', 'risk_score'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'risk_score' => 'integer',
        ];
    }

    /**
     * Get the user's primary wallet.
     *
     * @return HasOne<Wallet, $this>
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /**
     * Get matches created by the user.
     *
     * @return HasMany<MatchGame, $this>
     */
    public function createdMatches(): HasMany
    {
        return $this->hasMany(MatchGame::class, 'creator_user_id');
    }

    /**
     * Get matches joined by the user as opponent.
     *
     * @return HasMany<MatchGame, $this>
     */
    public function joinedMatches(): HasMany
    {
        return $this->hasMany(MatchGame::class, 'opponent_user_id');
    }

    /**
     * Get matches won by the user.
     *
     * @return HasMany<MatchGame, $this>
     */
    public function wonMatches(): HasMany
    {
        return $this->hasMany(MatchGame::class, 'winner_user_id');
    }
}
