<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_ingestion_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_model_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('processing'); // processing, completed, failed
            $table->json('extraction_json')->nullable();
            $table->timestamps();
            $table->index(['brand_id', 'brand_model_version_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_ingestion_records');
    }
};
