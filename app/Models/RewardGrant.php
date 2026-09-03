<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'creative_id',
    'provider_event_id',
    'reward_type',
    'value_bps',
    'expires_at',
    'consumed_at',
])]
class RewardGrant extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'value_bps' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
