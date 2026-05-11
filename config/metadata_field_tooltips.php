<?php

/**
 * Fallback helper text for asset drawer / metadata tooltips when metadata_fields.description is empty.
 * Prefer configuring descriptions in Manage → Fields; these defaults match MetadataFieldsSeeder::syncFeaturedFieldLabelAndDescriptions.
 */
return [
    'descriptions' => [
        'environment_type' => 'Where the scene takes place.',
        'subject_type' => 'What the main subject is.',
        'orientation' => 'Landscape, portrait, or square (from the file).',
        'color_space' => 'Color profile detected from the file.',
        'resolution_class' => 'Rough resolution tier from pixel size.',
        'dominant_colors' => 'Main colors extracted from the image.',
        'dominant_hue_group' => 'Broad color family for quick filtering.',
        'photo_type' => 'How the photo is shot or staged.',
        'usage_rights' => 'Where and how this asset may be used.',
        'expiration_date' => 'When usage rights end, if applicable.',
        'tags' => 'Labels for search, filters, and organization.',
        'collection' => 'Curated groups this asset belongs to.',
        'quality_rating' => 'Team quality score from 1–5 stars.',
        'starred' => 'Feature this asset to keep it at the top of the list.',
        'dimensions' => 'Width and height detected from the file.',
    ],
];
