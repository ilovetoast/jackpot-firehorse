<?php

namespace App\Enums;

/**
 * Asset type classification.
 *
 * Defines the category or purpose of an asset within the DAM system.
 * Used for organizational purposes and may affect processing pipelines.
 */
enum AssetType: string
{
    /**
     * General asset type for standard digital assets.
     * Default classification for uploaded files.
     * Applies to all standard asset categories.
     */
    case ASSET = 'asset';

    /**
     * Deliverable assets.
     * Used for deliverables, campaigns, and promotional content.
     * May have different processing or access rules.
     */
    case DELIVERABLE = 'deliverable';

    /**
     * AI-generated content.
     * Assets created or modified by AI services.
     * Used to track and manage AI-generated materials separately.
     */
    case AI_GENERATED = 'ai_generated';

    /**
     * Reference materials for Brand Builder.
     * PDFs, screenshots, ads, packaging examples used for brand research,
     * future AI analysis, and visual reference selection.
     * Not shown in normal asset libraries.
     */
    case REFERENCE = 'reference';
}
