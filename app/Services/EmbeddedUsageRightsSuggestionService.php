<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Suggests {@see usage_rights} (e.g. "Licensed") when embedded metadata indicates rights/copyright text
 * and the category schema includes usage_rights (licensing) for the asset — even if the field is hidden on upload.
 *
 * Suggestions use the same storage shape as AI suggestions ({@see AiMetadataSuggestionService}) with
 * source {@see EmbeddedUsageRightsSuggestionService::SOURCE} so the UI can distinguish them.
 */
class EmbeddedUsageRightsSuggestionService
{
    public const SOURCE = 'jackpot_embedded';

    public function __construct(
        protected MetadataSchemaResolver $schemaResolver
    ) {}

    /**
     * Merge embedded usage_rights suggestions into asset.metadata['_ai_suggestions'] without removing existing keys.
     */
    public function mergeEmbeddedUsageRightsIntoAsset(Asset $asset, AiMetadataSuggestionService $suggestionService): void
    {
        if (! config('embedded_licensing_suggestions.enabled', true)) {
            return;
        }

        $asset->refresh();

        $new = $this->buildSuggestion($asset, $suggestionService);
        if ($new === []) {
            return;
        }

        $existing = $suggestionService->getSuggestions($asset);
        foreach ($new as $key => $payload) {
            if (! isset($existing[$key])) {
                $existing[$key] = $payload;
            }
        }

        $suggestionService->storeSuggestions($asset, $existing);

        Log::info('[EmbeddedUsageRightsSuggestionService] Merged embedded usage_rights suggestion', [
            'asset_id' => $asset->id,
            'keys_added' => array_keys($new),
        ]);
    }

    /**
     * @return array<string, array{value: mixed, confidence: float, source: string, generated_at: string, evidence?: string}>
     */
    public function buildSuggestion(Asset $asset, AiMetadataSuggestionService $suggestionService): array
    {
        if (! config('embedded_licensing_suggestions.enabled', true)) {
            return [];
        }

        $categoryId = data_get($asset->metadata, 'category_id');
        if (! $categoryId) {
            return [];
        }

        $assetType = $this->determineAssetType($asset);
        $schema = $this->schemaResolver->resolve(
            $asset->tenant_id,
            $asset->brand_id,
            (int) $categoryId,
            $assetType
        );

        $usageRightsInSchema = false;
        foreach ($schema['fields'] ?? [] as $field) {
            if (($field['key'] ?? null) === 'usage_rights' && ($field['is_visible'] ?? false) === true) {
                $usageRightsInSchema = true;
                break;
            }
        }

        if (! $usageRightsInSchema) {
            return [];
        }

        $fieldKey = 'usage_rights';
        if (! $this->isFieldEligibleForEmbeddedUsageRights($asset, $fieldKey)) {
            return [];
        }

        if (! $suggestionService->isFieldEmptyForSuggestions($asset, $fieldKey)) {
            return [];
        }

        $targetValue = config('embedded_licensing_suggestions.suggest_value', 'licensed');
        if (! $suggestionService->isValueAllowedForField($fieldKey, $targetValue)) {
            return [];
        }

        if ($suggestionService->isSuggestionDismissedPublic($asset, $fieldKey, $targetValue)) {
            return [];
        }

        $evidence = $this->detectEmbeddedLicensingEvidence($asset);
        if ($evidence === null) {
            return [];
        }

        return [
            $fieldKey => [
                'value' => $targetValue,
                'confidence' => 1.0,
                'source' => self::SOURCE,
                'generated_at' => now()->toIso8601String(),
                'evidence' => $evidence,
            ],
        ];
    }

    /**
     * Field is eligible for embedded-driven suggestion (does not require ai_eligible).
     */
    protected function isFieldEligibleForEmbeddedUsageRights(Asset $asset, string $fieldKey): bool
    {
        $field = DB::table('metadata_fields')
            ->where('key', $fieldKey)
            ->where(function ($query) use ($asset) {
                $query->where('scope', 'system')
                    ->orWhere(function ($q) use ($asset) {
                        $q->where('tenant_id', $asset->tenant_id)
                            ->where('scope', '!=', 'system');
                    });
            })
            ->first();

        if (! $field) {
            return false;
        }

        if (! ($field->is_user_editable ?? true)) {
            return false;
        }

        if (($field->population_mode ?? 'manual') === 'automatic') {
            return false;
        }

        $fieldType = $field->type ?? 'text';
        if ($fieldType !== 'select') {
            return false;
        }

        return DB::table('metadata_options')
            ->where('metadata_field_id', $field->id)
            ->exists();
    }

    protected function detectEmbeddedLicensingEvidence(Asset $asset): ?string
    {
        $asset->loadMissing(['metadataIndexEntries']);

        $minLen = (int) config('embedded_licensing_suggestions.min_copyright_length', 12);

        $rows = $asset->metadataIndexEntries()
            ->where('is_visible', true)
            ->where('normalized_key', 'copyright_notice')
            ->get();

        foreach ($rows as $row) {
            $text = $row->value_string ?? '';
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) < $minLen) {
                continue;
            }
            if ($this->textLooksLikeRightsInformation($text)) {
                return 'copyright_notice';
            }
        }

        return null;
    }

    protected function textLooksLikeRightsInformation(string $text): bool
    {
        $lower = mb_strtolower($text);

        if (preg_match('/\b(copyright|©|\(c\)|all rights reserved|licensed|licence|license|shutterstock|getty|alamy|stock photo|royalty|permission|no use without)\b/u', $lower)) {
            return true;
        }

        return mb_strlen($text) >= 48;
    }

    protected function determineAssetType(Asset $asset): string
    {
        $mimeType = $asset->mime_type ?? '';
        $filename = $asset->original_filename ?? '';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (str_starts_with($mimeType, 'video/') || in_array($extension, ['mp4', 'mov', 'avi', 'mkv', 'webm'], true)) {
            return 'video';
        }

        $fts = app(\App\Services\FileTypeService::class);
        if ($fts->matchesRegistryType($mimeType, $extension, 'pdf') ||
            $fts->isOfficeDocument($mimeType, $extension) ||
            str_starts_with($mimeType, 'application/vnd.openxmlformats-officedocument')) {
            return 'document';
        }

        if (str_starts_with($mimeType, 'image/') || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'psd', 'ai', 'tif', 'tiff', 'cr2'], true)) {
            return 'image';
        }

        return 'image';
    }
}
