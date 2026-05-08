<?php

namespace Tests\Feature\Demo;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DemoPlanCloneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_outputs_json_with_meta_and_storage_strategy(): void
    {
        Tenant::create([
            'name' => 'Cmd Template',
            'slug' => 'cmd-plan-tpl',
            'is_demo_template' => true,
        ]);

        $exit = Artisan::call('demo:plan-clone', [
            'tenant' => 'cmd-plan-tpl',
            '--plan' => 'pro',
            '--expires' => '7',
            '--email' => ['test@example.com'],
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('meta', $decoded);
        $this->assertSame(['test@example.com'], $decoded['meta']['invited_emails']);
        $this->assertArrayHasKey('would_clone', $decoded);
        $this->assertArrayHasKey('would_skip', $decoded);
        $this->assertArrayHasKey('storage_strategy', $decoded);
    }

    public function test_command_fails_for_non_template(): void
    {
        Tenant::create([
            'name' => 'NotTpl',
            'slug' => 'not-tpl-plan',
        ]);

        $this->artisan('demo:plan-clone', [
            'tenant' => 'not-tpl-plan',
            '--plan' => 'pro',
            '--expires' => '7',
        ])->assertExitCode(1);
    }

    public function test_command_fails_on_invalid_expiration(): void
    {
        Tenant::create([
            'name' => 'Tpl',
            'slug' => 'tpl-bad-exp',
            'is_demo_template' => true,
        ]);

        $this->artisan('demo:plan-clone', [
            'tenant' => 'tpl-bad-exp',
            '--plan' => 'pro',
            '--expires' => '99',
        ])->assertExitCode(1);
    }
}
