<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use Illuminate\Support\Collection;

/**
 * Builds the "assets with processing issues" list for admin dashboards (system status + full report).
 *
 * Query matches {@see \App\Http\Controllers\Admin\SystemStatusController::getAssetsWithIssues}:
 * thumbnail_status failed OR promotion_failed in metadata.
 */
final class AssetProcessingIssuesService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 100): array
    {
        return $this->baseQuery($limit)
            ->map(fn (Asset $asset) => $this->formatAsset($asset))
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Asset>
     */
    protected function baseQuery(int $limit): Collection
    {
        return Asset::query()
            ->whereNull('deleted_at')
            ->where(function ($query) {
                $query->where('thumbnail_status', ThumbnailStatus::FAILED)
                    ->orWhere(function ($q) {
                        $q->whereNotNull('metadata->promotion_failed')
                            ->where('metadata->promotion_failed', true);
                    });
            })
            ->with(['currentVersion:id,asset_id,mime_type,pipeline_status,metadata'])
            ->orderByDesc('updated_at')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatAsset(Asset $asset): array
    {
        $meta = $asset->metadata ?? [];
        $issues = [];
        $errorMessages = [];
        $errorDetails = [];

        if ($asset->thumbnail_status === ThumbnailStatus::FAILED) {
            $issues[] = 'thumbnail_generation_failed';
            $thumbErr = $asset->thumbnail_error;
            if (is_string($thumbErr) && $thumbErr !== '') {
                $line = 'Thumbnail (column): '.$thumbErr;
                $errorMessages[] = $line;
                $errorDetails[] = ['source' => 'assets.thumbnail_error', 'message' => $thumbErr];
            }
            $genErr = $meta['thumbnail_generation_error'] ?? null;
            if (is_string($genErr) && $genErr !== '' && $genErr !== (string) ($thumbErr ?? '')) {
                $errorMessages[] = 'Metadata (thumbnail_generation_error): '.$genErr;
                $errorDetails[] = ['source' => 'metadata.thumbnail_generation_error', 'message' => $genErr];
            }
            $skipReason = $meta['thumbnail_skip_reason'] ?? null;
            $skipMsg = $meta['thumbnail_skip_message'] ?? null;
            if (is_string($skipReason) && $skipReason !== '') {
                $combined = $skipReason.($skipMsg ? ' — '.$skipMsg : '');
                $errorMessages[] = 'Skip: '.$combined;
                $errorDetails[] = ['source' => 'metadata.thumbnail_skip', 'message' => $combined];
            }
            $this->appendThumbnailEngineMetadata($meta, $errorMessages, $errorDetails, 'metadata');
        }

        if (isset($meta['promotion_failed']) && $meta['promotion_failed'] === true) {
            $issues[] = 'promotion_failed';
            $promoErr = $meta['promotion_error'] ?? null;
            if (is_string($promoErr) && $promoErr !== '') {
                $errorMessages[] = 'Promotion: '.$promoErr;
                $errorDetails[] = ['source' => 'metadata.promotion_error', 'message' => $promoErr];
            }
        }

        $version = $asset->currentVersion;
        $versionSummary = null;
        if ($version) {
            $vMeta = $version->metadata ?? [];
            $vErr = $vMeta['thumbnail_generation_error'] ?? null;
            $versionSummary = [
                'id' => (string) $version->id,
                'mime_type' => $version->mime_type,
                'pipeline_status' => $version->pipeline_status,
                'thumbnail_generation_error' => is_string($vErr) ? $vErr : null,
            ];
            if (is_string($vErr) && $vErr !== '') {
                $errorMessages[] = 'Current version: '.$vErr;
                $errorDetails[] = ['source' => 'asset_versions.metadata.thumbnail_generation_error', 'message' => $vErr];
            }
            $this->appendThumbnailEngineMetadata($vMeta, $errorMessages, $errorDetails, 'asset_versions.metadata');
        }

        return [
            'id' => $asset->id,
            'title' => $asset->title ?? $asset->original_filename ?? 'Untitled Asset',
            'original_filename' => $asset->original_filename,
            'created_at' => $asset->created_at?->toIso8601String(),
            'updated_at' => $asset->updated_at?->toIso8601String(),
            'analysis_status' => $asset->analysis_status,
            'thumbnail_status' => $asset->thumbnail_status instanceof ThumbnailStatus
                ? $asset->thumbnail_status->value
                : (string) ($asset->thumbnail_status ?? ''),
            'issues' => $issues,
            'error_messages' => $errorMessages,
            'error_details' => $errorDetails,
            'current_version' => $versionSummary,
            'admin_asset_console_url' => route('admin.assets.index', ['search' => (string) $asset->id]),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $errorMessages
     * @param  list<array{source: string, message: string}>  $errorDetails
     */
    private function appendThumbnailEngineMetadata(array $meta, array &$errorMessages, array &$errorDetails, string $sourcePrefix): void
    {
        $summary = $meta['thumbnail_engine_error_summary'] ?? null;
        if (is_string($summary) && $summary !== '') {
            $errorMessages[] = 'Thumbnail engine: '.$summary;
            $errorDetails[] = ['source' => $sourcePrefix.'.thumbnail_engine_error_summary', 'message' => $summary];

            return;
        }

        $diag = $meta['thumbnail_engine_diagnostics'] ?? null;
        if (! is_array($diag) || $diag === []) {
            return;
        }

        foreach ($diag as $idx => $row) {
            if (! is_array($row)) {
                continue;
            }
            $ctx = (string) ($row['context'] ?? 'unknown');
            $msg = (string) ($row['message'] ?? '');
            if ($msg === '') {
                continue;
            }
            $line = '['.$ctx.'] '.$msg;
            $errorMessages[] = 'Thumbnail engine: '.$line;
            $errorDetails[] = [
                'source' => $sourcePrefix.'.thumbnail_engine_diagnostics['.(string) $idx.']',
                'message' => $line,
            ];
        }
    }
}
