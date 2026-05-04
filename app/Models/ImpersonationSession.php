<?php

namespace App\Models;

use App\Enums\ImpersonationMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImpersonationSession extends Model
{
    protected $fillable = [
        'initiator_user_id',
        'target_user_id',
        'tenant_id',
        'mode',
        'reason',
        'ticket_id',
        'started_at',
        'expires_at',
        'ended_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'mode' => ImpersonationMode::class,
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** The real signed-in user who started the session. */
    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_user_id');
    }

    /** The user being impersonated (target workspace identity). */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(ImpersonationAudit::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    /**
     * True when the session row is past expiry but not yet marked ended (should be rare; middleware normally ends it).
     */
    public function isExpired(): bool
    {
        return $this->ended_at === null && $this->expires_at->isPast();
    }

    /**
     * active — in flight; ended — closed before TTL (includes admin force); expired — TTL reached or closed after expiry.
     */
    public function status(): string
    {
        if ($this->isActive()) {
            return 'active';
        }
        if ($this->isExpired()) {
            return 'expired';
        }
        if ($this->ended_at !== null && $this->ended_at->greaterThanOrEqualTo($this->expires_at)) {
            return 'expired';
        }

        return 'ended';
    }

    public function modeLabel(): string
    {
        return $this->mode->label();
    }

    /**
     * Wall-clock span from start to end (or now if still active).
     */
    public function durationSeconds(): ?float
    {
        if ($this->started_at === null) {
            return null;
        }
        $end = $this->ended_at ?? now();

        return (float) $this->started_at->diffInSeconds($end, false);
    }

    /**
     * Seconds until expiry when active; null otherwise.
     */
    public function remainingSeconds(): ?int
    {
        if (! $this->isActive()) {
            return null;
        }

        return max(0, (int) now()->diffInSeconds($this->expires_at, false));
    }
}
