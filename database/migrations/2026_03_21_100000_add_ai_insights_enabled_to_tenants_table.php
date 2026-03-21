<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scheduled metadata insights (value/field suggestion sync) — opt-out per tenant.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'ai_insights_enabled')) {
                $table->boolean('ai_insights_enabled')->default(true);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'ai_insights_enabled')) {
                $table->dropColumn('ai_insights_enabled');
            }
        });
    }
};
