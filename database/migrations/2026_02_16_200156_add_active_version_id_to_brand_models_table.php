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
        Schema::table('brand_models', function (Blueprint $table) {
            $table->foreignId('active_version_id')
                ->nullable()
                ->after('is_enabled')
                ->constrained('brand_model_versions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brand_models', function (Blueprint $table) {
            $table->dropForeign(['active_version_id']);
        });
    }
};
