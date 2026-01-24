<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase J.2.1: Tag normalization supporting tables
     * 
     * This table stores tenant-scoped synonym mappings to resolve multiple
     * tag inputs to a single canonical form.
     */
    public function up(): void
    {
        Schema::create('tag_synonyms', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned();
            $table->string('synonym_tag', 64); // The input synonym (already normalized)
            $table->string('canonical_tag', 64); // The canonical form to resolve to
            $table->timestamps();

            // Foreign key constraint for tenant_id
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->onDelete('cascade');

            // Indexes for fast lookups
            $table->index('tenant_id');
            $table->index(['tenant_id', 'synonym_tag']);
            $table->index(['tenant_id', 'canonical_tag']);

            // Unique constraint: one synonym per tenant
            $table->unique(['tenant_id', 'synonym_tag']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tag_synonyms');
    }
};