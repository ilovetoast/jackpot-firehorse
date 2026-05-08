<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * pr_type option "backgrounder" was removed in favor of "internal" (MetadataFieldsSeeder).
 * Remap stored JSON values for tenants that already had canonical "backgrounder" from earlier seeds/migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('metadata_fields') || ! Schema::hasTable('asset_metadata')) {
            return;
        }

        $fieldId = DB::table('metadata_fields')->where('key', 'pr_type')->value('id');
        if (! $fieldId) {
            return;
        }

        DB::table('asset_metadata')
            ->where('metadata_field_id', $fieldId)
            ->where('value_json', json_encode('backgrounder'))
            ->update([
                'value_json' => json_encode('internal'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Intentionally empty: do not restore deprecated taxonomy value.
    }
};
