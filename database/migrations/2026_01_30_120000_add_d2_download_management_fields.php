<?php

/**
 * Phase D2 — Download Management & Access Control
 *
 * Adds:
 * - revoked_at, revoked_by_user_id for revoke flow
 * - download_user pivot for users scope (Enterprise)
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('hard_delete_at');
            $table->foreignId('revoked_by_user_id')->nullable()->after('revoked_at')
                ->constrained('users')->onDelete('set null');
        });

        Schema::create('download_user', function (Blueprint $table) {
            $table->uuid('download_id');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->primary(['download_id', 'user_id']);
            $table->foreign('download_id')->references('id')->on('downloads')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_user');
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropForeign(['revoked_by_user_id']);
            $table->dropColumn(['revoked_at', 'revoked_by_user_id']);
        });
    }
};
