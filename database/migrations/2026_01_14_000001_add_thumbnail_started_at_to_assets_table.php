<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds thumbnail_started_at timestamp to track when thumbnail generation began.
     * Used for timeout detection to prevent infinite processing states.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->timestamp('thumbnail_started_at')->nullable()->after('thumbnail_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('thumbnail_started_at');
        });
    }
};
