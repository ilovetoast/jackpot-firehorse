<?php

namespace Tests\Feature\Demo;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoWorkspaceCleanupService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DemoWorkspaceCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function makeTemplate(): Tenant
    {
        return Tenant::create([
            'name' => 'Demo Tpl',
            'slug' => 'tpl-'.uniqid(),
            'is_demo_template' => true,
            'demo_label' => 'Master',
            'demo_plan_key' => 'pro',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeDemo(Tenant $template, array $overrides = []): Tenant
    {
        return Tenant::create(array_merge([
            'name' => 'Demo Inst',
            'slug' => 'inst-'.uniqid(),
            'is_demo' => true,
            'is_demo_template' => false,
            'demo_template_id' => $template->id,
            'demo_plan_key' => 'pro',
            'demo_status' => 'archived',
            'demo_expires_at' => now()->subDays(30)->startOfDay(),
            'billing_status' => 'comped',
        ], $overrides));
    }

    public function test_dry_run_deletes_nothing_and_reports_storage_counts(): void
    {
        Storage::fake('s3');

        $template = $this->makeTemplate();
        $demo = $this->makeDemo($template);

        $demoUuid = (string) $demo->uuid;
        $tplUuid = (string) $template->uuid;

        Storage::disk('s3')->put('tenants/'.$demoUuid.'/assets/a.txt', 'demo-bytes');
        Storage::disk('s3')->put('tenants/'.$tplUuid.'/assets/t.txt', 'template-bytes');

        Config::set('demo.cleanup_grace_days', 0);
        Config::set('demo.cleanup_chunk_size', 10);

        $svc = app(DemoWorkspaceCleanupService::class);
        $result = $svc->cleanupTenant($demo->fresh(), dryRun: true, adminBypassGrace: true);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['storage_keys_removed']);

        $this->assertDatabaseHas('tenants', ['id' => $demo->id]);
        Storage::disk('s3')->assertExists('tenants/'.$demoUuid.'/assets/a.txt');
        Storage::disk('s3')->assertExists('tenants/'.$tplUuid.'/assets/t.txt');
    }

    public function test_cleanup_deletes_demo_tenant_and_only_demo_storage_prefix(): void
    {
        Storage::fake('s3');

        $template = $this->makeTemplate();
        $demo = $this->makeDemo($template);

        $demoUuid = (string) $demo->uuid;
        $tplUuid = (string) $template->uuid;

        Storage::disk('s3')->put('tenants/'.$demoUuid.'/assets/a.txt', 'demo-bytes');
        Storage::disk('s3')->put('tenants/'.$tplUuid.'/assets/t.txt', 'template-bytes');

        $svc = app(DemoWorkspaceCleanupService::class);
        $result = $svc->cleanupTenant($demo->fresh(), dryRun: false, adminBypassGrace: true);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['dry_run']);

        $this->assertDatabaseMissing('tenants', ['id' => $demo->id]);
        Storage::disk('s3')->assertMissing('tenants/'.$demoUuid.'/assets/a.txt');
        Storage::disk('s3')->assertExists('tenants/'.$tplUuid.'/assets/t.txt');
    }

    public function test_cleanup_refuses_demo_template(): void
    {
        $template = $this->makeTemplate();
        $svc = app(DemoWorkspaceCleanupService::class);
        $result = $svc->cleanupTenant($template->fresh(), dryRun: false, adminBypassGrace: true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('templates', strtolower($result['message']));
        $this->assertDatabaseHas('tenants', ['id' => $template->id]);
    }

    public function test_cleanup_refuses_normal_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Real Co',
            'slug' => 'real-'.uniqid(),
        ]);

        $svc = app(DemoWorkspaceCleanupService::class);
        $result = $svc->cleanupTenant($tenant->fresh(), dryRun: false, adminBypassGrace: true);

        $this->assertFalse($result['success']);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
    }

    public function test_archived_demos_can_be_deleted_via_manual_bypass(): void
    {
        Storage::fake('s3');

        $template = $this->makeTemplate();
        $demo = $this->makeDemo($template, [
            'demo_status' => 'archived',
        ]);

        $demoUuid = (string) $demo->uuid;
        Storage::disk('s3')->put('tenants/'.$demoUuid.'/x.bin', 'z');

        $svc = app(DemoWorkspaceCleanupService::class);
        $result = $svc->cleanupTenant($demo->fresh(), dryRun: false, adminBypassGrace: true);

        $this->assertTrue($result['success']);
        $this->assertDatabaseMissing('tenants', ['id' => $demo->id]);
    }

    public function test_active_non_expired_demo_not_selected_for_scheduled_pass(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'UTC'));

        Config::set('demo.cleanup_grace_days', 0);
        Config::set('demo.cleanup_chunk_size', 10);

        $template = $this->makeTemplate();
        $this->makeDemo($template, [
            'demo_status' => 'active',
            'demo_expires_at' => Carbon::parse('2026-08-01', 'UTC')->startOfDay(),
        ]);

        $svc = app(DemoWorkspaceCleanupService::class);
        $batch = $svc->findScheduledCleanupBatch();

        $this->assertCount(0, $batch);

        Carbon::setTestNow();
    }

    public function test_artisan_dry_run_deletes_nothing(): void
    {
        Storage::fake('s3');

        Config::set('demo.cleanup_enabled', true);
        Config::set('demo.cleanup_grace_days', 0);
        Config::set('demo.cleanup_chunk_size', 10);

        $template = $this->makeTemplate();
        $demo = $this->makeDemo($template, [
            'demo_status' => 'expired',
            'updated_at' => now()->subDays(5),
        ]);

        $demoUuid = (string) $demo->uuid;
        Storage::disk('s3')->put('tenants/'.$demoUuid.'/a.txt', 'x');

        Artisan::call('demo:cleanup-expired', ['--dry-run' => true, '--force' => true]);

        $this->assertDatabaseHas('tenants', ['id' => $demo->id]);
        Storage::disk('s3')->assertExists('tenants/'.$demoUuid.'/a.txt');
    }

    public function test_site_admin_can_delete_expired_demo_via_http(): void
    {
        Storage::fake('s3');

        Role::firstOrCreate(['name' => 'site_admin', 'guard_name' => 'web']);
        $admin = User::create([
            'email' => 'admin-clean@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'C',
        ]);
        $admin->assignRole('site_admin');

        $template = $this->makeTemplate();
        $demo = $this->makeDemo($template, [
            'demo_status' => 'expired',
            'demo_expires_at' => now()->subDays(5)->startOfDay(),
        ]);

        $demoUuid = (string) $demo->uuid;
        Storage::disk('s3')->put('tenants/'.$demoUuid.'/a.txt', 'x');

        $this->actingAs($admin)
            ->post(route('admin.demo-workspaces.delete-now', $demo), [
                'acknowledge' => true,
            ])
            ->assertRedirect(route('admin.demo-workspaces.index'));

        $this->assertDatabaseMissing('tenants', ['id' => $demo->id]);
    }
}
