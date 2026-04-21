<?php

namespace Tests\Unit;

use App\Support\StudioCreativeSetGenerationQueueGuard;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StudioCreativeSetGenerationQueueGuardTest extends TestCase
{
    public function test_allows_sync_in_local_override(): void
    {
        StudioCreativeSetGenerationQueueGuard::assertStudioGenerationUsesWorkers('local', 'sync');
        $this->addToAssertionCount(1);
    }

    public function test_allows_sync_in_testing_override(): void
    {
        StudioCreativeSetGenerationQueueGuard::assertStudioGenerationUsesWorkers('testing', 'sync');
        $this->addToAssertionCount(1);
    }

    public function test_blocks_sync_outside_local_testing(): void
    {
        $this->expectException(ValidationException::class);
        StudioCreativeSetGenerationQueueGuard::assertStudioGenerationUsesWorkers('staging', 'sync');
    }

    public function test_allows_non_sync_in_staging(): void
    {
        StudioCreativeSetGenerationQueueGuard::assertStudioGenerationUsesWorkers('staging', 'redis');
        $this->addToAssertionCount(1);
    }
}
