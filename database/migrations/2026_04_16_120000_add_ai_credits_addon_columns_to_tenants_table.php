<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('ai_credits_addon')->default(0);
            $table->string('ai_credits_addon_stripe_price_id')->nullable();
            $table->string('ai_credits_addon_stripe_subscription_item_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'ai_credits_addon',
                'ai_credits_addon_stripe_price_id',
                'ai_credits_addon_stripe_subscription_item_id',
            ]);
        });
    }
};
