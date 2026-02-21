<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Hybrid S3 storage: Standard plans use shared bucket; Enterprise uses dedicated per-tenant bucket.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('storage_mode', 20)->default('shared')->after('storage_addon_stripe_subscription_item_id');
            $table->string('storage_bucket')->nullable()->after('storage_mode');
            $table->string('cdn_distribution_id')->nullable()->after('storage_bucket');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['storage_mode', 'storage_bucket', 'cdn_distribution_id']);
        });
    }
};
