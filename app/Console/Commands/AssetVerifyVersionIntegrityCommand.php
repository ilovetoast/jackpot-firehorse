<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\AssetVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 7: Version integrity audit.
 *
 * Operational safety net - checks for version integrity violations:
 * - Assets with 0 current versions (when they have version records)
 * - Assets with >1 current versions
 * - Assets where storage_root_path ≠ currentVersion.file_path
 *
 * Usage:
 *   php artisan asset:verify-version-integrity
 */
class AssetVerifyVersionIntegrityCommand extends Command
{
    protected $signature = 'asset:verify-version-integrity {--fix : Attempt to fix single-current violations (experimental)}';

    protected $description = 'Verify version integrity: exactly one current version per asset, storage_root_path in sync';

    public function handle(): int
    {
        $this->info('Verifying version integrity...');

        $errors = [];
        $assetIds = Asset::pluck('id');

        foreach ($assetIds as $assetId) {
            $asset = Asset::find($assetId);
            if (!$asset) {
                continue;
            }

            $currentCount = $asset->versions()->where('is_current', true)->count();

            // Only check assets that have version records
            $totalVersions = $asset->versions()->count();
            if ($totalVersions === 0) {
                continue; // No versions - skip (Starter plan or legacy)
            }

            if ($currentCount === 0) {
                $errors[] = [
                    'asset_id' => $asset->id,
                    'type' => 'zero_current',
                    'message' => "Asset {$asset->id} has 0 current versions (has {$totalVersions} total)",
                ];
            } elseif ($currentCount > 1) {
                $errors[] = [
                    'asset_id' => $asset->id,
                    'type' => 'multiple_current',
                    'message' => "Asset {$asset->id} has {$currentCount} current versions",
                ];
            } else {
                $currentVersion = $asset->versions()->where('is_current', true)->first();
                if ($currentVersion && $asset->storage_root_path !== $currentVersion->file_path) {
                    $errors[] = [
                        'asset_id' => $asset->id,
                        'type' => 'path_mismatch',
                        'message' => "Asset {$asset->id}: storage_root_path ({$asset->storage_root_path}) != currentVersion.file_path ({$currentVersion->file_path})",
                    ];
                }
            }
        }

        if (empty($errors)) {
            $this->info('✓ No version integrity violations found.');
            return Command::SUCCESS;
        }

        $this->error('Found ' . count($errors) . ' version integrity violation(s):');
        foreach ($errors as $err) {
            $this->line('  - ' . $err['message']);
            Log::error('[AssetVerifyVersionIntegrity] ' . $err['message'], [
                'asset_id' => $err['asset_id'],
                'type' => $err['type'],
            ]);
        }

        return Command::FAILURE;
    }
}
