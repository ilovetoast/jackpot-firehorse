<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('brand_pdf_pages');
    }

    public function down(): void
    {
        Schema::create('brand_pdf_pages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brand_pipeline_run_id')->index();
            $table->integer('page_number');
            $table->string('status')->default('pending');
            $table->json('extraction_json')->nullable();
            $table->string('image_path')->nullable();
            $table->string('page_type')->nullable();
            $table->timestamps();
        });
    }
};
