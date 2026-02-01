<?php

/**
 * R3.2 — Brand-level download landing page settings.
 * Persisted as brand.download_landing_settings (JSON).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->json('download_landing_settings')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('download_landing_settings');
        });
    }
};
