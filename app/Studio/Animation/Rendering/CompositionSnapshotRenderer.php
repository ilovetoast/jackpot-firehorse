<?php

namespace App\Studio\Animation\Rendering;

use App\Models\StudioAnimationJob;
use App\Studio\Animation\Analysis\StartFrameDriftAnalyzer;
use App\Studio\Animation\Analysis\StudioAnimationDriftQualityClassifier;
use App\Studio\Animation\Contracts\CompositionAnimationRendererInterface;
use App\Studio\Animation\Data\StudioAnimationRenderData;
use App\Studio\Animation\Enums\StudioAnimationRenderRole;
use App\Studio\Animation\Enums\StudioAnimationSourceStrategy;
use App\Studio\Animation\Support\AnimationSourceLock;
use App\Studio\Animation\Support\StudioAnimationObservability;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CompositionSnapshotRenderer implements CompositionAnimationRendererInterface
{
    public const RENDERER_VERSION = '1.4.0';

    public function __construct(
        private readonly OfficialPlaywrightLockedFrameRenderer $officialPlaywright = new OfficialPlaywrightLockedFrameRenderer,
        private readonly LockedDocumentServerFrameRenderer $lockedDocumentRenderer = new LockedDocumentServerFrameRenderer,
        private readonly BrowserHeadlessLockedFrameRenderer $browserRenderer = new BrowserHeadlessLockedFrameRenderer,
        private readonly StartFrameDriftAnalyzer $driftAnalyzer = new StartFrameDriftAnalyzer,
    ) {}

    public function renderStartFrame(StudioAnimationJob $job): StudioAnimationRenderData
    {
        $strategy = StudioAnimationSourceStrategy::tryFrom((string) $job->source_strategy);
        $allowed = [
            StudioAnimationSourceStrategy::CompositionSnapshot,
            StudioAnimationSourceStrategy::SelectedLayerWithContext,
            StudioAnimationSourceStrategy::SelectedLayerIsolated,
        ];
        if ($strategy === null || ! in_array($strategy, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported source strategy for composition snapshot renderer.');
        }

        $settings = $job->settings_json ?? [];
        $b64 = $settings['composition_snapshot_png_base64'] ?? null;
        if (! is_string($b64) || $b64 === '') {
            throw new \RuntimeException('Missing composition snapshot payload.');
        }

        if (str_starts_with($b64, 'data:image')) {
            $b64 = (string) preg_replace('/^data:image\/\w+;base64,/', '', $b64);
        }

        $clientBinary = base64_decode($b64, true);
        if ($clientBinary === false || strlen($clientBinary) < 64 || strlen($clientBinary) > 8_000_000) {
            throw new \RuntimeException('Invalid composition snapshot image.');
        }

        $clientSha256 = hash('sha256', $clientBinary);

        $dims = @getimagesizefromstring($clientBinary);
        $width = isset($dims[0]) ? (int) $dims[0] : null;
        $height = isset($dims[1]) ? (int) $dims[1] : null;
        $imageType = isset($dims[2]) ? (int) $dims[2] : \IMAGETYPE_PNG;
        $mimeType = @image_type_to_mime_type($imageType) ?: 'image/png';
        if (! is_string($mimeType) || ! str_starts_with($mimeType, 'image/')) {
            $mimeType = 'image/png';
        }
        $pathExt = match (true) {
            str_contains($mimeType, 'jpeg') => 'jpg',
            str_contains($mimeType, 'png') => 'png',
            str_contains($mimeType, 'webp') => 'webp',
            default => 'img',
        };

        $expectedW = (int) ($settings['snapshot_width'] ?? 0);
        $expectedH = (int) ($settings['snapshot_height'] ?? 0);
        if ($expectedW > 0 && $expectedH > 0 && $width && $height) {
            $tolerance = 8;
            if (abs($width - $expectedW) > $tolerance || abs($height - $expectedH) > $tolerance) {
                throw new \RuntimeException('Snapshot dimensions do not match declared composition size.');
            }
        }

        $disk = (string) config('studio_animation.render_disk', 'local');
        $tenant = $job->tenant;
        $uuid = $tenant?->uuid ?? 'unknown-tenant';
        $path = "studio-animation/{$uuid}/{$job->id}/start_frame_".Str::random(8).'.'.$pathExt;

        $lockedDoc = $this->lockedCompositionDocument($settings);
        $highFidelity = (bool) ($settings['high_fidelity_submit'] ?? false);
        $requireHighFidelity = (bool) config('studio_animation.official_playwright_renderer.require_high_fidelity_submit', false);
        $tryOfficialFirst = (bool) config('studio_animation.official_playwright_renderer.enabled', false)
            && (! $requireHighFidelity || $highFidelity);

        $canonicalOrigin = 'client_snapshot';
        $serverBinary = null;
        $serverSha256 = null;
        $serverSkip = null;
        $renderEngine = 'client_snapshot';

        if ($lockedDoc !== null) {
            $viewportW = $expectedW > 0 ? $expectedW : (int) ($lockedDoc['width'] ?? $width ?? 1);
            $viewportH = $expectedH > 0 ? $expectedH : (int) ($lockedDoc['height'] ?? $height ?? 1);

            if ($tryOfficialFirst) {
                $officialAttempt = $this->officialPlaywright->tryRenderPng($job, $lockedDoc, $viewportW, $viewportH);
                if ($officialAttempt->ok && is_string($officialAttempt->pngBinary) && $officialAttempt->pngBinary !== '') {
                    $canonicalOrigin = 'server_locked_state';
                    $serverBinary = $officialAttempt->pngBinary;
                    $serverSha256 = hash('sha256', $serverBinary);
                    $renderEngine = 'browser_headless_official';
                } else {
                    $serverSkip = $officialAttempt->skipReason ?? null;
                }
            }

            if ($renderEngine !== 'browser_headless_official') {
                $legacyBrowser = ! ((bool) config('studio_animation.official_playwright_renderer.disable_legacy_browser_command', false));
                $browserAttempt = null;
                if ($legacyBrowser) {
                    $browserAttempt = $this->browserRenderer->tryRenderPng($job, $lockedDoc);
                    if ($browserAttempt->ok && is_string($browserAttempt->pngBinary) && $browserAttempt->pngBinary !== '') {
                        $canonicalOrigin = 'server_locked_state';
                        $serverBinary = $browserAttempt->pngBinary;
                        $serverSha256 = hash('sha256', $serverBinary);
                        $renderEngine = 'browser_headless';
                    }
                }

                if ($renderEngine === 'client_snapshot' || $serverBinary === null) {
                    $serverAttempt = $this->lockedDocumentRenderer->tryRenderPng($job, $lockedDoc);
                    if ($serverAttempt->ok && is_string($serverAttempt->pngBinary) && $serverAttempt->pngBinary !== '') {
                        $canonicalOrigin = 'server_locked_state';
                        $serverBinary = $serverAttempt->pngBinary;
                        $serverSha256 = hash('sha256', $serverBinary);
                        $renderEngine = 'server_basic';
                    } else {
                        $canonicalOrigin = 'client_snapshot';
                        $browserReason = $browserAttempt !== null ? $browserAttempt->skipReason : null;
                        $serverSkip = $serverSkip ?? $browserReason ?? $serverAttempt->skipReason ?? 'server_paths_unavailable';
                        $renderEngine = 'client_snapshot';
                    }
                }
            }
        } else {
            $serverSkip = 'no_locked_document_json';
        }

        $preferClientPixels = (bool) config('studio_animation.prefer_client_snapshot_for_provider', true);
        if ($preferClientPixels) {
            // Always submit what the user actually saw in the editor. Server-side rasterization of
            // locked_document_json uses Asset storage paths and can differ from the canvas when
            // layer.src points at a different version than the asset’s “current” file.
            $binaryForSubmit = $clientBinary;
        } else {
            $binaryForSubmit = $canonicalOrigin === 'server_locked_state' && $serverBinary !== null
                ? $serverBinary
                : $clientBinary;
        }
        $providerSubmitsFromClientBuffer = $preferClientPixels || $binaryForSubmit === $clientBinary;

        if ($strategy === StudioAnimationSourceStrategy::SelectedLayerIsolated) {
            $binaryForSubmit = $this->cropPngBinaryToLayerBounds($binaryForSubmit, $job);
            $dimsCrop = @getimagesizefromstring($binaryForSubmit);
            if ($dimsCrop && isset($dimsCrop[0], $dimsCrop[1])) {
                $width = (int) $dimsCrop[0];
                $height = (int) $dimsCrop[1];
                $imageType = isset($dimsCrop[2]) ? (int) $dimsCrop[2] : \IMAGETYPE_PNG;
                $mimeType = @image_type_to_mime_type($imageType) ?: 'image/png';
                if (! is_string($mimeType) || ! str_starts_with($mimeType, 'image/')) {
                    $mimeType = 'image/png';
                }
                $pathExt = match (true) {
                    str_contains($mimeType, 'jpeg') => 'jpg',
                    str_contains($mimeType, 'png') => 'png',
                    str_contains($mimeType, 'webp') => 'webp',
                    default => 'png',
                };
                $path = "studio-animation/{$uuid}/{$job->id}/start_frame_".Str::random(8).'.'.$pathExt;
            }
        }

        $providerPixelOrigin = $providerSubmitsFromClientBuffer ? 'client_snapshot' : 'server_locked_state';

        Storage::disk($disk)->put($path, $binaryForSubmit);

        $sha256 = hash('sha256', $binaryForSubmit);

        $visibleLayers = $this->countVisibleLayers($lockedDoc);
        $backgroundMode = $this->backgroundMode($lockedDoc);

        $drift = $this->driftAnalyzer->analyze(
            $serverBinary,
            $clientBinary,
            $serverSha256,
            $clientSha256,
        );
        $drift['drift_level'] = StudioAnimationDriftQualityClassifier::classify($drift);

        $auxPath = null;
        if ($canonicalOrigin === 'server_locked_state' && $serverBinary !== null) {
            $auxPath = "studio-animation/{$uuid}/{$job->id}/client_aux_".Str::random(6).'.'.$pathExt;
            Storage::disk($disk)->put($auxPath, $clientBinary);
        }

        $canonicalFrame = [
            'canonical_source_render_origin' => $canonicalOrigin,
            'canonical_source_render_hash' => $sha256,
            'client_snapshot_hash' => $clientSha256,
            'frame_drift_status' => $drift['frame_drift_status'],
            'frame_drift_score' => $drift['frame_drift_score'],
            'drift_summary' => $drift['drift_summary'],
            'drift_level' => $drift['drift_level'],
            'mismatch_reasons' => $drift['mismatch_reasons'],
            'provider_submit_start_image_origin' => $providerPixelOrigin,
            'prefer_client_snapshot_for_provider' => $preferClientPixels,
            'server_render_fallback_reason' => $canonicalOrigin === 'client_snapshot' ? $serverSkip : null,
            'auxiliary_client_snapshot_disk_path' => $auxPath,
            'renderer_version' => self::RENDERER_VERSION,
            'render_engine' => $renderEngine,
            'high_fidelity_submit' => $highFidelity,
        ];

        $jobSettingsPatch = [
            'canonical_frame' => $canonicalFrame,
        ];

        StudioAnimationObservability::log('start_frame_rendered', $job, [
            'render_engine' => $renderEngine,
            'renderer_version' => self::RENDERER_VERSION,
            'drift_level' => (string) ($drift['drift_level'] ?? ''),
            'provider_submission_used_frame' => $providerPixelOrigin,
        ]);

        return new StudioAnimationRenderData(
            role: StudioAnimationRenderRole::StartFrame,
            disk: $disk,
            path: $path,
            mimeType: $mimeType,
            width: $width,
            height: $height,
            sha256: $sha256,
            assetId: null,
            metadata: [
                'source' => $providerPixelOrigin === 'client_snapshot' ? 'client_stage_export' : 'server_locked_state',
                'aspect_ratio' => $job->aspect_ratio,
                'renderer_version' => self::RENDERER_VERSION,
                'render_engine' => $renderEngine,
                'snapshot_sha256' => $sha256,
                'composition_revision_hash' => $job->source_document_revision_hash,
                'locked_composition_version_id' => $job->source_composition_version_id,
                'target_aspect_ratio' => $job->aspect_ratio,
                'export_width' => $width,
                'export_height' => $height,
                'visible_layer_count' => $visibleLayers,
                'background_mode' => $backgroundMode,
                'canonical_source_render_origin' => $canonicalOrigin,
                'client_snapshot_hash' => $clientSha256,
                'frame_drift_status' => $drift['frame_drift_status'],
                'drift_level' => $drift['drift_level'],
                'provider_start_frame' => $providerPixelOrigin,
            ],
            jobSettingsPatch: $jobSettingsPatch,
        );
    }

    public function renderEndFrame(StudioAnimationJob $job): ?StudioAnimationRenderData
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    private function lockedCompositionDocument(array $settings): ?array
    {
        $lock = $settings[AnimationSourceLock::SETTINGS_KEY] ?? null;
        if (! is_array($lock)) {
            return null;
        }
        $doc = $lock['locked_document_json'] ?? null;

        return is_array($doc) ? $doc : null;
    }

    /**
     * @param  array<string, mixed>|null  $doc
     */
    private function countVisibleLayers(?array $doc): int
    {
        if ($doc === null || ! isset($doc['layers']) || ! is_array($doc['layers'])) {
            return 0;
        }
        $n = 0;
        foreach ($doc['layers'] as $layer) {
            if (is_array($layer) && ($layer['visible'] ?? true) === true) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  array<string, mixed>|null  $doc
     */
    private function backgroundMode(?array $doc): string
    {
        if ($doc === null) {
            return 'unknown';
        }
        $preset = $doc['preset'] ?? null;

        return is_string($preset) && $preset !== '' ? $preset : 'custom';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function cropPngBinaryToLayerBounds(string $binary, StudioAnimationJob $job): string
    {
        $settings = is_array($job->settings_json) ? $job->settings_json : [];
        $b = $settings['layer_bounds'] ?? null;
        if (! is_array($b)) {
            throw new \RuntimeException('Missing layer_bounds for isolated layer render.');
        }
        $x = max(0, (int) ($b['x'] ?? 0));
        $y = max(0, (int) ($b['y'] ?? 0));
        $w = max(2, (int) ($b['width'] ?? 0));
        $h = max(2, (int) ($b['height'] ?? 0));
        if (! class_exists(\Imagick::class)) {
            throw new \RuntimeException('Imagick is required for layer isolation crop.');
        }
        $img = new \Imagick;
        try {
            $img->readImageBlob($binary);
            $img->setImageFormat('png');
            $img->cropImage($w, $h, $x, $y);
            $out = $img->getImageBlob();
        } finally {
            $img->destroy();
        }

        return $out !== false && $out !== '' ? $out : $binary;
    }
}
