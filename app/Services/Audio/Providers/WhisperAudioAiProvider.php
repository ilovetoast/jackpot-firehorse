<?php

namespace App\Services\Audio\Providers;

use App\Models\Asset;
use App\Services\Audio\AudioAiPreparationService;
use App\Services\TenantBucketService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Phase 4: OpenAI Whisper transcription provider for audio assets.
 *
 * Behavior:
 *   - Resolves the right local file via {@see AudioAiPreparationService}:
 *     prefer the persisted 128 kbps MP3 web derivative if it fits Whisper's
 *     25 MB cap, else use the original (when it's an accepted codec and small
 *     enough), else transcode to a 32 kbps mono MP3 specifically for AI ingest.
 *     Whisper API expects a multipart upload — no presigned URL ingress.
 *   - Posts to the `audio/transcriptions` endpoint with
 *     `response_format=verbose_json` so we get word-/segment-level
 *     timestamps, which we persist as `transcript_chunks` for the
 *     audio search index.
 *   - Honors a per-asset cost budget (cents). Whisper bills $0.006/min,
 *     so we estimate cost from the asset's known duration before calling
 *     and short-circuit to budget_exceeded when the call would blow the cap.
 *
 * Mood / tone: Whisper itself doesn't return mood. For real speech we
 * derive a coarse mood label from the transcript using a tiny keyword
 * classifier. Instrumental or hallucinated non-speech lines are folded
 * into `content_kind=instrumental` with mood/style tags instead.
 */
class WhisperAudioAiProvider implements AudioAiProviderInterface
{
    public function __construct(
        protected HttpFactory $http,
        protected TenantBucketService $bucketService,
        protected ?AudioAiPreparationService $preparation = null,
    ) {
        $this->preparation ??= app(AudioAiPreparationService::class);
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

        $prep = $this->preparation->prepareLocalFile($asset);
        if (! ($prep['success'] ?? false)) {
            Log::warning('[WhisperAudioAiProvider] prep failed', [
                'asset_id' => $asset->id,
                'reason' => $prep['reason'] ?? 'unknown',
                'error' => $prep['error'] ?? null,
            ]);

            // Surface oversized-after-transcode separately so the caller (and
            // operators) can distinguish a hard-skip ("file too long even at
            // 32 kbps mono") from generic download/ffmpeg failures.
            return [
                'success' => false,
                'reason' => $prep['reason'] ?? 'prep_failed',
                'error' => $prep['error'] ?? null,
            ];
        }

        $local = (string) $prep['path'];
        $isTemp = (bool) ($prep['temporary'] ?? true);
        $usedKind = (string) ($prep['decision'] ?? 'original');

        try {
            // Whisper sniffs by extension when no Content-Type hint is given;
            // pick a filename that matches the file we actually upload so we
            // don't accidentally tell the API "audio.mp3" when we sent FLAC.
            $filename = $this->whisperFilename($asset, $local, $usedKind);

            $response = $this->http
                ->withToken($apiKey)
                ->timeout(180)
                ->attach(
                    'file',
                    fopen($local, 'rb'),
                    $filename,
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
                    'used_kind' => $usedKind,
                ]);

                return ['success' => false, 'reason' => 'api_error', 'error' => $response->status()];
            }

            $payload = $response->json();
            $rawTranscript = trim((string) ($payload['text'] ?? ''));
            $chunks = $this->normalizeChunks($payload['segments'] ?? []);
            $detectedLanguage = (string) ($payload['language'] ?? '') ?: null;
            $insights = $this->buildVerbalInsights($rawTranscript, $chunks);

            return [
                'success' => true,
                'transcript' => $insights['transcript'],
                'transcript_chunks' => $insights['transcript_chunks'],
                'summary' => $insights['summary'],
                'mood' => $insights['mood'],
                'content_kind' => $insights['content_kind'],
                'detected_language' => $insights['content_kind'] === 'speech' ? $detectedLanguage : null,
                'provider' => 'whisper',
                'cost_cents' => $estimatedCents,
                'analyzed_at' => now()->toIso8601String(),
                'source_kind' => $usedKind,
                'source_size_bytes' => (int) ($prep['size_bytes'] ?? 0),
            ];
        } catch (\Throwable $e) {
            Log::error('[WhisperAudioAiProvider] exception', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
        } finally {
            if ($isTemp && $local !== '' && file_exists($local)) {
                @unlink($local);
            }
        }
    }

    /**
     * Pick a filename that matches the bytes we're actually uploading. The
     * preparation service produces `.mp3` for transcoded files and for the
     * web derivative; passthrough originals keep their real extension.
     */
    protected function whisperFilename(Asset $asset, string $localPath, string $kind): string
    {
        $ext = strtolower(pathinfo($localPath, PATHINFO_EXTENSION) ?: 'mp3');
        $base = pathinfo((string) ($asset->original_filename ?? 'audio'), PATHINFO_FILENAME) ?: 'audio';

        return $base.'.'.$ext;
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
     * Turn raw Whisper text into stored transcript / summary / mood. Music
     * and other non-verbal clips often yield an empty string or a short
     * hallucinated outro ("thanks for watching") — treat those as
     * instrumental so the UI can show mood/style instead of a fake transcript.
     *
     * @param  array<int, array{start: float, end: float, text: string}>  $chunks
     * @return array{
     *     content_kind: 'speech'|'instrumental',
     *     transcript: ?string,
     *     transcript_chunks: array<int, array{start: float, end: float, text: string}>,
     *     summary: ?string,
     *     mood: array<int, string>
     * }
     */
    protected function buildVerbalInsights(string $rawTranscript, array $chunks): array
    {
        if ($rawTranscript === '' || $this->isLikelyNonVerbalOrHallucination($rawTranscript)) {
            return [
                'content_kind' => 'instrumental',
                'transcript' => null,
                'transcript_chunks' => [],
                'summary' => 'No speech detected. This sounds like instrumental or non-verbal audio (music, ambience, or effects).',
                'mood' => ['instrumental', 'non-verbal'],
            ];
        }

        return [
            'content_kind' => 'speech',
            'transcript' => $rawTranscript,
            'transcript_chunks' => $chunks,
            'summary' => $this->deriveSummary($rawTranscript),
            'mood' => $this->deriveMood($rawTranscript),
        ];
    }

    /**
     * Whisper often emits stock YouTube outros or bracket tags on music-only
     * sources. When the transcript is short and dominated by those patterns,
     * we classify as non-verbal rather than surfacing misleading "speech".
     */
    protected function isLikelyNonVerbalOrHallucination(string $transcript): bool
    {
        $norm = mb_strtolower(preg_replace('/[\[\]()♪♫]+/u', ' ', $transcript));
        $norm = trim(preg_replace('/\s+/u', ' ', $norm));
        if ($norm === '') {
            return true;
        }

        $compact = preg_replace('/[^\p{L}\p{N}\s]/u', '', $norm);
        $compact = trim(preg_replace('/\s+/u', ' ', (string) $compact));

        $exactJunk = [
            'thanks for watching',
            'thanks for watching all',
            'thank you for watching',
            'thank you so much for watching',
            'please subscribe',
            'like and subscribe',
            'subscribe for more',
            'see you next time',
            'bye bye',
            'music',
            'instrumental',
            'music playing',
            'background music',
        ];
        foreach ($exactJunk as $phrase) {
            if ($compact === $phrase) {
                return true;
            }
        }

        if (mb_strlen($compact) <= 48) {
            foreach ($exactJunk as $phrase) {
                if (str_contains($compact, $phrase) && mb_strlen($compact) - mb_strlen($phrase) <= 12) {
                    return true;
                }
            }
        }

        if (mb_strlen($compact) <= 36) {
            foreach (['thanks for watching', 'thank you for watching'] as $needle) {
                if (str_contains($compact, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Tiny keyword-driven mood classifier — intentionally simple.
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
