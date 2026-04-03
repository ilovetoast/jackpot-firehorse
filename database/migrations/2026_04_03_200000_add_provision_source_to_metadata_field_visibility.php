<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metadata_field_visibility', function (Blueprint $table) {
            if (! Schema::hasColumn('metadata_field_visibility', 'provision_source')) {
                $table->string('provision_source', 32)->nullable()->after('is_edit_hidden');
            }
        });
        Schema::table('metadata_field_visibility', function (Blueprint $table) {
            if (Schema::hasColumn('metadata_field_visibility', 'provision_source')) {
                $table->index(['tenant_id', 'provision_source'], 'mfv_tenant_provision_source_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('metadata_field_visibility', function (Blueprint $table) {
            if (Schema::hasColumn('metadata_field_visibility', 'provision_source')) {
                $table->dropIndex('mfv_tenant_provision_source_idx');
                $table->dropColumn('provision_source');
            }
        });
    }
};
