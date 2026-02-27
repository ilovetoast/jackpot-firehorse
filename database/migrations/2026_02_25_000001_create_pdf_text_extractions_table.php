<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_text_extractions', function (Blueprint $table) {
            $table->id();
            $table->uuid('asset_id');
            $table->longText('extracted_text')->nullable();
            $table->string('extraction_source')->nullable(); // pdftotext | tesseract | textract
            $table->string('status')->default('pending'); // pending, processing, complete, failed
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->onDelete('cascade');
            $table->index(['asset_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_text_extractions');
    }
};
