<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add configurable best practices limit for AI tag auto-apply
     */
    public function up(): void
    {
        Schema::table('tenant_ai_tag_settings', function (Blueprint $table) {
            $table->integer('ai_best_practices_limit')->default(5)->after('ai_auto_tag_limit_value')
                ->comment('Best practices limit for auto-applied tags (1-10)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_ai_tag_settings', function (Blueprint $table) {
            $table->dropColumn('ai_best_practices_limit');
        });
    }
};