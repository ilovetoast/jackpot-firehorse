<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_modules', function (Blueprint $table) {
            $table->string('stripe_price_id')->nullable()->after('seats_limit');
            $table->string('stripe_subscription_item_id')->nullable()->after('stripe_price_id');
            $table->string('seat_pack_stripe_price_id')->nullable()->after('stripe_subscription_item_id');
            $table->string('seat_pack_stripe_subscription_item_id')->nullable()->after('seat_pack_stripe_price_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_modules', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_price_id',
                'stripe_subscription_item_id',
                'seat_pack_stripe_price_id',
                'seat_pack_stripe_subscription_item_id',
            ]);
        });
    }
};
