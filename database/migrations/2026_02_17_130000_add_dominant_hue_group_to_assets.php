<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds dominant_hue_group column for perceptual hue cluster filtering.
     * Does NOT remove dominant_color_bucket (deprecated, kept for safety).
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('dominant_hue_group', 32)->nullable()->after('metadata')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['dominant_hue_group']);
            $table->dropColumn('dominant_hue_group');
        });
    }
};
