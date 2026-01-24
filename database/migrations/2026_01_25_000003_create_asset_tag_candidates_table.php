<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Create table for storing tag candidates before resolution.
     * Allows multiple tag candidates from different sources (AI, user) to be reviewed
     * before being applied to the asset.
     */
    public function up(): void
    {
        Schema::create('asset_tag_candidates', function (Blueprint $table) {
            $table->id();
            $table->uuid('asset_id');
            $table->string('tag', 64);
            $table->string('producer', 50)->default('ai');
            $table->string('source', 50)->default('ai');
            $table->decimal('confidence', 5, 4)->nullable(); // 0.0000 to 1.0000
            $table->timestamp('resolved_at')->nullable(); // When this candidate was resolved to asset_tags
            $table->timestamp('dismissed_at')->nullable(); // When this candidate was dismissed (rejected)
            $table->timestamps();

            // Foreign key constraint for asset_id
            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->onDelete('cascade');

            // Indexes
            $table->index('asset_id');
            $table->index('tag');

            // Unique constraint: ensures no duplicate unresolved candidates
            // Note: The unique constraint on (asset_id, tag, producer, resolved_at, dismissed_at)
            // allows multiple NULL values in MySQL, so application logic should also validate
            // that no duplicate unresolved candidates exist (where both resolved_at and dismissed_at are NULL)
            $table->unique(['asset_id', 'tag', 'producer', 'resolved_at', 'dismissed_at'], 'asset_tag_candidates_unique_unresolved');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_tag_candidates');
    }
};
