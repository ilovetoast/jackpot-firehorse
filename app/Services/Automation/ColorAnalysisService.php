<?php

namespace App\Services\Automation;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Facades\Log;

/**
 * Color Analysis Service
 *
 * Deterministic image color analysis for dominant colors extraction.
 * Analyzes images to extract color clusters and persists internal cluster data.
 * Used by DominantColorsExtractor to extract top 3 dominant colors.
 * No LLM or external APIs.
 *
 * Rules:
 * - Image assets only (GD: JPEG, PNG, WebP, GIF)
 * - Always runs against the generated thumbnail image (JPEG/PNG), never the original file.
 * - If thumbnail_status !== COMPLETED or thumbnail path is missing, skips analysis and logs once at INFO.
 * - No fallback to the original file.
 * - Deterministic output for identical images
 * - Internal cluster data stored in asset.metadata['_color_analysis'] (non-UI)
 * - Cluster data used by DominantColorsExtractor for dominant colors
 */
class ColorAnalysisService
{
    private const MAX_SIZE = 200;

    private const ALPHA_THRESHOLD = 0.95;

    private const K = 6;

    private const COVERAGE_MIN = 0.05;

    private const DELTA_E_MERGE = 10.0;

    private const BUCKET_COVERAGE_MIN = 0.08;

    private const BUCKET_MAX = 4;

    /** @var list<string> Color buckets (legacy - no longer used for metadata, kept for internal analysis) */
    private const BUCKETS = [
        'red', 'orange', 'yellow', 'green', 'blue',
        'purple', 'pink', 'brown', 'black', 'white', 'gray',
    ];

    /**
     * Analyze image and return macro buckets + internal cluster data.
     *
     * @return array{buckets: list<string>, internal: array{clusters: list<array{lab: list<float>, rgb: list<int>, coverage: float}>, ignored_pixels: float}}|null
     */
    public function analyze(Asset $asset): ?array
    {
        if (!$this->isImageAsset($asset)) {
            return null;
        }

        // Always use the generated thumbnail (JPEG/PNG); never the original file.
        if ($asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
            Log::info('[ColorAnalysisService] Skipping color analysis: thumbnail not completed', [
                'asset_id' => $asset->id,
                'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
            ]);
            return null;
        }

        $thumbnailPath = $asset->thumbnailPathForStyle('medium');
        if ($thumbnailPath === null || $thumbnailPath === '') {
            Log::info('[ColorAnalysisService] Skipping color analysis: thumbnail path missing', [
                'asset_id' => $asset->id,
            ]);
            return null;
        }

        $bucket = $asset->storageBucket;
        if (!$bucket) {
            Log::warning('[ColorAnalysisService] Missing storage bucket', ['asset_id' => $asset->id]);
            return null;
        }

        $tempPath = null;
        try {
            $tempPath = $this->downloadFromS3($bucket, $thumbnailPath);
            if (!is_string($tempPath) || !file_exists($tempPath) || filesize($tempPath) === 0) {
                return null;
            }

            return $this->analyzeFromPath($tempPath);
        } catch (\Throwable $e) {
            Log::warning('[ColorAnalysisService] Analysis failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        } finally {
            if ($tempPath && file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Analyze image from local path. Used by tests and job.
     *
     * @return array{buckets: list<string>, internal: array{clusters: list<array{lab: list<float>, rgb: list<int>, coverage: float}>, ignored_pixels: float}}|null
     */
    public function analyzeFromPath(string $path): ?array
    {
        $pixels = $this->loadAndSamplePixels($path);
        if (empty($pixels['lab'])) {
            return null;
        }

        $lab = $pixels['lab'];
        $ignored = $pixels['ignored_pixels'];

        $clusters = $this->kmeansLab($lab, self::K);
        $clusters = $this->noiseSuppression($clusters);
        $clusters = $this->mergeByDeltaE($clusters);

        $internal = [
            'clusters' => array_map(function ($c) {
                return [
                    'lab' => $c['lab'],
                    'rgb' => $c['rgb'],
                    'coverage' => round($c['coverage'], 4),
                ];
            }, $clusters),
            'ignored_pixels' => round($ignored, 4),
        ];

        $buckets = $this->mapToMacroBuckets($clusters);

        return [
            'buckets' => $buckets,
            'internal' => $internal,
        ];
    }

    protected function isImageAsset(Asset $asset): bool
    {
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $fileType = $fileTypeService->detectFileTypeFromAsset($asset);
        
        // Check if it's an image type (image, tiff, avif)
        return in_array($fileType, ['image', 'tiff', 'avif']);
    }

    /**
     * Load image, downsample to max 200x200, collect LAB pixels. Exclude alpha < 0.95.
     *
     * @return array{lab: list<list<float>>, ignored_pixels: float}
     */
    protected function loadAndSamplePixels(string $path): array
    {
        $img = $this->loadImage($path);
        if (!$img) {
            return ['lab' => [], 'ignored_pixels' => 1.0];
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= 0 || $h <= 0) {
            imagedestroy($img);
            return ['lab' => [], 'ignored_pixels' => 1.0];
        }

        $scale = min(1.0, self::MAX_SIZE / max($w, $h));
        $sw = (int) round($w * $scale);
        $sh = (int) round($h * $scale);
        $sw = max(1, $sw);
        $sh = max(1, $sh);

        $small = imagecreatetruecolor($sw, $sh);
        if (!$small) {
            imagedestroy($img);
            return ['lab' => [], 'ignored_pixels' => 1.0];
        }

        // Preserve alpha channel for PNG/WebP (always enable for truecolor destination)
        imagealphablending($small, false);
        imagesavealpha($small, true);
        $transparent = imagecolorallocatealpha($small, 0, 0, 0, 127);
        imagefill($small, 0, 0, $transparent);
        imagealphablending($small, true);

        imagecopyresampled($small, $img, 0, 0, 0, 0, $sw, $sh, $w, $h);
        imagedestroy($img);

        $total = $sw * $sh;
        $kept = 0;
        $labRows = [];

        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $rgba = $this->getPixelRgba($small, $x, $y);
                if ($rgba === null) {
                    continue;
                }
                [$r, $g, $b, $a] = $rgba;
                if ($a < self::ALPHA_THRESHOLD) {
                    continue;
                }
                $kept++;
                $labRows[] = $this->rgbToLab($r, $g, $b);
            }
        }

        imagedestroy($small);

        $ignored = $total > 0 ? 1.0 - ($kept / $total) : 1.0;

        return ['lab' => $labRows, 'ignored_pixels' => $ignored];
    }

    /**
     * @return resource|\GdImage|null
     */
    private function loadImage(string $path)
    {
        $info = @getimagesize($path);
        if (!$info || !isset($info[0], $info[1])) {
            return null;
        }

        $img = null;
        switch ($info[2]) {
            case IMAGETYPE_JPEG:
                $img = @imagecreatefromjpeg($path);
                break;
            case IMAGETYPE_PNG:
                $img = @imagecreatefrompng($path);
                break;
            case IMAGETYPE_WEBP:
                $img = @imagecreatefromwebp($path);
                break;
            case IMAGETYPE_GIF:
                $img = @imagecreatefromgif($path);
                break;
            default:
                return null;
        }

        if (!$img) {
            return null;
        }

        return $img;
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: float}|null RGB 0–255, alpha 0–1
     */
    private function getPixelRgba($im, int $x, int $y): ?array
    {
        $c = @imagecolorat($im, $x, $y);
        if ($c === false) {
            return null;
        }
        
        $r = ($c >> 16) & 0xFF;
        $g = ($c >> 8) & 0xFF;
        $b = $c & 0xFF;
        $a = 1.0;
        
        // Handle transparency for palette images
        if (!imageistruecolor($im)) {
            $transparent = imagecolortransparent($im);
            if ($transparent >= 0) {
                $tr = @imagecolorsforindex($im, $transparent);
                if ($tr && $tr['red'] === $r && $tr['green'] === $g && $tr['blue'] === $b) {
                    return null; // Transparent pixel
                }
            }
        } else {
            // Truecolor: alpha is in upper 7 bits (0-127, where 0 = opaque, 127 = transparent)
            $alpha = ($c >> 24) & 0x7F;
            $a = 1.0 - ($alpha / 127.0); // Convert to 0-1 scale where 1 = opaque
        }
        
        return [$r, $g, $b, $a];
    }

    /**
     * sRGB 0–255 -> CIE LAB L,a,b.
     *
     * @return list<float> [L, a, b]
     */
    protected function rgbToLab(int $r, int $g, int $b): array
    {
        $r = $r / 255.0;
        $g = $g / 255.0;
        $b = $b / 255.0;

        $r = $r <= 0.04045 ? $r / 12.92 : (($r + 0.055) / 1.055) ** 2.4;
        $g = $g <= 0.04045 ? $g / 12.92 : (($g + 0.055) / 1.055) ** 2.4;
        $b = $b <= 0.04045 ? $b / 12.92 : (($b + 0.055) / 1.055) ** 2.4;

        $x = $r * 0.4124564 + $g * 0.3575761 + $b * 0.1804375;
        $y = $r * 0.2126729 + $g * 0.7151522 + $b * 0.0721750;
        $z = $r * 0.0193339 + $g * 0.1191920 + $b * 0.9503041;

        $xn = 0.95047;
        $yn = 1.0;
        $zn = 1.08883;

        $x = $x / $xn;
        $y = $y / $yn;
        $z = $z / $zn;

        $x = $x > 0.008856 ? $x ** (1 / 3) : (7.787 * $x) + 16 / 116;
        $y = $y > 0.008856 ? $y ** (1 / 3) : (7.787 * $y) + 16 / 116;
        $z = $z > 0.008856 ? $z ** (1 / 3) : (7.787 * $z) + 16 / 116;

        $L = (116.0 * $y) - 16.0;
        $a = 500.0 * ($x - $y);
        $bLab = 200.0 * ($y - $z);

        return [$L, $a, $bLab];
    }

    /**
     * LAB -> sRGB [0-255, 0-255, 0-255] for internal storage.
     *
     * @param list<float> $lab
     * @return list<int>
     */
    protected function labToRgb(array $lab): array
    {
        $L = $lab[0];
        $a = $lab[1];
        $b = $lab[2];

        $y = ($L + 16) / 116;
        $x = $a / 500 + $y;
        $z = $y - $b / 200;

        $xn = 0.95047;
        $yn = 1.0;
        $zn = 1.08883;

        $x = $x > 0.206897 ? $x ** 3 : ($x - 16 / 116) / 7.787;
        $y = $y > 0.206897 ? $y ** 3 : ($y - 16 / 116) / 7.787;
        $z = $z > 0.206897 ? $z ** 3 : ($z - 16 / 116) / 7.787;

        $x *= $xn;
        $y *= $yn;
        $z *= $zn;

        $r = $x * 3.2404542 + $y * -1.5371385 + $z * -0.4985314;
        $g = $x * -0.9692660 + $y * 1.8760108 + $z * 0.0415560;
        $b = $x * 0.0556434 + $y * -0.2040259 + $z * 1.0572252;

        $r = $r > 0.0031308 ? 1.055 * ($r ** (1 / 2.4)) - 0.055 : 12.92 * $r;
        $g = $g > 0.0031308 ? 1.055 * ($g ** (1 / 2.4)) - 0.055 : 12.92 * $g;
        $b = $b > 0.0031308 ? 1.055 * ($b ** (1 / 2.4)) - 0.055 : 12.92 * $b;

        $r = (int) round(max(0, min(255, $r * 255)));
        $g = (int) round(max(0, min(255, $g * 255)));
        $b = (int) round(max(0, min(255, $b * 255)));

        return [$r, $g, $b];
    }

    /**
     * K-means on LAB vectors, k=6. Deterministic init and iterations.
     *
     * @param list<list<float>> $lab
     * @return list<array{lab: list<float>, rgb: list<int>, coverage: float, count: int}>
     */
    protected function kmeansLab(array $lab, int $k): array
    {
        $n = count($lab);
        if ($n === 0 || $k < 1) {
            return [];
        }

        $k = min($k, $n);
        $assignments = array_fill(0, $n, 0);
        $centroids = [];

        $sorted = $lab;
        usort($sorted, function ($a, $b) {
            return $a[0] <=> $b[0];
        });
        $step = (int) max(1, floor($n / $k));
        for ($i = 0; $i < $k; $i++) {
            $idx = min($i * $step, $n - 1);
            $centroids[] = $sorted[$idx];
        }

        $maxIter = 50;
        for ($iter = 0; $iter < $maxIter; $iter++) {
            $sums = array_fill(0, $k, [0.0, 0.0, 0.0]);
            $counts = array_fill(0, $k, 0);

            for ($i = 0; $i < $n; $i++) {
                $best = 0;
                $bestD = PHP_FLOAT_MAX;
                for ($c = 0; $c < $k; $c++) {
                    $d = $this->deltaE($lab[$i], $centroids[$c]);
                    if ($d < $bestD) {
                        $bestD = $d;
                        $best = $c;
                    }
                }
                $assignments[$i] = $best;
                $sums[$best][0] += $lab[$i][0];
                $sums[$best][1] += $lab[$i][1];
                $sums[$best][2] += $lab[$i][2];
                $counts[$best]++;
            }

            $updated = false;
            for ($c = 0; $c < $k; $c++) {
                if ($counts[$c] === 0) {
                    continue;
                }
                $newC = [
                    $sums[$c][0] / $counts[$c],
                    $sums[$c][1] / $counts[$c],
                    $sums[$c][2] / $counts[$c],
                ];
                if ($this->deltaE($centroids[$c], $newC) > 0.001) {
                    $updated = true;
                }
                $centroids[$c] = $newC;
            }
            if (!$updated) {
                break;
            }
        }

        $out = [];
        for ($c = 0; $c < $k; $c++) {
            $cnt = $counts[$c];
            if ($cnt === 0) {
                continue;
            }
            $coverage = $cnt / $n;
            $out[] = [
                'lab' => $centroids[$c],
                'rgb' => $this->labToRgb($centroids[$c]),
                'coverage' => $coverage,
                'count' => $cnt,
            ];
        }

        usort($out, fn ($a, $b) => $b['coverage'] <=> $a['coverage']);
        return $out;
    }

    protected function deltaE(array $a, array $b): float
    {
        $dL = $a[0] - $b[0];
        $da = $a[1] - $b[1];
        $db = $a[2] - $b[2];
        return sqrt($dL * $dL + $da * $da + $db * $db);
    }

    /**
     * Discard clusters with coverage < 5%.
     *
     * @param list<array{lab: list<float>, rgb: list<int>, coverage: float, count: int}> $clusters
     * @return list<array{lab: list<float>, rgb: list<int>, coverage: float, count: int}>
     */
    protected function noiseSuppression(array $clusters): array
    {
        $total = 0.0;
        foreach ($clusters as $c) {
            $total += $c['coverage'];
        }
        if ($total <= 0) {
            return [];
        }
        $kept = [];
        foreach ($clusters as $c) {
            if ($c['coverage'] >= self::COVERAGE_MIN) {
                $kept[] = $c;
            }
        }
        return $kept;
    }

    /**
     * Merge clusters with ΔE < 10. Merge into higher-coverage cluster.
     *
     * @param list<array{lab: list<float>, rgb: list<int>, coverage: float, count: int}> $clusters
     * @return list<array{lab: list<float>, rgb: list<int>, coverage: float, count: int}>
     */
    protected function mergeByDeltaE(array $clusters): array
    {
        if (count($clusters) <= 1) {
            return $clusters;
        }

        $merged = $clusters;
        $changed = true;
        $maxIterations = 10; // Prevent infinite loops
        
        while ($changed && $maxIterations-- > 0) {
            $changed = false;
            $next = [];
            $used = array_fill(0, count($merged), false);

            for ($i = 0; $i < count($merged); $i++) {
                if ($used[$i]) {
                    continue;
                }
                $ci = $merged[$i];
                $mergedThis = false;
                
                for ($j = $i + 1; $j < count($merged); $j++) {
                    if ($used[$j]) {
                        continue;
                    }
                    $cj = $merged[$j];
                    if ($this->deltaE($ci['lab'], $cj['lab']) < self::DELTA_E_MERGE) {
                        // Merge: weighted average by count
                        $totalCount = $ci['count'] + $cj['count'];
                        if ($totalCount > 0) {
                            $wi = $ci['count'] / $totalCount;
                            $wj = $cj['count'] / $totalCount;
                            $newLab = [
                                $ci['lab'][0] * $wi + $cj['lab'][0] * $wj,
                                $ci['lab'][1] * $wi + $cj['lab'][1] * $wj,
                                $ci['lab'][2] * $wi + $cj['lab'][2] * $wj,
                            ];
                            $next[] = [
                                'lab' => $newLab,
                                'rgb' => $this->labToRgb($newLab),
                                'coverage' => $ci['coverage'] + $cj['coverage'],
                                'count' => (int) $totalCount,
                            ];
                            $used[$i] = true;
                            $used[$j] = true;
                            $changed = true;
                            $mergedThis = true;
                            break; // Only merge one pair per iteration
                        }
                    }
                }
                
                if (!$used[$i] && !$mergedThis) {
                    $next[] = $ci;
                }
            }

            if ($changed) {
                usort($next, fn ($a, $b) => $b['coverage'] <=> $a['coverage']);
                $merged = $next;
            }
        }

        return $merged;
    }

    /**
     * Map LAB clusters to macro buckets. Max 4 buckets, min 8% coverage each.
     *
     * @param list<array{lab: list<float>, rgb: list<int>, coverage: float, count: int}> $clusters
     * @return list<string>
     */
    protected function mapToMacroBuckets(array $clusters): array
    {
        $buckets = [];
        $remain = self::BUCKET_MAX;

        foreach ($clusters as $c) {
            if ($remain <= 0) {
                break;
            }
            if ($c['coverage'] < self::BUCKET_COVERAGE_MIN) {
                continue;
            }
            $bucket = $this->labToBucket($c['lab']);
            if ($bucket !== null && !in_array($bucket, $buckets, true)) {
                $buckets[] = $bucket;
                $remain--;
            }
        }

        return $buckets;
    }

    /**
     * Map single LAB centroid to one macro bucket. Heuristics per spec.
     */
    protected function labToBucket(array $lab): ?string
    {
        $L = $lab[0];
        $a = $lab[1];
        $b = $lab[2];
        $chroma = sqrt($a * $a + $b * $b);

        if ($L < 15) {
            return 'black';
        }
        if ($L > 90) {
            return 'white';
        }
        if ($L >= 20 && $L <= 80 && $chroma < 12) {
            return 'gray';
        }
        if ($a > 40) {
            if ($L > 70) {
                return 'pink';
            }
            if ($b < -20) {
                return 'purple';
            }
            return 'red';
        }
        if ($a < -40) {
            return 'green';
        }
        if ($b < -40) {
            return 'blue';
        }
        if ($b > 40) {
            return 'yellow';
        }
        if ($L < 50 && $chroma < 40 && $a > 0 && $b > 0) {
            return 'brown';
        }
        if ($a > 15 && $a < 50 && $b > 15 && $b < 50) {
            return 'orange';
        }

        return null;
    }

    /**
     * Download asset from S3 to temp file. Same pattern as ComputedMetadataService.
     *
     * @param object $bucket
     */
    protected function downloadFromS3($bucket, string $s3Path): string
    {
        if (!class_exists(\Aws\S3\S3Client::class)) {
            throw new \RuntimeException('AWS SDK not installed.');
        }

        $accessKey = !empty($bucket->access_key_id) ? $bucket->access_key_id : env('AWS_ACCESS_KEY_ID');
        $secretKey = !empty($bucket->secret_access_key) ? $bucket->secret_access_key : env('AWS_SECRET_ACCESS_KEY');
        $region = $bucket->region ?? env('AWS_DEFAULT_REGION', 'us-east-1');

        if (empty($accessKey) || empty($secretKey)) {
            throw new \RuntimeException('S3 credentials not available.');
        }

        $config = [
            'version' => 'latest',
            'region' => $region,
            'credentials' => ['key' => $accessKey, 'secret' => $secretKey],
        ];
        if (!empty($bucket->endpoint)) {
            $config['endpoint'] = $bucket->endpoint;
            $config['use_path_style_endpoint'] = $bucket->use_path_style_endpoint ?? true;
        } elseif (env('AWS_ENDPOINT')) {
            $config['endpoint'] = env('AWS_ENDPOINT');
            $config['use_path_style_endpoint'] = env('AWS_USE_PATH_STYLE_ENDPOINT', true);
        }

        $client = new \Aws\S3\S3Client($config);

        try {
            $result = $client->getObject(['Bucket' => $bucket->name, 'Key' => $s3Path]);
            $body = (string) $result['Body'];
            if (strlen($body) === 0) {
                throw new \RuntimeException('Downloaded file is empty.');
            }
            $tmp = tempnam(sys_get_temp_dir(), 'color_analysis_');
            file_put_contents($tmp, $body);
            return $tmp;
        } catch (S3Exception $e) {
            Log::error('[ColorAnalysisService] S3 download failed', [
                'bucket' => $bucket->name,
                'key' => $s3Path,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to download from S3: ' . $e->getMessage(), 0, $e);
        }
    }
}
