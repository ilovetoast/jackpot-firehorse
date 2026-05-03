<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateThumbnailsJob;
use App\Models\Asset;
use App\Models\UploadSession;
use App\Services\Assets\AssetPipelineTimingLogReader;
use App\Support\Logging\AssetPipelineTimingLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Batch report: {@see AssetPipelineTimingLogger} timelines from laravel.log plus Horizon/queue context.
 *
 * Grep reference: [asset_pipeline_timing]
 */
class AssetsProfileUploadBatchCommand extends Command
{
    protected $signature = 'assets:profile-upload-batch
                            {--existing-session-id= : Upload session UUID; loads linked assets}
                            {--asset-ids= : Comma-separated asset UUIDs}
                            {--brand-id= : Brand UUID; use with --count for recent assets}
                            {--count=5 : With --brand-id, max recent assets}
                            {--since-minutes=1440 : With --brand-id, only assets created within this window}
                            {--log= : Log file path (default: storage/logs/laravel.log or newest laravel-*.log)}
                            {--log-max-mb=50 : Read at most this many megabytes from the end of the log file}
                            {--include-db-profiling= : When assets are loaded from DB, include thumbnail_profiling metadata (true/false, default false)}
                            {--report-json= : Write full report JSON to this path (relative to base_path allowed)}';

    protected $description = 'Report asset_pipeline_timing timelines (queue wait vs thumb runtime), Horizon/queue summary, and recommendations';

    public function handle(AssetPipelineTimingLogReader $reader): int
    {
        $sessionId = $this->option('existing-session-id');
        $assetIdsOpt = $this->option('asset-ids');
        $brandId = $this->option('brand-id');
        $count = max(1, (int) $this->option('count'));
        $sinceMinutes = max(1, (int) $this->option('since-minutes'));
        $logOption = $this->option('log');
        $logPath = $reader->resolveLogPath(is_string($logOption) && $logOption !== '' ? $logOption : null);
        $maxTailBytes = max(1_048_576, (int) $this->option('log-max-mb') * 1024 * 1024);
        $includeDbProfiling = filter_var($this->option('include-db-profiling'), FILTER_VALIDATE_BOOL);

        $assetIds = $this->resolveAssetIds($sessionId, $assetIdsOpt, $brandId, $count, $sinceMinutes);
        if ($assetIds === null) {
            return self::FAILURE;
        }

        if ($assetIds === []) {
            $this->warn('No assets to report.');

            return self::SUCCESS;
        }

        $this->info('Assets: '.count($assetIds));
        if ($logPath === null) {
            $this->warn('No readable laravel log found; timeline columns will be empty. Use --log= or ensure storage/logs/laravel.log exists.');
            $byAsset = [];
            foreach ($assetIds as $aid) {
                $byAsset[$aid] = [
                    'events' => [],
                    'thumbnail_dispatch_queue' => null,
                    'ai_dispatch_queue' => null,
                ];
            }
        } else {
            $this->comment('Log: '.$logPath.' (tail ~'.round($maxTailBytes / 1024 / 1024, 1).' MiB)');
            $byAsset = $reader->collectFromLogTail($logPath, $assetIds, $maxTailBytes);
        }

        $rows = [];
        $queueWaits = [];
        $thumbRuntimes = [];
        $tableRows = [];

        foreach ($assetIds as $aid) {
            $bundle = $byAsset[$aid] ?? ['events' => [], 'thumbnail_dispatch_queue' => null, 'ai_dispatch_queue' => null];
            /** @var array<string, CarbonImmutable> $ev */
            $ev = $bundle['events'];

            $tOriginal = $ev[AssetPipelineTimingLogger::EVENT_ORIGINAL_STORED] ?? null;
            $tDisp = $ev[AssetPipelineTimingLogger::EVENT_THUMBNAIL_DISPATCHED] ?? null;
            $tStart = $ev[AssetPipelineTimingLogger::EVENT_THUMBNAIL_STARTED] ?? null;
            $tDone = $ev[AssetPipelineTimingLogger::EVENT_THUMBNAIL_COMPLETED] ?? null;
            $tPreview = $ev[AssetPipelineTimingLogger::EVENT_PREVIEW_COMPLETED] ?? null;
            $tMeta = $ev[AssetPipelineTimingLogger::EVENT_METADATA_COMPLETED] ?? null;
            $tAi = $ev[AssetPipelineTimingLogger::EVENT_AI_CHAIN_DISPATCHED] ?? null;

            $queueWaitMs = null;
            if ($tDisp !== null && $tStart !== null) {
                // Carbon: $later->diffInMilliseconds($earlier, false) is negative; measure earliness → lateness from dispatch.
                $queueWaitMs = (int) round($tDisp->diffInMilliseconds($tStart, false));
                if ($queueWaitMs >= 0) {
                    $queueWaits[] = $queueWaitMs;
                }
            }

            $thumbRuntimeMs = null;
            if ($tStart !== null && $tDone !== null) {
                $thumbRuntimeMs = (int) round($tStart->diffInMilliseconds($tDone, false));
                if ($thumbRuntimeMs >= 0) {
                    $thumbRuntimes[] = $thumbRuntimeMs;
                }
            }

            $row = [
                'asset_id' => $aid,
                'pipeline_queue_from_log' => $bundle['thumbnail_dispatch_queue'] ?? null,
                'ai_chain_queue_from_log' => $bundle['ai_dispatch_queue'] ?? null,
                'original_stored' => $tOriginal?->toIso8601String(),
                'thumbnail_dispatched' => $tDisp?->toIso8601String(),
                'thumbnail_started' => $tStart?->toIso8601String(),
                'thumbnail_completed' => $tDone?->toIso8601String(),
                'queue_wait_ms' => $queueWaitMs,
                'thumb_runtime_ms' => $thumbRuntimeMs,
                'preview_completed' => $tPreview?->toIso8601String(),
                'metadata_completed' => $tMeta?->toIso8601String(),
                'ai_chain_dispatched' => $tAi?->toIso8601String(),
            ];

            if ($includeDbProfiling) {
                $asset = Asset::query()->find($aid);
                if ($asset) {
                    $asset->loadMissing('currentVersion');
                    $version = $asset->currentVersion;
                    $prof = $version ? ($version->metadata['thumbnail_profiling'] ?? null) : null;
                    if (! is_array($prof)) {
                        $prof = $asset->metadata['thumbnail_profiling'] ?? null;
                    }
                    $row['db_thumbnail_profiling'] = is_array($prof) ? $prof : null;
                }
            }

            $rows[] = $row;

            $iso = static fn (?CarbonImmutable $c): string => $c?->toIso8601String() ?? '—';
            $tableRows[] = [
                $aid,
                $row['pipeline_queue_from_log'] ?? '—',
                $iso($tOriginal),
                $iso($tDisp),
                $iso($tStart),
                $iso($tDone),
                $queueWaitMs !== null ? (string) $queueWaitMs : '—',
                $thumbRuntimeMs !== null ? (string) $thumbRuntimeMs : '—',
                $iso($tPreview),
                $iso($tMeta),
                $iso($tAi),
            ];
        }

        $this->newLine();
        $this->table(
            [
                'asset',
                'pipe_q',
                'original_stored',
                'thumb_dispatched',
                'thumb_started',
                'thumb_completed',
                'q_wait_ms',
                'thumb_run_ms',
                'preview_done',
                'metadata_done',
                'ai_dispatched',
            ],
            $tableRows
        );

        $avgQ = $this->avg($queueWaits);
        $maxQ = $queueWaits === [] ? null : max($queueWaits);
        $avgT = $this->avg($thumbRuntimes);
        $maxT = $thumbRuntimes === [] ? null : max($thumbRuntimes);

        $this->newLine();
        $this->info('Averages (assets with valid deltas only)');
        $this->table(
            ['metric', 'value'],
            [
                ['avg queue_wait_ms', $avgQ !== null ? (string) round($avgQ) : '—'],
                ['max queue_wait_ms', $maxQ !== null ? (string) $maxQ : '—'],
                ['avg thumb_runtime_ms', $avgT !== null ? (string) round($avgT) : '—'],
                ['max thumb_runtime_ms', $maxT !== null ? (string) $maxT : '—'],
            ]
        );

        $horizonBlock = $this->buildHorizonQueueReport();
        $this->newLine();
        $this->info('Horizon / queue (read-only, current config)');
        foreach (explode("\n", $horizonBlock) as $ln) {
            $this->line($ln);
        }

        $recommendations = $this->buildRecommendations($avgQ, $avgT, $maxQ, $maxT);
        $this->newLine();
        $this->info('Recommendations');
        foreach ($recommendations as $r) {
            $this->line('• '.$r);
        }

        $this->newLine();
        $this->info('Phase 5 summary');
        foreach ($this->buildPhase5Summary($avgQ, $avgT, $maxQ, $maxT, $horizonBlock) as $ln) {
            $this->line($ln);
        }

        $reportJson = $this->option('report-json');
        if (is_string($reportJson) && $reportJson !== '') {
            $path = $reportJson;
            if (! str_starts_with($path, '/') && ! preg_match('#^[A-Za-z]:\\\\#', $path)) {
                $path = base_path($path);
            }
            $dir = dirname($path);
            if (! is_dir($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
            $payload = [
                'generated_at' => now()->toIso8601String(),
                'log_path' => $logPath,
                'log_tail_bytes' => $maxTailBytes,
                'assets' => $rows,
                'summary' => [
                    'avg_queue_wait_ms' => $avgQ,
                    'max_queue_wait_ms' => $maxQ,
                    'avg_thumb_runtime_ms' => $avgT,
                    'max_thumb_runtime_ms' => $maxT,
                ],
                'horizon_queue_report' => $horizonBlock,
                'recommendations' => $recommendations,
                'phase_5' => $this->buildPhase5Summary($avgQ, $avgT, $maxQ, $maxT, $horizonBlock),
                'notes' => [
                    'Timestamps use the latest matching log row per event within the scanned tail (re-uploads in the same window can mix).',
                    'GenerateThumbnailsJob uses QueuesOnImagesChannel (default queue name from config) but the pipeline runs on Bus::chain()->onQueue() from PipelineQueueResolver — see horizon_queue_report.',
                    'RAW/CR2 routing: by file size vs assets.processing.heavy_queue_min_bytes and optional PSD queue; not by camera raw extension alone.',
                ],
            ];
            File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info('Wrote '.$path);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
    private function resolveAssetIds(mixed $sessionId, mixed $assetIdsOpt, mixed $brandId, int $count, int $sinceMinutes): ?array
    {
        $ids = [];

        if (is_string($assetIdsOpt) && $assetIdsOpt !== '') {
            foreach (explode(',', $assetIdsOpt) as $part) {
                $p = trim($part);
                if ($p !== '') {
                    $ids[] = $p;
                }
            }
        }

        if (is_string($sessionId) && $sessionId !== '') {
            $session = UploadSession::query()->find($sessionId);
            if (! $session) {
                $this->error("Upload session not found: {$sessionId}");

                return null;
            }
            $fromSession = Asset::query()->where('upload_session_id', $sessionId)->pluck('id')->all();
            $status = $session->status instanceof \BackedEnum ? $session->status->value : (string) $session->status;
            $this->info("Session {$sessionId} status={$status} assets=".count($fromSession));
            $ids = array_values(array_unique(array_merge($ids, array_map('strval', $fromSession))));
        }

        if (is_string($brandId) && $brandId !== '') {
            $since = Carbon::now()->subMinutes($sinceMinutes);
            $fromBrand = Asset::query()
                ->where('brand_id', $brandId)
                ->where('created_at', '>=', $since)
                ->orderByDesc('created_at')
                ->limit($count)
                ->pluck('id')
                ->all();
            $this->info('Recent assets from brand: '.count($fromBrand)." (limit {$count}, since {$sinceMinutes}m)");
            $ids = array_values(array_unique(array_merge($ids, array_map('strval', $fromBrand))));
        }

        if ($ids === []) {
            $this->error('Provide at least one of --asset-ids=, --existing-session-id=, or --brand-id=.');

            return null;
        }

        return $ids;
    }

    /**
     * @param  list<int>  $values
     */
    private function avg(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return array_sum($values) / count($values);
    }

    private function buildHorizonQueueReport(): string
    {
        $appEnv = (string) config('app.env', 'production');
        $images = (string) config('queue.images_queue', 'images');
        $heavy = (string) config('queue.images_heavy_queue', 'images-heavy');
        $psd = trim((string) config('queue.images_psd_queue', ''));
        $heavyMin = (int) config('assets.processing.heavy_queue_min_bytes', 200 * 1024 * 1024);
        $workersEnabled = filter_var(env('QUEUE_WORKERS_ENABLED', true), FILTER_VALIDATE_BOOL);

        $envKey = in_array($appEnv, ['production', 'staging', 'testing', 'local'], true) ? $appEnv : 'production';
        $supervisors = config('horizon.environments.'.$envKey, []);

        $lines = [];
        $lines[] = 'APP_ENV='.$appEnv.'  QUEUE_WORKERS_ENABLED='.($workersEnabled ? 'true' : 'false');
        $lines[] = 'queue.images_queue='.$images.'  queue.images_heavy_queue='.$heavy;
        $lines[] = 'queue.images_psd_queue='.($psd === '' ? '(empty — PSD uses size-based heavy vs images only)' : $psd);
        $lines[] = 'assets.processing.heavy_queue_min_bytes='.$heavyMin.' ('.round($heavyMin / 1024 / 1024).' MiB)';
        $lines[] = 'GenerateThumbnailsJob: first job in ProcessAssetJob Bus::chain()->onQueue(PipelineQueueResolver::forPipeline(...)); constructor still calls QueuesOnImagesChannel → default '.GenerateThumbnailsJob::class.' queue name in config is `'.config('queue.images_queue', 'images').'` (chain queue wins at runtime).';
        $lines[] = 'images vs images-heavy: same job classes; separation is worker pools (memory/timeout).';
        $lines[] = 'Active Horizon supervisors for this environment key ('.$envKey.'): '.(is_array($supervisors) && $supervisors !== [] ? implode(', ', array_keys($supervisors)) : '(none — workers disabled or all process counts 0)');

        if (is_array($supervisors)) {
            foreach ($supervisors as $name => $settings) {
                if (! is_string($name) || ! is_array($settings)) {
                    continue;
                }
                $maxP = $settings['maxProcesses'] ?? '?';
                $lines[] = '  '.$name.': maxProcesses='.$maxP;
                $defaults = config('horizon.defaults.'.$name, []);
                if (is_array($defaults) && isset($defaults['queue'])) {
                    $lines[] = '    queues: '.json_encode($defaults['queue']);
                }
            }
        }

        $lines[] = 'Local vs staging vs prod: process counts come from HORIZON_*_PROCESSES with APP_ENV fallbacks in config/horizon.php (see file header).';

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function buildRecommendations(?float $avgQ, ?float $avgT, ?int $maxQ, ?int $maxT): array
    {
        $out = [];
        if ($avgQ === null && $avgT === null) {
            $out[] = 'Insufficient timeline data in the scanned log tail. Widen --log-max-mb, point --log= at the correct file, or re-run after uploads.';

            return $out;
        }

        $highMs = 1500.0;
        $queueDominates = $avgQ !== null && $avgT !== null && $avgQ > $avgT;
        $thumbDominates = $avgQ !== null && $avgT !== null && $avgT > $avgQ;
        $bothHigh = ($avgQ !== null && $avgQ >= $highMs) && ($avgT !== null && $avgT >= $highMs);

        if ($queueDominates) {
            $out[] = 'Average queue_wait_ms exceeds average thumb_runtime_ms — likely queue backlog or low concurrency. Tune Horizon images / images-heavy process counts, separate heavy traffic, or reduce competing work on the same Redis queues.';
        }
        if ($thumbDominates) {
            $out[] = 'Thumbnail runtime dominates — prioritize derivative pipeline work (smaller source reads, fewer styles up front, PSD/RAW service tuning) before adding more workers.';
        }
        if ($bothHigh) {
            $out[] = 'Both wait and runtime are elevated — consider a quick-grid-thumb-first path (ASSET_QUICK_GRID_THUMBNAILS) plus measured worker tuning once a dedicated fast queue has Horizon coverage.';
        }
        if ($out === [] && $avgQ !== null && $avgT !== null) {
            $out[] = 'Queue wait and thumb runtime are in the same ballpark; inspect max_queue_wait_ms ('.($maxQ ?? 'n/a').') and max_thumb_runtime_ms ('.($maxT ?? 'n/a').') for outliers.';
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function buildPhase5Summary(?float $avgQ, ?float $avgT, ?int $maxQ, ?int $maxT, string $horizonBlock): array
    {
        $lines = [];
        $lines[] = 'Measured: avg queue_wait_ms='.($avgQ !== null ? (string) round($avgQ) : 'n/a').', avg thumb_runtime_ms='.($avgT !== null ? (string) round($avgT) : 'n/a').', max queue_wait_ms='.($maxQ ?? 'n/a').', max thumb_runtime_ms='.($maxT ?? 'n/a').'.';
        if (str_contains($horizonBlock, 'supervisor-images')) {
            $lines[] = 'Horizon: supervisor-images appears in the resolved environment block — confirm maxProcesses and Redis load match observed queue_wait_ms.';
        } else {
            $lines[] = 'Horizon: no supervisor-images in the resolved environment (workers disabled or zero processes); uploads will wait until workers exist.';
        }
        $worth = ($avgQ !== null && $avgT !== null && $avgQ > $avgT) || (($avgQ ?? 0) >= 1500);
        $lines[] = 'Quick-grid-thumbnail-first: '.($worth ? 'likely worthwhile if the grid blocks on the same queue as heavy assets — implement behind ASSET_QUICK_GRID_THUMBNAILS with a dedicated images-fast worker.' : 'optional; largest gains may be elsewhere unless queue_wait stays high.');
        $lines[] = 'Safest next change: read-only profiling in staging with this command; then adjust HORIZON_IMAGES_PROCESSES / heavy split or heavy_min_bytes based on measured queue_wait_ms.';

        return $lines;
    }
}
