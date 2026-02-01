<?php

/**
 * Phase D7 — Password-Protected & Branded Downloads
 * Adds optional password_hash (bcrypt) and branding_options (JSON) to downloads.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->string('password_hash')->nullable()->after('allow_reshare');
            $table->json('branding_options')->nullable()->after('password_hash');
        });
    }

    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn(['password_hash', 'branding_options']);
        });
    }
};
