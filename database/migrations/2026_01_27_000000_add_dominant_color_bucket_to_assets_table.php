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
        Schema::table('assets', function (Blueprint $table) {
            // Add dominant_color_bucket column for filtering
            // Format: "L{L}_A{A}_B{B}" where L, A, B are quantized LAB values
            // Example: "L50_A10_B20"
            $table->string('dominant_color_bucket', 32)->nullable()->after('metadata')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['dominant_color_bucket']);
            $table->dropColumn('dominant_color_bucket');
        });
    }
};
