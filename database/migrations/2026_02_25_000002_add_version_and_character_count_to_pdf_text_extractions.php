<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_text_extractions', function (Blueprint $table) {
            if (!Schema::hasColumn('pdf_text_extractions', 'asset_version_id')) {
                $table->uuid('asset_version_id')->nullable()->after('asset_id');
                $table->foreign('asset_version_id')
                    ->references('id')
                    ->on('asset_versions')
                    ->nullOnDelete();
                $table->index(['asset_id', 'asset_version_id']);
            }
            if (!Schema::hasColumn('pdf_text_extractions', 'character_count')) {
                $table->unsignedInteger('character_count')->nullable()->after('extracted_text');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pdf_text_extractions', function (Blueprint $table) {
            if (Schema::hasColumn('pdf_text_extractions', 'asset_version_id')) {
                $table->dropForeign(['asset_version_id']);
                $table->dropIndex(['asset_id', 'asset_version_id']);
                $table->dropColumn('asset_version_id');
            }
        });
        Schema::table('pdf_text_extractions', function (Blueprint $table) {
            if (Schema::hasColumn('pdf_text_extractions', 'character_count')) {
                $table->dropColumn('character_count');
            }
        });
    }
};
