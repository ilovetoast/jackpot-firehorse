<?php

use App\Enums\AssetType;
use App\Models\Brand;
use App\Models\SystemCategory;
use App\Services\SystemCategoryService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hidden "Fonts" system category (synced from Brand Guidelines typography) + font_role metadata field.
     */
    public function up(): void
    {
        $template = SystemCategory::query()
            ->where('slug', 'fonts')
            ->where('asset_type', AssetType::ASSET)
            ->where('version', 1)
            ->first();

        if (! $template) {
            $template = SystemCategory::create([
                'name' => 'Fonts',
                'slug' => 'fonts',
                'asset_type' => AssetType::ASSET,
                'is_private' => false,
                'is_hidden' => true,
                'sort_order' => 4,
                'version' => 1,
            ]);
        }

        $svc = app(SystemCategoryService::class);
        foreach (Brand::query()->cursor() as $brand) {
            $svc->addTemplateToBrand($brand, $template);
        }

        $existingField = DB::table('metadata_fields')
            ->where('key', 'font_role')
            ->where('scope', 'system')
            ->first();

        if (! $existingField) {
            $row = [
                'key' => 'font_role',
                'system_label' => 'Font role',
                'type' => 'select',
                'applies_to' => 'all',
                'scope' => 'system',
                'group_key' => 'creative',
                'is_filterable' => true,
                'is_user_editable' => true,
                'is_ai_trainable' => false,
                'is_upload_visible' => true,
                'is_internal_only' => false,
                'ai_eligible' => false,
                'display_widget' => 'select',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('metadata_fields', 'tenant_id')) {
                $row['tenant_id'] = null;
            }
            if (Schema::hasColumn('metadata_fields', 'is_active')) {
                $row['is_active'] = true;
            }
            if (Schema::hasColumn('metadata_fields', 'population_mode')) {
                $row['population_mode'] = 'manual';
            }
            if (Schema::hasColumn('metadata_fields', 'show_on_upload')) {
                $row['show_on_upload'] = true;
            }
            if (Schema::hasColumn('metadata_fields', 'show_on_edit')) {
                $row['show_on_edit'] = true;
            }
            if (Schema::hasColumn('metadata_fields', 'show_in_filters')) {
                $row['show_in_filters'] = true;
            }
            if (Schema::hasColumn('metadata_fields', 'readonly')) {
                $row['readonly'] = false;
            }

            $fieldId = DB::table('metadata_fields')->insertGetId($row);

            foreach (
                [
                    ['value' => 'headline', 'system_label' => 'Headline'],
                    ['value' => 'body_copy', 'system_label' => 'Body copy'],
                ] as $opt
            ) {
                DB::table('metadata_options')->insert([
                    'metadata_field_id' => $fieldId,
                    'value' => $opt['value'],
                    'system_label' => $opt['system_label'],
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        $fieldId = DB::table('metadata_fields')
            ->where('key', 'font_role')
            ->where('scope', 'system')
            ->value('id');

        if ($fieldId) {
            DB::table('metadata_options')->where('metadata_field_id', $fieldId)->delete();
            if (Schema::hasTable('metadata_field_visibility')) {
                DB::table('metadata_field_visibility')->where('metadata_field_id', $fieldId)->delete();
            }
            if (Schema::hasTable('asset_metadata')) {
                DB::table('asset_metadata')->where('metadata_field_id', $fieldId)->delete();
            }
            DB::table('metadata_fields')->where('id', $fieldId)->delete();
        }

        SystemCategory::query()
            ->where('slug', 'fonts')
            ->where('asset_type', AssetType::ASSET)
            ->delete();
    }
};
