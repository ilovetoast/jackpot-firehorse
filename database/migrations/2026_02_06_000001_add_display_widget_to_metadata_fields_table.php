<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * display_widget: optional UI hint for how to render the field (e.g. 'toggle' for boolean).
     * When set, upload form, edit modal, and filters use the same widget for consistency.
     */
    public function up(): void
    {
        Schema::table('metadata_fields', function (Blueprint $table) {
            if (!Schema::hasColumn('metadata_fields', 'display_widget')) {
                $table->string('display_widget', 32)->nullable()->after('group_key');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('metadata_fields', function (Blueprint $table) {
            if (Schema::hasColumn('metadata_fields', 'display_widget')) {
                $table->dropColumn('display_widget');
            }
        });
    }
};
