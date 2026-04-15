<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignVisualReference extends Model
{
    public const TYPE_IDENTITY = 'identity';
    public const TYPE_LOGO_VARIANT = 'logo_variant';
    public const TYPE_STYLE = 'style';
    public const TYPE_MOOD = 'mood';
    public const TYPE_MOTIF = 'motif';
    public const TYPE_EXEMPLAR = 'exemplar';

    public const ALL_TYPES = [
        self::TYPE_IDENTITY,
        self::TYPE_LOGO_VARIANT,
        self::TYPE_STYLE,
        self::TYPE_MOOD,
        self::TYPE_MOTIF,
        self::TYPE_EXEMPLAR,
    ];

    /**
     * Reference types that inform the Identity evaluator.
     */
    public const IDENTITY_TYPES = [
        self::TYPE_IDENTITY,
        self::TYPE_LOGO_VARIANT,
    ];

    /**
     * Reference types that inform the Visual Style evaluator.
     * Mood and motif references carry lower weight than direct style references.
     * Exemplar executions inform context fit more than strict style compliance.
     */
    public const STYLE_TYPES = [
        self::TYPE_STYLE,
        self::TYPE_MOOD,
        self::TYPE_MOTIF,
        self::TYPE_EXEMPLAR,
    ];

    /**
     * Default weight multiplier per reference type.
     * Mood and exemplar are intentionally softer than style/identity.
     */
    public const TYPE_WEIGHT_DEFAULTS = [
        self::TYPE_IDENTITY => 1.0,
        self::TYPE_LOGO_VARIANT => 1.0,
        self::TYPE_STYLE => 1.0,
        self::TYPE_MOOD => 0.5,
        self::TYPE_MOTIF => 0.6,
        self::TYPE_EXEMPLAR => 0.4,
    ];

    protected $fillable = [
        'campaign_identity_id',
        'asset_id',
        'reference_type',
        'embedding_vector',
        'weight',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'embedding_vector' => 'array',
        ];
    }

    public function campaignIdentity(): BelongsTo
    {
        return $this->belongsTo(CollectionCampaignIdentity::class, 'campaign_identity_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Effective weight: explicit column value or type-based default.
     */
    public function effectiveWeight(): float
    {
        if ($this->weight !== null && is_numeric($this->weight)) {
            return max(0.0, (float) $this->weight);
        }

        return self::TYPE_WEIGHT_DEFAULTS[$this->reference_type] ?? 0.5;
    }

    /**
     * Whether this reference should be used for identity/logo similarity.
     */
    public function isIdentityReference(): bool
    {
        return in_array($this->reference_type, self::IDENTITY_TYPES, true);
    }

    /**
     * Whether this reference should be used for visual style similarity.
     */
    public function isStyleReference(): bool
    {
        return in_array($this->reference_type, self::STYLE_TYPES, true);
    }
}
