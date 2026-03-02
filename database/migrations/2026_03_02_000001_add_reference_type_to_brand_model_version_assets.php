<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds reference_type for visual references (logo, lifestyle_photography, etc.)
     * when builder_context = 'visual_reference'.
     */
    public function up(): void
    {
        if (! Schema::hasTable('brand_model_version_assets')) {
            return;
        }
        Schema::table('brand_model_version_assets', function (Blueprint $table) {
            $table->string('reference_type', 50)->nullable()->after('builder_context');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('brand_model_version_assets')) {
            Schema::table('brand_model_version_assets', function (Blueprint $table) {
                $table->dropColumn('reference_type');
            });
        }
    }
};
