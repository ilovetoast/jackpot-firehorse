<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migrate agency_silver to enterprise.
     * Agency Silver has been removed; Enterprise is the single tier for that tier.
     */
    public function up(): void
    {
        DB::table('tenants')
            ->where('manual_plan_override', 'agency_silver')
            ->update(['manual_plan_override' => 'enterprise']);
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        // Cannot reliably reverse without knowing which tenants were originally agency_silver
    }
};
