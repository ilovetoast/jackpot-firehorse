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
     * Brand DNA / Brand Guidelines — versioned JSON model.
     * source_type: manual | scrape | ai | retrain
     * status: draft | active | archived
     */
    public function up(): void
    {
        Schema::create('brand_model_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('brand_model_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->integer('version_number')->default(1);

            $table->string('source_type')->default('manual');
            // manual | scrape | ai | retrain

            $table->json('model_payload');
            $table->json('metrics_payload')->nullable();

            $table->string('status')->default('draft');
            // draft | active | archived

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['brand_model_id', 'version_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_model_versions');
    }
};
