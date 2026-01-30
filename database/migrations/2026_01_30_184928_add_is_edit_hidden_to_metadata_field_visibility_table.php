<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * C9.2: Add is_edit_hidden column to separate edit visibility from category suppression.
     * 
     * is_hidden = category suppression (big toggle) - hides field entirely
     * is_edit_hidden = edit visibility (Quick View checkbox) - only hides from edit/drawer
     */
    public function up(): void
    {
        Schema::table('metadata_field_visibility', function (Blueprint $table) {
            if (!Schema::hasColumn('metadata_field_visibility', 'is_edit_hidden')) {
                $table->boolean('is_edit_hidden')->default(false)->after('is_upload_hidden');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('metadata_field_visibility', function (Blueprint $table) {
            if (Schema::hasColumn('metadata_field_visibility', 'is_edit_hidden')) {
                $table->dropColumn('is_edit_hidden');
            }
        });
    }
};
