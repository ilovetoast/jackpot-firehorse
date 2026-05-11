<?php

namespace App\Services\Audio\Providers;

use App\Models\Asset;
use App\Services\TenantBucketService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Phase 4: OpenAI Whisper transcription provider for audio assets.
 *
 * Behavior:
 *   - Downloads the asset's original bytes from S3 via TenantBucketService
 *     (Whisper API expects a multipart upload — no presigned URL ingress).
 *   - Posts to the `audio/transcriptions` endpoint with
 *     `response_format=verbose_json` so we get word-/segment-level
 *     timestamps, which we persist as `transcript_chunks` for the
 *     audio search index.
 *   - Honors a per-asset cost budget (cents). Whisper bills $0.006/min,
 *     so we estimate cost from the asset's known duration before calling
 *     and short-circuit to budget_exceeded when the call would blow the cap.
 *
 * Mood / tone: Whisper itself doesn't return mood. We derive a coarse
 * mood label from the transcript using a tiny keyword classifier (kept
 * in this class to avoid pulling another model). Future: swap to a real
 * LLM call when budget allows.
 */
class WhisperAudioAiProvider implements AudioAiProviderInterface
{
    public function __construct(
        protected HttpFactory $http,
        protected TenantBucketService $bucketService,
    ) {
    }

    public function analyze(Asset $asset): array
    {
        $cfg = (array) config('assets.audio_ai.whisper', []);
        $apiKey = (string) ($cfg['api_key'] ?? '');
        if ($apiKey === '') {
            return ['success' => false, 'reason' => 'no_api_key'];
        }

        $durationSeconds = (float) ($asset->metadata['audio']['duration_seconds'] ?? 0);
        $maxDuration = (int) ($cfg['max_duration_seconds'] ?? 7200);
        if ($durationSeconds > $maxDuration) {
            Log::info('[WhisperAudioAiProvider] skipping — duration exceeds max', [
                'asset_id' => $asset->id,
                'duration_seconds' => $durationSeconds,
                'max_duration' => $maxDuration,
            ]);

            return ['success' => false, 'reason' => 'duration_exceeded'];
        }

        $budgetCents = (int) ($cfg['budget_cents_per_asset'] ?? 200);
        $estimatedCents = (int) ceil(($durationSeconds / 60) * 0.6); // 0.6 cents per minute, rounded up
        if ($estimatedCents > $budgetCents) {
            Log::warning('[WhisperAudioAiProvider] estimated cost exceeds budget', [
                'asset_id' => $asset->id,
                'estimated_cents' => $estimatedCents,
                'budget_cents' => $budgetCents,
            ]);

            return ['success' => false, 'reason' => 'budget_exceeded'];
        }

        $local = $this->downloadOriginal($asset);
        if ($local === null) {
            return ['success' => false, 'reason' => 'download_failed'];
        }

        try {
            $response = $this->http
                ->withToken($apiKey)
                ->timeout(180)
                ->attach(
                    'file',
                    fopen($local, 'rb'),
                    basename($asset->original_filename ?? 'audio.mp3'),
                )
                ->asMultipart()
                ->post((string) $cfg['endpoint'], [
                    ['name' => 'model', 'contents' => (string) ($cfg['model'] ?? 'whisper-1')],
                    ['name' => 'response_format', 'contents' => 'verbose_json'],
                    ['name' => 'temperature', 'contents' => '0'],
                ]);

            if (! $response->successful()) {
                Log::error('[WhisperAudioAiProvider] non-2xx response', [
                    'asset_id' => $asset->id,
                    'status' => $response->status(),
                    'body' => substr((string) $response->body(), 0, 500),
                ]);

                return ['success' => false, 'reason' => 'api_error', 'error' => $response->status()];
            }

            $payload = $response->json();
            $transcript = trim((string) ($payload['text'] ?? ''));
            $chunks = $this->normalizeChunks($payload['segments'] ?? []);
            $detectedLanguage = (string) ($payload['language'] ?? '') ?: null;
            $mood = $this->deriveMood($transcript);
            $summary = $this->deriveSummary($transcript);

            return [
                'success' => true,
                'transcript' => $transcript !== '' ? $transcript : null,
                'transcript_chunks' => $chunks,
                'summary' => $summary,
                'mood' => $mood,
                'detected_language' => $detectedLanguage,
                'provider' => 'whisper',
                'cost_cents' => $estimatedCents,
                'analyzed_at' => now()->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            Log::error('[WhisperAudioAiProvider] exception', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
        } finally {
            if ($local && file_exists($local)) {
                @unlink($local);
            }
        }
    }

    protected function downloadOriginal(Asset $asset): ?string
    {
        $bucket = $asset->storageBucket;
        $key = (string) ($asset->storage_root_path ?? '');
        if (! $bucket || $key === '') {
            return null;
        }
        try {
            $bytes = $this->bucketService->getObjectContents($bucket, $key);
            if ($bytes === '' || $bytes === null) {
                return null;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'whisper_').'.'.pathinfo($key, PATHINFO_EXTENSION);
            if (@file_put_contents($tmp, $bytes) === false) {
                return null;
            }

            return $tmp;
        } catch (\Throwable $e) {
            Log::warning('[WhisperAudioAiProvider] download_failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $segments
     * @return array<int, array{start: float, end: float, text: string}>
     */
    protected function normalizeChunks(array $segments): array
    {
        $out = [];
        foreach ($segments as $seg) {
            if (! is_array($seg)) {
                continue;
            }
            $text = trim((string) ($seg['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $out[] = [
                'start' => (float) ($seg['start'] ?? 0),
                'end' => (float) ($seg['end'] ?? 0),
                'text' => $text,
            ];
        }

        return $out;
    }

    /**
     * Tiny keyword-driven mood classifier — intentionally simple. The point
     * is to ship *something* useful when the provider doesn't return mood
     * directly; a real classifier can replace this method later.
     *
     * @return array<int, string>
     */
    protected function deriveMood(string $transcript): array
    {
        if ($transcript === '') {
            return [];
        }
        $lc = mb_strtolower($transcript);
        $hits = [];
        $patterns = [
            'energetic' => ['amazing', 'incredible', 'lets go', "let's go", 'awesome', 'unbelievable'],
            'calm' => ['gentle', 'soft', 'peaceful', 'relax', 'calm', 'quiet'],
            'serious' => ['important', 'critical', 'urgent', 'note that', 'must'],
            'humorous' => ['lol', 'haha', 'funny', 'joke', 'silly'],
            'instructional' => ['first', 'next', 'step', 'tutorial', 'how to'],
        ];
        foreach ($patterns as $label => $kws) {
            foreach ($kws as $kw) {
                if (str_contains($lc, $kw)) {
                    $hits[] = $label;
                    break;
                }
            }
        }

        return array_values(array_unique($hits));
    }

    /**
     * Derive a 1-2 sentence summary by clipping the transcript on a
     * sentence boundary. Cheap, deterministic, no extra API call.
     */
    protected function deriveSummary(string $transcript): ?string
    {
        if ($transcript === '') {
            return null;
        }
        $clean = preg_replace('/\s+/u', ' ', $transcript);
        if (mb_strlen($clean) <= 220) {
            return $clean;
        }
        $clipped = mb_substr($clean, 0, 220);
        $lastPeriod = max(
            mb_strrpos($clipped, '. '),
            mb_strrpos($clipped, '! '),
            mb_strrpos($clipped, '? '),
        );
        if ($lastPeriod !== false && $lastPeriod > 80) {
            return rtrim(mb_substr($clipped, 0, $lastPeriod + 1));
        }

        return rtrim($clipped).'…';
    }
}
