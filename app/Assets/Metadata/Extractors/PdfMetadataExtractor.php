<?php

namespace App\Assets\Metadata\Extractors;

use App\Assets\Metadata\Extractors\Contracts\EmbeddedMetadataExtractor;
use Imagick;

/**
 * PDF document info via Imagick (first page). Requires Ghostscript/ImageMagick PDF support.
 *
 * Best-effort only: property names and availability vary by ImageMagick/Ghostscript build.
 * Missing or differently named keys are expected in some environments; raw payload may be sparse.
 */
class PdfMetadataExtractor implements EmbeddedMetadataExtractor
{
    public function supports(string $mimeType, string $extension): bool
    {
        return $mimeType === 'application/pdf' || strtolower($extension) === 'pdf';
    }

    /**
     * {@inheritdoc}
     */
    public function extract(string $localPath, string $mimeType, string $extension): array
    {
        $pdf = [];

        if (! class_exists(Imagick::class)) {
            return ['pdf' => $pdf, 'other' => ['pdf_extract' => 'imagick_unavailable']];
        }

        try {
            $imagick = new Imagick;
            $imagick->setResolution(72, 72);
            $imagick->readImage($localPath.'[0]');
            $props = $imagick->getImageProperties();
            $imagick->clear();
            $imagick->destroy();

            $prefix = 'pdf:';
            foreach ($props as $name => $value) {
                if (! str_starts_with(strtolower($name), $prefix)) {
                    continue;
                }
                if (! is_string($value) && ! is_numeric($value)) {
                    continue;
                }
                $short = substr($name, strlen($prefix));
                if ($short === '') {
                    continue;
                }
                // Map common PDF info keys to registry names
                $canonical = match (strtolower($short)) {
                    'author' => 'Author',
                    'title' => 'Title',
                    'subject' => 'Subject',
                    'keywords' => 'Keywords',
                    'creator' => 'Creator',
                    'producer' => 'Producer',
                    'creationdate' => 'CreationDate',
                    'moddate' => 'ModDate',
                    default => ucfirst($short),
                };
                $pdf[$canonical] = is_string($value) ? $value : (string) $value;
            }
        } catch (\Throwable $e) {
            return [
                'pdf' => [],
                'other' => ['pdf_extract_error' => $e->getMessage()],
            ];
        }

        return ['pdf' => $pdf];
    }
}
