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
            // Remove asset-specific fields
            $table->dropColumn(['file_name', 'file_size', 'mime_type', 'path', 'metadata']);

            // Add upload attempt fields
            $table->unsignedBigInteger('expected_size')->after('type');
            $table->unsignedBigInteger('uploaded_size')->nullable()->after('expected_size');
            $table->timestamp('expires_at')->nullable()->after('uploaded_size');
            $table->text('failure_reason')->nullable()->after('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            // Restore asset-specific fields
            $table->string('file_name')->after('type');
            $table->unsignedBigInteger('file_size')->after('file_name');
            $table->string('mime_type')->nullable()->after('file_size');
            $table->string('path')->after('mime_type');
            $table->json('metadata')->nullable()->after('path');

            // Remove upload attempt fields
            $table->dropColumn(['expected_size', 'uploaded_size', 'expires_at', 'failure_reason']);
        });
    }
};
