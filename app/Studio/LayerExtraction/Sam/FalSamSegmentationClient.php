<?php

namespace App\Studio\LayerExtraction\Sam;

use App\Studio\LayerExtraction\Contracts\SamSegmentationClientInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Driver for {@see https://fal.ai/models/fal-ai/sam2/image} — request shape is data-driven via config, not hardcoded in callers.
 */
final class FalSamSegmentationClient implements SamSegmentationClientInterface
{
    public function __construct(
        private readonly string $apiKey,
    ) {
        if ($this->apiKey === '') {
            throw new InvalidArgumentException('Fal API key is required.');
        }
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    public function autoSegment(string $imageBinary, array $options = []): SamSegmentationResult
    {
        $t0 = microtime(true);
        $mime = (string) ($options['image_mime'] ?? 'image/png');
        $uri = SamLayerExtractionImage::dataUriFromBinary($imageBinary, $mime);
        $input = $this->baseInput($uri);
        $input['apply_mask'] = true;
        $data = $this->requestFal($input, $options);
        $maskPng = $this->resultImagePng($data, $options);
        $segs = SamMaskComponentSplitter::splitFromRgbaOrGrayscaleMask(
            $maskPng,
            (int) ($options['min_component_area_px'] ?? 80)
        );
        if ($segs === []) {
            $bbox = $this->bboxFromMaskPng($maskPng) ?? ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2];
            $segs = [new SamMaskSegment($maskPng, $bbox, null, 'Object 1')];
        }
        $duration = (int) round((microtime(true) - $t0) * 1000);
        $model = (string) (config('studio_layer_extraction.sam.model', 'fal_sam2'));

        return new SamSegmentationResult($segs, $model, $duration, 'fal_sam2');
    }

    public function segmentWithPoints(
        string $imageBinary,
        array $positivePoints,
        array $negativePoints = [],
        array $options = []
    ): SamSegmentationResult {
        $t0 = microtime(true);
        $w = (int) ($options['fal_width'] ?? 0);
        $h = (int) ($options['fal_height'] ?? 0);
        if ($w < 2 || $h < 2) {
            throw new InvalidArgumentException('Invalid point segmentation dimensions.');
        }
        $prompts = [];
        foreach ($positivePoints as $p) {
            $px = SamLayerExtractionImage::normToPixel(
                ['x' => (float) $p['x'], 'y' => (float) $p['y']],
                $w,
                $h
            );
            $prompts[] = ['x' => $px['x'], 'y' => $px['y'], 'label' => 1];
        }
        foreach ($negativePoints as $p) {
            $px = SamLayerExtractionImage::normToPixel(
                ['x' => (float) $p['x'], 'y' => (float) $p['y']],
                $w,
                $h
            );
            $prompts[] = ['x' => $px['x'], 'y' => $px['y'], 'label' => 0];
        }
        $mime = (string) ($options['image_mime'] ?? 'image/png');
        $uri = SamLayerExtractionImage::dataUriFromBinary($imageBinary, $mime);
        $input = $this->baseInput($uri);
        $input['apply_mask'] = true;
        $input['prompts'] = $prompts;
        $data = $this->requestFal($input, $options);
        $maskPng = $this->resultImagePng($data, $options);
        $bbox = $this->bboxFromMaskPng($maskPng) ?? ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2];
        $duration = (int) round((microtime(true) - $t0) * 1000);
        $model = (string) (config('studio_layer_extraction.sam.model', 'fal_sam2'));

        return new SamSegmentationResult(
            [new SamMaskSegment($maskPng, $bbox, null, 'Selection')],
            $model,
            $duration,
            'fal_sam2',
        );
    }

    public function segmentWithBox(string $imageBinary, array $boxPixels, array $options = []): SamSegmentationResult
    {
        $t0 = microtime(true);
        $mime = (string) ($options['image_mime'] ?? 'image/png');
        $uri = SamLayerExtractionImage::dataUriFromBinary($imageBinary, $mime);
        $input = $this->baseInput($uri);
        $input['apply_mask'] = true;
        $input['box_prompts'] = [
            [
                'x_min' => (int) $boxPixels['x_min'],
                'y_min' => (int) $boxPixels['y_min'],
                'x_max' => (int) $boxPixels['x_max'],
                'y_max' => (int) $boxPixels['y_max'],
            ],
        ];
        $data = $this->requestFal($input, $options);
        $maskPng = $this->resultImagePng($data, $options);
        $bbox = $this->bboxFromMaskPng($maskPng) ?? ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2];
        $duration = (int) round((microtime(true) - $t0) * 1000);
        $model = (string) (config('studio_layer_extraction.sam.model', 'fal_sam2'));

        return new SamSegmentationResult(
            [new SamMaskSegment($maskPng, $bbox, null, 'Box')],
            $model,
            $duration,
            'fal_sam2',
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function requestFal(array $input, array $options): array
    {
        $timeout = (int) ($options['timeout_seconds'] ?? (int) config('studio_layer_extraction.sam.timeout', 120));
        $url = (string) config('services.fal.sam2_endpoint', 'https://fal.run/fal-ai/sam2/image');
        $t0 = microtime(true);
        try {
            $resp = Http::withHeaders(['Authorization' => 'Key '.$this->apiKey])
                ->timeout(max(1, $timeout))
                ->acceptJson()
                ->asJson()
                ->post($url, $input);
        } catch (ConnectionException $e) {
            $this->logFal('post_connect', $url, 0, (int) round((microtime(true) - $t0) * 1000), $options, $this->sanitizedFalErrorBody($e->getMessage()));
            throw new RuntimeException('AI segmentation timed out. Try Draw box or Local mask detection.');
        } catch (Throwable $e) {
            if ($e instanceof RuntimeException && str_starts_with($e->getMessage(), 'AI segmentation ')) {
                throw $e;
            }
            $this->logFal('post_throw', $url, 0, (int) round((microtime(true) - $t0) * 1000), $options, $this->sanitizedFalErrorBody($e->getMessage()));
            throw new RuntimeException('AI segmentation failed. Try Local mask detection or try again.');
        }
        $httpStatus = $resp->status();
        $this->logFal('post', $url, $httpStatus, (int) round((microtime(true) - $t0) * 1000), $options, null);
        if (! $resp->ok()) {
            $this->logFal('post_error', $url, $httpStatus, (int) round((microtime(true) - $t0) * 1000), $options, $this->sanitizedFalErrorBody($resp->body()));
            if (config('app.debug', false)) {
                throw new RuntimeException('Fal SAM request failed: HTTP '.$httpStatus);
            }
            throw new RuntimeException('AI segmentation failed. Try Local mask detection or try again.');
        }
        $j = $resp->json();
        if (! is_array($j)) {
            throw new RuntimeException('Invalid response from the segmentation service.');
        }
        $raw = $j;
        $j = $this->unwrapFalResponsePayload($j);
        if ($this->falPayloadHasImage($j)) {
            return $j;
        }
        $requestId = $this->extractFalRequestId($raw) ?? $this->extractFalRequestId($j);
        if ($requestId !== null) {
            $statusUrl = $this->stringOrNull($raw['status_url'] ?? null) ?? $this->stringOrNull($j['status_url'] ?? null);
            $responseUrl = $this->stringOrNull($raw['response_url'] ?? null) ?? $this->stringOrNull($j['response_url'] ?? null);

            return $this->pollFalQueueUntilResult(
                $requestId,
                $options,
                $statusUrl,
                $responseUrl,
            );
        }

        $this->logFal('unexpected_shape', $url, $httpStatus, (int) round((microtime(true) - $t0) * 1000), $options, 'no_image_and_no_request_id');
        throw new RuntimeException('AI segmentation failed. Try Local mask detection or try again.');
    }

    private function stringOrNull(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * Unwrap `data` / nested queue result shapes so {@see resultImagePng} can find `image`.
     *
     * @param  array<string, mixed>  $j
     * @return array<string, mixed>
     */
    private function unwrapFalResponsePayload(array $j): array
    {
        if (isset($j['data']) && is_array($j['data']) && $this->falPayloadHasImage($j['data'])) {
            return $j['data'];
        }
        if (isset($j['data']) && is_array($j['data'])) {
            return $j['data'];
        }

        return $j;
    }

    /**
     * @param  array<string, mixed>  $j
     */
    private function falPayloadHasImage(array $j): bool
    {
        if (isset($j['image']) && (is_array($j['image']) || is_string($j['image']))) {
            return true;
        }
        if (isset($j['output']) && is_array($j['output']) && isset($j['output']['image'])) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $j
     */
    private function extractFalRequestId(array $j): ?string
    {
        if (isset($j['request_id']) && is_string($j['request_id']) && $j['request_id'] !== '') {
            return $j['request_id'];
        }
        if (isset($j['gateway_request_id']) && is_string($j['gateway_request_id']) && $j['gateway_request_id'] !== '') {
            return $j['gateway_request_id'];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function pollFalQueueUntilResult(
        string $requestId,
        array $options,
        ?string $falStatusUrl = null,
        ?string $falResponseUrl = null,
    ): array {
        $modelId = $this->falModelIdForQueue();
        $queueBase = (string) config('services.fal.queue_base', 'https://queue.fal.run');
        $queueBase = rtrim($queueBase, '/');
        $maxPolls = max(1, (int) config('studio_layer_extraction.sam.fal_queue_max_polls', 180));
        $intervalMs = max(200, (int) config('studio_layer_extraction.sam.fal_queue_poll_interval_ms', 1000));
        $statusUrl = $falStatusUrl
            ?? ($queueBase.'/'.$modelId.'/requests/'.rawurlencode($requestId).'/status');
        $defaultResultUrl = $falResponseUrl
            ?? ($queueBase.'/'.$modelId.'/requests/'.rawurlencode($requestId));
        for ($i = 0; $i < $maxPolls; $i++) {
            if ($i > 0) {
                usleep($intervalMs * 1000);
            }
            $t0 = microtime(true);
            try {
                $st = Http::withHeaders(['Authorization' => 'Key '.$this->apiKey])
                    ->timeout(60)
                    ->acceptJson()
                    ->get($statusUrl);
            } catch (ConnectionException $e) {
                $this->logFal('queue_status_connect', $statusUrl, 0, (int) round((microtime(true) - $t0) * 1000), $options, $this->sanitizedFalErrorBody($e->getMessage()));
                throw new RuntimeException('AI segmentation timed out. Try Draw box or Local mask detection.');
            } catch (Throwable $e) {
                if (! $e instanceof RuntimeException) {
                    $this->logFal('queue_status_throw', $statusUrl, 0, (int) round((microtime(true) - $t0) * 1000), $options, $this->sanitizedFalErrorBody($e->getMessage()));
                }
                throw $e instanceof RuntimeException ? $e : new RuntimeException('AI segmentation failed. Try Local mask detection or try again.');
            }
            $this->logFal('queue_status', $statusUrl, $st->status(), (int) round((microtime(true) - $t0) * 1000), $options, null);
            if (! $st->ok()) {
                if (config('app.debug', false)) {
                    throw new RuntimeException('Fal queue status failed: HTTP '.$st->status());
                }
                throw new RuntimeException('AI segmentation failed. Try Local mask detection or try again.');
            }
            $sj = $st->json();
            if (! is_array($sj)) {
                throw new RuntimeException('Invalid queue status from the segmentation service.');
            }
            $nextStatus = $this->stringOrNull($sj['status_url'] ?? null);
            if ($nextStatus !== null) {
                $statusUrl = $nextStatus;
            }
            $status = strtoupper(trim((string) ($sj['status'] ?? '')));
            if (in_array($status, ['FAILED', 'ERROR', 'FAILURE'], true)) {
                $this->logFal('queue_failed', $statusUrl, $st->status(), 0, $options, 'queue_status='.$status);
                throw new RuntimeException('AI segmentation failed. Try Local mask detection or try again.');
            }
            if ($status === 'COMPLETED') {
                if (isset($sj['error']) && is_string($sj['error']) && trim($sj['error']) !== '') {
                    $errPreview = $this->sanitizedFalErrorBody($sj['error']);
                    $this->logFal('queue_completed_error', $statusUrl, $st->status(), 0, $options, $errPreview);
                    throw new RuntimeException('AI segmentation failed. Try Local mask detection or try again.');
                }
                if ($this->falPayloadHasImage($sj) || (isset($sj['data']) && is_array($sj['data']) && $this->falPayloadHasImage($sj['data']))) {
                    $embedded = $this->unwrapFalResponsePayload($sj);
                    if ($this->falPayloadHasImage($embedded)) {
                        return $embedded;
                    }
                }
                $resultUrl = $this->stringOrNull($sj['response_url'] ?? null)
                    ?? $falResponseUrl
                    ?? $defaultResultUrl;
                $t1 = microtime(true);
                try {
                    $out = Http::withHeaders(['Authorization' => 'Key '.$this->apiKey])
                        ->timeout(120)
                        ->acceptJson()
                        ->get($resultUrl);
                } catch (ConnectionException $e) {
                    $this->logFal('queue_result_connect', $resultUrl, 0, (int) round((microtime(true) - $t1) * 1000), $options, $this->sanitizedFalErrorBody($e->getMessage()));
                    throw new RuntimeException('AI segmentation timed out. Try Draw box or Local mask detection.');
                }
                $this->logFal('queue_result', $resultUrl, $out->status(), (int) round((microtime(true) - $t1) * 1000), $options, null);
                if (! $out->ok()) {
                    if (config('app.debug', false)) {
                        throw new RuntimeException('Fal queue result failed: HTTP '.$out->status());
                    }
                    throw new RuntimeException('AI segmentation failed. Try Local mask detection or try again.');
                }
                $r = $out->json();
                if (! is_array($r)) {
                    throw new RuntimeException('Invalid result from the segmentation service.');
                }

                return $this->unwrapFalResponsePayload($r);
            }
        }
        $this->logFal('queue_timeout', $statusUrl, 0, 0, $options, 'max_polls='.$maxPolls);
        throw new RuntimeException(
            'AI segmentation timed out. Try Draw box or Local mask detection.'
        );
    }

    private function falModelIdForQueue(): string
    {
        $u = (string) config('services.fal.sam2_endpoint', 'https://fal.run/fal-ai/sam2/image');
        $path = parse_url($u, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $p = trim($path, '/');

            return $p !== '' ? $p : 'fal-ai/sam2/image';
        }

        return 'fal-ai/sam2/image';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function logFal(
        string $step,
        string $endpoint,
        int $httpStatus,
        int $durationMs,
        array $options,
        ?string $extra,
    ): void {
        $mode = (string) ($options['fal_log_mode'] ?? 'auto');
        Log::info('[studio_layer_extraction_fal]', [
            'step' => $step,
            'http_status' => $httpStatus,
            'duration_ms' => $durationMs,
            'request_mode' => $mode,
            'provider' => 'fal',
            'endpoint' => $endpoint,
            'fal_key_present' => true,
            'session_id' => $options['layer_extraction_session_id'] ?? null,
            'detail' => $extra,
        ]);
    }

    private function sanitizedFalErrorBody(string $raw): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $raw) ?? '');
        if (strlen($s) > 500) {
            $s = substr($s, 0, 500).'…';
        }

        return $s;
    }

    private function baseInput(string $dataUri): array
    {
        $out = ['image_url' => $dataUri, 'output_format' => 'png'];
        if ((bool) config('studio_layer_extraction.sam.fal_sync_mode', false)) {
            $out['sync_mode'] = true;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resultImagePng(array $data, array $options): string
    {
        if (isset($data['output']['image']) && ! isset($data['image'])) {
            $data['image'] = $data['output']['image'];
        }
        if (isset($data['image']) && is_array($data['image']) && is_string($data['image']['url'] ?? null)) {
            $bytes = $this->downloadResultBytes((string) $data['image']['url'], $options);
            if (str_starts_with($bytes, "\x89PNG")) {
                return $bytes;
            }
        }
        if (is_string($data['image'] ?? null) && str_starts_with((string) $data['image'], 'data:')) {
            $parts = explode(',', (string) $data['image'], 2);
            if (count($parts) === 2) {
                $b = base64_decode($parts[1], true);
                if ($b !== false && $b !== '') {
                    return $b;
                }
            }
        }

        throw new RuntimeException('The segmentation service did not return a usable mask image.');
    }

    private function downloadResultBytes(string $url, array $options): string
    {
        if ($url === '' || str_starts_with($url, 'data:')) {
            throw new RuntimeException('The segmentation service did not return a usable mask image.');
        }
        $timeout = (int) ($options['timeout_seconds'] ?? (int) config('studio_layer_extraction.sam.timeout', 120));
        $resp = Http::timeout(max(1, $timeout))
            ->withOptions(['http_errors' => false])
            ->get($url);
        if (! $resp->ok() || $resp->body() === '') {
            throw new RuntimeException('The segmentation result could not be loaded.');
        }

        return (string) $resp->body();
    }

    /**
     * @return ?array{x: int, y: int, width: int, height: int}
     */
    private function bboxFromMaskPng(string $maskPng): ?array
    {
        $im = @imagecreatefromstring($maskPng);
        if ($im === false) {
            return null;
        }
        if (! imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $minX = $w;
        $minY = $h;
        $maxX = 0;
        $maxY = 0;
        $any = false;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                $a = ($c >> 24) & 127;
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;
                $lum = ($r + $g + $b) / 3.0;
                $wgt = (1.0 - $a / 127.0) * ($lum / 255.0);
                if ($wgt > 0.1) {
                    $any = true;
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }
        imagedestroy($im);
        if (! $any) {
            return null;
        }

        return [
            'x' => $minX,
            'y' => $minY,
            'width' => $maxX - $minX + 1,
            'height' => $maxY - $minY + 1,
        ];
    }
}
