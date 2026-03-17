<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('brand_research_snapshots');
        Schema::dropIfExists('brand_pdf_vision_extractions');
        Schema::dropIfExists('brand_pdf_page_extractions');
        Schema::dropIfExists('brand_ingestion_records');
    }

    public function down(): void
    {
        // Recreating old tables would require full schema - not implemented for rollback
    }
};
