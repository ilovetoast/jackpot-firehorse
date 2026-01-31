<?php

/**
 * Phase D1 — Secure Asset Downloader (Foundation)
 * Add brand_id to downloads for context (nullable for collection-only users).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('tenant_id')->constrained('brands')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
        });
    }
};
