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
        Schema::create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('brand_id')->nullable()->constrained()->onDelete('cascade');
            $table->uuid('upload_session_id');
            $table->uuid('storage_bucket_id');
            $table->string('status');
            $table->string('type');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type')->nullable();
            $table->string('path');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();

            // Foreign key constraints
            $table->foreign('upload_session_id')
                ->references('id')
                ->on('upload_sessions')
                ->onDelete('cascade');

            $table->foreign('storage_bucket_id')
                ->references('id')
                ->on('storage_buckets')
                ->onDelete('cascade');

            // Indexes
            $table->index('tenant_id');
            $table->index('brand_id');
            $table->index('upload_session_id');
            $table->index('storage_bucket_id');
            $table->index('status');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
