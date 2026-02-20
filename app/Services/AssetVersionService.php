<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssetVersionService
{
    public function createVersion(
        Asset $asset,
        array $fileMeta,
        ?string $uploadedBy = null,
        ?string $changeNote = null,
        ?string $restoredFromVersionId = null
    ): AssetVersion {
        return DB::transaction(function () use (
            $asset,
            $fileMeta,
            $uploadedBy,
            $changeNote,
            $restoredFromVersionId
        ) {
            $this->enforcePlanVersionLimit($asset);

            $currentMax = $asset->versions()
                ->withTrashed()
                ->max('version_number') ?? 0;

            $nextVersion = $currentMax + 1;

            // Toggle previous current
            $asset->versions()
                ->where('is_current', true)
                ->update(['is_current' => false]);

            // Do NOT copy width/height/mime from previous version; all derived fields come from FileInspectionService
            $version = AssetVersion::create([
                'id' => (string) Str::uuid(),
                'asset_id' => $asset->id,
                'version_number' => $nextVersion,
                'file_path' => $fileMeta['file_path'],
                'file_size' => $fileMeta['file_size'],
                'mime_type' => $fileMeta['mime_type'] ?? 'application/octet-stream', // DB NOT NULL; FileInspectionService overwrites
                'width' => null,
                'height' => null,
                'checksum' => $fileMeta['checksum'],
                'uploaded_by' => $uploadedBy,
                'change_note' => $changeNote,
                'pipeline_status' => 'pending',
                'is_current' => true,
                'restored_from_version_id' => $restoredFromVersionId,
            ]);

            return $version;
        });
    }

    /**
     * Get the next version number for an asset.
     */
    public function getNextVersionNumber(Asset $asset): int
    {
        $currentMax = $asset->versions()
            ->withTrashed()
            ->max('version_number') ?? 0;

        return $currentMax + 1;
    }

    /**
     * Enforce plan version limit.
     *
     * @throws \DomainException when version count >= plan limit
     */
    protected function enforcePlanVersionLimit(Asset $asset): void
    {
        $limit = app(PlanService::class)->maxVersionsPerAsset($asset->tenant);
        $currentCount = $asset->versions()->withTrashed()->count();

        if ($currentCount >= $limit) {
            throw new \DomainException(
                "This asset has reached the maximum number of versions allowed by your plan ({$limit})."
            );
        }
    }
}
