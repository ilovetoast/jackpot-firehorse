<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6 — Contextual Navigation Intelligence reviewable recommendation row.
 *
 * Eloquent surface is intentionally thin. Business logic lives in:
 *   - ContextualNavigationRecommender (writes pending rows)
 *   - ContextualNavigationApprovalService (mutates rows + delegates to
 *     FolderQuickFilterAssignmentService)
 *   - ContextualNavigationStaleResolver (downgrades pending → stale)
 */
class ContextualNavigationRecommendation extends Model
{
    protected $table = 'contextual_navigation_recommendations';

    /*
    | Recommendation types. Strings (not enum) so adding a new one is a
    | code-only change. UI and approval logic switch on these.
    */
    public const TYPE_SUGGEST_QUICK_FILTER = 'suggest_quick_filter';
    public const TYPE_SUGGEST_PIN = 'suggest_pin_quick_filter';
    public const TYPE_SUGGEST_UNPIN = 'suggest_unpin_quick_filter';
    public const TYPE_SUGGEST_DISABLE = 'suggest_disable_quick_filter';
    public const TYPE_SUGGEST_OVERFLOW = 'suggest_move_to_overflow';
    public const TYPE_WARN_HIGH_CARDINALITY = 'warn_high_cardinality';
    public const TYPE_WARN_LOW_NAV_VALUE = 'warn_low_navigation_value';
    public const TYPE_WARN_FRAGMENTATION = 'warn_metadata_fragmentation';
    public const TYPE_WARN_LOW_COVERAGE = 'warn_low_coverage';
    // NOTE: warn_duplicate_contextual_filter was reserved for cross-field
    // dimensional similarity but never wired into the recommender. Removed
    // in the consolidation pass to keep the type list 1:1 with what
    // ContextualNavigationRecommender::deriveRecommendationTypes emits.
    // Re-introduce with a real producer + frontend label when implemented.

    public const ALL_TYPES = [
        self::TYPE_SUGGEST_QUICK_FILTER,
        self::TYPE_SUGGEST_PIN,
        self::TYPE_SUGGEST_UNPIN,
        self::TYPE_SUGGEST_DISABLE,
        self::TYPE_SUGGEST_OVERFLOW,
        self::TYPE_WARN_HIGH_CARDINALITY,
        self::TYPE_WARN_LOW_NAV_VALUE,
        self::TYPE_WARN_FRAGMENTATION,
        self::TYPE_WARN_LOW_COVERAGE,
    ];

    /*
    | Suggestion type subset (recommendation actions). The remainder are
    | warnings (informational only). Approval logic only operates on
    | suggestion types.
    */
    public const ACTIONABLE_TYPES = [
        self::TYPE_SUGGEST_QUICK_FILTER,
        self::TYPE_SUGGEST_PIN,
        self::TYPE_SUGGEST_UNPIN,
        self::TYPE_SUGGEST_DISABLE,
        self::TYPE_SUGGEST_OVERFLOW,
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_DEFERRED = 'deferred';
    public const STATUS_STALE = 'stale';

    public const SOURCE_STATISTICAL = 'statistical';
    public const SOURCE_AI = 'ai';
    public const SOURCE_HYBRID = 'hybrid';

    protected $fillable = [
        'tenant_id',
        'brand_id',
        'category_id',
        'metadata_field_id',
        'recommendation_type',
        'source',
        'status',
        'score',
        'confidence',
        'reason_summary',
        'metrics',
        'reviewed_by_user_id',
        'reviewed_at',
        'reviewer_notes',
        'last_seen_at',
    ];

    protected $casts = [
        'score' => 'float',
        'confidence' => 'float',
        'metrics' => 'array',
        'reviewed_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function metadataField(): BelongsTo
    {
        return $this->belongsTo(MetadataField::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function isActionable(): bool
    {
        return in_array($this->recommendation_type, self::ACTIONABLE_TYPES, true);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
