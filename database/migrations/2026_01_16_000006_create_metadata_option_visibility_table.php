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
        if (Schema::hasTable('metadata_option_visibility')) {
            return;
        }

        Schema::create('metadata_option_visibility', function (Blueprint $table) {
            $table->id();
            $table->foreignId('metadata_option_id')->constrained('metadata_options')->onDelete('restrict');
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('brand_id')->nullable()->constrained('brands')->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('cascade');
            
            // Visibility flag
            $table->boolean('is_hidden')->default(false);
            
            $table->timestamps();

            // Indexes
            $table->index('metadata_option_id');
            $table->index('tenant_id');
            $table->index('brand_id');
            $table->index('category_id');
            $table->index(['metadata_option_id', 'tenant_id'], 'mov_option_tenant_idx');
            $table->index(['metadata_option_id', 'tenant_id', 'brand_id'], 'mov_option_tenant_brand_idx');
            $table->index(['metadata_option_id', 'tenant_id', 'brand_id', 'category_id'], 'mov_option_tenant_brand_cat_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metadata_option_visibility');
    }
};
