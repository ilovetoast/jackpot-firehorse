<?php

namespace App\Studio\Animation\Providers\Kling;

use App\Studio\Animation\Contracts\AnimationProviderInterface;
use App\Studio\Animation\Data\ProviderAnimationRequestData;
use App\Studio\Animation\Data\ProviderAnimationResultData;
use App\Studio\Animation\Support\AnimationCapabilityRegistry;
use App\Studio\Animation\Support\AnimationPromptBuilder;

final class KlingAnimationProvider implements AnimationProviderInterface
{
    public function supports(string $capability): bool
    {
        return AnimationCapabilityRegistry::forProvider('kling')[$capability] ?? false;
    }

    public function submitImageToVideo(ProviderAnimationRequestData $request): ProviderAnimationResultData
    {
        $transportKind = (string) config('studio_animation.providers.kling.transport', 'kling_api');

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
        $tmpPath = null;

        try {
            $absolute = KlingStartImageFile::materializeToTempFile(
                $request->startImageDisk,
                $request->startImageStoragePath,
            );
            if (! in_array($request->startImageDisk, ['local', 'public'], true)) {
                $tmpPath = $absolute;
            }
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
        }

        $composedPrompt = AnimationPromptBuilder::compose($request->prompt, $request->motionPresetKey);
        $prompt = $composedPrompt !== '' ? $composedPrompt : 'Smooth cinematic motion, preserve layout and branding.';

        try {
            if ($transportKind === 'kling_api') {
                $native = is_array($cfg['native'] ?? null) ? $cfg['native'] : [];
                $access = (string) ($native['access_key'] ?? '');
                $secret = (string) ($native['secret_key'] ?? '');
                if ($access === '' || $secret === '') {
                    return new ProviderAnimationResultData(
                        phase: 'failed',
                        providerJobId: null,
                        remoteVideoUrl: null,
                        remoteWidth: null,
                        remoteHeight: null,
                        remoteDurationSeconds: null,
                        errorCode: 'missing_api_key',
                        errorMessage: 'Set KLING_API_KEY (Access Key) and KLING_SECRET_KEY for the official Kling API.',
                        rawRequestDebug: null,
                        rawResponseDebug: null,
                        normalizedProviderStatus: 'submit_failed',
                        providerPhaseDebug: ['reason' => 'missing_native_keys'],
                    );
                }

                $imageBase64 = base64_encode((string) file_get_contents($absolute));
                $modelName = $this->resolveNativeModelName($request->providerModelKey);
                $payload = [
                    'model_name' => $modelName,
                    'image' => $imageBase64,
                    'prompt' => $prompt,
                    'duration' => $this->nativeDurationString($request->durationSeconds),
                    'mode' => 'std',
                    'aspect_ratio' => $this->mapNativeAspectRatio($request->aspectRatio),
                    'sound' => $request->generateAudio ? 'on' : 'off',
                ];
                if ($request->negativePrompt !== null && trim($request->negativePrompt) !== '') {
                    $payload['negative_prompt'] = trim($request->negativePrompt);
                }

                $baseUrl = (string) ($native['base_url'] ?? 'https://api-singapore.klingai.com');
                $client = new KlingNativeClient($access, $secret, $baseUrl);
                $submit = $client->postImage2Video($payload);

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
                        rawRequestDebug: ['model_name' => $modelName, 'input_keys' => array_keys($payload)],
                        rawResponseDebug: is_array($submit['raw'] ?? null) ? $submit['raw'] : ['raw' => $submit],
                        normalizedProviderStatus: 'submit_failed',
                        providerPhaseDebug: ['provider_error' => (string) ($submit['error'] ?? ''), 'transport' => 'kling_api'],
                    );
                }

                $taskId = (string) ($submit['task_id'] ?? '');
                if ($taskId === '') {
                    return new ProviderAnimationResultData(
                        phase: 'failed',
                        providerJobId: null,
                        remoteVideoUrl: null,
                        remoteWidth: null,
                        remoteHeight: null,
                        remoteDurationSeconds: null,
                        errorCode: 'missing_request_id',
                        errorMessage: 'Kling API returned no task id.',
                        rawRequestDebug: null,
                        rawResponseDebug: is_array($submit['raw'] ?? null) ? $submit['raw'] : null,
                        normalizedProviderStatus: 'submit_failed',
                        providerPhaseDebug: ['reason' => 'missing_task_id', 'transport' => 'kling_api'],
                    );
                }

                $ref = [
                    'transport' => 'kling_api',
                    'task_id' => $taskId,
                    'request_id' => $taskId,
                ];

                return new ProviderAnimationResultData(
                    phase: 'submitted',
                    providerJobId: (string) json_encode($ref, JSON_THROW_ON_ERROR),
                    remoteVideoUrl: null,
                    remoteWidth: null,
                    remoteHeight: null,
                    remoteDurationSeconds: null,
                    errorCode: null,
                    errorMessage: null,
                    rawRequestDebug: ['model_name' => $modelName, 'transport' => 'kling_api'],
                    rawResponseDebug: is_array($submit['raw'] ?? null) ? $submit['raw'] : null,
                    normalizedProviderStatus: 'submitted_to_provider',
                    providerPhaseDebug: ['request_id' => $taskId, 'transport' => 'kling_api'],
                );
            }
        } catch (\JsonException) {
            return new ProviderAnimationResultData(
                phase: 'failed',
                providerJobId: null,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: 'submit_failed',
                errorMessage: 'Could not encode provider job reference.',
                rawRequestDebug: null,
                rawResponseDebug: null,
                normalizedProviderStatus: 'submit_failed',
                providerPhaseDebug: ['reason' => 'json_encode'],
            );
        } finally {
            if ($tmpPath !== null && is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        return new ProviderAnimationResultData(
            phase: 'failed',
            providerJobId: null,
            remoteVideoUrl: null,
            remoteWidth: null,
            remoteHeight: null,
            remoteDurationSeconds: null,
            errorCode: 'unknown_transport',
            errorMessage: 'STUDIO_ANIMATION_KLING_TRANSPORT must be kling_api or mock.',
            rawRequestDebug: null,
            rawResponseDebug: null,
            normalizedProviderStatus: 'submit_failed',
            providerPhaseDebug: ['transport' => $transportKind],
        );
    }

    public function poll(string $providerJobId): ProviderAnimationResultData
    {
        $transportKind = (string) config('studio_animation.providers.kling.transport', 'kling_api');

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
        if (! is_array($meta)) {
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

        $isKlingNative = ($meta['transport'] ?? null) === 'kling_api'
            || (isset($meta['task_id']) && ! isset($meta['status_url']));

        if ($isKlingNative) {
            if (empty($meta['task_id'])) {
                return new ProviderAnimationResultData(
                    phase: 'failed',
                    providerJobId: $providerJobId,
                    remoteVideoUrl: null,
                    remoteWidth: null,
                    remoteHeight: null,
                    remoteDurationSeconds: null,
                    errorCode: 'invalid_provider_job_ref',
                    errorMessage: 'Stored Kling task id is missing.',
                    rawRequestDebug: null,
                    rawResponseDebug: null,
                    normalizedProviderStatus: 'provider_failed',
                    providerPhaseDebug: ['reason' => 'invalid_provider_job_ref'],
                );
            }

            return $this->pollKlingNative((string) $meta['task_id'], $providerJobId);
        }

        if (($meta['transport'] ?? null) === 'fal_queue' || isset($meta['status_url'])) {
            return new ProviderAnimationResultData(
                phase: 'failed',
                providerJobId: $providerJobId,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: 'deprecated_provider',
                errorMessage: 'This job was created with a removed third-party queue. Create a new Studio animation; only the official Kling API is supported.',
                rawRequestDebug: null,
                rawResponseDebug: $meta,
                normalizedProviderStatus: 'provider_failed',
                providerPhaseDebug: ['reason' => 'deprecated_queue_transport'],
            );
        }

        return new ProviderAnimationResultData(
            phase: 'failed',
            providerJobId: $providerJobId,
            remoteVideoUrl: null,
            remoteWidth: null,
            remoteHeight: null,
            remoteDurationSeconds: null,
            errorCode: 'invalid_provider_job_ref',
            errorMessage: 'Stored provider job reference is not a Kling API task.',
            rawRequestDebug: null,
            rawResponseDebug: $meta,
            normalizedProviderStatus: 'provider_failed',
            providerPhaseDebug: ['reason' => 'invalid_provider_job_ref'],
        );
    }

    private function pollKlingNative(string $taskId, string $providerJobId): ProviderAnimationResultData
    {
        $cfg = config('studio_animation.providers.kling', []);
        $native = is_array($cfg['native'] ?? null) ? $cfg['native'] : [];
        $access = (string) ($native['access_key'] ?? '');
        $secret = (string) ($native['secret_key'] ?? '');
        if ($access === '' || $secret === '') {
            return new ProviderAnimationResultData(
                phase: 'failed',
                providerJobId: $providerJobId,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: 'missing_api_key',
                errorMessage: 'KLING_API_KEY and KLING_SECRET_KEY are not configured.',
                rawRequestDebug: null,
                rawResponseDebug: null,
                normalizedProviderStatus: 'provider_failed',
                providerPhaseDebug: ['reason' => 'missing_api_key', 'transport' => 'kling_api'],
            );
        }

        $client = new KlingNativeClient(
            $access,
            $secret,
            (string) ($native['base_url'] ?? 'https://api-singapore.klingai.com')
        );
        $q = $client->getImage2VideoTask($taskId);
        if (! ($q['ok'] ?? false)) {
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
                rawResponseDebug: ['query_error' => $q, 'transport' => 'kling_api'],
                normalizedProviderStatus: 'provider_processing',
                providerPhaseDebug: ['transport' => 'kling_api', 'transient' => true],
            );
        }

        $json = is_array($q['json'] ?? null) ? $q['json'] : [];
        $code = (int) ($json['code'] ?? -1);
        if ($code !== 0) {
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
                normalizedProviderStatus: 'provider_processing',
                providerPhaseDebug: ['transport' => 'kling_api', 'code' => $code],
            );
        }

        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $status = (string) ($data['task_status'] ?? '');

        if (in_array($status, ['submitted', 'processing'], true) || $status === '') {
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
                normalizedProviderStatus: 'provider_processing',
                providerPhaseDebug: ['transport' => 'kling_api', 'task_status' => $status],
            );
        }

        if ($status === 'failed') {
            $msg = (string) ($data['task_status_msg'] ?? 'Kling task failed.');

            return new ProviderAnimationResultData(
                phase: 'failed',
                providerJobId: $providerJobId,
                remoteVideoUrl: null,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: null,
                errorCode: 'provider_failed',
                errorMessage: $msg,
                rawRequestDebug: null,
                rawResponseDebug: $json,
                normalizedProviderStatus: 'provider_failed',
                providerPhaseDebug: ['transport' => 'kling_api'],
            );
        }

        if ($status === 'succeed') {
            $taskResult = is_array($data['task_result'] ?? null) ? $data['task_result'] : [];
            $videos = is_array($taskResult['videos'] ?? null) ? $taskResult['videos'] : [];
            $v0 = is_array($videos[0] ?? null) ? $videos[0] : null;
            $url = is_array($v0) ? (string) ($v0['url'] ?? '') : '';
            if ($url === '') {
                return new ProviderAnimationResultData(
                    phase: 'failed',
                    providerJobId: $providerJobId,
                    remoteVideoUrl: null,
                    remoteWidth: null,
                    remoteHeight: null,
                    remoteDurationSeconds: null,
                    errorCode: 'missing_video_url',
                    errorMessage: 'Kling result missing video url.',
                    rawRequestDebug: null,
                    rawResponseDebug: $json,
                    normalizedProviderStatus: 'provider_failed',
                    providerPhaseDebug: ['reason' => 'missing_video_url', 'transport' => 'kling_api'],
                );
            }
            $dur = is_array($v0) && isset($v0['duration']) ? (int) $v0['duration'] : null;

            return new ProviderAnimationResultData(
                phase: 'complete',
                providerJobId: $providerJobId,
                remoteVideoUrl: $url,
                remoteWidth: null,
                remoteHeight: null,
                remoteDurationSeconds: $dur,
                errorCode: null,
                errorMessage: null,
                rawRequestDebug: null,
                rawResponseDebug: $json,
                normalizedProviderStatus: 'provider_complete',
                providerPhaseDebug: [
                    'transport' => 'kling_api',
                    'task_status' => 'succeed',
                ],
            );
        }

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
            normalizedProviderStatus: 'provider_processing',
            providerPhaseDebug: ['transport' => 'kling_api', 'unexpected_status' => $status],
        );
    }

    private function nativeDurationString(int $durationSeconds): string
    {
        $s = max(3, min(15, $durationSeconds));

        return $s <= 7 ? '5' : '10';
    }

    private function mapNativeAspectRatio(string $aspectRatio): string
    {
        $a = str_replace('x', ':', $aspectRatio);
        if (in_array($a, ['16:9', '9:16', '1:1'], true)) {
            return $a;
        }

        return '1:1';
    }

    private function resolveNativeModelName(string $providerModelKey): string
    {
        $models = config('studio_animation.providers.kling.models', []);
        $name = $models[$providerModelKey]['native_model_name'] ?? null;
        if (is_string($name) && $name !== '') {
            return $name;
        }

        return (string) config('studio_animation.providers.kling.native.default_model', 'kling-v2-5-turbo');
    }
}
