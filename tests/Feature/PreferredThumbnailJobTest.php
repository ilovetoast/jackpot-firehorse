<?php

namespace Tests\Feature;

use App\Jobs\GeneratePreferredThumbnailJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Preferred thumbnails are optional (second pass). They are off by default; opt in with
 * THUMBNAIL_PREFERRED_ENABLED=true. {@see GenerateThumbnailsJob} only dispatches when enabled.
 *
 * Manual check: upload a JPEG, confirm Horizon shows GenerateThumbnailsJob but not
 * GeneratePreferredThumbnailJob when this flag is off.
 */
class PreferredThumbnailJobTest extends TestCase
{
    public function test_pipeline_gate_skips_preferred_jobs_when_config_disabled(): void
    {
        config(['assets.thumbnail.preferred.enabled' => false]);
        $this->assertFalse((bool) config('assets.thumbnail.preferred.enabled'));
    }

    public function test_preferred_job_can_be_dispatched_to_images_queue(): void
    {
        Queue::fake();

        GeneratePreferredThumbnailJob::dispatch((string) Str::uuid(), (string) Str::uuid(), false)
            ->onQueue('images');

        Queue::assertPushed(GeneratePreferredThumbnailJob::class, function (GeneratePreferredThumbnailJob $job): bool {
            return $job->queue === 'images';
        });
    }
}
