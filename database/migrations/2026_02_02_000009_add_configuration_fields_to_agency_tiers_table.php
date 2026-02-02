<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Phase AG-6: Add admin-configurable settings to agency tiers.
     * All fields are nullable - defaults will be set via seeder.
     */
    public function up(): void
    {
        Schema::table('agency_tiers', function (Blueprint $table) {
            $table->unsignedInteger('activation_threshold')->nullable()->after('tier_order');
            $table->decimal('reward_percentage', 5, 2)->nullable()->after('activation_threshold');
            $table->unsignedInteger('max_incubated_companies')->nullable()->after('reward_percentage');
            $table->unsignedInteger('max_incubated_brands')->nullable()->after('max_incubated_companies');
            $table->unsignedInteger('incubation_window_days')->nullable()->after('max_incubated_brands');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agency_tiers', function (Blueprint $table) {
            $table->dropColumn([
                'activation_threshold',
                'reward_percentage',
                'max_incubated_companies',
                'max_incubated_brands',
                'incubation_window_days',
            ]);
        });
    }
};
