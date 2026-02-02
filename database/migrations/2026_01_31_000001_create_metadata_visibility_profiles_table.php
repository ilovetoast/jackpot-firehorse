<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3a: Named metadata visibility profiles.
     * Snapshot = per-field visibility (field_id, is_hidden, is_upload_hidden, is_filter_hidden, is_primary, is_edit_hidden).
     * Scope: tenant_id required; brand_id nullable = tenant-wide profile.
     */
    public function up(): void
    {
        if (Schema::hasTable('metadata_visibility_profiles')) {
            return;
        }

        Schema::create('metadata_visibility_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('brand_id')->nullable()->constrained('brands')->onDelete('cascade');
            $table->string('name');
            $table->string('category_slug')->nullable();
            $table->json('snapshot');
            $table->timestamps();

            $table->index(['tenant_id', 'brand_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metadata_visibility_profiles');
    }
};
