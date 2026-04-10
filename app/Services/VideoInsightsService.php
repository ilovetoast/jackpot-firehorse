<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\Category;
use App\Services\AI\Contracts\AIProviderInterface;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates FFmpeg frame sampling, optional Whisper transcription, and one vision call
 * (composited frames) via {@see AIProviderInterface::analyzeImage} — same provider stack as image AI.
 */
class VideoInsightsService
{
    public function __construct(
        protected VideoPreviewGenerationService $videoDownload,
        protected VideoFrameExtractor $frameExtractor,
        protected VideoInsightCollageBuilder $collageBuilder,
        protected VideoAudioTranscriptionService $audioTranscription,
        protected AIProviderInterface $provider,
    ) {}

    /**
     * @return array{
     *   tags: list<string>,
     *   summary: string,
     *   suggested_category: string,
     *   metadata: array{scene: string, activity: string, setting: string},
     *   moments: list<array{timestamp: string, seconds: float, label: string, frame_index: int}>,
     *   transcript: string,
     *   tokens_in: int,
     *   tokens_out: int,
     *   cost_usd: float,
     *   model: string,
     *   frame_count: int,
     *   effective_duration_sampled: float,
     *   whisper_cost_usd: float,
     *   vision_cost_usd: float,
     *   vision_prompt: string,
     *   raw_llm_response: string
     * }
     *
     * @param  (callable(string): void)|null  $onStep  Optional progress hook for admin / job observability.
     */
    public function analyze(Asset $asset, ?callable $onStep = null): array
    {
        $onStep?->__invoke('downloading_source');
        $videoTmp = $this->videoDownload->downloadSourceToTemp($asset);

        $framePaths = [];
        $transcript = '';
        $whisperCost = 0.0;
        $effectiveDurationSampled = 0.0;

        try {
            $onStep?->__invoke('extracting_frames');
            $extracted = $this->frameExtractor->extractFrames($videoTmp);
            $framePaths = $extracted['frame_paths'];
            $hasAudio = $extracted['video_has_audio'];
            $effectiveDurationSampled = (float) ($extracted['effective_duration_sampled'] ?? 0);

            if ($framePaths === []) {
                throw new \RuntimeException('No video frames could be extracted for AI analysis.');
            }

            if (config('assets.video.store_frames', false)) {
                $this->maybePersistFramesToS3($asset, $framePaths);
            }

            $maxDur = (float) config('assets.video_ai.max_duration_seconds', 120);
            if (
                $hasAudio
                && (bool) config('assets.video_ai.transcription_enabled', true)
            ) {
                $onStep?->__invoke('transcribing');
                $t = $this->audioTranscription->transcribeVideoAudio($videoTmp, $maxDur);
                $transcript = $t['text'];
                $whisperCost = $t['cost_usd'];
            } else {
                $onStep?->__invoke('transcribe_skipped');
            }

            $onStep?->__invoke('building_collage');
            $collageUrl = $this->collageBuilder->buildDataUrl($framePaths);

            $intervalSeconds = max(1, (int) config('assets.video_ai.frame_interval_seconds', 3));
            $timelineLines = [];
            foreach (array_keys($framePaths) as $i) {
                $t = $i * $intervalSeconds;
                $timelineLines[] = 'Frame '.($i + 1).': ~'.$this->formatTimestampMmSs((float) $t);
            }
            $timelineBlock = "FRAME_TIMELINE (composite grid order: left-to-right, top-to-bottom):\n"
                .implode("\n", $timelineLines)."\n\n";

            $basePrompt = (string) config('ai.video_insights.prompt', '');
            $ctx = $this->buildAssetContextJson($asset);
            $transcriptBlock = $transcript !== ''
                ? "OPTIONAL TRANSCRIPT (spoken content):\n".$transcript."\n\n"
                : "OPTIONAL TRANSCRIPT: (none — silent or not transcribed)\n\n";

            $prompt = $basePrompt."\n\n".$timelineBlock.$this->buildPromptContext($asset).'asset_context (JSON): '.$ctx."\n\n".$transcriptBlock;

            $model = (string) config('ai.video_insights.model', 'gpt-4o-mini');
            $maxTokens = (int) config('ai.video_insights.max_tokens', 1200);

            $onStep?->__invoke('calling_vision_api');
            $response = $this->provider->analyzeImage($collageUrl, $prompt, [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'response_format' => ['type' => 'json_object'],
            ]);

            $onStep?->__invoke('parsing_response');
            $parsed = $this->parseInsightsJson(
                $response['text'] ?? '',
                count($framePaths),
                $intervalSeconds
            );

            $visionCost = $this->provider->calculateCost(
                (int) ($response['tokens_in'] ?? 0),
                (int) ($response['tokens_out'] ?? 0),
                (string) ($response['model'] ?? $model)
            );

            return [
                'tags' => $parsed['tags'],
                'summary' => $parsed['summary'],
                'suggested_category' => $parsed['suggested_category'],
                'metadata' => $parsed['metadata'],
                'moments' => $parsed['moments'],
                'transcript' => $transcript,
                'tokens_in' => (int) ($response['tokens_in'] ?? 0),
                'tokens_out' => (int) ($response['tokens_out'] ?? 0),
                'cost_usd' => $visionCost + $whisperCost,
                'model' => (string) ($response['model'] ?? $model),
                'frame_count' => count($framePaths),
                'effective_duration_sampled' => $effectiveDurationSampled,
                'whisper_cost_usd' => $whisperCost,
                'vision_cost_usd' => $visionCost,
                'vision_prompt' => $prompt,
                'raw_llm_response' => (string) ($response['text'] ?? ''),
            ];
        } finally {
            @unlink($videoTmp);
            foreach ($framePaths as $p) {
                if (is_string($p) && is_file($p)) {
                    @unlink($p);
                }
            }
        }
    }

    /**
     * @param  list<string>  $framePaths
     */
    protected function maybePersistFramesToS3(Asset $asset, array $framePaths): void
    {
        $bucket = $asset->storageBucket;
        if (! $bucket) {
            return;
        }

        $client = $this->makeS3Client();
        $prefix = app()->environment().'/'.$asset->tenant_id.'/system/video-frames/'.$asset->id.'/';

        foreach ($framePaths as $i => $local) {
            if (! is_file($local)) {
                continue;
            }
            $key = $prefix.sprintf('frame_%02d.jpg', $i + 1);
            try {
                $client->putObject([
                    'Bucket' => $bucket->name,
                    'Key' => $key,
                    'Body' => file_get_contents($local),
                    'ContentType' => 'image/jpeg',
                    'Metadata' => [
                        'system-generated' => 'true',
                        'asset-id' => $asset->id,
                        'billing-excluded' => 'true',
                    ],
                ]);
            } catch (S3Exception $e) {
                Log::warning('[VideoInsightsService] Frame upload skipped', [
                    'asset_id' => $asset->id,
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function makeS3Client(): S3Client
    {
        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];
        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        return new S3Client($config);
    }

    protected function buildAssetContextJson(Asset $asset): string
    {
        $meta = $asset->metadata ?? [];
        $categoryId = $meta['category_id'] ?? null;
        $categoryName = null;
        if ($categoryId) {
            $cat = Category::find($categoryId);
            $categoryName = $cat?->name;
        }

        $payload = [
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'category_id' => $categoryId,
            'category' => $categoryName,
            'original_filename' => $asset->original_filename,
        ];

        return json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    protected function buildPromptContext(Asset $asset): string
    {
        $lines = [];
        $meta = $asset->metadata ?? [];
        $categoryId = $meta['category_id'] ?? null;
        if ($categoryId) {
            $category = Category::find($categoryId);
            if ($category && $category->name) {
                $lines[] = 'Library category: '.$category->name.'.';
            }
        }
        if ($asset->brand_id) {
            $brand = Brand::find($asset->brand_id);
            if ($brand && $brand->name) {
                $lines[] = 'Brand: '.$brand->name.'.';
            }
        }

        if ($lines === []) {
            return '';
        }

        return "CONTEXT:\n".implode("\n", $lines)."\n\n";
    }

    protected function formatTimestampMmSs(float $seconds): string
    {
        $s = max(0, (int) floor($seconds));
        $m = intdiv($s, 60);
        $r = $s % 60;

        return sprintf('%02d:%02d', $m, $r);
    }

    /**
     * @return array{
     *   tags: list<string>,
     *   summary: string,
     *   suggested_category: string,
     *   metadata: array{scene: string, activity: string, setting: string},
     *   moments: list<array{timestamp: string, seconds: float, label: string, frame_index: int}>
     * }
     */
    protected function parseInsightsJson(string $text, int $frameCount, int $intervalSeconds): array
    {
        $default = [
            'tags' => [],
            'summary' => '',
            'suggested_category' => '',
            'metadata' => ['scene' => '', 'activity' => '', 'setting' => ''],
            'moments' => [],
        ];

        $text = trim($text);
        if ($text === '') {
            return $default;
        }

        $data = json_decode($text, true);
        if (! is_array($data)) {
            Log::warning('[VideoInsightsService] Model returned non-JSON', ['snippet' => mb_substr($text, 0, 200)]);

            return $default;
        }

        $tags = [];
        if (isset($data['tags']) && is_array($data['tags'])) {
            foreach ($data['tags'] as $t) {
                if (is_string($t) && trim($t) !== '') {
                    $tags[] = strtolower(trim($t));
                }
            }
        }

        $summary = is_string($data['summary'] ?? null) ? trim($data['summary']) : '';
        $suggested = is_string($data['suggested_category'] ?? null) ? trim($data['suggested_category']) : '';

        $meta = $data['metadata'] ?? [];
        $scene = is_array($meta) && is_string($meta['scene'] ?? null) ? trim($meta['scene']) : '';
        $activity = is_array($meta) && is_string($meta['activity'] ?? null) ? trim($meta['activity']) : '';
        $setting = is_array($meta) && is_string($meta['setting'] ?? null) ? trim($meta['setting']) : '';

        $moments = [];
        if (isset($data['moments']) && is_array($data['moments'])) {
            foreach ($data['moments'] as $m) {
                if (! is_array($m) || count($moments) >= 8) {
                    break;
                }
                $fi = (int) ($m['frame_index'] ?? 0);
                $label = is_string($m['label'] ?? null) ? trim($m['label']) : '';
                if ($fi < 1 || $label === '' || $frameCount < 1) {
                    continue;
                }
                if ($fi > $frameCount) {
                    continue;
                }
                $sec = (float) (($fi - 1) * max(1, $intervalSeconds));
                $moments[] = [
                    'timestamp' => $this->formatTimestampMmSs($sec),
                    'seconds' => $sec,
                    'label' => $label,
                    'frame_index' => $fi,
                ];
            }
        }

        return [
            'tags' => array_values(array_unique($tags)),
            'summary' => $summary,
            'suggested_category' => $suggested,
            'metadata' => [
                'scene' => $scene,
                'activity' => $activity,
                'setting' => $setting,
            ],
            'moments' => $moments,
        ];
    }

    /**
     * Admin troubleshooting: re-sample frames the same way as analyze() without calling the vision API.
     *
     * @return array{
     *   frames: list<array{index: int, seconds: float, label: string, data_url: string}>,
     *   frame_interval_seconds: int,
     *   frame_count: int
     * }
     */
    public function previewSampledFramesForAdmin(Asset $asset): array
    {
        $videoTmp = $this->videoDownload->downloadSourceToTemp($asset);
        $framePaths = [];

        try {
            $extracted = $this->frameExtractor->extractFrames($videoTmp);
            $framePaths = $extracted['frame_paths'];
            $intervalSeconds = max(1, (int) config('assets.video_ai.frame_interval_seconds', 3));
            $frames = [];

            foreach ($framePaths as $i => $path) {
                if (! is_string($path) || ! is_file($path)) {
                    continue;
                }
                $raw = file_get_contents($path);
                if ($raw === false || $raw === '') {
                    continue;
                }
                $sec = (float) ($i * $intervalSeconds);
                $frames[] = [
                    'index' => $i,
                    'seconds' => $sec,
                    'label' => 'Frame '.($i + 1).' (~'.$this->formatTimestampMmSs($sec).')',
                    'data_url' => 'data:image/jpeg;base64,'.base64_encode($raw),
                ];
            }

            return [
                'frames' => $frames,
                'frame_interval_seconds' => $intervalSeconds,
                'frame_count' => count($frames),
            ];
        } finally {
            @unlink($videoTmp);
            foreach ($framePaths as $p) {
                if (is_string($p) && is_file($p)) {
                    @unlink($p);
                }
            }
        }
    }
}
