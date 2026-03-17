<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_pdf_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_pipeline_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('page_number');
            $table->json('extraction_json')->nullable();
            $table->string('status', 32)->default('pending'); // pending, processing, completed, failed
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['brand_pipeline_run_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_pdf_pages');
    }
};
