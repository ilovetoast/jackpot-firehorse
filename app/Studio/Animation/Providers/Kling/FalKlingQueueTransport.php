<?php

namespace App\Studio\Animation\Providers\Kling;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * fal.ai queue REST for Kling image-to-video models.
 *
 * @internal
 */
final class FalKlingQueueTransport
{
    public function submit(string $modelPath, array $input, string $apiKey, string $baseUrl): array
    {
        $url = rtrim($baseUrl, '/').'/'.ltrim($modelPath, '/');

        $response = Http::withHeaders([
            'Authorization' => 'Key '.$apiKey,
            'Content-Type' => 'application/json',
        ])
            ->timeout(120)
            ->post($url, ['input' => $input]);

        if (! $response->successful()) {
            Log::warning('[FalKlingQueueTransport] submit failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'ok' => false,
                'error' => 'submit_http_'.$response->status(),
                'message' => $response->body(),
            ];
        }

        $json = $response->json();

        return [
            'ok' => true,
            'request_id' => $json['request_id'] ?? null,
            'status_url' => $json['status_url'] ?? null,
            'response_url' => $json['response_url'] ?? null,
            'cancel_url' => $json['cancel_url'] ?? null,
            'raw' => $json,
        ];
    }

    public function fetchStatus(string $statusUrl, string $apiKey): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Key '.$apiKey,
        ])
            ->timeout(60)
            ->get($statusUrl);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => 'status_http_'.$response->status(),
                'message' => $response->body(),
            ];
        }

        return [
            'ok' => true,
            'json' => $response->json(),
        ];
    }

    public function fetchResult(string $responseUrl, string $apiKey): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Key '.$apiKey,
        ])
            ->timeout(120)
            ->get($responseUrl);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => 'result_http_'.$response->status(),
                'message' => $response->body(),
            ];
        }

        return [
            'ok' => true,
            'json' => $response->json(),
        ];
    }

    public static function buildStartImageDataUri(string $absolutePath, string $mimeType): string
    {
        $binary = @file_get_contents($absolutePath);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('Could not read start frame image.');
        }

        return 'data:'.$mimeType.';base64,'.base64_encode($binary);
    }

    public static function materializeToTempFile(string $disk, string $path): string
    {
        if ($disk === 'local' || $disk === 'public') {
            /** @phpstan-ignore-next-line */
            return Storage::disk($disk)->path($path);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'sa_kling_');
        if ($tmp === false) {
            throw new \RuntimeException('Temp file failed.');
        }
        $bytes = Storage::disk($disk)->get($path);
        file_put_contents($tmp, $bytes);

        return $tmp;
    }
}
