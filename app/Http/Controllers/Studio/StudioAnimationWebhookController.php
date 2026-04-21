<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Jobs\FinalizeStudioAnimationJob;
use App\Models\StudioAnimationJob;
use App\Studio\Animation\Enums\StudioAnimationStatus;
use App\Studio\Animation\Services\StudioAnimationProviderStatusService;
use App\Studio\Animation\Support\StudioAnimationObservability;
use App\Studio\Animation\Webhooks\StudioAnimationWebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound provider lifecycle hook (webhook-ready). Polling remains the primary driver until configured.
 */
class StudioAnimationWebhookController extends Controller
{
    public function __construct(
        protected StudioAnimationProviderStatusService $statusService,
        protected StudioAnimationWebhookSignatureVerifier $signatureVerifier,
    ) {}

    public function ingest(Request $request, string $provider): JsonResponse
    {
        if (! (bool) config('studio_animation.webhooks.ingest_enabled', false)) {
            return response()->json(['ok' => false, 'message' => 'Webhook ingest disabled.'], 503);
        }

        $sharedSecret = (string) config('studio_animation.webhooks.shared_secret', '');
        if ($sharedSecret !== '') {
            $hdr = (string) $request->header('X-Studio-Animation-Secret', '');
            if (! hash_equals($sharedSecret, $hdr)) {
                Log::warning('[StudioAnimationWebhook] bad_shared_secret', ['provider' => $provider]);

                return response()->json(['ok' => false, 'message' => 'Invalid shared secret.'], 401);
            }
        }

        $falSecretConfigured = (string) config('studio_animation.webhooks.fal_signature_secret', '') !== '';
        $sig = $this->signatureVerifier->verifyProviderPayload($request, $provider);
        if ($falSecretConfigured && ! $sig['ok']) {
            Log::warning('[StudioAnimationWebhook] bad_signature', [
                'provider' => $provider,
                'detail' => $sig['detail'] ?? null,
            ]);

            return response()->json(['ok' => false, 'message' => 'Invalid webhook signature.'], 401);
        }

        $payload = $request->all();
        if (! is_array($payload)) {
            return response()->json(['ok' => false, 'message' => 'Invalid JSON.'], 422);
        }

        $requestId = (string) ($payload['request_id'] ?? $payload['gateway_request_id'] ?? '');
        if ($requestId === '') {
            return response()->json(['ok' => false, 'message' => 'Missing request_id.'], 422);
        }

        $job = StudioAnimationJob::query()
            ->where('provider', $provider)
            ->where('provider_queue_request_id', $requestId)
            ->first();
        if (! $job) {
            Log::info('[StudioAnimationWebhook] job_not_found', ['provider' => $provider, 'request_id' => $requestId]);

            return response()->json(['ok' => true, 'note' => 'no_matching_job']);
        }

        $verifiedWebhook = $sig['verified'] === true;
        $sharedOk = $sharedSecret === '' || hash_equals($sharedSecret, (string) $request->header('X-Studio-Animation-Secret', ''));

        $merged = $this->statusService->mergeProviderTelemetry($job, [
            'kind' => 'webhook',
            'normalized_provider_status' => (string) ($payload['normalized_status'] ?? 'webhook_received'),
            'provider_phase_debug' => [
                'transport' => 'webhook',
                'raw_status' => $payload['status'] ?? null,
                'verified_webhook' => $verifiedWebhook,
                'webhook_signature_method' => $sig['method'] ?? null,
                'webhook_signature_detail' => $sig['detail'] ?? null,
                'webhook_shared_secret_ok' => $sharedOk,
            ],
            'raw_response_excerpt' => $payload,
        ], $payload);
        $job->update(['provider_response_json' => $merged]);

        $settings = $job->settings_json ?? [];
        $settings['last_webhook_verified'] = $verifiedWebhook;
        $settings['last_webhook_signature_method'] = $sig['method'] ?? null;
        $job->update(['settings_json' => $settings]);
        $job->refresh();
        StudioAnimationObservability::log('webhook_ingest', $job);

        $status = strtoupper((string) ($payload['status'] ?? ''));
        $videoUrl = (string) ($payload['video_url'] ?? $payload['output_video_url'] ?? '');

        if (in_array($status, ['COMPLETED', 'COMPLETE', 'SUCCEEDED'], true) && $videoUrl !== '') {
            if (! in_array($job->status, [
                StudioAnimationStatus::Complete->value,
                StudioAnimationStatus::Failed->value,
                StudioAnimationStatus::Canceled->value,
            ], true)) {
                $settings = $job->settings_json ?? [];
                $settings['pending_finalize_remote_video_url'] = $videoUrl;
                $job->update([
                    'settings_json' => $settings,
                    'status' => StudioAnimationStatus::Downloading->value,
                ]);
                FinalizeStudioAnimationJob::dispatch($job->fresh()->id, $videoUrl)->onQueue(config('queue.ai_queue', 'ai'));
            }
        }

        return response()->json(['ok' => true]);
    }
}
