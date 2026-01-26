<?php

/**
 * Diagnostic script to test video thumbnail generation
 * Run: docker compose exec laravel.test php test_video_thumbnail.php {asset_id}
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Asset;
use App\Services\FileTypeService;
use App\Services\ThumbnailGenerationService;

$assetId = $argv[1] ?? null;

if (!$assetId) {
    echo "Usage: php test_video_thumbnail.php {asset_id}\n";
    exit(1);
}

echo "Testing video thumbnail generation for asset: {$assetId}\n\n";

$asset = Asset::find($assetId);

if (!$asset) {
    echo "✗ Asset not found\n";
    exit(1);
}

echo "Asset Info:\n";
echo "  ID: {$asset->id}\n";
echo "  Filename: {$asset->original_filename}\n";
echo "  MIME Type: {$asset->mime_type}\n";
echo "  Thumbnail Status: " . ($asset->thumbnail_status?->value ?? 'null') . "\n";
echo "  Thumbnail Error: " . ($asset->thumbnail_error ?? 'none') . "\n\n";

// Test 1: File type detection
echo "Test 1: File Type Detection\n";
$fileTypeService = app(FileTypeService::class);
$fileType = $fileTypeService->detectFileTypeFromAsset($asset);
echo "  Detected file type: " . ($fileType ?? 'null') . "\n";

if (!$fileType) {
    echo "  ✗ File type not detected\n";
    exit(1);
}

if ($fileType !== 'video') {
    echo "  ✗ File type is not 'video' (got: {$fileType})\n";
    exit(1);
}

echo "  ✓ File type detected as 'video'\n\n";

// Test 2: Capability check
echo "Test 2: Thumbnail Capability\n";
$supportsThumbnail = $fileTypeService->supportsCapability($fileType, 'thumbnail');
echo "  Supports thumbnail: " . ($supportsThumbnail ? 'yes' : 'no') . "\n";

if (!$supportsThumbnail) {
    echo "  ✗ Video does not support thumbnail generation\n";
    exit(1);
}

echo "  ✓ Video supports thumbnail generation\n\n";

// Test 3: Requirements check
echo "Test 3: Requirements Check\n";
$requirements = $fileTypeService->checkRequirements($fileType);
echo "  Requirements met: " . ($requirements['met'] ? 'yes' : 'no') . "\n";

if (!$requirements['met']) {
    echo "  ✗ Requirements not met:\n";
    foreach ($requirements['missing'] as $missing) {
        echo "    - {$missing}\n";
    }
    exit(1);
}

echo "  ✓ All requirements met\n\n";

// Test 4: Handler check
echo "Test 4: Handler Check\n";
$handler = $fileTypeService->getHandler($fileType, 'thumbnail');
echo "  Handler method: " . ($handler ?? 'null') . "\n";

if (!$handler) {
    echo "  ✗ No handler found for video thumbnail generation\n";
    exit(1);
}

echo "  ✓ Handler found: {$handler}\n\n";

// Test 5: Method existence check
echo "Test 5: Method Existence Check\n";
$thumbnailService = app(ThumbnailGenerationService::class);
$reflection = new ReflectionClass($thumbnailService);
$hasMethod = $reflection->hasMethod($handler);

if (!$hasMethod) {
    echo "  ✗ Method '{$handler}' does not exist in ThumbnailGenerationService\n";
    exit(1);
}

$method = $reflection->getMethod($handler);
$isProtected = $method->isProtected();

echo "  ✓ Method exists: {$handler}\n";
echo "  Method visibility: " . ($isProtected ? 'protected' : 'public') . "\n\n";

// Test 6: FFmpeg check (via requirements check)
echo "Test 6: FFmpeg Availability\n";
// FFmpeg check is done via checkRequirements, which we already tested above
// If requirements are met, FFmpeg is available
echo "  ✓ FFmpeg availability confirmed via requirements check\n\n";

// Summary
echo "Summary:\n";
echo "  ✓ All checks passed! Video thumbnail generation should work.\n";
echo "  If thumbnails still aren't generating, check:\n";
echo "    1. Queue worker is running\n";
echo "    2. Asset has storage_root_path set\n";
echo "    3. Asset has storageBucket relationship\n";
echo "    4. Check Laravel logs for errors during thumbnail generation\n";
