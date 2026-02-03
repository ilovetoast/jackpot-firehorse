<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D-UX: Persist ZIP build duration for time estimate messaging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->unsignedInteger('zip_build_duration_seconds')->nullable()->after('zip_build_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn('zip_build_duration_seconds');
        });
    }
};
