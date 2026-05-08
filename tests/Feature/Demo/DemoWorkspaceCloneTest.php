<?php

namespace Tests\Feature\Demo;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Jobs\CreateDemoWorkspaceCloneJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\DemoWorkspaceCloneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DemoWorkspaceCloneTest extends TestCase
{
    use RefreshDatabase;

    protected function createSiteAdmin(): User
    {
        Role::firstOrCreate(['name' => 'site_admin', 'guard_name' => 'web']);
        $admin = User::create([
            'email' => 'sysadmin-clone@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'S',
            'last_name' => 'A',
        ]);
        $admin->assignRole('site_admin');

        return $admin;
    }

    protected function seedTemplateWithAsset(Tenant $template): array
    {
        $brand = DB::table('brands')->where('tenant_id', $template->id)->where('is_default', true)->first();
        $this->assertNotNull($brand);

        $bucket = DB::table('storage_buckets')->where('tenant_id', $template->id)->orderBy('id')->first();
        $this->assertNotNull($bucket);

        $categoryId = DB::table('categories')->insertGetId([
            'tenant_id' => $template->id,
            'brand_id' => $brand->id,
            'asset_type' => 'asset',
            'name' => 'Cat',
            'slug' => 'cat',
            'is_system' => false,
            'is_private' => false,
            'is_locked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $assetId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $pathPrefix = 'tenants/'.$template->uuid.'/assets/'.$assetId.'/v1';
        $filePath = $pathPrefix.'/original.jpg';

        DB::table('assets')->insert([
            'id' => $assetId,
            'tenant_id' => $template->id,
            'brand_id' => $brand->id,
            'user_id' => null,
            'upload_session_id' => null,
            'storage_bucket_id' => $bucket->id,
            'status' => AssetStatus::VISIBLE->value,
            'thumbnail_status' => ThumbnailStatus::PENDING->value,
            'analysis_status' => 'pending',
            'type' => AssetType::ASSET->value,
            'original_filename' => 'x.jpg',
            'title' => 'X',
            'size_bytes' => 3,
            'mime_type' => 'image/jpeg',
            'width' => null,
            'height' => null,
            'storage_root_path' => $pathPrefix,
            'metadata' => json_encode(['category_id' => (string) $categoryId]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('asset_versions')->insert([
            'id' => $versionId,
            'asset_id' => $assetId,
            'version_number' => 1,
            'file_path' => $filePath,
            'file_size' => 3,
            'mime_type' => 'image/jpeg',
            'checksum' => 'abc',
            'uploaded_by' => null,
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Storage::disk('s3')->put($filePath, 'jpg');

        DB::table('subscriptions')->insert([
            'tenant_id' => $template->id,
            'name' => 'default',
            'stripe_id' => 'sub_demo_'.Str::random(8),
            'stripe_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (Schema::hasTable('activity_events')) {
            DB::table('activity_events')->insert([
                'tenant_id' => $template->id,
                'brand_id' => $brand->id,
                'actor_type' => 'system',
                'actor_id' => null,
                'event_type' => 'test',
                'subject_type' => 'App\\Models\\Asset',
                'subject_id' => $assetId,
                'metadata' => json_encode([]),
                'created_at' => now(),
            ]);
        }

        return ['asset_id' => $assetId, 'file_path' => $filePath, 'category_id' => $categoryId];
    }

    public function test_create_demo_is_forbidden_when_cloning_disabled(): void
    {
        config(['demo.cloning_enabled' => false]);

        $admin = $this->createSiteAdmin();
        $template = Tenant::create([
            'name' => 'Tpl',
            'slug' => 'tpl-clone-off',
            'is_demo_template' => true,
        ]);

        $this->actingAs($admin)
            ->post('/app/admin/demo-workspaces/templates/'.$template->id.'/create-demo', [
                'target_demo_label' => 'X',
                'plan_key' => 'pro',
                'expiration_days' => 7,
                'invited_emails_text' => $admin->email,
            ])
            ->assertForbidden();
    }

    public function test_clone_creates_demo_tenant_with_records_and_distinct_storage_paths(): void
    {
        Storage::fake('s3');
        config(['demo.cloning_enabled' => true]);

        $admin = $this->createSiteAdmin();

        $template = Tenant::create([
            'name' => 'Tpl',
            'slug' => 'tpl-clone-on',
            'is_demo_template' => true,
            'demo_plan_key' => 'pro',
        ]);

        $this->seedTemplateWithAsset($template);

        $beforeDemoCount = Tenant::query()->where('is_demo', true)->where('is_demo_template', false)->count();

        $this->actingAs($admin)
            ->post('/app/admin/demo-workspaces/templates/'.$template->id.'/create-demo', [
                'target_demo_label' => 'ACME Demo',
                'plan_key' => 'pro',
                'expiration_days' => 7,
                'invited_emails_text' => 'buyer@example.com,'.$admin->email,
            ])
            ->assertRedirect(route('admin.demo-workspaces.index'));

        $this->assertSame($beforeDemoCount + 1, Tenant::query()->where('is_demo', true)->where('is_demo_template', false)->count());

        $demo = Tenant::query()->where('is_demo', true)->where('demo_template_id', $template->id)->first();
        $this->assertNotNull($demo);
        $this->assertSame('active', $demo->demo_status);
        $this->assertSame((int) $template->id, (int) $demo->demo_template_id);
        $this->assertSame('pro', $demo->demo_plan_key);
        $this->assertNotEmpty($demo->uuid);
        $this->assertNotSame($template->uuid, $demo->uuid);

        $this->assertSame(
            DB::table('brands')->where('tenant_id', $template->id)->count(),
            DB::table('brands')->where('tenant_id', $demo->id)->count(),
        );
        $this->assertSame(
            DB::table('categories')->where('tenant_id', $template->id)->count(),
            DB::table('categories')->where('tenant_id', $demo->id)->count(),
        );

        $newAsset = DB::table('assets')->where('tenant_id', $demo->id)->first();
        $this->assertNotNull($newAsset);
        $this->assertNotSame($template->id, $newAsset->tenant_id);

        $ver = DB::table('asset_versions')->where('asset_id', $newAsset->id)->where('is_current', true)->first();
        $this->assertNotNull($ver);
        $this->assertStringContainsString('tenants/'.$demo->uuid.'/assets/'.$newAsset->id.'/', $ver->file_path);
        $this->assertStringNotContainsString($template->uuid, $ver->file_path);

        Storage::disk('s3')->assertExists($ver->file_path);

        $this->assertSame(0, DB::table('subscriptions')->where('tenant_id', $demo->id)->count());
        if (Schema::hasTable('activity_events')) {
            $this->assertSame(0, DB::table('activity_events')->where('tenant_id', $demo->id)->count());
        }

        $this->assertTrue(DB::table('tenant_user')->where('tenant_id', $demo->id)->exists());
    }

    public function test_failed_clone_marks_demo_status_failed(): void
    {
        config(['demo.cloning_enabled' => true]);

        $admin = $this->createSiteAdmin();
        $template = Tenant::create([
            'name' => 'Tpl',
            'slug' => 'tpl-fail',
            'is_demo_template' => true,
        ]);

        $demo = Tenant::create([
            'name' => 'Pending',
            'slug' => 'pending-fail-'.Str::lower(Str::random(4)),
            'is_demo' => true,
            'demo_template_id' => $template->id,
            'demo_status' => 'pending',
            'demo_plan_key' => 'pro',
            'demo_label' => 'P',
            'manual_plan_override' => 'pro',
            'billing_status' => 'comped',
        ]);

        $this->mock(DemoWorkspaceCloneService::class, function ($m) {
            $m->shouldReceive('cloneFromTemplate')->once()->andThrow(new \RuntimeException('clone boom'));
        });

        try {
            Bus::dispatchSync(new CreateDemoWorkspaceCloneJob($demo->id, [$admin->email]));
        } catch (\Throwable) {
            // expected
        }

        $demo->refresh();
        $this->assertSame('failed', $demo->demo_status);
        $this->assertStringContainsString('clone boom', (string) $demo->demo_clone_failure_message);
    }
}
