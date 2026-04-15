<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CollectionCampaignIdentity extends Model
{
    protected $fillable = [
        'collection_id',
        'campaign_name',
        'campaign_slug',
        'campaign_status',
        'campaign_goal',
        'campaign_description',
        'identity_payload',
        'readiness_status',
        'scoring_enabled',
        'featured_asset_id',
        'created_by',
    ];

    protected $attributes = [
        'identity_payload' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'identity_payload' => 'array',
            'scoring_enabled' => 'boolean',
        ];
    }

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ARCHIVED = 'archived';

    public const READINESS_INCOMPLETE = 'incomplete';
    public const READINESS_PARTIAL = 'partial';
    public const READINESS_READY = 'ready';

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function featuredAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'featured_asset_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function campaignVisualReferences(): HasMany
    {
        return $this->hasMany(CampaignVisualReference::class, 'campaign_identity_id');
    }

    public function campaignAlignmentScores(): HasMany
    {
        return $this->hasMany(CampaignAlignmentScore::class, 'campaign_identity_id');
    }

    /**
     * Whether scoring can run: requires scoring_enabled AND readiness >= partial.
     */
    public function isScorable(): bool
    {
        return $this->scoring_enabled
            && in_array($this->readiness_status, [self::READINESS_PARTIAL, self::READINESS_READY], true);
    }

    /**
     * Compute readiness from identity_payload content and campaign references.
     *
     * Scoring pillars:
     *   1. Visual identity -- palette has >= 1 color OR style_description is set
     *   2. Messaging -- tone is set OR pillars has entries OR approved/discouraged phrases exist
     *   3. Campaign references -- at least 1 CampaignVisualReference exists
     *
     * incomplete: fewer than 1 pillar and no campaign_goal
     * partial: at least 1 pillar OR campaign_goal is set, but fewer than 2 pillars
     * ready: at least 2 pillars AND (campaign_goal or campaign_description is set)
     */
    public function computeReadiness(): string
    {
        $payload = is_array($this->identity_payload) ? $this->identity_payload : [];
        $pillars = 0;

        // Pillar 1: Visual identity
        $visual = $payload['visual'] ?? null;
        if (is_array($visual)) {
            $hasPalette = ! empty($visual['palette']);
            $hasStyleDesc = ! empty($visual['style_description']);
            if ($hasPalette || $hasStyleDesc) {
                $pillars++;
            }
        }

        // Pillar 2: Messaging
        $messaging = $payload['messaging'] ?? null;
        if (is_array($messaging)) {
            $hasTone = ! empty($messaging['tone']);
            $hasPillars = ! empty($messaging['pillars']);
            $hasApproved = ! empty($messaging['approved_phrases']);
            $hasDiscouraged = ! empty($messaging['discouraged_phrases']);
            if ($hasTone || $hasPillars || $hasApproved || $hasDiscouraged) {
                $pillars++;
            }
        }

        // Pillar 3: Campaign references
        $hasReferences = $this->campaignVisualReferences()->exists();
        if ($hasReferences) {
            $pillars++;
        }

        $hasGoalOrDesc = ! empty($this->campaign_goal) || ! empty($this->campaign_description);

        if ($pillars >= 2 && $hasGoalOrDesc) {
            return self::READINESS_READY;
        }

        if ($pillars >= 1 || ! empty($this->campaign_goal)) {
            return self::READINESS_PARTIAL;
        }

        return self::READINESS_INCOMPLETE;
    }

    /**
     * Recompute and persist readiness_status.
     */
    public function refreshReadiness(): void
    {
        $status = $this->computeReadiness();
        if ($this->readiness_status !== $status) {
            $this->update(['readiness_status' => $status]);
        }
    }
}
