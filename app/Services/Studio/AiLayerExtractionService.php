<?php

namespace App\Services\Studio;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\StudioLayerExtractionSession;
use App\Models\Tenant;
use App\Services\AiUsageService;
use App\Services\Fal\FalModelPricingService;
use App\Services\Studio\StudioLayerExtractionMethodService;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionBoxPickProviderInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionInpaintBackgroundInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionPointPickProviderInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionPointRefineProviderInterface;
use App\Studio\LayerExtraction\Contracts\StudioLayerExtractionProviderInterface;
use App\Studio\LayerExtraction\Providers\FloodfillStudioLayerExtractionProvider;
use App\Studio\LayerExtraction\Providers\SamStudioLayerExtractionProvider;
use App\Studio\LayerExtraction\Dto\LayerExtractionCandidateDto;
use App\Studio\LayerExtraction\Dto\LayerExtractionResult;
use App\Studio\LayerExtraction\Exceptions\LocalExtractionSourceTooLargeException;
use App\Support\EditorAssetOriginalBytesLoader;
use App\Support\StudioLayerExtractionStoragePaths;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class AiLayerExtractionService
{
    /** @see https://www.php.net/manual/en/image.constants.php (\IMG_BILINEAR_FIXED) */
    private const IMAGE_SCALE_BILINEAR_FIXED = 5;

    public function __construct(
        protected FloodfillStudioLayerExtractionProvider $floodfillProvider,
        protected SamStudioLayerExtractionProvider $samProvider,
        protected StudioLayerExtractionInpaintBackgroundInterface $inpaint,
        protected AiUsageService $aiUsageService,
        protected FalModelPricingService $falModelPricing,
    ) {}

    /**
     * @see StudioLayerExtractionMethodService::METHOD_LOCAL|METHOD_AI
     */
    public function providerForSession(StudioLayerExtractionSession $session): StudioLayerExtractionProviderInterface
    {
        $m = is_array($session->metadata) ? $session->metadata : [];
        $em = $m['extraction_method'] ?? null;
        if ($em === StudioLayerExtractionMethodService::METHOD_AI) {
            return $this->samProvider;
        }
        if ($em === StudioLayerExtractionMethodService::METHOD_LOCAL) {
            return $this->floodfillProvider;
        }
        if ((string) $session->provider === 'sam') {
            return $this->samProvider;
        }

        return $this->floodfillProvider;
    }

    public function sessionSupportsBoxPick(StudioLayerExtractionSession $session): bool
    {
        $p = $this->providerForSession($session);

        return $p instanceof StudioLayerExtractionBoxPickProviderInterface
            && $this->boxPickEnabled($p);
    }

    /**
     * @internal Used by the studio modal / tests. Legacy sessions without metadata use global config.
     */
    public static function resultUsesRemoteFal(LayerExtractionResult $result): bool
    {
        foreach ($result->candidates as $c) {
            $md = $c->metadata;
            if (is_array($md) && (string) ($md['segmentation_engine'] ?? '') === 'fal_sam2') {
                return true;
            }
        }

        return false;
    }

    public static function shouldBillExtractionForSession(StudioLayerExtractionSession $session, ?LayerExtractionResult $result = null): bool
    {
        $m = is_array($session->metadata) ? $session->metadata : [];
        $em = $m['extraction_method'] ?? null;
        if ($em === null) {
            return self::shouldBillExtractionForConfig();
        }
        if ($em === StudioLayerExtractionMethodService::METHOD_LOCAL) {
            return (bool) config('studio_layer_extraction.bill_floodfill_extraction', false);
        }
        if ($em === StudioLayerExtractionMethodService::METHOD_AI) {
            if (empty($m['billable'])) {
                return false;
            }
            if ($result === null) {
                return true;
            }

            return self::resultUsesRemoteFal($result);
        }

        return false;
    }

    public function processSession(StudioLayerExtractionSession $session): void
    {
        if ($session->status !== StudioLayerExtractionSession::STATUS_PENDING) {
            return;
        }

        $started = microtime(true);
        $meta0 = is_array($session->metadata) ? $session->metadata : [];
        Log::info('[studio_layer_extraction]', [
            'session_id' => $session->id,
            'method' => $meta0['extraction_method'] ?? null,
            'provider' => $session->provider,
            'sam_enabled' => (bool) config('studio_layer_extraction.sam.enabled'),
            'fal_key_present' => filled((string) config('services.fal.key', '')),
            'status' => 'start',
        ]);
        if (($meta0['extraction_method'] ?? null) === StudioLayerExtractionMethodService::METHOD_AI) {
            $t = Tenant::query()->find($session->tenant_id);
            $b = Brand::query()->find($session->brand_id);
            if ($t === null || $b === null) {
                $this->failSession($session, 'Invalid session context.', $started, null, null, 'pre_check');

                return;
            }
            if (! app(StudioLayerExtractionMethodService::class)->isAiExtractionRuntimeAvailable($t, $b)) {
                $this->failSession(
                    $session,
                    'AI segmentation is not available. Check that STUDIO_LAYER_EXTRACTION_SAM_ENABLED is true and the Fal API key is set.',
                    $started,
                    null,
                    null,
                    'ai_runtime_unavailable'
                );

                return;
            }
        }
        $asset = Asset::query()
            ->whereKey($session->source_asset_id)
            ->where('tenant_id', $session->tenant_id)
            ->where('brand_id', $session->brand_id)
            ->first();
        if ($asset === null) {
            $this->failSession($session, 'Source asset not found.', $started, null, null, 'validate_asset');

            return;
        }

        try {
            $binary = EditorAssetOriginalBytesLoader::loadFromStorage($asset);
        } catch (Throwable $e) {
            $this->failSession($session, 'Could not read source image.', $started, $e, null, 'load_source');

            return;
        }

        $prov = $this->providerForSession($session);
        try {
            $result = $prov->extractMasks($asset, [
                'image_binary' => $binary,
            ]);
        } catch (LocalExtractionSourceTooLargeException $e) {
            $m0 = is_array($session->metadata) ? $session->metadata : [];
            if (($m0['extraction_method'] ?? null) === StudioLayerExtractionMethodService::METHOD_LOCAL) {
                $t = Tenant::query()->find($session->tenant_id);
                $b = Brand::query()->find($session->brand_id);
                if ($t !== null && $b !== null) {
                    $ms = app(StudioLayerExtractionMethodService::class);
                    $aiAvailable = $ms->isAiExtractionRuntimeAvailable($t, $b);
                    $hasCredits = $this->aiUsageService->canUseFeature($t, 'studio_layer_extraction', 1);
                    $canTryAi = $aiAvailable && $hasCredits;
                    if ($aiAvailable && $hasCredits) {
                        $userMsg = 'This image is too large for local extraction. Use AI segmentation instead, or upload a smaller image.';
                        $unavail = null;
                    } elseif ($aiAvailable && ! $hasCredits) {
                        $userMsg = 'This image is too large for local extraction. AI segmentation may handle it, but you need more credits.';
                        $unavail = 'insufficient_ai_credits';
                    } else {
                        $userMsg = 'This image is too large for local extraction. Try a smaller resolution or enable an AI segmentation provider.';
                        $unavail = $ms->aiExtractionUnavailableReasonIfAny($t, $b);
                    }
                    $extractionError = [
                        'code' => LocalExtractionSourceTooLargeException::CODE,
                        'method' => StudioLayerExtractionMethodService::METHOD_LOCAL,
                        'can_try_ai' => $canTryAi,
                        'ai_available' => $aiAvailable,
                        'ai_unavailable_reason' => $unavail,
                    ];
                    Log::warning('[studio_layer_extraction_provider]', [
                        'session_id' => $session->id,
                        'document_id' => $session->composition_id,
                        'layer_id' => $session->source_layer_id,
                        'asset_id' => $session->source_asset_id,
                        'method' => $m0['extraction_method'] ?? null,
                        'provider' => $session->provider,
                        'request_mode' => 'extract_masks',
                        'status_code' => 'local_source_too_large',
                        'extraction_error' => $extractionError,
                        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    ]);
                    $this->failSession($session, $userMsg, $started, $e, $extractionError, 'extract_masks');

                    return;
                }
            }
            $m = is_array($session->metadata) ? $session->metadata : [];
            $userMsg = $this->providerExceptionMessageForUser($e);
            Log::warning('[studio_layer_extraction_provider]', [
                'session_id' => $session->id,
                'document_id' => $session->composition_id,
                'layer_id' => $session->source_layer_id,
                'asset_id' => $session->source_asset_id,
                'method' => $m['extraction_method'] ?? null,
                'provider' => $session->provider,
                'sam_enabled' => (bool) config('studio_layer_extraction.sam.enabled'),
                'fal_key_present' => filled((string) config('services.fal.key', '')),
                'request_mode' => 'extract_masks',
                'status_code' => 'exception',
                'candidate_count' => 0,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error_class' => $e::class,
                'failure_message' => $this->sanitizedLogFragment($e->getMessage()),
            ]);
            $this->failSession($session, $userMsg, $started, $e, null, 'extract_masks');

            return;
        } catch (Throwable $e) {
            $m = is_array($session->metadata) ? $session->metadata : [];
            $userMsg = $this->providerExceptionMessageForUser($e);
            Log::warning('[studio_layer_extraction_provider]', [
                'session_id' => $session->id,
                'document_id' => $session->composition_id,
                'layer_id' => $session->source_layer_id,
                'asset_id' => $session->source_asset_id,
                'method' => $m['extraction_method'] ?? null,
                'provider' => $session->provider,
                'sam_enabled' => (bool) config('studio_layer_extraction.sam.enabled'),
                'fal_key_present' => filled((string) config('services.fal.key', '')),
                'request_mode' => 'extract_masks',
                'status_code' => 'exception',
                'candidate_count' => 0,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'error_class' => $e::class,
                'failure_message' => $this->sanitizedLogFragment($e->getMessage()),
            ]);
            $this->failSession($session, $userMsg, $started, $e, null, 'extract_masks');

            return;
        }

        try {
            $stored = $this->persistCandidateArtifacts($session->id, $result);
        } catch (Throwable $e) {
            $this->failSession($session, 'Failed to stage extraction files.', $started, $e, null, 'persist_artifacts');

            return;
        }

        $usedRemoteFal = self::resultUsesRemoteFal($result);
        $remoteSegProvider = $usedRemoteFal
            ? (string) config('studio_layer_extraction.sam.sam_provider', 'fal')
            : 'floodfill';
        $tenant = Tenant::query()->find($session->tenant_id);
        if ($tenant !== null && self::shouldBillExtractionForSession($session, $result)) {
            $this->aiUsageService->tryBillStudioLayerExtraction(
                $tenant,
                $session,
                (string) ($result->model ?? ''),
                $remoteSegProvider,
            );
        }

        $session->refresh();

        $bgSupported = $this->backgroundFillCapable();

        $segProvider = $remoteSegProvider;
        $charged = self::shouldBillExtractionForSession($session, $result);

        $baseMeta = is_array($session->metadata) ? $session->metadata : [];
        $meta = array_merge($baseMeta, [
            'used_remote_sam' => $usedRemoteFal,
            'segmentation_engine_provider' => $segProvider,
            'ai_credit_key' => 'studio_layer_extraction',
            'billable' => $charged,
            'model' => $result->model,
            'estimated_provider_cost_usd' => $this->falModelPricing->estimatedCostUsd(),
            'provider_cost_source' => $this->falModelPricing->costSource(),
        ]);
        $session->setAttribute('metadata', $meta);

        $session->update([
            'status' => StudioLayerExtractionSession::STATUS_READY,
            'provider' => $result->provider,
            'model' => $result->model,
            'candidates_json' => json_encode($stored, JSON_THROW_ON_ERROR),
            'error_message' => null,
            'metadata' => array_merge($meta, [
                'provider_capabilities' => $this->providerCapabilities($session),
                'background_fill_supported' => $bgSupported,
            ]),
        ]);

        $mDone = is_array($session->metadata) ? $session->metadata : [];
        Log::info('[studio_layer_extraction]', [
            'session_id' => $session->id,
            'method' => $mDone['extraction_method'] ?? null,
            'document_id' => $session->composition_id,
            'layer_id' => $session->source_layer_id,
            'asset_id' => $session->source_asset_id,
            'provider' => $result->provider,
            'sam_enabled' => (bool) config('studio_layer_extraction.sam.enabled'),
            'fal_key_present' => filled((string) config('services.fal.key', '')),
            'candidate_count' => count($stored),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'status' => 'ready',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function persistCandidateArtifacts(string $sessionId, LayerExtractionResult $result): array
    {
        $disk = Storage::disk('studio_layer_extraction');
        $sessionDir = StudioLayerExtractionStoragePaths::sessionDirectory($sessionId);
        $disk->makeDirectory($sessionDir);

        $out = [];
        foreach ($result->candidates as $c) {
            $maskBinary = null;
            if ($c->maskBase64 !== null && $c->maskBase64 !== '') {
                $maskBinary = base64_decode($c->maskBase64, true);
            }
            if ($maskBinary === false || $maskBinary === null || $maskBinary === '') {
                continue;
            }

            $maskRel = StudioLayerExtractionStoragePaths::relative($sessionId, $c->id.'_mask.png');
            $disk->put($maskRel, $maskBinary);

            $previewBinary = $this->buildPreviewPng($maskBinary, 256);
            $previewRel = StudioLayerExtractionStoragePaths::relative($sessionId, $c->id.'_preview.png');
            $disk->put($previewRel, $previewBinary);

            $out[] = [
                'id' => $c->id,
                'label' => $c->label,
                'confidence' => $c->confidence,
                'bbox' => $c->bbox,
                'mask_relative' => $maskRel,
                'preview_relative' => $previewRel,
                'selected' => $c->selected,
                'notes' => $c->notes,
                'metadata' => $c->metadata,
            ];
        }

        if ($out === []) {
            throw new \RuntimeException('No candidates produced.');
        }

        return $out;
    }

    /**
     * @return non-empty-string
     */
    private function buildPreviewPng(string $maskPng, int $maxEdge): string
    {
        $im = @imagecreatefromstring($maskPng);
        if ($im === false) {
            return $maskPng;
        }
        if (! imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 2 || $h < 2) {
            imagedestroy($im);

            return $maskPng;
        }
        $scale = min(1.0, $maxEdge / max($w, $h));
        $nw = max(2, (int) round($w * $scale));
        $nh = max(2, (int) round($h * $scale));
        $sm = imagescale($im, $nw, $nh, self::IMAGE_SCALE_BILINEAR_FIXED);
        imagedestroy($im);
        if ($sm === false) {
            return $maskPng;
        }
        ob_start();
        imagepng($sm);
        $bin = (string) ob_get_clean();
        imagedestroy($sm);

        return $bin !== '' ? $bin : $maskPng;
    }

    /**
     * Prefer a concrete error (GD/Imagick/disk) over a generic string so the modal is actionable.
     * Hides file paths in production; with APP_DEBUG the raw message is shown.
     */
    private function providerExceptionMessageForUser(Throwable $e): string
    {
        $fallback = 'Extraction could not be completed. Try a different image or try again later.';
        if (config('app.debug', false)) {
            $msg = trim($e->getMessage());

            return $msg !== '' ? $msg : $fallback;
        }
        $msg = trim($e->getMessage());
        if (str_starts_with($msg, 'AI segmentation ')) {
            return $msg;
        }
        if (str_contains($msg, 'The segmentation service returned no usable masks')
            || str_contains($msg, 'The segmentation service did not return a usable mask image')) {
            return 'AI segmentation found no separable elements. Try Pick point or Draw box.';
        }
        if (preg_match('/cURL error 28|Connection timed out|Operation timed out|timed out|timed out\./i', $msg)) {
            return 'AI segmentation timed out. Try Draw box or Local mask detection.';
        }
        if ($msg === '' || strlen($msg) > 600) {
            return $fallback;
        }
        if (preg_match('#/[^\s]{3,}:\d+#', $msg)
            || preg_match('#\\\\[A-Za-z]+:\\d+#', $msg)
            || preg_match('#[A-Za-z]:\\\\[^\n]+#', $msg)) {
            return $fallback;
        }
        if (stripos($msg, 'allowed memory') !== false
            || stripos($msg, 'memory size') !== false) {
            return 'This file is too large to process. Try a smaller or lower resolution image first.';
        }
        // Most extension errors (e.g. ImagickException) extend \Exception, not \RuntimeException.
        if ($e instanceof \Exception) {
            return $msg;
        }
        // PHP 8+ engine errors the provider may surface
        if ($e instanceof \TypeError || $e instanceof \ValueError) {
            return $msg;
        }

        return $fallback;
    }

    /**
     * Merges `extraction_error` (method-aware codes) for API/Operations use.
     *
     * @param  array{code: string, method: string, can_try_ai: bool, ai_available: bool, ai_unavailable_reason: string|null}|null  $extractionError
     */
    private function failSession(
        StudioLayerExtractionSession $session,
        string $message,
        float $startedAt,
        ?Throwable $cause = null,
        ?array $extractionError = null,
        ?string $failureStage = null,
    ): void {
        $m = is_array($session->metadata) ? $session->metadata : [];
        if ($extractionError !== null) {
            $m['extraction_error'] = $extractionError;
        }
        if ($failureStage !== null || $cause !== null) {
            $m['failure_detail'] = array_filter([
                'stage' => $failureStage,
                'exception_class' => $cause ? $cause::class : null,
                'exception_message' => $cause
                    ? $this->sanitizedLogFragment($cause->getMessage(), 2000)
                    : null,
                'storage_disk' => (string) config('filesystems.disks.studio_layer_extraction.driver', 'local'),
            ], fn ($v) => $v !== null && $v !== '');
        }
        $session->update([
            'status' => StudioLayerExtractionSession::STATUS_FAILED,
            'error_message' => $message,
            'metadata' => $m,
        ]);

        $m2 = is_array($session->metadata) ? $session->metadata : [];
        Log::error('studio_layer_extraction.session_failed', [
            'session_id' => $session->id,
            'failure_stage' => $failureStage,
            'method' => $m2['extraction_method'] ?? null,
            'document_id' => $session->composition_id,
            'layer_id' => $session->source_layer_id,
            'asset_id' => $session->source_asset_id,
            'provider' => $session->provider,
            'tenant_id' => $session->tenant_id,
            'user_id' => $session->user_id,
            'storage_disk' => (string) config('filesystems.disks.studio_layer_extraction.driver', 'local'),
            'sam_enabled' => (bool) config('studio_layer_extraction.sam.enabled'),
            'fal_key_present' => filled((string) config('services.fal.key', '')),
            'candidate_count' => 0,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'status' => 'failed',
            'user_facing_message' => $this->sanitizedLogFragment($message, 2000),
            'failure_message' => $this->sanitizedLogFragment($cause?->getMessage() ?? $message, 2000),
            'exception_class' => $cause ? $cause::class : null,
        ]);
    }

    /**
     * @return array<string, string|bool|null>
     */
    public function extractionFailureFieldsForApi(StudioLayerExtractionSession $session): array
    {
        $m = is_array($session->metadata) ? $session->metadata : [];
        $e = $m['extraction_error'] ?? null;
        if (! is_array($e) || (string) ($e['code'] ?? '') === '') {
            return [];
        }

        return [
            'code' => (string) $e['code'],
            'method' => (string) ($e['method'] ?? 'local'),
            'can_try_ai' => (bool) ($e['can_try_ai'] ?? false),
            'ai_available' => (bool) ($e['ai_available'] ?? false),
            'ai_unavailable_reason' => isset($e['ai_unavailable_reason']) && is_string($e['ai_unavailable_reason']) ? $e['ai_unavailable_reason'] : null,
        ];
    }

    private function sanitizedLogFragment(string $raw, int $maxLen = 400): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $raw) ?? '');
        if (strlen($s) > $maxLen) {
            $s = substr($s, 0, $maxLen).'…';
        }

        return $s;
    }

    /**
     * @return array{
     *   supports_multiple_masks: bool,
     *   supports_background_fill: bool,
     *   supports_labels: bool,
     *   supports_confidence: bool,
     *   supports_point_pick: bool,
     *   supports_point_refine: bool,
     *   supports_box_pick: bool,
     *   uses_ai_segmentation: bool,
     *   extraction_method?: 'local'|'ai',
     *   segmentation_engine_provider?: 'fal'|'floodfill',
     * }
     */
    public function providerCapabilities(StudioLayerExtractionSession $session): array
    {
        $p = $this->providerForSession($session);
        $refine = $this->pointRefineEnabled($p);
        $boxPick = $this->boxPickEnabled($p);

        $m = is_array($session->metadata) ? $session->metadata : [];
        $em = $m['extraction_method'] ?? null;
        if ($em === StudioLayerExtractionMethodService::METHOD_AI) {
            if ($session->status === StudioLayerExtractionSession::STATUS_READY) {
                $usesAi = (bool) ($m['used_remote_sam'] ?? false);
            } elseif ($session->status === StudioLayerExtractionSession::STATUS_FAILED) {
                $usesAi = false;
            } else {
                $usesAi = true;
            }
        } elseif ($em === StudioLayerExtractionMethodService::METHOD_LOCAL) {
            $usesAi = false;
        } else {
            $usesAi = self::usesAiRemoteSegmentation();
        }

        $out = [
            'supports_multiple_masks' => $p->supportsMultipleMasks(),
            'supports_background_fill' => $this->backgroundFillCapable(),
            'supports_labels' => $p->supportsLabels(),
            'supports_confidence' => $p->supportsConfidence(),
            'supports_point_pick' => $p instanceof StudioLayerExtractionPointPickProviderInterface,
            'supports_point_refine' => $refine,
            'supports_box_pick' => $boxPick,
            'uses_ai_segmentation' => $usesAi,
        ];
        if (is_string($em) && in_array($em, [StudioLayerExtractionMethodService::METHOD_LOCAL, StudioLayerExtractionMethodService::METHOD_AI], true)) {
            $out['extraction_method'] = $em;
        }
        if (isset($m['segmentation_engine_provider']) && in_array($m['segmentation_engine_provider'], ['fal', 'floodfill'], true)) {
            $out['segmentation_engine_provider'] = (string) $m['segmentation_engine_provider'];
        }

        return $out;
    }

    /**
     * True when provider is SAM with a configured remote driver and API key (Fal, future Replicate, etc.).
     */
    public static function usesAiRemoteSegmentation(): bool
    {
        if ((string) config('studio_layer_extraction.provider', 'floodfill') !== 'sam') {
            return false;
        }
        if (! (bool) config('studio_layer_extraction.sam.enabled', false)) {
            return false;
        }
        $driver = (string) config('studio_layer_extraction.sam.sam_provider', 'fal');
        if ($driver === 'fal' && filled((string) config('services.fal.key'))) {
            return true;
        }
        if ($driver === 'replicate' && filled((string) config('services.replicate.api_token'))) {
            return \Illuminate\Support\Facades\App::make(
                \App\Studio\LayerExtraction\Contracts\SamSegmentationClientInterface::class
            )->isAvailable();
        }

        return false;
    }

    private function backgroundFillCapable(): bool
    {
        if (! (bool) config('studio_layer_extraction.inpaint_enabled', false)) {
            return false;
        }

        return $this->inpaint->supportsBackgroundFill();
    }

    /**
     * Bill successful extraction: SAM (when enabled) and non-floodfill providers; optional floodfill via
     * `bill_floodfill_extraction`. If `provider` is `sam` but `sam.enabled` is false, the app still uses
     * the floodfill engine — bill only when `bill_floodfill_extraction` is on.
     */
    public static function shouldBillExtractionForConfig(): bool
    {
        $p = (string) config('studio_layer_extraction.provider', 'floodfill');
        if ($p === 'floodfill') {
            return (bool) config('studio_layer_extraction.bill_floodfill_extraction', false);
        }
        if ($p === 'sam' && ! (bool) config('studio_layer_extraction.sam.enabled', false)) {
            return (bool) config('studio_layer_extraction.bill_floodfill_extraction', false);
        }

        return true;
    }

    private function pointRefineEnabled(StudioLayerExtractionProviderInterface $provider): bool
    {
        if (! $provider instanceof StudioLayerExtractionPointRefineProviderInterface) {
            return false;
        }
        if ($provider instanceof SamStudioLayerExtractionProvider) {
            return (bool) config('studio_layer_extraction.sam.refine_enabled', true);
        }

        return (bool) config('studio_layer_extraction.local_floodfill.refine_enabled', true);
    }

    private function boxPickEnabled(StudioLayerExtractionProviderInterface $provider): bool
    {
        if (! $provider instanceof StudioLayerExtractionBoxPickProviderInterface) {
            return false;
        }
        if ($provider instanceof SamStudioLayerExtractionProvider) {
            return (bool) config('studio_layer_extraction.sam.box_pick_enabled', true);
        }

        return (bool) config('studio_layer_extraction.local_floodfill.box_pick_enabled', true);
    }

    private function assertSessionManualAiToolsIfNeeded(StudioLayerExtractionSession $session): void
    {
        $m = is_array($session->metadata) ? $session->metadata : [];
        if (($m['extraction_method'] ?? null) !== StudioLayerExtractionMethodService::METHOD_AI) {
            return;
        }
        $tenant = Tenant::query()->find($session->tenant_id);
        $brand = Brand::query()->find($session->brand_id);
        if ($tenant === null || $brand === null) {
            throw new InvalidArgumentException('Invalid session context.');
        }
        if (! app(StudioLayerExtractionMethodService::class)->isAiExtractionRuntimeAvailable($tenant, $brand)) {
            throw new InvalidArgumentException('AI segmentation is not available. Check that STUDIO_LAYER_EXTRACTION_SAM_ENABLED is true and the Fal API key is set.');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sessionRowSupportsPointRefine(array $row): bool
    {
        $m = isset($row['metadata']) && is_array($row['metadata']) ? $row['metadata'] : [];
        $id = (string) ($row['id'] ?? '');
        if (($m['segmentation_engine'] ?? null) === 'fal_sam2') {
            return str_starts_with($id, 'pick_') || str_starts_with($id, 'box_');
        }
        if (str_starts_with($id, 'pick_')) {
            $mt = (string) ($m['method'] ?? '');

            return in_array($mt, ['local_seed_floodfill', 'local_seed_floodfill_refined'], true);
        }
        if (str_starts_with($id, 'box_')) {
            $mt = (string) ($m['method'] ?? '');

            return in_array(
                $mt,
                [
                    'local_box_floodfill', 'local_box_floodfill_refined',
                    'local_box_rect_cutout',
                    'local_box_text_graphic', 'local_box_text_graphic_refined',
                ],
                true
            );
        }

        return false;
    }

    public function decorateCandidatesForApi(StudioLayerExtractionSession $session, array $stored): array
    {
        $mSession = is_array($session->metadata) ? $session->metadata : [];
        $em = $mSession['extraction_method'] ?? null;
        $out = [];
        foreach ($stored as $row) {
            $im = isset($row['metadata']) && is_array($row['metadata']) ? $row['metadata'] : [];
            $eng = (string) ($im['segmentation_engine'] ?? '');
            $rawNotes = isset($row['notes']) ? (string) $row['notes'] : null;
            $displayNotes = $rawNotes;
            if ($eng === 'fal_sam2' || str_starts_with((string) ($im['method'] ?? ''), 'fal_sam2')) {
                $displayNotes = 'AI segmentation';
            } elseif (
                str_starts_with((string) ($im['method'] ?? ''), 'local_')
                || (string) ($im['provider'] ?? '') === 'floodfill'
            ) {
                $displayNotes = 'Local mask detection';
            }
            $out[] = [
                'id' => $row['id'],
                'label' => $row['label'] ?? null,
                'confidence' => $row['confidence'] ?? null,
                'bbox' => $row['bbox'],
                'selected' => (bool) ($row['selected'] ?? true),
                'notes' => $displayNotes,
                'metadata' => isset($row['metadata']) && is_array($row['metadata']) ? $row['metadata'] : null,
                'preview_url' => route('app.studio.layer-extraction-sessions.preview', [
                    'session' => $session->id,
                    'candidate' => $row['id'],
                ], absolute: true),
            ];
        }

        return $out;
    }

    /**
     * @return array{
     *   status: string,
     *   extraction_session_id: string,
     *   candidates: list<array<string, mixed>>,
     *   new_candidate: ?array<string, mixed>,
     *   warning: ?string,
     *   provider_capabilities: array<string, bool>
     * }
     */
    public function appendPointPickCandidate(StudioLayerExtractionSession $session, float $xNorm, float $yNorm): array
    {
        if ($session->status !== StudioLayerExtractionSession::STATUS_READY) {
            throw new InvalidArgumentException('Extraction session is not ready for point picking.');
        }
        $this->assertSessionManualAiToolsIfNeeded($session);
        $prov = $this->providerForSession($session);
        if (! $prov instanceof StudioLayerExtractionPointPickProviderInterface) {
            throw new InvalidArgumentException('Point picking is not available for the current provider.');
        }
        if ($xNorm < 0.0 || $xNorm > 1.0 || $yNorm < 0.0 || $yNorm > 1.0) {
            throw new InvalidArgumentException('Coordinates must be between 0 and 1.');
        }

        $asset = Asset::query()
            ->whereKey($session->source_asset_id)
            ->where('tenant_id', $session->tenant_id)
            ->where('brand_id', $session->brand_id)
            ->first();
        if ($asset === null) {
            throw new InvalidArgumentException('Source asset not found.');
        }

        $stored = json_decode((string) $session->candidates_json, true);
        if (! is_array($stored)) {
            $stored = [];
        }

        $existingBboxes = [];
        foreach ($stored as $row) {
            if (isset($row['bbox']) && is_array($row['bbox'])) {
                $existingBboxes[] = $row['bbox'];
            }
        }

        $pickCount = 0;
        foreach ($stored as $row) {
            $rid = (string) ($row['id'] ?? '');
            if (str_starts_with($rid, 'pick_')) {
                $pickCount++;
            }
        }
        $label = $pickCount === 0 ? 'Picked element' : 'Picked element '.($pickCount + 1);
        $candidateId = 'pick_'.(string) Str::uuid();

        try {
            $binary = EditorAssetOriginalBytesLoader::loadFromStorage($asset);
        } catch (Throwable) {
            throw new InvalidArgumentException('Could not read source image for point pick.');
        }

        $cand = $prov->extractCandidateFromPoint($asset, $xNorm, $yNorm, [
            'image_binary' => $binary,
            'label' => $label,
            'candidate_id' => $candidateId,
            'existing_bboxes' => $existingBboxes,
            'extraction_method' => is_array($session->metadata) ? ($session->metadata['extraction_method'] ?? null) : null,
        ]);

        if ($cand === null) {
            return $this->pointPickResponse(
                $session,
                $stored,
                null,
                'No separable element at that point. Try clicking a distinct foreground area, or a different region.'
            );
        }

        try {
            $row = $this->persistOneCandidateRow($session->id, $cand);
        } catch (Throwable) {
            throw new InvalidArgumentException('Failed to stage the picked region mask.');
        }
        $stored[] = $row;
        $session->update([
            'candidates_json' => json_encode($stored, JSON_THROW_ON_ERROR),
        ]);

        return $this->pointPickResponse($session->fresh() ?? $session, $stored, $row, null);
    }

    /**
     * @param  array{x: float, y: float, width: float, height: float}  $boxNorm
     * @return array{status: string, extraction_session_id: string, candidates: list<array<string, mixed>>, new_candidate: ?array<string, mixed>, warning: ?string, provider_capabilities: array<string, bool>}
     */
    public function appendBoxPickCandidate(StudioLayerExtractionSession $session, array $boxNorm, string $mode): array
    {
        if ($session->status !== StudioLayerExtractionSession::STATUS_READY) {
            throw new InvalidArgumentException('Extraction session is not ready for box selection.');
        }
        $prov = $this->providerForSession($session);
        if (! $this->boxPickEnabled($prov)) {
            throw new InvalidArgumentException('Box selection is not available for the current configuration.');
        }
        if (! $prov instanceof StudioLayerExtractionBoxPickProviderInterface) {
            throw new InvalidArgumentException('Box selection is not available for the current provider.');
        }
        if (! isset($boxNorm['x'], $boxNorm['y'], $boxNorm['width'], $boxNorm['height'])) {
            throw new InvalidArgumentException('Box must include x, y, width, and height.');
        }
        if ($boxNorm['width'] <= 0.0 || $boxNorm['height'] <= 0.0) {
            throw new InvalidArgumentException('Box width and height must be positive.');
        }
        if ($boxNorm['x'] < 0.0 || $boxNorm['x'] > 1.0 || $boxNorm['y'] < 0.0 || $boxNorm['y'] > 1.0) {
            throw new InvalidArgumentException('Box origin must be between 0 and 1.');
        }
        if ($boxNorm['x'] + $boxNorm['width'] > 1.0001 || $boxNorm['y'] + $boxNorm['height'] > 1.0001) {
            throw new InvalidArgumentException('Box must fit within the image (0 to 1).');
        }
        if (! in_array($mode, ['object', 'text_graphic'], true)) {
            throw new InvalidArgumentException('Mode must be object or text_graphic.');
        }
        $this->assertSessionManualAiToolsIfNeeded($session);

        $asset = Asset::query()
            ->whereKey($session->source_asset_id)
            ->where('tenant_id', $session->tenant_id)
            ->where('brand_id', $session->brand_id)
            ->first();
        if ($asset === null) {
            throw new InvalidArgumentException('Source asset not found.');
        }

        $stored = json_decode((string) $session->candidates_json, true);
        if (! is_array($stored)) {
            $stored = [];
        }

        $boxCount = 0;
        foreach ($stored as $row) {
            if (str_starts_with((string) ($row['id'] ?? ''), 'box_')) {
                $boxCount++;
            }
        }
        $label = $mode === 'text_graphic' ? 'Selected text/graphic' : 'Box-selected element';
        if ($boxCount > 0) {
            $label = $mode === 'text_graphic'
                ? 'Selected text/graphic '.($boxCount + 1)
                : 'Box-selected element '.($boxCount + 1);
        }
        $candidateId = 'box_'.(string) Str::uuid();

        try {
            $binary = EditorAssetOriginalBytesLoader::loadFromStorage($asset);
        } catch (Throwable) {
            throw new InvalidArgumentException('Could not read source image for box selection.');
        }

        $cand = $prov->extractCandidateFromBox($asset, $boxNorm, [
            'image_binary' => $binary,
            'label' => $label,
            'candidate_id' => $candidateId,
            'mode' => $mode,
            'extraction_method' => is_array($session->metadata) ? ($session->metadata['extraction_method'] ?? null) : null,
        ]);

        if ($cand === null) {
            if ($mode === 'text_graphic') {
                $warn = 'No text or graphic edges found in that box.';
            } else {
                $warn = (bool) config('studio_layer_extraction.local_floodfill.box_fallback_rectangle', true)
                    ? 'No clear foreground in that area. Try a different box or use point-pick. If rectangle fallback is disabled, the region may be too small or too large.'
                    : 'No useful region in that box. Try adjusting the size, enable rectangle cutout, or use point-pick.';
            }

            return $this->pointPickResponse(
                $session,
                $stored,
                null,
                $warn
            );
        }

        try {
            $row = $this->persistOneCandidateRow($session->id, $cand);
        } catch (Throwable) {
            throw new InvalidArgumentException('Failed to stage the box region mask.');
        }
        $stored[] = $row;
        $session->update([
            'candidates_json' => json_encode($stored, JSON_THROW_ON_ERROR),
        ]);

        return $this->pointPickResponse($session->fresh() ?? $session, $stored, $row, null);
    }

    public function removeCandidate(StudioLayerExtractionSession $session, string $candidateId): void
    {
        if ($session->status !== StudioLayerExtractionSession::STATUS_READY) {
            throw new InvalidArgumentException('Extraction session is not ready.');
        }
        if (! str_starts_with($candidateId, 'pick_') && ! str_starts_with($candidateId, 'box_')) {
            throw new InvalidArgumentException('Only manual (pick or box) candidates can be removed this way.');
        }
        $stored = json_decode((string) $session->candidates_json, true);
        if (! is_array($stored)) {
            throw new InvalidArgumentException('Invalid session candidate data.');
        }
        $disk = Storage::disk('studio_layer_extraction');
        $kept = [];
        foreach ($stored as $row) {
            if ((string) ($row['id'] ?? '') === $candidateId) {
                $mr = (string) ($row['mask_relative'] ?? '');
                $pr = (string) ($row['preview_relative'] ?? '');
                foreach ([$mr, $pr] as $rel) {
                    if ($rel !== '' && $disk->exists($rel)) {
                        try {
                            $disk->delete($rel);
                        } catch (Throwable) {
                        }
                    }
                }

                continue;
            }
            $kept[] = $row;
        }
        if (count($kept) === count($stored)) {
            throw new InvalidArgumentException('Candidate not found in session.');
        }
        $session->update(['candidates_json' => json_encode($kept, JSON_THROW_ON_ERROR)]);
    }

    public function clearManualCandidates(StudioLayerExtractionSession $session): void
    {
        if ($session->status !== StudioLayerExtractionSession::STATUS_READY) {
            throw new InvalidArgumentException('Extraction session is not ready.');
        }
        $stored = json_decode((string) $session->candidates_json, true);
        if (! is_array($stored)) {
            return;
        }
        $disk = Storage::disk('studio_layer_extraction');
        $kept = [];
        foreach ($stored as $row) {
            $id = (string) ($row['id'] ?? '');
            if (str_starts_with($id, 'pick_') || str_starts_with($id, 'box_')) {
                $mr = (string) ($row['mask_relative'] ?? '');
                $pr = (string) ($row['preview_relative'] ?? '');
                foreach ([$mr, $pr] as $rel) {
                    if ($rel !== '' && $disk->exists($rel)) {
                        try {
                            $disk->delete($rel);
                        } catch (Throwable) {
                        }
                    }
                }

                continue;
            }
            $kept[] = $row;
        }
        $session->update(['candidates_json' => json_encode($kept, JSON_THROW_ON_ERROR)]);
    }

    public function clearPickedCandidates(StudioLayerExtractionSession $session): void
    {
        if ($session->status !== StudioLayerExtractionSession::STATUS_READY) {
            throw new InvalidArgumentException('Extraction session is not ready.');
        }
        $stored = json_decode((string) $session->candidates_json, true);
        if (! is_array($stored)) {
            return;
        }
        $disk = Storage::disk('studio_layer_extraction');
        $kept = [];
        foreach ($stored as $row) {
            $id = (string) ($row['id'] ?? '');
            if (str_starts_with($id, 'pick_')) {
                $mr = (string) ($row['mask_relative'] ?? '');
                $pr = (string) ($row['preview_relative'] ?? '');
                foreach ([$mr, $pr] as $rel) {
                    if ($rel !== '' && $disk->exists($rel)) {
                        try {
                            $disk->delete($rel);
                        } catch (Throwable) {
                        }
                    }
                }

                continue;
            }
            $kept[] = $row;
        }
        $session->update(['candidates_json' => json_encode($kept, JSON_THROW_ON_ERROR)]);
    }

    /**
     * @return array{
     *   status: string,
     *   extraction_session_id: string,
     *   candidates: list<array<string, mixed>>,
     *   updated_candidate: ?array<string, mixed>,
     *   warning: ?string,
     *   provider_capabilities: array<string, bool>
     * }
     */
    public function appendRefineNegativePoint(StudioLayerExtractionSession $session, string $candidateId, float $xNorm, float $yNorm): array
    {
        if ($session->status !== StudioLayerExtractionSession::STATUS_READY) {
            throw new InvalidArgumentException('Extraction session is not ready for refinement.');
        }
        $this->assertSessionManualAiToolsIfNeeded($session);
        $prov = $this->providerForSession($session);
        if (! $this->pointRefineEnabled($prov)) {
            throw new InvalidArgumentException('Point refinement is disabled.');
        }
        if (! $prov instanceof StudioLayerExtractionPointRefineProviderInterface) {
            throw new InvalidArgumentException('Point refinement is not available for the current provider.');
        }
        if (! str_starts_with($candidateId, 'pick_') && ! str_starts_with($candidateId, 'box_')) {
            throw new InvalidArgumentException('Only point- or box-selected candidates can be refined.');
        }
        if ($xNorm < 0.0 || $xNorm > 1.0 || $yNorm < 0.0 || $yNorm > 1.0) {
            throw new InvalidArgumentException('Coordinates must be between 0 and 1.');
        }

        $maxNeg = max(0, (int) config('studio_layer_extraction.local_floodfill.max_negative_points', 8));

        $asset = Asset::query()
            ->whereKey($session->source_asset_id)
            ->where('tenant_id', $session->tenant_id)
            ->where('brand_id', $session->brand_id)
            ->first();
        if ($asset === null) {
            throw new InvalidArgumentException('Source asset not found.');
        }

        $stored = json_decode((string) $session->candidates_json, true);
        if (! is_array($stored)) {
            throw new InvalidArgumentException('Invalid session candidate data.');
        }
        $row = null;
        $idx = -1;
        foreach ($stored as $i => $r) {
            if ((string) ($r['id'] ?? '') === $candidateId) {
                $row = $r;
                $idx = $i;
                break;
            }
        }
        if ($row === null || $idx < 0) {
            throw new InvalidArgumentException('Candidate not found in this session.');
        }
        if (! $this->sessionRowSupportsPointRefine($row)) {
            throw new InvalidArgumentException('This candidate cannot be refined. Try Pick point or Draw box again.');
        }

        $meta = isset($row['metadata']) && is_array($row['metadata']) ? $row['metadata'] : [];
        $positive = $this->normalizePositivePointsFromMetadata($meta);
        if ($positive === []) {
            throw new InvalidArgumentException('Candidate has no positive seed metadata.');
        }

        $negs = [];
        if (isset($meta['negative_points']) && is_array($meta['negative_points'])) {
            foreach ($meta['negative_points'] as $n) {
                if (is_array($n) && isset($n['x'], $n['y']) && is_numeric($n['x']) && is_numeric($n['y'])) {
                    $negs[] = ['x' => (float) $n['x'], 'y' => (float) $n['y']];
                }
            }
        }
        if (count($negs) >= $maxNeg) {
            throw new InvalidArgumentException('Maximum number of exclude points reached for this candidate.');
        }
        $negs[] = ['x' => $xNorm, 'y' => $yNorm];

        try {
            $binary = EditorAssetOriginalBytesLoader::loadFromStorage($asset);
        } catch (Throwable) {
            throw new InvalidArgumentException('Could not read source image for refinement.');
        }

        $dto = $this->layerExtractionCandidateDtoFromSessionRow($row);
        $mSession = is_array($session->metadata) ? $session->metadata : [];
        $em = $mSession['extraction_method'] ?? null;
        $refined = $prov->refineCandidateWithPoints($asset, $dto, $positive, $negs, [
            'image_binary' => $binary,
            'extraction_method' => $em,
        ]);

        if ($refined === null) {
            $api = $this->decorateCandidatesForApi($session, $stored);

            return [
                'status' => (string) $session->status,
                'extraction_session_id' => (string) $session->id,
                'candidates' => $api,
                'updated_candidate' => null,
                'warning' => 'That exclusion removed too much of the element. Try a different point.',
                'provider_capabilities' => $this->providerCapabilities($session),
            ];
        }

        $this->deleteSessionCandidateMaskFiles($session->id, $row);
        $newRow = $this->persistOneCandidateRow($session->id, $refined);
        $stored[$idx] = $newRow;
        $session->update([
            'candidates_json' => json_encode($stored, JSON_THROW_ON_ERROR),
        ]);

        $fresh = $session->fresh() ?? $session;
        $api = $this->decorateCandidatesForApi($fresh, $stored);
        $updatedApi = null;
        foreach ($api as $c) {
            if ((string) ($c['id'] ?? '') === $candidateId) {
                $updatedApi = $c;
                break;
            }
        }

        return [
            'status' => (string) $fresh->status,
            'extraction_session_id' => (string) $fresh->id,
            'candidates' => $api,
            'updated_candidate' => $updatedApi,
            'warning' => null,
            'provider_capabilities' => $this->providerCapabilities($fresh),
        ];
    }

    /**
     * @return array{
     *   status: string,
     *   extraction_session_id: string,
     *   candidates: list<array<string, mixed>>,
     *   updated_candidate: ?array<string, mixed>,
     *   warning: ?string,
     *   provider_capabilities: array<string, bool>
     * }
     */
    public function appendRefinePositivePoint(StudioLayerExtractionSession $session, string $candidateId, float $xNorm, float $yNorm): array
    {
        if ($session->status !== StudioLayerExtractionSession::STATUS_READY) {
            throw new InvalidArgumentException('Extraction session is not ready for refinement.');
        }
        $this->assertSessionManualAiToolsIfNeeded($session);
        $prov = $this->providerForSession($session);
        if (! $this->pointRefineEnabled($prov)) {
            throw new InvalidArgumentException('Point refinement is disabled.');
        }
        if (! $prov instanceof StudioLayerExtractionPointRefineProviderInterface) {
            throw new InvalidArgumentException('Point refinement is not available for the current provider.');
        }
        if (! str_starts_with($candidateId, 'pick_') && ! str_starts_with($candidateId, 'box_')) {
            throw new InvalidArgumentException('Only point- or box-selected candidates can be refined.');
        }
        if ($xNorm < 0.0 || $xNorm > 1.0 || $yNorm < 0.0 || $yNorm > 1.0) {
            throw new InvalidArgumentException('Coordinates must be between 0 and 1.');
        }

        $maxPos = max(1, (int) config('studio_layer_extraction.local_floodfill.max_positive_refine_points', 8));
        $asset = Asset::query()
            ->whereKey($session->source_asset_id)
            ->where('tenant_id', $session->tenant_id)
            ->where('brand_id', $session->brand_id)
            ->first();
        if ($asset === null) {
            throw new InvalidArgumentException('Source asset not found.');
        }

        $stored = json_decode((string) $session->candidates_json, true);
        if (! is_array($stored)) {
            throw new InvalidArgumentException('Invalid session candidate data.');
        }
        $row = null;
        $idx = -1;
        foreach ($stored as $i => $r) {
            if ((string) ($r['id'] ?? '') === $candidateId) {
                $row = $r;
                $idx = $i;
                break;
            }
        }
        if ($row === null || $idx < 0) {
            throw new InvalidArgumentException('Candidate not found in this session.');
        }
        if (! $this->sessionRowSupportsPointRefine($row)) {
            throw new InvalidArgumentException('This candidate cannot be refined. Try Pick point or Draw box again.');
        }

        $meta = isset($row['metadata']) && is_array($row['metadata']) ? $row['metadata'] : [];
        $positive = $this->normalizePositivePointsFromMetadata($meta);
        if ($positive === []) {
            throw new InvalidArgumentException('Candidate has no positive seed metadata.');
        }
        if (count($positive) >= $maxPos) {
            throw new InvalidArgumentException('Maximum number of include points reached for this candidate.');
        }
        $positive[] = ['x' => $xNorm, 'y' => $yNorm];

        $negs = [];
        if (isset($meta['negative_points']) && is_array($meta['negative_points'])) {
            foreach ($meta['negative_points'] as $n) {
                if (is_array($n) && isset($n['x'], $n['y']) && is_numeric($n['x']) && is_numeric($n['y'])) {
                    $negs[] = ['x' => (float) $n['x'], 'y' => (float) $n['y']];
                }
            }
        }

        try {
            $binary = EditorAssetOriginalBytesLoader::loadFromStorage($asset);
        } catch (Throwable) {
            throw new InvalidArgumentException('Could not read source image for refinement.');
        }

        $dto = $this->layerExtractionCandidateDtoFromSessionRow($row);
        $mSession = is_array($session->metadata) ? $session->metadata : [];
        $em = $mSession['extraction_method'] ?? null;
        $refined = $prov->refineCandidateWithPoints($asset, $dto, $positive, $negs, [
            'image_binary' => $binary,
            'extraction_method' => $em,
        ]);

        if ($refined === null) {
            $api = $this->decorateCandidatesForApi($session, $stored);

            return [
                'status' => (string) $session->status,
                'extraction_session_id' => (string) $session->id,
                'candidates' => $api,
                'updated_candidate' => null,
                'warning' => 'That point did not expand the cutout. Try a different area on the subject, or use Remove to exclude background.',
                'provider_capabilities' => $this->providerCapabilities($session),
            ];
        }

        $this->deleteSessionCandidateMaskFiles($session->id, $row);
        $newRow = $this->persistOneCandidateRow($session->id, $refined);
        $stored[$idx] = $newRow;
        $session->update([
            'candidates_json' => json_encode($stored, JSON_THROW_ON_ERROR),
        ]);

        $fresh = $session->fresh() ?? $session;
        $api = $this->decorateCandidatesForApi($fresh, $stored);
        $updatedApi = null;
        foreach ($api as $c) {
            if ((string) ($c['id'] ?? '') === $candidateId) {
                $updatedApi = $c;
                break;
            }
        }

        return [
            'status' => (string) $fresh->status,
            'extraction_session_id' => (string) $fresh->id,
            'candidates' => $api,
            'updated_candidate' => $updatedApi,
            'warning' => null,
            'provider_capabilities' => $this->providerCapabilities($fresh),
        ];
    }

    /**
     * @return array{
     *   status: string,
     *   extraction_session_id: string,
     *   candidates: list<array<string, mixed>>,
     *   updated_candidate: ?array<string, mixed>,
     *   provider_capabilities: array<string, bool>
     * }
     */
    public function resetPickCandidateRefine(StudioLayerExtractionSession $session, string $candidateId): array
    {
        if ($session->status !== StudioLayerExtractionSession::STATUS_READY) {
            throw new InvalidArgumentException('Extraction session is not ready.');
        }
        $prov = $this->providerForSession($session);
        if (! $prov instanceof StudioLayerExtractionPointPickProviderInterface) {
            throw new InvalidArgumentException('Point picking is not available for the current provider.');
        }
        if (! str_starts_with($candidateId, 'pick_')) {
            throw new InvalidArgumentException('Only picked candidates can be reset.');
        }

        $asset = Asset::query()
            ->whereKey($session->source_asset_id)
            ->where('tenant_id', $session->tenant_id)
            ->where('brand_id', $session->brand_id)
            ->first();
        if ($asset === null) {
            throw new InvalidArgumentException('Source asset not found.');
        }

        $stored = json_decode((string) $session->candidates_json, true);
        if (! is_array($stored)) {
            throw new InvalidArgumentException('Invalid session candidate data.');
        }
        $row = null;
        $idx = -1;
        foreach ($stored as $i => $r) {
            if ((string) ($r['id'] ?? '') === $candidateId) {
                $row = $r;
                $idx = $i;
                break;
            }
        }
        if ($row === null || $idx < 0) {
            throw new InvalidArgumentException('Candidate not found in this session.');
        }

        $meta = isset($row['metadata']) && is_array($row['metadata']) ? $row['metadata'] : [];
        $positive = $this->normalizePositivePointsFromMetadata($meta);
        if ($positive === []) {
            throw new InvalidArgumentException('Candidate has no positive seed metadata.');
        }
        $p0 = $positive[0];

        $existingBboxes = [];
        foreach ($stored as $r) {
            if ((string) ($r['id'] ?? '') === $candidateId) {
                continue;
            }
            if (isset($r['bbox']) && is_array($r['bbox'])) {
                $existingBboxes[] = $r['bbox'];
            }
        }

        try {
            $binary = EditorAssetOriginalBytesLoader::loadFromStorage($asset);
        } catch (Throwable) {
            throw new InvalidArgumentException('Could not read source image for refinement reset.');
        }

        $cand = $prov->extractCandidateFromPoint($asset, $p0['x'], $p0['y'], [
            'image_binary' => $binary,
            'label' => (string) ($row['label'] ?? 'Picked element'),
            'candidate_id' => $candidateId,
            'existing_bboxes' => $existingBboxes,
        ]);
        if ($cand === null) {
            throw new InvalidArgumentException('Could not regenerate the picked region. Try a different area.');
        }

        $this->deleteSessionCandidateMaskFiles($session->id, $row);
        $newRow = $this->persistOneCandidateRow($session->id, $cand);
        $stored[$idx] = $newRow;
        $session->update([
            'candidates_json' => json_encode($stored, JSON_THROW_ON_ERROR),
        ]);

        $fresh = $session->fresh() ?? $session;
        $api = $this->decorateCandidatesForApi($fresh, $stored);
        $updatedApi = null;
        foreach ($api as $c) {
            if ((string) ($c['id'] ?? '') === $candidateId) {
                $updatedApi = $c;
                break;
            }
        }

        return [
            'status' => (string) $fresh->status,
            'extraction_session_id' => (string) $fresh->id,
            'candidates' => $api,
            'updated_candidate' => $updatedApi,
            'provider_capabilities' => $this->providerCapabilities($fresh),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<array{x: float, y: float}>
     */
    private function normalizePositivePointsFromMetadata(array $meta): array
    {
        if (isset($meta['positive_points']) && is_array($meta['positive_points']) && $meta['positive_points'] !== []) {
            $out = [];
            foreach ($meta['positive_points'] as $p) {
                if (is_array($p) && isset($p['x'], $p['y']) && is_numeric($p['x']) && is_numeric($p['y'])) {
                    $out[] = ['x' => (float) $p['x'], 'y' => (float) $p['y']];
                }
            }
            if ($out !== []) {
                return $out;
            }
        }
        if (isset($meta['seed_point_normalized']) && is_array($meta['seed_point_normalized'])) {
            $sp = $meta['seed_point_normalized'];
            if (isset($sp['x'], $sp['y']) && is_numeric($sp['x']) && is_numeric($sp['y'])) {
                return [['x' => (float) $sp['x'], 'y' => (float) $sp['y']]];
            }
        }
        if (isset($meta['box_normalized']) && is_array($meta['box_normalized'])) {
            $b = $meta['box_normalized'];
            if (isset($b['x'], $b['y'], $b['width'], $b['height'])
                && is_numeric($b['x']) && is_numeric($b['y']) && is_numeric($b['width']) && is_numeric($b['height'])) {
                return [[
                    'x' => (float) $b['x'] + (float) $b['width'] / 2.0,
                    'y' => (float) $b['y'] + (float) $b['height'] / 2.0,
                ]];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function deleteSessionCandidateMaskFiles(string $sessionId, array $row): void
    {
        $disk = Storage::disk('studio_layer_extraction');
        $mr = (string) ($row['mask_relative'] ?? '');
        $pr = (string) ($row['preview_relative'] ?? '');
        foreach ([$mr, $pr] as $rel) {
            if ($rel !== '' && $disk->exists($rel)) {
                try {
                    $disk->delete($rel);
                } catch (Throwable) {
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function layerExtractionCandidateDtoFromSessionRow(array $row): LayerExtractionCandidateDto
    {
        $disk = Storage::disk('studio_layer_extraction');
        $rel = (string) ($row['mask_relative'] ?? '');
        if ($rel === '' || ! $disk->exists($rel)) {
            throw new InvalidArgumentException('Candidate mask is missing.');
        }
        $maskBin = $disk->get($rel);
        if (! is_string($maskBin) || $maskBin === '') {
            throw new InvalidArgumentException('Candidate mask is empty.');
        }
        $meta = isset($row['metadata']) && is_array($row['metadata']) ? $row['metadata'] : null;
        if (! isset($row['bbox']) || ! is_array($row['bbox'])) {
            throw new InvalidArgumentException('Invalid candidate bbox.');
        }

        $conf = null;
        if (array_key_exists('confidence', $row) && is_numeric($row['confidence'])) {
            $conf = (float) $row['confidence'];
        }

        return new LayerExtractionCandidateDto(
            id: (string) $row['id'],
            label: isset($row['label']) ? (string) $row['label'] : null,
            confidence: $conf,
            bbox: $row['bbox'],
            maskPath: null,
            maskBase64: base64_encode($maskBin),
            previewPath: null,
            selected: (bool) ($row['selected'] ?? true),
            notes: isset($row['notes']) ? (string) $row['notes'] : null,
            metadata: $meta,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $stored
     * @param  ?array<string, mixed>  $newRow
     * @return array{status: string, extraction_session_id: string, candidates: list<array<string, mixed>>, new_candidate: ?array<string, mixed>, warning: ?string, provider_capabilities: array<string, bool>}
     */
    private function pointPickResponse(
        StudioLayerExtractionSession $session,
        array $stored,
        ?array $newRow,
        ?string $warning,
    ): array {
        $api = $this->decorateCandidatesForApi($session, $stored);
        $newApi = null;
        if ($newRow !== null) {
            $nid = (string) ($newRow['id'] ?? '');
            foreach ($api as $c) {
                if ((string) ($c['id'] ?? '') === $nid) {
                    $newApi = $c;
                    break;
                }
            }
        }

        return [
            'status' => (string) $session->status,
            'extraction_session_id' => (string) $session->id,
            'candidates' => $api,
            'new_candidate' => $newApi,
            'warning' => $warning,
            'provider_capabilities' => $this->providerCapabilities($session),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function persistOneCandidateRow(string $sessionId, LayerExtractionCandidateDto $c): array
    {
        $maskBinary = null;
        if ($c->maskBase64 !== null && $c->maskBase64 !== '') {
            $maskBinary = base64_decode($c->maskBase64, true);
        }
        if ($maskBinary === false || $maskBinary === null || $maskBinary === '') {
            throw new \RuntimeException('Invalid mask for candidate.');
        }
        $disk = Storage::disk('studio_layer_extraction');
        $dir = StudioLayerExtractionStoragePaths::sessionDirectory($sessionId);
        $disk->makeDirectory($dir);
        $maskRel = StudioLayerExtractionStoragePaths::relative($sessionId, $c->id.'_mask.png');
        $disk->put($maskRel, $maskBinary);
        $previewBinary = $this->buildPreviewPng($maskBinary, 256);
        $previewRel = StudioLayerExtractionStoragePaths::relative($sessionId, $c->id.'_preview.png');
        $disk->put($previewRel, $previewBinary);

        return [
            'id' => $c->id,
            'label' => $c->label,
            'confidence' => $c->confidence,
            'bbox' => $c->bbox,
            'mask_relative' => $maskRel,
            'preview_relative' => $previewRel,
            'selected' => $c->selected,
            'notes' => $c->notes,
            'metadata' => $c->metadata,
        ];
    }
}
