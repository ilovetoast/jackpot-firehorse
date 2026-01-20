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
        if (Schema::hasTable('metadata_field_visibility')) {
            return;
        }

        Schema::create('metadata_field_visibility', function (Blueprint $table) {
            $table->id();
            $table->foreignId('metadata_field_id')->constrained('metadata_fields')->onDelete('restrict');
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('brand_id')->nullable()->constrained('brands')->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('cascade');
            
            // Visibility flags
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_upload_hidden')->default(false);
            $table->boolean('is_filter_hidden')->default(false);
            
            $table->timestamps();

            // Indexes
            $table->index('metadata_field_id');
            $table->index('tenant_id');
            $table->index('brand_id');
            $table->index('category_id');
            $table->index(['metadata_field_id', 'tenant_id'], 'mfv_field_tenant_idx');
            $table->index(['metadata_field_id', 'tenant_id', 'brand_id'], 'mfv_field_tenant_brand_idx');
            $table->index(['metadata_field_id', 'tenant_id', 'brand_id', 'category_id'], 'mfv_field_tenant_brand_cat_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metadata_field_visibility');
    }
};
