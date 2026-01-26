<?php

/**
 * Simple script to check FFmpeg availability
 * Run this inside Docker: docker compose exec laravel.test php check_ffmpeg.php
 */

echo "Checking FFmpeg availability...\n\n";

// Method 1: Check using 'which' command
$output = [];
$returnCode = 0;
exec('which ffmpeg 2>&1', $output, $returnCode);

if ($returnCode === 0 && !empty($output[0]) && file_exists($output[0])) {
    echo "✓ FFmpeg found in PATH: {$output[0]}\n";
    $ffmpegPath = $output[0];
} else {
    echo "✗ FFmpeg not found in PATH\n";
    $ffmpegPath = null;
}

// Method 2: Check common paths
$possiblePaths = [
    '/usr/bin/ffmpeg',
    '/usr/local/bin/ffmpeg',
    '/opt/homebrew/bin/ffmpeg',
];

foreach ($possiblePaths as $path) {
    if (file_exists($path) && is_executable($path)) {
        echo "✓ FFmpeg found at: {$path}\n";
        $ffmpegPath = $path;
        break;
    }
}

if (!$ffmpegPath) {
    echo "\n✗ FFmpeg is NOT installed or not accessible\n";
    echo "To install FFmpeg in Docker, add to Dockerfile:\n";
    echo "  RUN apt-get update && apt-get install -y ffmpeg\n";
    exit(1);
}

// Test FFmpeg version
echo "\nTesting FFmpeg version:\n";
exec("{$ffmpegPath} -version 2>&1 | head -n 1", $versionOutput, $versionReturnCode);
if ($versionReturnCode === 0 && !empty($versionOutput)) {
    echo "✓ " . $versionOutput[0] . "\n";
} else {
    echo "✗ Could not get FFmpeg version\n";
}

// Test FFprobe (usually comes with FFmpeg)
$ffprobePath = str_replace('ffmpeg', 'ffprobe', $ffmpegPath);
if (file_exists($ffprobePath) && is_executable($ffprobePath)) {
    echo "\n✓ FFprobe found at: {$ffprobePath}\n";
} else {
    echo "\n⚠ FFprobe not found (may be needed for video metadata)\n";
}

echo "\n✓ FFmpeg is available and should work for video thumbnail generation!\n";
