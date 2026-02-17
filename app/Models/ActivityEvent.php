<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ActivityEvent Model
 * 
 * Append-only activity logging for audit-grade event tracking.
 * 
 * Key characteristics:
 * - Immutable after creation (no updated_at)
 * - Tenant-aware at database level
 * - Supports brand-level, asset-level, and tenant-level queries
 * - Designed for high-volume non-CRUD events
 * 
 * @property int $id
 * @property int $tenant_id
 * @property int|null $brand_id
 * @property string $actor_type (user, system, api, guest)
 * @property int|null $actor_id
 * @property string $event_type
 * @property string $subject_type
 * @property int $subject_id
 * @property array|null $metadata
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Carbon\Carbon $created_at
 */
class ActivityEvent extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'activity_events';

    /**
     * Indicates if the model should be timestamped.
     * Only created_at, no updated_at (append-only).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'tenant_id',
        'brand_id',
        'actor_type',
        'actor_id',
        'event_type',
        'subject_type',
        'subject_id',
        'metadata',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    /**
     * Boot the model.
     * Prevent updates and deletions to maintain immutability.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Prevent updates (append-only)
        static::updating(function ($model) {
            return false;
        });

        // Prevent deletions (audit trail must be preserved)
        static::deleting(function ($model) {
            return false;
        });
    }

    /**
     * Get the tenant that owns this activity event.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the brand associated with this activity event.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * String actor types that don't correspond to model classes.
     */
    protected static array $stringActorTypes = ['system', 'api', 'guest'];

    /**
     * Normalize actor_type value.
     * Store short strings ('user', 'system', 'api', 'guest') to avoid tight coupling to class names.
     */
    protected function normalizeActorType(?string $value): ?string
    {
        if (! $value) {
            return $value;
        }

        // Map class names to short strings for stable storage
        $classToShort = [
            \App\Models\User::class => 'user',
        ];
        if (isset($classToShort[$value])) {
            return $classToShort[$value];
        }

        // Keep short string types as-is
        if (in_array($value, static::$stringActorTypes, true)) {
            return $value;
        }

        return $value;
    }

    /**
     * Set the actor_type attribute with normalization.
     */
    public function setActorTypeAttribute($value): void
    {
        $this->attributes['actor_type'] = $this->normalizeActorType($value);
    }

    /**
     * Get the actor (polymorphic).
     * Can be User, System, API, or Guest.
     * Handles string actor types (system, api, guest) that aren't model classes.
     * 
     * Note: This relationship should NOT be eager loaded when there are string types.
     * Use getActorModel() method instead for safe access.
     */
    public function actor(): MorphTo
    {
        return $this->morphTo('actor', 'actor_type', 'actor_id');
    }

    /**
     * Get the subject (polymorphic).
     * The model that this event is about (e.g., Asset, User, Tenant).
     */
    public function subject(): MorphTo
    {
        return $this->morphTo('subject', 'subject_type', 'subject_id');
    }
    
    /**
     * Check if actor type is a string type (not a model class).
     */
    public function isStringActorType(): bool
    {
        return in_array($this->actor_type, static::$stringActorTypes, true);
    }
    
    /**
     * Get actor model safely, handling string types.
     * Use this instead of accessing $event->actor directly to avoid errors.
     */
    public function getActorModel()
    {
        // String types (system, api, guest) don't have models
        if ($this->isStringActorType()) {
            return null;
        }
        
        // Only try to load if it's a 'user' type
        if ($this->actor_type === 'user' && $this->actor_id) {
            try {
                return \App\Models\User::find($this->actor_id);
            } catch (\Exception $e) {
                return null;
            }
        }
        
        return null;
    }

    /**
     * Scope to filter by tenant.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $tenantId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter by brand.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int|null $brandId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForBrand($query, ?int $brandId)
    {
        if ($brandId === null) {
            return $query->whereNull('brand_id');
        }
        
        return $query->where('brand_id', $brandId);
    }

    /**
     * Scope to filter by event type.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $eventType
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOfType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    /**
     * Scope to filter by subject.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $subjectType
     * @param int $subjectId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForSubject($query, string $subjectType, int $subjectId)
    {
        return $query->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId);
    }

    /**
     * Scope to get recent events.
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query, int $limit = 50)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }
}
