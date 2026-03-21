<?php

namespace App\Services\AI\Insights;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Tenant;
use App\Services\TenantMetadataFieldService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Accept / reject flows for Insights Review (value + field suggestion tables only).
 */
class AiInsightSuggestionActionService
{
    public function __construct(
        protected TenantMetadataFieldService $tenantMetadataFieldService,
        protected AiSuggestionSuppressionService $suggestionSuppression
    ) {}

    public function acceptValueSuggestion(int $id, Tenant $tenant): void
    {
        $row = DB::table('ai_metadata_value_suggestions')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->first();

        if (! $row) {
            throw ValidationException::withMessages(['id' => ['Suggestion not found or already handled.']]);
        }

        $field = $this->resolveMetadataFieldForTenant($tenant->id, (string) $row->field_key);
        if (! $field) {
            throw ValidationException::withMessages(['field_key' => ['No metadata field matches this key for your tenant.']]);
        }

        if (! in_array($field->type, ['select', 'multiselect'], true)) {
            throw ValidationException::withMessages(['field_key' => ['This field is not a select; add options only for select or multiselect fields.']]);
        }

        $suggested = trim((string) $row->suggested_value);
        if ($suggested === '') {
            throw ValidationException::withMessages(['suggested_value' => ['Invalid suggested value.']]);
        }

        $norm = mb_strtolower($suggested);
        $dup = DB::table('metadata_options')
            ->where('metadata_field_id', $field->id)
            ->where(function ($q) use ($norm) {
                $q->whereRaw('LOWER(TRIM(value)) = ?', [$norm])
                    ->orWhereRaw('LOWER(TRIM(system_label)) = ?', [$norm]);
            })
            ->exists();

        DB::transaction(function () use ($field, $suggested, $dup, $id, $tenant) {
            if (! $dup) {
                DB::table('metadata_options')->insert([
                    'metadata_field_id' => $field->id,
                    'value' => $suggested,
                    'system_label' => $suggested,
                    'is_system' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('ai_metadata_value_suggestions')
                ->where('id', $id)
                ->where('tenant_id', $tenant->id)
                ->where('status', 'pending')
                ->update(['status' => 'accepted', 'updated_at' => now()]);
        });
    }

    public function rejectValueSuggestion(int $id, Tenant $tenant): bool
    {
        $row = DB::table('ai_metadata_value_suggestions')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->first();

        if (! $row) {
            return false;
        }

        $ok = DB::table('ai_metadata_value_suggestions')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->update(['status' => 'rejected', 'updated_at' => now()]) > 0;

        if ($ok) {
            $key = AiSuggestionSuppressionService::normalizeValueKey((string) $row->field_key, (string) $row->suggested_value);
            $this->suggestionSuppression->recordRejection($tenant->id, 'value', $key);
        }

        return $ok;
    }

    public function acceptFieldSuggestion(int $id, Tenant $tenant, Brand $brand): void
    {
        $row = DB::table('ai_metadata_field_suggestions')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->first();

        if (! $row) {
            throw ValidationException::withMessages(['id' => ['Suggestion not found or already handled.']]);
        }

        $category = Category::query()
            ->where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('slug', $row->category_slug)
            ->whereNull('deleted_at')
            ->first();

        if (! $category) {
            throw ValidationException::withMessages(['category' => ['Category not found for this brand.']]);
        }

        $optionsJson = $row->suggested_options;
        if (is_string($optionsJson)) {
            $optionsJson = json_decode($optionsJson, true);
        }
        if (! is_array($optionsJson) || $optionsJson === []) {
            throw ValidationException::withMessages(['suggested_options' => ['No suggested options to create.']]);
        }

        $customKey = $this->toCustomFieldKey((string) $row->field_key);

        $options = [];
        $usedValues = [];
        foreach ($optionsJson as $label) {
            if (! is_string($label) || trim($label) === '') {
                continue;
            }
            $label = trim($label);
            $val = Str::slug($label, '_');
            if ($val === '') {
                $val = mb_strtolower(preg_replace('/\s+/', '_', $label));
            }
            $orig = $val;
            $n = 2;
            while (isset($usedValues[$val])) {
                $val = $orig.'_'.$n;
                $n++;
            }
            $usedValues[$val] = true;
            $options[] = [
                'value' => $val,
                'label' => $label,
            ];
        }

        if ($options === []) {
            throw ValidationException::withMessages(['suggested_options' => ['No valid option labels.']]);
        }

        DB::transaction(function () use ($tenant, $customKey, $row, $category, $options, $id) {
            $this->tenantMetadataFieldService->createField($tenant, [
                'key' => $customKey,
                'system_label' => (string) $row->field_name,
                'type' => 'select',
                'applies_to' => 'all',
                'selectedCategories' => [(int) $category->id],
                'options' => $options,
                'is_filterable' => true,
                'show_on_upload' => true,
                'show_on_edit' => true,
                'show_in_filters' => true,
                'group_key' => 'custom',
                'ai_eligible' => true,
            ]);

            DB::table('ai_metadata_field_suggestions')
                ->where('id', $id)
                ->where('tenant_id', $tenant->id)
                ->where('status', 'pending')
                ->update(['status' => 'accepted', 'updated_at' => now()]);
        });
    }

    public function rejectFieldSuggestion(int $id, Tenant $tenant): bool
    {
        $row = DB::table('ai_metadata_field_suggestions')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->first();

        if (! $row) {
            return false;
        }

        $ok = DB::table('ai_metadata_field_suggestions')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->update(['status' => 'rejected', 'updated_at' => now()]) > 0;

        if ($ok) {
            $key = AiSuggestionSuppressionService::normalizeFieldKey(
                (string) $row->category_slug,
                (string) $row->field_key,
                (string) $row->source_cluster
            );
            $this->suggestionSuppression->recordRejection($tenant->id, 'field', $key);
        }

        return $ok;
    }

    /**
     * @return object{id: int, type: string}|null
     */
    protected function resolveMetadataFieldForTenant(int $tenantId, string $fieldKey): ?object
    {
        return DB::table('metadata_fields')
            ->whereNull('deprecated_at')
            ->where('key', $fieldKey)
            ->where(function ($q) use ($tenantId) {
                $q->where(function ($q2) use ($tenantId) {
                    $q2->where('scope', 'tenant')
                        ->where('tenant_id', $tenantId);
                })->orWhere(function ($q2) {
                    $q2->where('scope', 'system')
                        ->whereNull('tenant_id');
                });
            })
            ->select(['id', 'type'])
            ->first();
    }

    protected function toCustomFieldKey(string $suggestedKey): string
    {
        $base = preg_replace('/[^a-z0-9_]/', '_', mb_strtolower($suggestedKey));
        $base = preg_replace('/_+/', '_', (string) $base);
        $base = trim((string) $base, '_');
        if ($base === '') {
            $base = 'field';
        }

        return 'custom__'.$base;
    }
}
