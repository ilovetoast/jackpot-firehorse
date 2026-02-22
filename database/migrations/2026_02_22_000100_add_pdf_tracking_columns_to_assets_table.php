<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->unsignedInteger('pdf_page_count')->nullable()->after('thumbnail_error');
            $table->boolean('pdf_unsupported_large')->default(false)->after('pdf_page_count');
            $table->unsignedInteger('pdf_rendered_pages_count')->nullable()->after('pdf_unsupported_large');
            $table->unsignedBigInteger('pdf_rendered_storage_bytes')->nullable()->after('pdf_rendered_pages_count');
            $table->boolean('pdf_pages_rendered')->default(false)->after('pdf_rendered_storage_bytes');
            $table->string('full_pdf_extraction_batch_id')->nullable()->after('pdf_pages_rendered');

            $table->index('pdf_page_count');
            $table->index('pdf_unsupported_large');
            $table->index('full_pdf_extraction_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['pdf_page_count']);
            $table->dropIndex(['pdf_unsupported_large']);
            $table->dropIndex(['full_pdf_extraction_batch_id']);
            $table->dropColumn([
                'pdf_page_count',
                'pdf_unsupported_large',
                'pdf_rendered_pages_count',
                'pdf_rendered_storage_bytes',
                'pdf_pages_rendered',
                'full_pdf_extraction_batch_id',
            ]);
        });
    }
};
