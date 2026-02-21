<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 5: Add uuid to tenants for canonical storage path isolation.
 *
 * Canonical path format: tenants/{tenant_uuid}/assets/{asset_uuid}/v{version}/...
 * Tenant UUID provides stable, non-sequential isolation (tenant_id is internal).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id')->unique();
        });

        // Backfill existing tenants
        $tenants = \DB::table('tenants')->whereNull('uuid')->get();
        foreach ($tenants as $tenant) {
            \DB::table('tenants')->where('id', $tenant->id)->update([
                'uuid' => (string) Str::uuid(),
            ]);
        }

    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
