<?php

namespace Tests\Feature;

use App\Jobs\GeneratePreferredThumbnailJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Preferred thumbnails run asynchronously after original thumbnails complete.
 */
class PreferredThumbnailJobTest extends TestCase
{
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
