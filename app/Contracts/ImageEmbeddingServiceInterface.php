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
     * @param  string|null  $imageUrlOverride  When set, embed from this authenticated image URL instead of the asset's default thumbnail variant.
     * @return array<float> Embedding vector (normalized, typically 512 or 1536 dimensions)
     */
    public function embedAsset(Asset $asset, ?string $imageUrlOverride = null): array;

    /**
     * Generate embedding vector directly from a local image file.
     *
     * Used when the image is not an asset's own delivery URL -- e.g. a derived
     * crop of a detected logo region. When no external embedding API is
     * configured, implementations should fall back to a deterministic
     * placeholder seeded by the file contents.
     *
     * @return array<float>  Normalized embedding vector, or [] on failure.
     */
    public function embedLocalImage(string $localPath): array;
}
