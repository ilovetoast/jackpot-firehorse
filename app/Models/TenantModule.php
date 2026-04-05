<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class TenantModule extends Model
{
    public const KEY_CREATOR = 'creator_module';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'module_key',
        'status',
        'expires_at',
        'granted_by_admin',
        'seats_limit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'granted_by_admin' => 'boolean',
            'seats_limit' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TenantModule $module): void {
            if ($module->granted_by_admin && $module->expires_at === null) {
                throw new InvalidArgumentException(
                    'Admin-granted modules must set expires_at.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Rows that are currently entitled: active or trial, and not past {@see $expires_at}.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['active', 'trial'])
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
}
