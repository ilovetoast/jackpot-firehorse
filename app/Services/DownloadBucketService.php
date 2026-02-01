<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;

/**
 * Phase D1 — Secure Asset Downloader (Foundation)
 * D6.1: Only eligible assets (published, non-archived) are addable and visible.
 */
class DownloadBucketService
{
    private const SESSION_KEY = 'download_bucket_asset_ids';

    /** @var User|null */
    protected $user;

    public function __construct(
        protected AssetEligibilityService $eligibilityService
    ) {
        $this->user = auth()->user();
    }

    /**
     * Add an asset to the bucket if the user can view it.
     * Fails closed: invalid IDs are not added; no exception.
     */
    public function add(string $assetId): bool
    {
        if (! $this->user) {
            return false;
        }

        $asset = Asset::query()->find($assetId);
        if (! $asset) {
            return false;
        }

        if (! Gate::forUser($this->user)->allows('view', $asset)) {
            return false;
        }

        // D6.1: Eligibility (published, non-archived) is enforced at visibleItems() and download creation — any viewable asset can be added to the bucket so the toolbar works; only eligible assets are included in the ZIP.
        $ids = $this->ids();
        if (in_array($assetId, $ids, true)) {
            return true;
        }

        $ids[] = $assetId;
        $this->persist($ids);

        return true;
    }

    /**
     * Add multiple assets to the bucket. Only adds assets the user can view.
     * Returns the number of assets actually added (excluding already present and unauthorized).
     */
    public function addMany(array $assetIds): int
    {
        if (! $this->user || empty($assetIds)) {
            return 0;
        }

        $ids = $this->ids();
        $added = 0;

        foreach ($assetIds as $assetId) {
            if (! is_string($assetId) || in_array($assetId, $ids, true)) {
                continue;
            }

            $asset = Asset::query()->find($assetId);
            if (! $asset || ! Gate::forUser($this->user)->allows('view', $asset)) {
                continue;
            }
            // D6.1: Eligibility enforced at visibleItems() and download creation; any viewable asset can be in the bucket.
            $ids[] = $assetId;
            $added++;
        }

        if ($added > 0) {
            $this->persist($ids);
        }

        return $added;
    }

    /**
     * Remove an asset from the bucket.
     */
    public function remove(string $assetId): void
    {
        $ids = array_values(array_filter(
            $this->ids(),
            fn (string $id) => $id !== $assetId
        ));
        $this->persist($ids);
    }

    /**
     * Clear all items from the bucket.
     */
    public function clear(): void
    {
        $this->persist([]);
    }

    /**
     * Return current bucket asset IDs (order preserved).
     *
     * @return list<string>
     */
    public function items(): array
    {
        return $this->ids();
    }

    /**
     * Return bucket items filtered to only assets the user can view AND that are eligible (published, non-archived).
     * Use this for display and for creating the download (resolved snapshot).
     *
     * IMPORTANT: Asset eligibility (published, non-archived) is enforced here. Do not bypass this for collections or downloads.
     *
     * @return list<string>
     */
    public function visibleItems(): array
    {
        if (! $this->user) {
            return [];
        }

        $ids = $this->ids();
        if (empty($ids)) {
            return [];
        }

        $assets = Asset::query()
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (Asset $asset) => Gate::forUser($this->user)->allows('view', $asset)
                && $this->eligibilityService->isEligibleForDownloads($asset));

        return $assets->pluck('id')->values()->all();
    }

    /**
     * Count of items in bucket (all stored IDs).
     */
    public function count(): int
    {
        return count($this->ids());
    }

    private function ids(): array
    {
        $raw = Session::get(self::SESSION_KEY, []);
        return is_array($raw) ? array_values(array_filter($raw, 'is_string')) : [];
    }

    private function persist(array $ids): void
    {
        Session::put(self::SESSION_KEY, $ids);
    }
}
