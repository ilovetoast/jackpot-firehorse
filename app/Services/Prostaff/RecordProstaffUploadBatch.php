<?php

namespace App\Services\Prostaff;

use App\Jobs\ProcessProstaffUploadBatchJob;
use App\Models\Asset;
use App\Models\ProstaffUploadBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecordProstaffUploadBatch
{
    public function __construct(
        private BuildProstaffUploadBatchKey $buildBatchKey
    ) {}

    public function record(Asset $asset): void
    {
        if (! $asset->isProstaffAsset() || $asset->prostaff_user_id === null) {
            return;
        }

        $brand = $asset->brand;
        if ($brand === null) {
            return;
        }

        $tenantId = (int) $asset->tenant_id;
        $brandId = (int) $asset->brand_id;
        $prostaffUserId = (int) $asset->prostaff_user_id;

        $batchKey = ($this->buildBatchKey)($tenantId, $brandId, $prostaffUserId, now());

        $now = now();

        DB::transaction(function () use ($asset, $batchKey, $tenantId, $brandId, $prostaffUserId, $now): void {
            $batch = ProstaffUploadBatch::query()
                ->where('batch_key', $batchKey)
                ->lockForUpdate()
                ->first();

            if ($batch === null) {
                $batch = new ProstaffUploadBatch([
                    'tenant_id' => $tenantId,
                    'brand_id' => $brandId,
                    'prostaff_user_id' => $prostaffUserId,
                    'batch_key' => $batchKey,
                    'upload_count' => 0,
                    'first_asset_id' => $asset->id,
                    'last_asset_id' => $asset->id,
                    'started_at' => $now,
                    'last_activity_at' => $now,
                ]);
                $batch->save();
            }

            $locked = ProstaffUploadBatch::query()
                ->whereKey($batch->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $locked->upload_count = (int) $locked->upload_count + 1;
            $locked->last_asset_id = $asset->id;
            $locked->last_activity_at = $now;
            if ($locked->first_asset_id === null) {
                $locked->first_asset_id = $asset->id;
            }
            $locked->save();
        });

        ProcessProstaffUploadBatchJob::dispatch($batchKey);

        Log::info('[RecordProstaffUploadBatch] Batch updated and job dispatched', [
            'batch_key' => $batchKey,
            'asset_id' => $asset->id,
        ]);
    }
}
