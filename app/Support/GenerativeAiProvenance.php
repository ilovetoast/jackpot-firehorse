<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * IPTC-style AI / composite disclosure for {@see Asset::$metadata} (JSON), aligned with
 * <https://iptc.org/std/photometadata/documentation/userguide/> digital source type vocabulary.
 *
 * Does not embed XMP/EXIF into file bytes — DAM-side JSON for search, audit, and export pipelines.
 */
final class GenerativeAiProvenance
{
    /** Pure trained model output (no separate photographic source). */
    public const DIGITAL_SOURCE_TRAINED = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';

    /** Composite including trained algorithmic media and/or other sources. */
    public const DIGITAL_SOURCE_COMPOSITE_TRAINED = 'http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia';

    /** Human-made or manually composed digital artwork (no generative step). */
    public const DIGITAL_SOURCE_DIGITAL_ART = 'http://cv.iptc.org/newscodes/digitalsourcetype/digitalArt';

    /**
     * @param  array<string, mixed>  $hints
     *   From editor: document_id, layers_count, has_text, has_images, has_generative, has_brand_influence,
     *   reference_asset_ids (string[]), font_families (string[]).
     * @return array<string, mixed>
     */
    public static function forPublishedComposition(User $user, Brand $brand, ?Tenant $tenant, array $hints): array
    {
        $hasGenerative = (bool) ($hints['has_generative'] ?? false);
        $hasImages = (bool) ($hints['has_images'] ?? false);
        $refs = self::normalizeUuidList($hints['reference_asset_ids'] ?? []);

        $digitalSource = self::DIGITAL_SOURCE_DIGITAL_ART;
        if ($hasGenerative) {
            $digitalSource = ($hasImages || $refs !== [])
                ? self::DIGITAL_SOURCE_COMPOSITE_TRAINED
                : self::DIGITAL_SOURCE_TRAINED;
        }

        return self::basePayload(
            $user,
            $brand,
            $tenant,
            'editor_publish',
            $digitalSource,
            array_merge($hints, [
                'reference_asset_ids' => $refs,
            ])
        );
    }

    /**
     * @param  array<string, mixed>  $context
     *   composition_id, generative_layer_uuid, reference_asset_ids, model_provider, model_api_id,
     *   resolved_model_key, model_display_name, source_asset_id (edit), brand_context_applied (bool).
     * @return array<string, mixed>
     */
    public static function forPersistedGenerativeOutput(
        User $user,
        Brand $brand,
        ?Tenant $tenant,
        array $context,
        string $operation
    ): array {
        $refs = self::normalizeUuidList($context['reference_asset_ids'] ?? []);
        $sourceAsset = isset($context['source_asset_id']) ? trim((string) $context['source_asset_id']) : '';
        $isEdit = $operation === 'generative_edit' || $sourceAsset !== '';

        $digitalSource = ($isEdit || $refs !== [])
            ? self::DIGITAL_SOURCE_COMPOSITE_TRAINED
            : self::DIGITAL_SOURCE_TRAINED;

        return self::basePayload(
            $user,
            $brand,
            $tenant,
            $operation,
            $digitalSource,
            array_merge($context, [
                'reference_asset_ids' => $refs,
                'source_asset_id' => $sourceAsset !== '' ? $sourceAsset : null,
            ])
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function basePayload(
        User $user,
        Brand $brand,
        ?Tenant $tenant,
        string $operation,
        string $digitalSourceType,
        array $extra
    ): array {
        $vendor = trim((string) config('editor.provenance.vendor_name', 'Velvetysoft'));
        $appName = trim((string) config('app.name', 'Jackpot'));
        $generatorLabel = trim((string) config('editor.provenance.generator_label', ''));
        if ($generatorLabel === '') {
            $generatorLabel = $appName.' Generative Editor';
        }

        $creatorName = trim((string) $user->name);
        if ($creatorName === '') {
            $creatorName = 'User #'.$user->id;
        }

        return [
            'schema_version' => 1,
            'standard_note' => 'IPTC Digital Source Type vocabulary (JSON on asset; not embedded XMP in this version).',
            'digital_source_type' => $digitalSourceType,
            'operation' => $operation,
            'software_agent' => [
                'name' => $generatorLabel,
                'product' => $appName,
            ],
            'vendor' => [
                'name' => $vendor !== '' ? $vendor : 'Velvetysoft',
            ],
            'creator' => [
                'user_id' => (int) $user->id,
                'display_name' => $creatorName,
            ],
            'workspace' => [
                'tenant_id' => $tenant?->id,
                'brand_id' => (int) $brand->id,
                'brand_name' => (string) $brand->name,
            ],
            'captured_at' => now()->toIso8601String(),
            'hints' => self::pruneSerializableHints($extra),
        ];
    }

    /**
     * @param  array<string, mixed>  $hints
     * @return array<string, mixed>
     */
    private static function pruneSerializableHints(array $hints): array
    {
        $out = [];
        $allowedKeys = [
            'document_id',
            'layers_count',
            'has_text',
            'has_images',
            'has_generative',
            'has_brand_influence',
            'reference_asset_ids',
            'font_families',
            'composition_id',
            'generative_layer_uuid',
            'model_provider',
            'model_api_id',
            'resolved_model_key',
            'model_display_name',
            'model_key',
            'source_asset_id',
            'brand_context_applied',
        ];
        foreach ($allowedKeys as $key) {
            if (! array_key_exists($key, $hints)) {
                continue;
            }
            $v = $hints[$key];
            if ($v === null || $v === '') {
                continue;
            }
            if (is_array($v) && $v === []) {
                continue;
            }
            $out[$key] = $v;
        }

        return $out;
    }

    /**
     * @param  mixed  $ids
     * @return list<string>
     */
    private static function normalizeUuidList(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }
        $seen = [];
        $out = [];
        foreach ($ids as $id) {
            $s = strtolower(trim((string) $id));
            if ($s === '' || ! Str::isUuid($s)) {
                continue;
            }
            if (isset($seen[$s])) {
                continue;
            }
            $seen[$s] = true;
            $out[] = $s;
        }

        return $out;
    }
}
