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
        Schema::table('activity_events', function (Blueprint $table) {
            // Change subject_id from bigint unsigned to string to support UUIDs (e.g., Asset IDs)
            // We need to drop the index first, change the column, then re-add the index
            $table->dropIndex(['subject_type', 'subject_id', 'created_at']);
            $table->dropIndex(['subject_id']);
        });

        Schema::table('activity_events', function (Blueprint $table) {
            // Change column type from unsignedBigInteger to string
            $table->string('subject_id')->change();
            
            // Re-add indexes
            $table->index('subject_id');
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_events', function (Blueprint $table) {
            // Drop indexes before changing column type
            $table->dropIndex(['subject_type', 'subject_id', 'created_at']);
            $table->dropIndex(['subject_id']);
        });

        Schema::table('activity_events', function (Blueprint $table) {
            // Change back to unsignedBigInteger
            $table->unsignedBigInteger('subject_id')->change();
            
            // Re-add indexes
            $table->index('subject_id');
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });
    }
};