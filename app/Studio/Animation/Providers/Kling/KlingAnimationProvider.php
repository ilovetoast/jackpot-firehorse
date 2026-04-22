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
            $absolute = FalKlingQueueTransport::materializeToTempFile(
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
                        errorMessage: 'Set KLING_API_KEY (Access Key) and KLING_SECRET_KEY for official Kling API, or use STUDIO_ANIMATION_KLING_TRANSPORT=fal_queue with FAL_KEY.',
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

            if ($transportKind === 'fal_queue') {
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
                        errorMessage: 'FAL_KEY is not set (required for STUDIO_ANIMATION_KLING_TRANSPORT=fal_queue).',
                        rawRequestDebug: null,
                        rawResponseDebug: null,
                        normalizedProviderStatus: 'submit_failed',
                        providerPhaseDebug: ['reason' => 'missing_fal_key'],
                    );
                }

                $modelPath = $this->resolveFalModelPath($request->providerModelKey);
                $baseUrl = (string) ($cfg['fal']['queue_base_url'] ?? 'https://queue.fal.run');
                $dataUri = FalKlingQueueTransport::buildStartImageDataUri(
                    $absolute,
                    $request->startImageMimeType
                );
                $input = [
                    'prompt' => $prompt,
                    'start_image_url' => $dataUri,
                    'duration' => (string) max(3, min(15, $request->durationSeconds)),
                    'generate_audio' => $request->generateAudio,
                ];
                if ($request->negativePrompt !== null && trim($request->negativePrompt) !== '') {
                    $input['negative_prompt'] = trim($request->negativePrompt);
                }

                $fal = new FalKlingQueueTransport;
                $submit = $fal->submit($modelPath, $input, $apiKey, $baseUrl);
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
                        'transport' => 'fal_queue',
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
                    providerPhaseDebug: ['request_id' => $rid, 'transport' => 'fal_queue'],
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
            errorMessage: 'STUDIO_ANIMATION_KLING_TRANSPORT must be kling_api, fal_queue, or mock.',
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

        if (empty($meta['request_id']) || empty($meta['status_url'])) {
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
                errorMessage: 'FAL_KEY is not configured.',
                rawRequestDebug: null,
                rawResponseDebug: null,
                normalizedProviderStatus: 'provider_failed',
                providerPhaseDebug: ['reason' => 'missing_api_key'],
            );
        }

        $fal = new FalKlingQueueTransport;
        $statusUrl = (string) $meta['status_url'];
        $responseUrl = (string) ($meta['response_url'] ?? '');

        $st = $fal->fetchStatus($statusUrl.'?logs=0', $apiKey);
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

        $res = $fal->fetchResult($responseUrl, $apiKey);
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
