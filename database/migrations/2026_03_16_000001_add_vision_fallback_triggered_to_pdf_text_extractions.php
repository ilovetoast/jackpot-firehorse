<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_text_extractions', function (Blueprint $table) {
            if (! Schema::hasColumn('pdf_text_extractions', 'vision_fallback_triggered')) {
                $table->boolean('vision_fallback_triggered')->default(false)->after('failure_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pdf_text_extractions', function (Blueprint $table) {
            if (Schema::hasColumn('pdf_text_extractions', 'vision_fallback_triggered')) {
                $table->dropColumn('vision_fallback_triggered');
            }
        });
    }
};
