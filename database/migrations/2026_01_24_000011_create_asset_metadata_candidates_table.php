<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Phase B8: Create table for storing metadata candidates before resolution.
     * Allows multiple candidates per field from different sources (EXIF, AI, system).
     */
    public function up(): void
    {
        Schema::create('asset_metadata_candidates', function (Blueprint $table) {
            $table->id();
            $table->uuid('asset_id');
            $table->foreignId('metadata_field_id')->constrained('metadata_fields')->onDelete('restrict');
            $table->json('value_json');
            $table->string('source', 50); // exif, ai, system, user
            $table->decimal('confidence', 3, 2)->nullable(); // 0.00 to 1.00
            $table->string('producer', 50)->nullable(); // exif, ai, system, user
            $table->timestamp('resolved_at')->nullable(); // When this candidate was resolved to asset_metadata
            $table->timestamps();

            // Foreign key constraint for asset_id
            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->onDelete('cascade');

            // Indexes
            $table->index('asset_id');
            $table->index('metadata_field_id');
            $table->index(['asset_id', 'metadata_field_id']);
            $table->index('source');
            $table->index('resolved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_metadata_candidates');
    }
};
