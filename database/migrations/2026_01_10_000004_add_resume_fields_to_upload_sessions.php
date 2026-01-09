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
        Schema::table('upload_sessions', function (Blueprint $table) {
            // Store multipart upload ID for resume support
            $table->string('multipart_upload_id')->nullable()->after('type');
            
            // Track last activity for abandoned session detection
            $table->timestamp('last_activity_at')->nullable()->after('expires_at');
            
            // Index for abandoned session queries
            $table->index(['status', 'last_activity_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->dropIndex(['status', 'last_activity_at']);
            $table->dropColumn(['multipart_upload_id', 'last_activity_at']);
        });
    }
};
