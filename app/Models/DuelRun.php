<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'match_id',
    'user_id',
    'session_secret',
    'ticket_token',
    'ticket_hash',
    'ticket_expires_at',
    'started_at',
    'submitted_at',
    'ticks_elapsed',
    'final_distance',
    'final_score',
    'inputs_hash',
    'input_log',
    'client_signature',
    'audit_status',
    'audit_failure_reason',
])]
class DuelRun extends Model
{
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'audit_status' => AuditStatus::class,
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'ticket_expires_at' => 'datetime',
            'ticks_elapsed' => 'integer',
            'final_distance' => 'decimal:2',
            'final_score' => 'integer',
            'input_log' => 'array',
        ];
    }

    /**
     * Get the associated match.
     *
     * @return BelongsTo<MatchGame, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchGame::class, 'match_id');
    }

    /**
     * Get the player user who executed this run.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
