<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds is_required column to metadata_field_visibility for category-scoped required field settings.
     * When true, the field must be filled when adding assets to that category.
     *
     * ARCHITECTURAL RULE: Required status MUST be category-scoped (like is_primary).
     * A field may be required in Photography but optional in Logos.
     */
    public function up(): void
    {
        Schema::table('metadata_field_visibility', function (Blueprint $table) {
            if (!Schema::hasColumn('metadata_field_visibility', 'is_required')) {
                $table->boolean('is_required')->nullable()->default(null)->after('is_primary');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('metadata_field_visibility', function (Blueprint $table) {
            if (Schema::hasColumn('metadata_field_visibility', 'is_required')) {
                $table->dropColumn('is_required');
            }
        });
    }
};
