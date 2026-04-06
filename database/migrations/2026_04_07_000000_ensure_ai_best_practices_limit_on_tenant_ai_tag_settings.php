<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixes installs where 2026_01_24_162504 ran before the create-table migration (162504 no-op)
 * and the table was created without ai_best_practices_limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_ai_tag_settings')) {
            return;
        }
        Schema::table('tenant_ai_tag_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_ai_tag_settings', 'ai_best_practices_limit')) {
                $table->integer('ai_best_practices_limit')->default(5)->after('ai_auto_tag_limit_value');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenant_ai_tag_settings')) {
            return;
        }
        if (Schema::hasColumn('tenant_ai_tag_settings', 'ai_best_practices_limit')) {
            Schema::table('tenant_ai_tag_settings', function (Blueprint $table) {
                $table->dropColumn('ai_best_practices_limit');
            });
        }
    }
};
