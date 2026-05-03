<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Support\Logging\AssetPipelineTimingLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * Reads laravel.log-style lines and extracts {@see AssetPipelineTimingLogger} rows
 * for a set of asset IDs. Intended for upload-batch profiling (queue wait vs runtime).
 */
final class AssetPipelineTimingLogReader
{
    private const MARKER = '[asset_pipeline_timing]';

    /** @var list<string> */
    private const EVENTS = [
        AssetPipelineTimingLogger::EVENT_ORIGINAL_STORED,
        AssetPipelineTimingLogger::EVENT_THUMBNAIL_DISPATCHED,
        AssetPipelineTimingLogger::EVENT_THUMBNAIL_STARTED,
        AssetPipelineTimingLogger::EVENT_THUMBNAIL_COMPLETED,
        AssetPipelineTimingLogger::EVENT_PREVIEW_COMPLETED,
        AssetPipelineTimingLogger::EVENT_METADATA_COMPLETED,
        AssetPipelineTimingLogger::EVENT_AI_CHAIN_DISPATCHED,
    ];

    /**
     * @param  list<string>  $assetIds
     * @return array<string, array{
     *     events: array<string, CarbonImmutable>,
     *     thumbnail_dispatch_queue: ?string,
     *     ai_dispatch_queue: ?string
     * }>
     */
    public function collectFromLogTail(string $logPath, array $assetIds, int $maxTailBytes): array
    {
        $targets = [];
        foreach ($assetIds as $id) {
            $s = trim((string) $id);
            if ($s !== '') {
                $targets[$s] = true;
            }
        }

        $empty = [];
        foreach (array_keys($targets) as $aid) {
            $empty[$aid] = [
                'events' => [],
                'thumbnail_dispatch_queue' => null,
                'ai_dispatch_queue' => null,
            ];
        }

        if ($targets === [] || ! is_readable($logPath)) {
            return $empty;
        }

        $maxTsByAssetEvent = [];
        $dispatchQueueByAsset = [];
        $aiQueueByAsset = [];

        foreach (array_keys($targets) as $aid) {
            $maxTsByAssetEvent[$aid] = [];
        }

        $this->scanFileTail($logPath, $maxTailBytes, function (string $line) use (&$maxTsByAssetEvent, &$dispatchQueueByAsset, &$aiQueueByAsset, $targets): void {
            if (! str_contains($line, self::MARKER)) {
                return;
            }

            $json = $this->extractJsonPayload($line);
            if ($json === null) {
                return;
            }

            /** @var array<string, mixed>|null $row */
            $row = json_decode($json, true);
            if (! is_array($row)) {
                return;
            }

            $aid = isset($row['asset_id']) ? (string) $row['asset_id'] : '';
            if ($aid === '' || ! isset($targets[$aid])) {
                return;
            }

            $event = isset($row['event']) ? (string) $row['event'] : '';
            if ($event === '' || ! in_array($event, self::EVENTS, true)) {
                return;
            }

            $tsRaw = $row['ts'] ?? null;
            if (! is_string($tsRaw) || $tsRaw === '') {
                return;
            }

            try {
                $t = CarbonImmutable::parse($tsRaw);
            } catch (\Throwable) {
                return;
            }

            $prev = $maxTsByAssetEvent[$aid][$event] ?? null;
            if ($prev === null || $t->gt($prev)) {
                $maxTsByAssetEvent[$aid][$event] = $t;
                if ($event === AssetPipelineTimingLogger::EVENT_THUMBNAIL_DISPATCHED) {
                    $dispatchQueueByAsset[$aid] = isset($row['queue']) ? (string) $row['queue'] : null;
                }
                if ($event === AssetPipelineTimingLogger::EVENT_AI_CHAIN_DISPATCHED) {
                    $aiQueueByAsset[$aid] = isset($row['queue']) ? (string) $row['queue'] : null;
                }
            }
        });

        $out = [];
        foreach (array_keys($targets) as $aid) {
            $out[$aid] = [
                'events' => $maxTsByAssetEvent[$aid] ?? [],
                'thumbnail_dispatch_queue' => $dispatchQueueByAsset[$aid] ?? null,
                'ai_dispatch_queue' => $aiQueueByAsset[$aid] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Resolve a readable log path: explicit file, default laravel.log, or newest laravel-*.log.
     */
    public function resolveLogPath(?string $preferredPath): ?string
    {
        if ($preferredPath !== null && $preferredPath !== '') {
            $p = $preferredPath;
            if (! str_starts_with($p, '/') && ! preg_match('#^[A-Za-z]:\\\\#', $p)) {
                $p = base_path($p);
            }
            if (is_readable($p)) {
                return $p;
            }
        }

        $default = storage_path('logs/laravel.log');
        if (is_readable($default)) {
            return $default;
        }

        $files = File::glob(storage_path('logs/laravel-*.log')) ?: [];
        if ($files === []) {
            return null;
        }

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        foreach ($files as $f) {
            if (is_readable($f)) {
                return $f;
            }
        }

        return null;
    }

    private function extractJsonPayload(string $line): ?string
    {
        $pos = strpos($line, self::MARKER);
        if ($pos === false) {
            return null;
        }

        $brace = strpos($line, '{', $pos);
        if ($brace === false) {
            return null;
        }

        return rtrim(substr($line, $brace));
    }

    /**
     * Stream lines from $path starting after (filesize - $maxTailBytes), skipping a likely partial first line.
     */
    private function scanFileTail(string $path, int $maxTailBytes, callable $onLine): void
    {
        $size = filesize($path);
        if ($size === false || $size === 0) {
            return;
        }

        $start = 0;
        if ($size > $maxTailBytes) {
            $start = $size - $maxTailBytes;
        }

        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return;
        }

        try {
            if ($start > 0) {
                fseek($fp, $start);
                fgets($fp);
            }

            while (($line = fgets($fp)) !== false) {
                $onLine($line);
            }
        } finally {
            fclose($fp);
        }
    }
}
