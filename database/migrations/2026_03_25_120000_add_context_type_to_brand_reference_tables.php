<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_visual_references') && ! Schema::hasColumn('brand_visual_references', 'context_type')) {
            Schema::table('brand_visual_references', function (Blueprint $table) {
                $table->string('context_type', 32)->nullable()->after('weight');
            });
        }

        if (Schema::hasTable('brand_reference_assets') && ! Schema::hasColumn('brand_reference_assets', 'context_type')) {
            Schema::table('brand_reference_assets', function (Blueprint $table) {
                $table->string('context_type', 32)->nullable()->after('category');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('brand_visual_references') && Schema::hasColumn('brand_visual_references', 'context_type')) {
            Schema::table('brand_visual_references', function (Blueprint $table) {
                $table->dropColumn('context_type');
            });
        }

        if (Schema::hasTable('brand_reference_assets') && Schema::hasColumn('brand_reference_assets', 'context_type')) {
            Schema::table('brand_reference_assets', function (Blueprint $table) {
                $table->dropColumn('context_type');
            });
        }
    }
};
