<?php

/**
 * R3.1 — Download landing page refactor.
 * uses_landing_page: when true, show branded landing; when false, go straight to ZIP.
 * landing_copy: optional headline/subtext overrides (JSON).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->boolean('uses_landing_page')->default(false)->after('branding_options');
            $table->json('landing_copy')->nullable()->after('uses_landing_page');
        });
    }

    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn(['uses_landing_page', 'landing_copy']);
        });
    }
};
