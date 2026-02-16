<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * Brand DNA / Brand Guidelines — foundational table.
     * One per brand, auto-created on brand creation.
     * active_version_id added in separate migration (after brand_model_versions).
     */
    public function up(): void
    {
        Schema::create('brand_models', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_id')
                ->constrained()
                ->cascadeOnDelete()
                ->unique();

            $table->boolean('is_enabled')->default(false);

            $table->timestamps();

            $table->index('brand_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_models');
    }
};
