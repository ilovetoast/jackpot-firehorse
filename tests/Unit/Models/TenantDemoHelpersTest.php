<?php

namespace Tests\Unit\Models;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantDemoHelpersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_is_demo_and_template_helpers(): void
    {
        $t = Tenant::create([
            'name' => 'Demo Co',
            'slug' => 'demo-co',
            'is_demo' => true,
            'is_demo_template' => false,
        ]);

        $this->assertTrue($t->isDemo());
        $this->assertFalse($t->isDemoTemplate());

        $t2 = Tenant::create([
            'name' => 'Template Co',
            'slug' => 'template-co',
            'is_demo' => false,
            'is_demo_template' => true,
        ]);

        $this->assertFalse($t2->isDemo());
        $this->assertTrue($t2->isDemoTemplate());
    }

    public function test_is_disposable_demo(): void
    {
        $instance = Tenant::create([
            'name' => 'Instance',
            'slug' => 'instance',
            'is_demo' => true,
            'is_demo_template' => false,
        ]);
        $this->assertTrue($instance->isDisposableDemo());

        $template = Tenant::create([
            'name' => 'Template',
            'slug' => 'template',
            'is_demo' => false,
            'is_demo_template' => true,
        ]);
        $this->assertFalse($template->isDisposableDemo());
    }

    public function test_demo_expired_and_days_remaining(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-10 12:00:00', 'UTC'));

        $open = Tenant::create([
            'name' => 'Open',
            'slug' => 'open',
            'is_demo' => true,
        ]);
        $this->assertFalse($open->demoExpired());
        $this->assertNull($open->demoDaysRemaining());

        $future = Tenant::create([
            'name' => 'Future',
            'slug' => 'future',
            'is_demo' => true,
            'demo_expires_at' => Carbon::parse('2026-05-13 23:59:59', 'UTC'),
        ]);
        $this->assertFalse($future->demoExpired());
        $this->assertSame(3, $future->demoDaysRemaining());

        $past = Tenant::create([
            'name' => 'Past',
            'slug' => 'past',
            'is_demo' => true,
            'demo_expires_at' => Carbon::parse('2026-05-09 08:00:00', 'UTC'),
        ]);
        $this->assertTrue($past->demoExpired());
        $this->assertSame(0, $past->demoDaysRemaining());
    }
}
