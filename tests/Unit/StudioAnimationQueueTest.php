<?php

namespace Tests\Unit;

use App\Support\StudioAnimationQueue;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class StudioAnimationQueueTest extends TestCase
{
    public function test_non_local_environment_uses_ai_queue_config(): void
    {
        Config::set('studio_animation.dispatch_queue', '');
        Config::set('queue.ai_queue', 'ai');
        // phpunit.xml sets APP_ENV=testing
        $this->assertSame('ai', StudioAnimationQueue::name());
    }

    public function test_explicit_dispatch_queue_override_wins(): void
    {
        Config::set('studio_animation.dispatch_queue', 'ai');
        Config::set('queue.ai_queue', 'low-priority-ai');

        $this->assertSame('ai', StudioAnimationQueue::name());
    }
}
