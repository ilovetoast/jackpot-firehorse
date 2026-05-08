<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metadata_fields', function (Blueprint $table) {
            if (! Schema::hasColumn('metadata_fields', 'description')) {
                $table->text('description')->nullable()->after('system_label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('metadata_fields', function (Blueprint $table) {
            if (Schema::hasColumn('metadata_fields', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
