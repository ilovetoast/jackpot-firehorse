<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remove deprecated dominant_color_bucket field.
 * Replaced by dominant_hue_group (HueClusterService).
 */
return new class extends Migration
{
    public function up(): void
    {
        $fieldId = DB::table('metadata_fields')
            ->where('key', 'dominant_color_bucket')
            ->value('id');

        if ($fieldId) {
            DB::table('asset_metadata')
                ->where('metadata_field_id', $fieldId)
                ->delete();

            DB::table('metadata_field_visibility')
                ->where('metadata_field_id', $fieldId)
                ->delete();

            DB::table('metadata_fields')
                ->where('key', 'dominant_color_bucket')
                ->delete();
        }

        if (Schema::hasColumn('assets', 'dominant_color_bucket')) {
            Schema::table('assets', function (Blueprint $table) {
                $table->dropIndex(['dominant_color_bucket']); // Laravel default: assets_dominant_color_bucket_index
                $table->dropColumn('dominant_color_bucket');
            });
        }
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('dominant_color_bucket', 32)->nullable()->after('metadata')->index();
        });

        // Recreate metadata field for rollback (seeder no longer creates it)
        DB::table('metadata_fields')->insert([
            'key' => 'dominant_color_bucket',
            'system_label' => 'Dominant Color Bucket (Deprecated)',
            'type' => 'text',
            'applies_to' => 'image',
            'scope' => 'system',
            'tenant_id' => null,
            'group_key' => 'technical',
            'is_filterable' => false,
            'is_user_editable' => false,
            'is_ai_trainable' => false,
            'is_upload_visible' => false,
            'is_internal_only' => true,
            'ai_eligible' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
