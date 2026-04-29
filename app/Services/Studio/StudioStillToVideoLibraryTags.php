<?php

namespace App\Services\Studio;

use App\Models\Asset;
use App\Models\Tenant;
use App\Services\MetadataPersistenceService;

/**
 * Consistent library tags for Studio still → AI video outputs (animation MP4 in staged, or baked composition export
 * from a still→clip layer). Respects {@see config('studio.still_to_video_library_tags.enabled')}; tenant tag
 * normalization and plan limits apply via {@see MetadataPersistenceService::syncApprovedTagBatchValues}.
 */
final class StudioStillToVideoLibraryTags
{
    /**
     * @var list<string>
     */
    public const DEFAULT_SLUGS = [
        'ai',
        'ai-generated',
        'ai-video',
        'studio',
        'still-to-video',
    ];

    public static function isEnabled(): bool
    {
        return (bool) config('studio.still_to_video_library_tags.enabled', true);
    }

    public static function apply(Asset $asset, Tenant $tenant): void
    {
        if (! self::isEnabled()) {
            return;
        }
        app(MetadataPersistenceService::class)->syncApprovedTagBatchValues(
            $asset,
            $tenant,
            self::DEFAULT_SLUGS
        );
    }
}
