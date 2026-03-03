<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_pdf_page_extractions', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id', 64)->index();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_model_version_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('asset_id');
            $table->unsignedTinyInteger('page_number');
            $table->json('extraction_json')->nullable();
            $table->string('status')->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'page_number']);
            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_pdf_page_extractions');
    }
};
