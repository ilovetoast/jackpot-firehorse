<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tenant UUID enforcement and self-healing.
 *
 * Ensures all tenants always have a UUID for canonical storage paths.
 */
class TenantUuidEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_created_without_uuid_receives_uuid(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        $this->assertNotNull($tenant->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $tenant->uuid
        );
    }

    public function test_saving_hook_regenerates_null_uuid(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        $originalUuid = $tenant->uuid;
        $this->assertNotNull($originalUuid);

        $tenant->uuid = null;
        $tenant->save();

        $tenant->refresh();
        $this->assertNotNull($tenant->uuid);
        $this->assertNotSame($originalUuid, $tenant->uuid);
    }

    public function test_ensure_buckets_command_repairs_missing_uuids(): void
    {
        $tenant = Tenant::create([
            'name' => 'Repair Test',
            'slug' => 'repair-test',
        ]);

        DB::table('tenants')->where('id', $tenant->id)->update(['uuid' => null]);

        $this->assertNull(Tenant::find($tenant->id)->uuid);

        $this->artisan('tenants:ensure-buckets', ['--force' => true, '--dry-run' => true]);

        $tenant->refresh();
        $this->assertNotNull($tenant->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $tenant->uuid
        );
    }
}
