<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds optional color and icon to metadata_options for select/multiselect fields.
     * Backward compatible: existing options without color/icon render normally.
     */
    public function up(): void
    {
        Schema::table('metadata_options', function (Blueprint $table) {
            $table->string('color', 7)->nullable()->after('system_label');
            $table->string('icon', 64)->nullable()->after('color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('metadata_options', function (Blueprint $table) {
            $table->dropColumn(['color', 'icon']);
        });
    }
};
