<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1A: Asset versioning.
 *
 * Creates asset_versions table. Additive only.
 * is_current uniqueness enforced at service layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id')->index();
            $table->unsignedInteger('version_number');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('checksum')->index();
            $table->uuid('uploaded_by')->nullable();
            $table->text('change_note')->nullable();
            $table->uuid('restored_from_version_id')->nullable();
            $table->string('pipeline_status')->default('pending');
            $table->boolean('is_current')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
            $table->unique(['asset_id', 'version_number']);
            $table->index(['asset_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_versions');
    }
};
