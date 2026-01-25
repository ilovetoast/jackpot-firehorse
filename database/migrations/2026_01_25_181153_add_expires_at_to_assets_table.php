<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Phase M: Add expiration support for time-based access control.
     * expires_at is nullable - assets without expiration never expire.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // Add expires_at column if it doesn't exist
            if (!Schema::hasColumn('assets', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('archived_at');
                $table->index('expires_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            if (Schema::hasColumn('assets', 'expires_at')) {
                $table->dropIndex(['expires_at']);
                $table->dropColumn('expires_at');
            }
        });
    }
};
