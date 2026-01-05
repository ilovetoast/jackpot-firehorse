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
        Schema::create('system_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('asset_type'); // basic or marketing
            $table->boolean('is_private')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // Indexes
            $table->index('asset_type');
            $table->index('sort_order');

            // Unique constraint: slug must be unique per asset_type
            $table->unique(['slug', 'asset_type'], 'system_category_unique_slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_categories');
    }
};
