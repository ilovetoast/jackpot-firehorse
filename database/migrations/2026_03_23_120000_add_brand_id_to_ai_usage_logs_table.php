<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attribute AI usage logs to a brand when applicable (e.g. Brand Intelligence).
     */
    public function up(): void
    {
        if (! Schema::hasTable('ai_usage_logs')) {
            return;
        }

        Schema::table('ai_usage_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('ai_usage_logs', 'brand_id')) {
                $table->foreignId('brand_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('ai_usage_logs') || ! Schema::hasColumn('ai_usage_logs', 'brand_id')) {
            return;
        }

        Schema::table('ai_usage_logs', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
            $table->dropColumn('brand_id');
        });
    }
};
