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
        if (Schema::hasTable('categories')) {
            return;
        }

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('brand_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('asset_type')->index(); // basic or marketing
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_private')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->timestamps();

            // Indexes
            $table->index('tenant_id');
            $table->index('brand_id');
            $table->index('asset_type');
            $table->index('is_system');

            // Unique constraint: slug must be unique per tenant/brand/asset_type combination
            $table->unique(['tenant_id', 'brand_id', 'asset_type', 'slug'], 'category_unique_slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
