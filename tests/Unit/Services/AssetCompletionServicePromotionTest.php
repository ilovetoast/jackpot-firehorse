<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\AssetStatus;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Services\AssetCompletionService;
use Tests\TestCase;

final class AssetCompletionServicePromotionTest extends TestCase
{
    public function test_may_promote_allows_failed_thumbnails_when_version_pipeline_complete_and_thumbnails_generated_flag_set(): void
    {
        $version = new AssetVersion([
            'id' => '00000000-0000-4000-8000-000000000001',
            'pipeline_status' => 'complete',
        ]);

        $asset = new Asset([
            'status' => AssetStatus::VISIBLE,
            'analysis_status' => 'complete',
            'thumbnail_status' => ThumbnailStatus::FAILED,
            'metadata' => [
                'ai_tagging_completed' => true,
                'metadata_extracted' => true,
                'thumbnails_generated' => true,
                'preview_skipped' => true,
                'preview_skipped_reason' => 'office_pdf_conversion_failed',
            ],
        ]);
        $asset->setRelation('currentVersion', $version);

        $svc = app(AssetCompletionService::class);
        $this->assertFalse($svc->isComplete($asset));
        $this->assertTrue($svc->mayPromoteProcessedAsset($asset));
    }

    public function test_may_promote_rejects_failed_thumbnails_without_thumbnails_generated(): void
    {
        $version = new AssetVersion([
            'id' => '00000000-0000-4000-8000-000000000002',
            'pipeline_status' => 'complete',
        ]);

        $asset = new Asset([
            'status' => AssetStatus::VISIBLE,
            'analysis_status' => 'complete',
            'thumbnail_status' => ThumbnailStatus::FAILED,
            'metadata' => [
                'ai_tagging_completed' => true,
                'metadata_extracted' => true,
                'thumbnails_generated' => false,
            ],
        ]);
        $asset->setRelation('currentVersion', $version);

        $svc = app(AssetCompletionService::class);
        $this->assertFalse($svc->mayPromoteProcessedAsset($asset));
    }
}
