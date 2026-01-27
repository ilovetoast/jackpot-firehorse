<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Phase J.3.1: Add replace mode support to upload sessions
     * Allows upload sessions to be created in 'replace' mode for file-only replacement
     */
    public function up(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            // Add mode column: 'create' (default) or 'replace'
            $table->string('mode')->default('create')->after('type');
            
            // Add asset_id for replace mode (nullable, only set when mode = 'replace')
            $table->uuid('asset_id')->nullable()->after('mode');
            
            // Add foreign key constraint for asset_id
            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->onDelete('cascade');
            
            // Add index for asset_id lookups
            $table->index('asset_id');
            $table->index('mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->dropForeign(['asset_id']);
            $table->dropIndex(['asset_id']);
            $table->dropIndex(['mode']);
            $table->dropColumn(['mode', 'asset_id']);
        });
    }
};
