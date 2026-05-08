<?php

namespace Tests\Feature\Demo;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DemoAuditTemplateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_outputs_json_for_template_slug(): void
    {
        Tenant::create([
            'name' => 'Cmd Template',
            'slug' => 'cmd-template',
            'is_demo_template' => true,
        ]);

        $exit = Artisan::call('demo:audit-template', ['tenant' => 'cmd-template', '--json' => true]);
        $this->assertSame(0, $exit);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('clone_ready', $decoded);
        $this->assertArrayHasKey('excluded_from_clone', $decoded);
    }

    public function test_command_fails_for_non_template(): void
    {
        Tenant::create([
            'name' => 'NotTpl',
            'slug' => 'not-tpl',
        ]);

        $this->artisan('demo:audit-template', ['tenant' => 'not-tpl'])
            ->assertExitCode(1);
    }
}
