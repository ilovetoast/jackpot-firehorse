<?php

namespace App\Support\Metadata;

/**
 * Maps category slug → canonical primary metadata field key (UI abstraction only).
 * Aligns with config/metadata_category_defaults.php category_config + metadata_field_families.type.fields.
 */
class CategoryTypeResolver
{
    /**
     * Slug (lowercase) → field key. Use hyphens as in system category slugs.
     */
    public const SLUG_TO_TYPE_FIELD = [
        'photography' => 'photo_type',
        'logos' => 'logo_type',
        'fonts' => 'font_role',
        'graphics' => 'graphic_type',
        'illustrations' => 'graphic_type',
        'brand-elements' => 'graphic_type',
        'video' => 'video_type',
        'print' => 'print_type',
        'digital-ads' => 'digital_type',
        'ooh' => 'ooh_type',
        'events' => 'event_type',
        'videos' => 'execution_video_type',
        'sales-collateral' => 'sales_collateral_type',
        'pr' => 'pr_type',
        'packaging' => 'packaging_type',
        'product-renders' => 'product_render_type',
        'radio' => 'radio_type',
        'templates' => 'template_type',
        'audio' => 'audio_type',
        'model-3d' => 'model_3d_type',
        'social' => 'social_format',
        'email' => 'email_type',
        'web' => 'web_type',
    ];

    /**
     * File kind for {@see \App\Services\MetadataSchemaResolver::resolve} when loading upload metadata schema.
     * Must match {@see metadata_fields.applies_to} for this folder’s canonical type field (see MetadataFieldsSeeder).
     * Tags/collection/etc. use applies_to=all and appear for any kind.
     */
    public static function metadataSchemaAssetTypeForSlug(?string $categorySlug): string
    {
        $resolved = self::resolve($categorySlug);
        if ($resolved === null) {
            return 'image';
        }

        return match ($resolved['field_key']) {
            'video_type' => 'video',
            default => 'image',
        };
    }

    /**
     * @return array{field_key: string, label: string}|null
     */
    public static function resolve(?string $categorySlug): ?array
    {
        if ($categorySlug === null || $categorySlug === '') {
            return null;
        }

        $slug = strtolower(trim($categorySlug));
        if (! isset(self::SLUG_TO_TYPE_FIELD[$slug])) {
            return null;
        }

        $fieldKey = self::SLUG_TO_TYPE_FIELD[$slug];
        $label = match (true) {
            $slug === 'fonts' => 'Font role',
            $fieldKey === 'social_format' => 'Format',
            $fieldKey === 'email_type' => 'Email type',
            $fieldKey === 'web_type' => 'Web type',
            default => 'Type',
        };

        return [
            'field_key' => $fieldKey,
            'label' => $label,
        ];
    }

    /**
     * Keys listed in metadata_field_families.type.fields (for coverage / UI filtering).
     *
     * @return list<string>
     */
    public static function typeFamilyFieldKeys(): array
    {
        $fromConfig = config('metadata_field_families.type.fields', []);

        return is_array($fromConfig) ? array_values($fromConfig) : [];
    }
}
