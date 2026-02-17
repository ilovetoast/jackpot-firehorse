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
            $table->unsignedInteger('storage_addon_mb')->default(0);
            $table->string('storage_addon_stripe_price_id')->nullable();
            $table->string('storage_addon_stripe_subscription_item_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'storage_addon_mb',
                'storage_addon_stripe_price_id',
                'storage_addon_stripe_subscription_item_id',
            ]);
        });
    }
};
