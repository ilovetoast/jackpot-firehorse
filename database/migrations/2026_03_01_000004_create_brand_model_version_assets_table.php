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
        if (Schema::hasTable('brand_model_version_assets')) {
            return;
        }
        Schema::create('brand_model_version_assets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_model_version_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignUuid('asset_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('builder_context'); // e.g. brand_material_example

            $table->timestamps();

            $table->unique(['brand_model_version_id', 'asset_id', 'builder_context']);
            $table->index('brand_model_version_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_model_version_assets');
    }
};
