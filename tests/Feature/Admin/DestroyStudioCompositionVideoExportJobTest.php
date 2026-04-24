<?php

namespace Tests\Feature\Admin;

use App\Models\Brand;
use App\Models\Composition;
use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DestroyStudioCompositionVideoExportJobTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function site_admin_can_delete_failed_studio_export_job_row(): void
    {
        Role::firstOrCreate(['name' => 'site_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('site_admin');

        $tenant = Tenant::create([
            'name' => 'T',
            'slug' => 't-'.Str::random(6),
            'uuid' => (string) Str::uuid(),
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'slug' => 'b-'.Str::random(6),
        ]);
        $composition = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $admin->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'C',
            'document_json' => ['width' => 640, 'height' => 480, 'layers' => []],
        ]);

        $job = StudioCompositionVideoExportJob::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $admin->id,
            'composition_id' => $composition->id,
            'render_mode' => 'ffmpeg_native',
            'status' => StudioCompositionVideoExportJob::STATUS_FAILED,
            'error_json' => ['code' => 'ffmpeg_failed', 'message' => 'x', 'debug' => ['stderr_tail' => 'err']],
            'meta_json' => [],
        ]);

        $admin->tenants()->attach($tenant->id, ['role' => 'member']);

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id])
            ->delete(route('admin.studio-composition-video-export-jobs.destroy', $job->id))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('studio_composition_video_export_jobs', ['id' => $job->id]);
    }

    #[Test]
    public function site_admin_cannot_delete_non_failed_export_job(): void
    {
        Role::firstOrCreate(['name' => 'site_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('site_admin');

        $tenant = Tenant::create([
            'name' => 'T2',
            'slug' => 't2-'.Str::random(6),
            'uuid' => (string) Str::uuid(),
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B2',
            'slug' => 'b2-'.Str::random(6),
        ]);
        $composition = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $admin->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'C2',
            'document_json' => ['width' => 640, 'height' => 480, 'layers' => []],
        ]);

        $job = StudioCompositionVideoExportJob::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $admin->id,
            'composition_id' => $composition->id,
            'render_mode' => 'ffmpeg_native',
            'status' => StudioCompositionVideoExportJob::STATUS_COMPLETE,
            'meta_json' => [],
        ]);

        $admin->tenants()->attach($tenant->id, ['role' => 'member']);

        $this->actingAs($admin)
            ->withSession(['tenant_id' => $tenant->id])
            ->delete(route('admin.studio-composition-video-export-jobs.destroy', $job->id))
            ->assertStatus(422);

        $this->assertDatabaseHas('studio_composition_video_export_jobs', ['id' => $job->id]);
    }
}
