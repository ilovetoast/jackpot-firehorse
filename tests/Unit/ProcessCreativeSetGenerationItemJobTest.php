<?php

namespace Tests\Unit;

use App\Jobs\ProcessCreativeSetGenerationItemJob;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Regression: the job must target the connection's queue *name* (e.g. "default"), not
 * the queue connection *key* (e.g. "redis") — see ProcessCreativeSetGenerationItemJob constructor.
 */
class ProcessCreativeSetGenerationItemJobTest extends TestCase
{
    public function test_uses_list_queue_name_for_redis_connection(): void
    {
        Config::set('queue.default', 'redis');
        Config::set('queue.connections.redis.queue', 'default');

        $job = new ProcessCreativeSetGenerationItemJob(1);

        $this->assertSame('default', $job->queue);
    }

    public function test_respects_non_default_queue_name_on_connection(): void
    {
        Config::set('queue.default', 'redis');
        Config::set('queue.connections.redis.queue', 'custom');

        $job = new ProcessCreativeSetGenerationItemJob(1);

        $this->assertSame('custom', $job->queue);
    }
}
