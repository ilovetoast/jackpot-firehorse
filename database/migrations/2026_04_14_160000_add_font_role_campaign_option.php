<?php

use App\Models\Tenant;
use App\Support\MetadataCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add "Campaign" option to system font_role select (Fonts library + filters + edit).
     */
    public function up(): void
    {
        if (! Schema::hasTable('metadata_fields') || ! Schema::hasTable('metadata_options')) {
            return;
        }

        $fieldId = DB::table('metadata_fields')
            ->where('key', 'font_role')
            ->where('scope', 'system')
            ->value('id');

        if (! $fieldId) {
            return;
        }

        $exists = DB::table('metadata_options')
            ->where('metadata_field_id', $fieldId)
            ->where('value', 'campaign')
            ->exists();

        if (! $exists) {
            DB::table('metadata_options')->insert([
                'metadata_field_id' => $fieldId,
                'value' => 'campaign',
                'system_label' => 'Campaign',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (Tenant::query()->pluck('id') as $tenantId) {
            MetadataCache::flushTenant((int) $tenantId);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('metadata_fields') || ! Schema::hasTable('metadata_options')) {
            return;
        }

        $fieldId = DB::table('metadata_fields')
            ->where('key', 'font_role')
            ->where('scope', 'system')
            ->value('id');

        if (! $fieldId) {
            return;
        }

        DB::table('metadata_options')
            ->where('metadata_field_id', $fieldId)
            ->where('value', 'campaign')
            ->delete();

        foreach (Tenant::query()->pluck('id') as $tenantId) {
            MetadataCache::flushTenant((int) $tenantId);
        }
    }
};
