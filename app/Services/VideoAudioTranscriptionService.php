<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort Whisper transcription via OpenAI (same API key as chat/vision).
 * Audio is extracted locally with FFmpeg; temp files are caller-managed.
 */
class VideoAudioTranscriptionService
{
    /**
     * Extract audio to MP3 (first maxSeconds only) and call /v1/audio/transcriptions.
     *
     * @return array{text: string, cost_usd: float, audio_seconds: float}
     */
    public function transcribeVideoAudio(string $videoPath, float $maxSeconds = 120.0): array
    {
        $apiKey = config('ai.openai.api_key');
        if (! is_string($apiKey) || $apiKey === '') {
            return ['text' => '', 'cost_usd' => 0.0, 'audio_seconds' => 0.0];
        }

        $ffmpeg = $this->findFFmpegPath();
        if (! $ffmpeg) {
            return ['text' => '', 'cost_usd' => 0.0, 'audio_seconds' => 0.0];
        }

        $audioTmp = tempnam(sys_get_temp_dir(), 'vai_audio_').'.mp3';
        $maxSeconds = max(1.0, min(600.0, $maxSeconds));

        $cmd = sprintf(
            '%s -hide_banner -loglevel error -y -i %s -t %.3f -vn -acodec libmp3lame -q:a 6 %s 2>&1',
            escapeshellarg($ffmpeg),
            escapeshellarg($videoPath),
            $maxSeconds,
            escapeshellarg($audioTmp)
        );
        exec($cmd, $out, $code);

        if ($code !== 0 || ! is_file($audioTmp) || filesize($audioTmp) < 64) {
            @unlink($audioTmp);
            Log::info('[VideoAudioTranscriptionService] No extractable audio or ffmpeg failed', [
                'return' => $code,
            ]);

            return ['text' => '', 'cost_usd' => 0.0, 'audio_seconds' => 0.0];
        }

        try {
            $model = (string) config('ai.video_insights.whisper_model', 'whisper-1');
            $baseUrl = rtrim((string) config('ai.openai.base_url', 'https://api.openai.com/v1'), '/');

            $response = Http::timeout(180)
                ->withToken($apiKey)
                ->attach('file', (string) file_get_contents($audioTmp), 'audio.mp3')
                ->post($baseUrl.'/audio/transcriptions', [
                    'model' => $model,
                    'response_format' => 'json',
                ]);

            if ($response->failed()) {
                Log::warning('[VideoAudioTranscriptionService] Transcription HTTP failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return ['text' => '', 'cost_usd' => 0.0, 'audio_seconds' => $maxSeconds];
            }

            $json = $response->json();
            $text = is_array($json) && isset($json['text']) && is_string($json['text'])
                ? trim($json['text'])
                : '';

            $rate = (float) config('ai.video_insights.whisper_cost_per_second_usd', 0.0001);
            $cost = $maxSeconds * $rate;

            return [
                'text' => $text,
                'cost_usd' => $cost,
                'audio_seconds' => $maxSeconds,
            ];
        } finally {
            @unlink($audioTmp);
        }
    }

    protected function findFFmpegPath(): ?string
    {
        $candidates = ['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'];
        foreach ($candidates as $path) {
            if ($path === 'ffmpeg') {
                $out = [];
                $code = 0;
                exec('which ffmpeg 2>/dev/null', $out, $code);
                if ($code === 0 && isset($out[0]) && is_executable($out[0])) {
                    return $out[0];
                }
            } elseif (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
