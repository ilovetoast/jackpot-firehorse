<?php

namespace App\Assets\Metadata\Extractors\Contracts;

/**
 * Returns partial namespace buckets (e.g. ['exif' => [...], 'iptc' => [...]]).
 */
interface EmbeddedMetadataExtractor
{
    public function supports(string $mimeType, string $extension): bool;

    /**
     * @return array<string, mixed>
     */
    public function extract(string $localPath, string $mimeType, string $extension): array;
}
