<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds unique constraint on upload_session_id to prevent duplicate assets
     * from being created from the same upload session. This enforces idempotency
     * at the database level.
     */
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            // Make upload_session_id unique to prevent duplicate assets
            // One upload session can only create one asset (idempotency requirement)
            $table->unique('upload_session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropUnique(['upload_session_id']);
        });
    }
};
