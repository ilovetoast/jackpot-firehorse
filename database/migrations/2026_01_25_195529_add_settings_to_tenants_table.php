<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * Run the migrations.
     * 
     * Phase M-2: Add settings JSON column to tenants table for metadata approval gating.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Additional settings stored as JSON
            // Phase M-2: enable_metadata_approval stored in settings JSON
            $table->json('settings')->nullable()->after('equivalent_plan_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
