<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Soft delete for custom metadata fields (scope != 'system').
     * System fields cannot be archived. Custom fields can be archived
     * to remove them from the interface without hard deletion.
     */
    public function up(): void
    {
        Schema::table('metadata_fields', function (Blueprint $table) {
            if (!Schema::hasColumn('metadata_fields', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('deprecated_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('metadata_fields', function (Blueprint $table) {
            if (Schema::hasColumn('metadata_fields', 'archived_at')) {
                $table->dropColumn('archived_at');
            }
        });
    }
};
