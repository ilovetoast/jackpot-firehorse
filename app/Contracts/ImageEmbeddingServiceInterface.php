<?php

namespace App\Contracts;

use App\Models\Asset;

/**
 * Image Embedding Service Interface
 *
 * Generates embedding vectors for images. Used for imagery similarity scoring.
 * Implementations may call external APIs (e.g. OpenAI CLIP, Replicate) or use local models.
 */
interface ImageEmbeddingServiceInterface
{
    /**
     * Generate embedding vector for an asset's image.
     *
     * @return array<float> Embedding vector (normalized, typically 512 or 1536 dimensions)
     */
    public function embedAsset(Asset $asset): array;
}
