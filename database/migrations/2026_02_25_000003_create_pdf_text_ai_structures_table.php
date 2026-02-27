<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_text_ai_structures', function (Blueprint $table) {
            $table->id();
            $table->uuid('asset_id');
            $table->foreignId('pdf_text_extraction_id')->constrained('pdf_text_extractions')->cascadeOnDelete();
            $table->string('ai_model');
            $table->json('structured_json');
            $table->text('summary')->nullable();
            $table->float('confidence_score')->nullable();
            $table->string('status')->default('complete');
            $table->timestamps();

            $table->foreign('asset_id')
                ->references('id')
                ->on('assets')
                ->cascadeOnDelete();
            $table->index(['asset_id', 'created_at']);
            // pdf_text_extraction_id is indexed via its foreign key
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_text_ai_structures');
    }
};
