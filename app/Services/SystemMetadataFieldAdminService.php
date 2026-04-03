<?php

namespace App\Services;

use App\Jobs\BackfillHybridVisibilityForMetadataFieldJob;
use App\Models\SystemCategory;
use App\Models\SystemCategoryFieldDefault;
use App\Support\MetadataCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin-only creation of system-scoped metadata fields and optional bundle rows.
 */
class SystemMetadataFieldAdminService
{
    protected const ALLOWED_TYPES = [
        'text',
        'textarea',
        'select',
        'multiselect',
        'number',
        'boolean',
        'date',
    ];

    /**
     * @param  array{
     *   key:string,
     *   system_label:string,
     *   type:string,
     *   applies_to:string,
     *   population_mode?:string,
     *   show_on_upload?:bool,
     *   show_on_edit?:bool,
     *   show_in_filters?:bool,
     *   readonly?:bool,
     *   is_filterable?:bool,
     *   is_user_editable?:bool,
     *   is_ai_trainable?:bool,
     *   is_internal_only?:bool,
     *   group_key?:string|null,
     *   options?: list<array{value:string,label:string}>,
     *   template_defaults?: list<array{
     *     system_category_id:int,
     *     is_hidden?:bool,
     *     is_upload_hidden?:bool,
     *     is_filter_hidden?:bool,
     *     is_edit_hidden?:bool,
     *     is_primary?:bool|null
     *   }>
     * }  $data
     * @return array{id:int}
     */
    public function createSystemField(array $data): array
    {
        $type = $data['type'] ?? '';
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => ['Invalid field type.'],
            ]);
        }

        $key = $this->normalizeKey($data['key'] ?? '');
        if ($key === '') {
            throw ValidationException::withMessages([
                'key' => ['A unique snake_case key is required.'],
            ]);
        }

        $exists = DB::table('metadata_fields')
            ->where('key', $key)
            ->where('scope', 'system')
            ->whereNull('archived_at')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'key' => ['A system field with this key already exists.'],
            ]);
        }

        $appliesTo = $data['applies_to'] ?? 'all';
        if (! in_array($appliesTo, ['all', 'image', 'video', 'document'], true)) {
            throw ValidationException::withMessages([
                'applies_to' => ['applies_to must be all, image, video, or document.'],
            ]);
        }

        $populationMode = $data['population_mode'] ?? 'manual';
        if (! in_array($populationMode, ['manual', 'automatic', 'hybrid'], true)) {
            throw ValidationException::withMessages([
                'population_mode' => ['Invalid population_mode.'],
            ]);
        }

        if (in_array($type, ['select', 'multiselect'], true)) {
            $options = $data['options'] ?? [];
            if (! is_array($options) || $options === []) {
                throw ValidationException::withMessages([
                    'options' => ['At least one option is required for select/multiselect.'],
                ]);
            }
        }

        $showOnUpload = (bool) ($data['show_on_upload'] ?? true);
        $showOnEdit = (bool) ($data['show_on_edit'] ?? true);
        $showInFilters = (bool) ($data['show_in_filters'] ?? true);

        return DB::transaction(function () use ($data, $key, $type, $appliesTo, $populationMode, $showOnUpload, $showOnEdit, $showInFilters) {
            $now = now();
            $fieldId = DB::table('metadata_fields')->insertGetId([
                'key' => $key,
                'system_label' => $data['system_label'],
                'type' => $type,
                'applies_to' => $appliesTo,
                'scope' => 'system',
                'tenant_id' => null,
                'is_active' => true,
                'is_filterable' => (bool) ($data['is_filterable'] ?? $showInFilters),
                'is_user_editable' => (bool) ($data['is_user_editable'] ?? true),
                'is_ai_trainable' => (bool) ($data['is_ai_trainable'] ?? false),
                'is_upload_visible' => $showOnUpload,
                'is_internal_only' => (bool) ($data['is_internal_only'] ?? false),
                'group_key' => $data['group_key'] ?? 'custom',
                'plan_gate' => null,
                'deprecated_at' => null,
                'replacement_field_id' => null,
                'population_mode' => $populationMode,
                'show_on_upload' => $showOnUpload,
                'show_on_edit' => $showOnEdit,
                'show_in_filters' => $showInFilters,
                'readonly' => (bool) ($data['readonly'] ?? false),
                'is_primary' => false,
                'archived_at' => null,
                'ai_eligible' => false,
                'display_widget' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if (in_array($type, ['select', 'multiselect'], true)) {
                foreach ($data['options'] as $i => $opt) {
                    $value = isset($opt['value']) ? trim((string) $opt['value']) : '';
                    $label = isset($opt['label']) ? trim((string) $opt['label']) : '';
                    if ($value === '' || $label === '') {
                        continue;
                    }
                    DB::table('metadata_options')->insert([
                        'metadata_field_id' => $fieldId,
                        'value' => $value,
                        'system_label' => $label,
                        'is_system' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $templates = $data['template_defaults'] ?? [];
            if (is_array($templates)) {
                foreach ($templates as $row) {
                    $scid = (int) ($row['system_category_id'] ?? 0);
                    if ($scid <= 0) {
                        continue;
                    }
                    $template = SystemCategory::query()->where('id', $scid)->first();
                    if (! $template || ! $template->isLatestVersion()) {
                        continue;
                    }
                    SystemCategoryFieldDefault::query()->updateOrCreate(
                        [
                            'system_category_id' => $scid,
                            'metadata_field_id' => $fieldId,
                        ],
                        [
                            'is_hidden' => (bool) ($row['is_hidden'] ?? false),
                            'is_upload_hidden' => (bool) ($row['is_upload_hidden'] ?? false),
                            'is_filter_hidden' => (bool) ($row['is_filter_hidden'] ?? false),
                            'is_edit_hidden' => (bool) ($row['is_edit_hidden'] ?? false),
                            'is_primary' => array_key_exists('is_primary', $row) ? $row['is_primary'] : null,
                        ]
                    );
                }
            }

            MetadataCache::flushGlobal();

            if (! empty($templates)) {
                BackfillHybridVisibilityForMetadataFieldJob::dispatch($fieldId);
            }

            return ['id' => $fieldId];
        });
    }

    protected function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/\s+/', '_', $key) ?? $key;
        if (! preg_match('/^[a-z][a-z0-9_]{0,62}$/', $key)) {
            return '';
        }

        return $key;
    }
}
