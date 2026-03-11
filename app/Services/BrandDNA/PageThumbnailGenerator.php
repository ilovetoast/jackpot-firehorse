<?php

namespace App\Services\BrandDNA;

/**
 * Generates small base64 thumbnails from page images for developer QA.
 */
class PageThumbnailGenerator
{
    protected const MAX_WIDTH = 200;

    protected const QUALITY = 75;

    /**
     * Generate a base64 data URL thumbnail from a PNG/WebP image path.
     *
     * @return string|null data:image/webp;base64,... or null on failure
     */
    public function generate(string $imagePath): ?string
    {
        if (! file_exists($imagePath) || ! is_readable($imagePath)) {
            return null;
        }

        $mime = mime_content_type($imagePath) ?: 'image/png';
        $img = null;
        if (str_contains($mime, 'png')) {
            $img = @imagecreatefrompng($imagePath);
        } elseif (str_contains($mime, 'webp') && function_exists('imagecreatefromwebp')) {
            $img = @imagecreatefromwebp($imagePath);
        } elseif (str_contains($mime, 'jpeg') || str_contains($mime, 'jpg')) {
            $img = @imagecreatefromjpeg($imagePath);
        } else {
            $img = @imagecreatefrompng($imagePath);
        }
        if (! $img) {
            return null;
        }

        $width = imagesx($img);
        $height = imagesy($img);
        if ($width < 1 || $height < 1) {
            imagedestroy($img);
            return null;
        }

        $scale = min(1.0, self::MAX_WIDTH / $width);
        $newWidth = (int) round($width * $scale);
        $newHeight = (int) round($height * $scale);
        if ($newWidth < 1) {
            $newWidth = 1;
        }
        if ($newHeight < 1) {
            $newHeight = 1;
        }

        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        if (! $thumb) {
            imagedestroy($img);
            return null;
        }

        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($img);

        ob_start();
        if (function_exists('imagewebp')) {
            imagewebp($thumb, null, self::QUALITY);
            $mimeOut = 'image/webp';
        } else {
            imagepng($thumb, null, 8);
            $mimeOut = 'image/png';
        }
        $data = ob_get_clean();
        imagedestroy($thumb);

        if ($data === false || $data === '') {
            return null;
        }

        return 'data:' . $mimeOut . ';base64,' . base64_encode($data);
    }
}
