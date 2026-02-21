<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1 + 2 + 6: Rename enterprise → premium, add infrastructure_tier, migrate existing tenants.
     */
    public function up(): void
    {
        // Phase 2: Add infrastructure_tier column
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('infrastructure_tier', 20)->default('shared')->after('storage_mode');
        });

        // Phase 6: Migrate existing enterprise tenants to premium, set infrastructure_tier = shared
        DB::table('tenants')
            ->where('manual_plan_override', 'enterprise')
            ->update([
                'manual_plan_override' => 'premium',
                'infrastructure_tier' => 'shared',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('infrastructure_tier');
        });
        // Note: We do NOT reverse the enterprise→premium plan migration; that would be unsafe.
    }
};
