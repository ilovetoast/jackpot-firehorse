<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('plan_management_source')->nullable()->after('stripe_id')->comment('Source of plan management: stripe, shopify, manual, or null (auto-detected)');
            $table->string('manual_plan_override')->nullable()->after('plan_management_source')->comment('Manually set plan name (overrides subscription-based plan)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['plan_management_source', 'manual_plan_override']);
        });
    }
};
