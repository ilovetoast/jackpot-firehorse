<?php

namespace App\Studio\Animation\Providers\Kling;

use App\Studio\Animation\Contracts\AnimationProviderInterface;
use App\Studio\Animation\Data\ProviderAnimationRequestData;
use App\Studio\Animation\Data\ProviderAnimationResultData;
use App\Studio\Animation\Support\AnimationCapabilityRegistry;
use App\Studio\Animation\Support\AnimationPromptBuilder;
use Illuminate\Support\Facades\Log;

final class KlingAnimationProvider implements AnimationProviderInterface
{
    public function __construct(
        private readonly FalKlingQueueTransport $transport = new FalKlingQueueTransport,
    ) {}

    public function supports(string $capability): bool
    {
        return AnimationCapabilityRegistry::forProvider('kling')[$capability] ?? false;
    }

    public function submitImageToVideo(ProviderAnimationRequestData $request): ProviderAnimationResultData
    {
        $transportKind = (string) config('studio_animation.providers.kling.transport', 'fal_queue');

        if ($transportKind === 'mock') {
            return new ProviderAnimationResultData(
                phase: 'submitted',
                providerJobId: 'mock-'.bin2hex(random_bytes(8)),
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: null,
                errorMessage: null,
                rawRequestDebug: ['mock' => true],
                rawResponseDebug: null,
                normalizedProviderStatus: 'submitted_to_provider',
                providerPhaseDebug: ['transport' => 'mock'],
            );
        }

        $cfg = config('studio_animation.providers.kling', []);
        $apiKey = (string) ($cfg['fal']['api_key'] ?? '');
        if ($apiKey === '') {
            return new ProviderAnimationResultData(
                phase: 'failed',
                providerJobId: null,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: 'missing_api_key',
                errorMessage: 'FAL_KEY (or KLING_API_KEY fallback) is not configured.',
                rawRequestDebug: null,
                rawResponseDebug: null,
                normalizedProviderStatus: 'submit_failed',
                providerPhaseDebug: ['reason' => 'missing_api_key'],
            );
        }

        $modelPath = $this->resolveFalModelPath($request->providerModelKey);
        $baseUrl = (string) ($cfg['fal']['queue_base_url'] ?? 'https://queue.fal.run');

        $tmpPath = null;
        try {
            $absolute = FalKlingQueueTransport::materializeToTempFile(
                $request->startImageDisk,
                $request->startImageStoragePath,
            );
            if (! in_array($request->startImageDisk, ['local', 'public'], true)) {
                $tmpPath = $absolute;
            }
            $dataUri = FalKlingQueueTransport::buildStartImageDataUri(
                $absolute,
                $request->startImageMimeType
            );
        } catch (\Throwable $e) {
            return new ProviderAnimationResultData(
                phase: 'failed',
                providerJobId: null,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: 'start_image_read_failed',
                errorMessage: $e->getMessage(),
                rawRequestDebug: null,
                rawResponseDebug: null,
                normalizedProviderStatus: 'start_frame_unavailable',
                providerPhaseDebug: ['reason' => 'start_image_read_failed'],
            );
        } finally {
            if ($tmpPath !== null && is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        $composedPrompt = AnimationPromptBuilder::compose($request->prompt, $request->motionPresetKey);

        $input = [
            'prompt' => $composedPrompt !== '' ? $composedPrompt : 'Smooth cinematic motion, preserve layout and branding.',
            'start_image_url' => $dataUri,
            'duration' => (string) max(3, min(15, $request->durationSeconds)),
            'generate_audio' => $request->generateAudio,
        ];

        if ($request->negativePrompt !== null && trim($request->negativePrompt) !== '') {
            $input['negative_prompt'] = trim($request->negativePrompt);
        }

        $submit = $this->transport->submit($modelPath, $input, $apiKey, $baseUrl);

        if (! ($submit['ok'] ?? false)) {
            return new ProviderAnimationResultData(
                phase: 'failed',
                providerJobId: null,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: (string) ($submit['error'] ?? 'submit_failed'),
                errorMessage: (string) ($submit['message'] ?? 'Submit failed'),
                rawRequestDebug: ['model_path' => $modelPath, 'input_keys' => array_keys($input)],
                rawResponseDebug: is_array($submit['raw'] ?? null) ? $submit['raw'] : ['raw' => $submit],
                normalizedProviderStatus: 'submit_failed',
                providerPhaseDebug: ['provider_error' => (string) ($submit['error'] ?? '')],
            );
        }

        $rid = (string) ($submit['request_id'] ?? '');
        if ($rid === '') {
            return new ProviderAnimationResultData(
                phase: 'failed',
                providerJobId: null,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: 'missing_request_id',
                errorMessage: 'Provider returned no request id.',
                rawRequestDebug: null,
                rawResponseDebug: is_array($submit['raw'] ?? null) ? $submit['raw'] : null,
                normalizedProviderStatus: 'submit_failed',
                providerPhaseDebug: ['reason' => 'missing_request_id'],
            );
        }

        return new ProviderAnimationResultData(
            phase: 'submitted',
            providerJobId: json_encode([
                'request_id' => $rid,
                'status_url' => (string) ($submit['status_url'] ?? ''),
                'response_url' => (string) ($submit['response_url'] ?? ''),
            ], JSON_THROW_ON_ERROR),
            remoteVideoUrl: null,
            remoteWidth: null,
            remoteHeight: null,
            remoteDurationSeconds: null,
            errorCode: null,
            errorMessage: null,
            rawRequestDebug: ['model_path' => $modelPath],
            rawResponseDebug: is_array($submit['raw'] ?? null) ? $submit['raw'] : null,
            normalizedProviderStatus: 'submitted_to_provider',
            providerPhaseDebug: ['request_id' => $rid],
        );
    }

    public function poll(string $providerJobId): ProviderAnimationResultData
    {
        $transportKind = (string) config('studio_animation.providers.kling.transport', 'fal_queue');

        if ($transportKind === 'mock' || str_starts_with($providerJobId, 'mock-')) {
            return new ProviderAnimationResultData(
                phase: 'complete',
                providerJobId: $providerJobId,
                remoteVideoUrl: 'https://studio-animation.mock/local-test.mp4',
                remoteWidth: 1280,
                remoteHeight: 720,
                remoteDurationSeconds: 5,
                errorCode: null,
                errorMessage: null,
                rawRequestDebug: ['mock' => true],
                rawResponseDebug: null,
                normalizedProviderStatus: 'provider_complete',
                providerPhaseDebug: ['transport' => 'mock'],
            );
        }

        $meta = json_decode($providerJobId, true);
        if (! is_array($meta) || empty($meta['request_id']) || empty($meta['status_url'])) {
            return new ProviderAnimationResultData(
                phase: 'failed',
                providerJobId: $providerJobId,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: 'invalid_provider_job_ref',
                errorMessage: 'Stored provider job reference is invalid.',
                rawRequestDebug: null,
                rawResponseDebug: null,
                normalizedProviderStatus: 'provider_failed',
                providerPhaseDebug: ['reason' => 'invalid_provider_job_ref'],
            );
        }

        $apiKey = (string) (config('studio_animation.providers.kling.fal.api_key') ?? '');
        if ($apiKey === '') {
            return new ProviderAnimationResultData(
                phase: 'failed',
                providerJobId: $providerJobId,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: 'missing_api_key',
                errorMessage: 'FAL_KEY (or KLING_API_KEY fallback) is not configured.',
                rawRequestDebug: null,
                rawResponseDebug: null,
                normalizedProviderStatus: 'provider_failed',
                providerPhaseDebug: ['reason' => 'missing_api_key'],
            );
        }

        $statusUrl = (string) $meta['status_url'];
        $responseUrl = (string) ($meta['response_url'] ?? '');

        $st = $this->transport->fetchStatus($statusUrl.'?logs=0', $apiKey);
        if (! ($st['ok'] ?? false)) {
            $norm = KlingFalStatusNormalizer::fromQueueStatus(null, true);

            return new ProviderAnimationResultData(
                phase: 'processing',
                providerJobId: $providerJobId,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: null,
                errorMessage: null,
                rawRequestDebug: null,
                rawResponseDebug: ['status_error' => $st],
                normalizedProviderStatus: $norm['normalized_provider_status'],
                providerPhaseDebug: array_merge(['transport' => 'fal_queue'], $norm),
            );
        }

        $json = is_array($st['json'] ?? null) ? $st['json'] : [];
        $norm = KlingFalStatusNormalizer::fromQueueStatus($json, false);
        $state = strtoupper((string) ($json['status'] ?? ''));

        if ($state === 'IN_QUEUE' || $state === 'IN_PROGRESS') {
            return new ProviderAnimationResultData(
                phase: 'processing',
                providerJobId: $providerJobId,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: null,
                errorMessage: null,
                rawRequestDebug: null,
                rawResponseDebug: $json,
                normalizedProviderStatus: $norm['normalized_provider_status'],
                providerPhaseDebug: array_merge(['transport' => 'fal_queue'], $norm),
            );
        }

        if ($state === 'FAILED') {
            return new ProviderAnimationResultData(
                phase: 'failed',
                providerJobId: $providerJobId,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: (string) ($json['error_type'] ?? 'provider_failed'),
                errorMessage: (string) ($json['error'] ?? 'Provider reported failure.'),
                rawRequestDebug: null,
                rawResponseDebug: $json,
                normalizedProviderStatus: $norm['normalized_provider_status'],
                providerPhaseDebug: array_merge(['transport' => 'fal_queue'], $norm),
            );
        }

        if ($state !== 'COMPLETED') {
            Log::info('[KlingAnimationProvider] unexpected status', ['status' => $state]);

            return new ProviderAnimationResultData(
                phase: 'processing',
                providerJobId: $providerJobId,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: null,
                errorMessage: null,
                rawRequestDebug: null,
                rawResponseDebug: $json,
                normalizedProviderStatus: $norm['normalized_provider_status'],
                providerPhaseDebug: array_merge(['transport' => 'fal_queue', 'unexpected_state' => $state], $norm),
            );
        }

        if ($responseUrl === '') {
            return new ProviderAnimationResultData(
                phase: 'failed',
                providerJobId: $providerJobId,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: 'missing_response_url',
                errorMessage: 'Completed without response URL.',
                rawRequestDebug: null,
                rawResponseDebug: $json,
                normalizedProviderStatus: 'provider_failed',
                providerPhaseDebug: ['reason' => 'missing_response_url'],
            );
        }

        $res = $this->transport->fetchResult($responseUrl, $apiKey);
        if (! ($res['ok'] ?? false)) {
            return new ProviderAnimationResultData(
                phase: 'failed',
                providerJobId: $providerJobId,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: 'result_fetch_failed',
                errorMessage: (string) ($res['message'] ?? ''),
                rawRequestDebug: null,
                rawResponseDebug: is_array($res['json'] ?? null) ? $res['json'] : null,
                normalizedProviderStatus: 'provider_failed',
                providerPhaseDebug: ['reason' => 'result_fetch_failed'],
            );
        }

        $out = is_array($res['json'] ?? null) ? $res['json'] : [];
        $video = is_array($out['video'] ?? null) ? $out['video'] : [];
        $url = (string) ($video['url'] ?? '');

        if ($url === '') {
            return new ProviderAnimationResultData(
                phase: 'failed',
                providerJobId: $providerJobId,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: 'missing_video_url',
                errorMessage: 'Provider result missing video url.',
                rawRequestDebug: null,
                rawResponseDebug: $out,
                normalizedProviderStatus: 'provider_failed',
                providerPhaseDebug: ['reason' => 'missing_video_url'],
            );
        }

        return new ProviderAnimationResultData(
            phase: 'complete',
            providerJobId: $providerJobId,
            remoteVideoUrl: $url,
            remoteWidth: isset($video['width']) ? (int) $video['width'] : null,
            remoteHeight: isset($video['height']) ? (int) $video['height'] : null,
            remoteDurationSeconds: isset($out['duration']) ? (int) $out['duration'] : null,
            errorCode: null,
            errorMessage: null,
            rawRequestDebug: null,
            rawResponseDebug: $out,
            normalizedProviderStatus: 'provider_complete',
            providerPhaseDebug: [
                'transport' => 'fal_queue',
                'normalized_provider_status' => 'provider_complete',
                'provider_queue_state' => 'COMPLETED',
            ],
        );
    }

    private function resolveFalModelPath(string $providerModelKey): string
    {
        $models = config('studio_animation.providers.kling.models', []);
        $path = $models[$providerModelKey]['fal_model_path'] ?? null;
        if (is_string($path) && $path !== '') {
            return $path;
        }

        return (string) config('studio_animation.providers.kling.fal.model_path');
    }
}
