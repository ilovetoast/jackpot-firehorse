<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_text_extractions', function (Blueprint $table) {
            if (! Schema::hasColumn('pdf_text_extractions', 'failure_reason')) {
                $table->string('failure_reason', 255)->nullable()->after('error_message');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pdf_text_extractions', function (Blueprint $table) {
            if (Schema::hasColumn('pdf_text_extractions', 'failure_reason')) {
                $table->dropColumn('failure_reason');
            }
        });
    }
};
