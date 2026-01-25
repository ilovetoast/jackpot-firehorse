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
        Schema::table('assets', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('deleted_at');
            $table->foreignId('published_by_id')->nullable()->after('published_at')->constrained('users')->onDelete('set null');
            $table->timestamp('archived_at')->nullable()->after('published_by_id');
            $table->foreignId('archived_by_id')->nullable()->after('archived_at')->constrained('users')->onDelete('set null');

            // Indexes for query performance
            $table->index('published_at');
            $table->index('archived_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['published_by_id']);
            $table->dropForeign(['archived_by_id']);
            $table->dropIndex(['published_at']);
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['published_at', 'published_by_id', 'archived_at', 'archived_by_id']);
        });
    }
};
