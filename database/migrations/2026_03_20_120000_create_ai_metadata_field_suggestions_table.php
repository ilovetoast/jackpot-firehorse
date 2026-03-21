<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suggested new metadata fields (not created in metadata_fields until accepted).
     */
    public function up(): void
    {
        Schema::create('ai_metadata_field_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('category_slug');
            $table->string('field_name');
            $table->string('field_key');
            $table->json('suggested_options');
            $table->unsignedInteger('supporting_asset_count');
            $table->decimal('confidence', 7, 4);
            $table->string('source_cluster', 191);
            $table->string('status', 32)->default('pending');
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'category_slug', 'field_key', 'source_cluster'],
                'amfs_tenant_cat_field_cluster_unique'
            );
            $table->index(['tenant_id', 'category_slug']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_metadata_field_suggestions');
    }
};
