<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // pdf_page_count and pdf_pages_rendered are added by add_pdf_page_tracking_to_assets_and_create_asset_pdf_pages_table (runs first)
        $after = Schema::hasColumn('assets', 'pdf_pages_rendered') ? 'pdf_pages_rendered' : 'thumbnail_error';

        Schema::table('assets', function (Blueprint $table) use (&$after) {
            if (!Schema::hasColumn('assets', 'pdf_unsupported_large')) {
                $table->boolean('pdf_unsupported_large')->default(false)->after($after);
                $after = 'pdf_unsupported_large';
            }
            if (!Schema::hasColumn('assets', 'pdf_rendered_pages_count')) {
                $table->unsignedInteger('pdf_rendered_pages_count')->nullable()->after($after);
                $after = 'pdf_rendered_pages_count';
            }
            if (!Schema::hasColumn('assets', 'pdf_rendered_storage_bytes')) {
                $table->unsignedBigInteger('pdf_rendered_storage_bytes')->nullable()->after($after);
                $after = 'pdf_rendered_storage_bytes';
            }
            if (!Schema::hasColumn('assets', 'full_pdf_extraction_batch_id')) {
                $table->string('full_pdf_extraction_batch_id')->nullable()->after($after);
            }
        });

        Schema::table('assets', function (Blueprint $table) {
            if (Schema::hasColumn('assets', 'pdf_unsupported_large')) {
                try {
                    $table->index('pdf_unsupported_large');
                } catch (\Throwable) {
                    // Index already exists (e.g. from prior run of this migration)
                }
            }
            if (Schema::hasColumn('assets', 'full_pdf_extraction_batch_id')) {
                try {
                    $table->index('full_pdf_extraction_batch_id');
                } catch (\Throwable) {
                    // Index already exists
                }
            }
        });
    }

    public function down(): void
    {
        $columnsToDrop = array_filter([
            'pdf_unsupported_large' => Schema::hasColumn('assets', 'pdf_unsupported_large'),
            'pdf_rendered_pages_count' => Schema::hasColumn('assets', 'pdf_rendered_pages_count'),
            'pdf_rendered_storage_bytes' => Schema::hasColumn('assets', 'pdf_rendered_storage_bytes'),
            'full_pdf_extraction_batch_id' => Schema::hasColumn('assets', 'full_pdf_extraction_batch_id'),
        ], fn ($exists) => $exists);

        Schema::table('assets', function (Blueprint $table) use ($columnsToDrop) {
            if (Schema::hasColumn('assets', 'pdf_unsupported_large')) {
                $table->dropIndex(['pdf_unsupported_large']);
            }
            if (Schema::hasColumn('assets', 'full_pdf_extraction_batch_id')) {
                $table->dropIndex(['full_pdf_extraction_batch_id']);
            }
            if (!empty($columnsToDrop)) {
                $table->dropColumn(array_keys($columnsToDrop));
            }
        });
    }
};
