<?php

namespace App\Studio\Animation\Providers\Kling;

use Illuminate\Support\Facades\Http;

/**
 * HTTP client for Kling’s official image-to-video API (Singapore region by default).
 *
 * @internal
 */
final class KlingNativeClient
{
    private ?string $cachedToken = null;

    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $baseUrl,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     * @return array{ok: bool, error?: string, message?: string, task_id?: string, raw?: array}
     */
    public function postImage2Video(array $body): array
    {
        $url = rtrim($this->baseUrl, '/').'/v1/videos/image2video';
        $response = Http::withHeaders($this->authHeaders())
            ->timeout(120)
            ->post($url, $body);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => 'native_http_'.$response->status(),
                'message' => $response->body(),
            ];
        }

        $json = $response->json();
        if (! is_array($json)) {
            return ['ok' => false, 'error' => 'native_invalid_json', 'message' => 'Response was not JSON.'];
        }

        $code = (int) ($json['code'] ?? -1);
        if ($code !== 0) {
            return [
                'ok' => false,
                'error' => 'native_api_'.$code,
                'message' => (string) ($json['message'] ?? 'Kling API error'),
                'raw' => $json,
            ];
        }

        $data = $json['data'] ?? null;
        $taskId = is_array($data) ? (string) ($data['task_id'] ?? '') : '';

        if ($taskId === '') {
            return [
                'ok' => false,
                'error' => 'native_missing_task_id',
                'message' => 'Kling API returned no task_id.',
                'raw' => $json,
            ];
        }

        return ['ok' => true, 'task_id' => $taskId, 'raw' => $json];
    }

    /**
     * @return array{ok: bool, json?: array, error?: string, message?: string}
     */
    public function getImage2VideoTask(string $taskId): array
    {
        $url = rtrim($this->baseUrl, '/').'/v1/videos/image2video/'.rawurlencode($taskId);
        $response = Http::withHeaders($this->authHeaders())
            ->timeout(60)
            ->get($url);

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => 'native_status_http_'.$response->status(),
                'message' => $response->body(),
            ];
        }

        $json = $response->json();

        return [
            'ok' => true,
            'json' => is_array($json) ? $json : [],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $now = time();
        if ($this->cachedToken === null || $this->tokenExpiresAt <= $now + 300) {
            $this->cachedToken = KlingNativeJwt::sign($this->accessKey, $this->secretKey);
            $this->tokenExpiresAt = $now + 1800;
        }

        return [
            'Authorization' => 'Bearer '.$this->cachedToken,
            'Content-Type' => 'application/json',
        ];
    }
}
