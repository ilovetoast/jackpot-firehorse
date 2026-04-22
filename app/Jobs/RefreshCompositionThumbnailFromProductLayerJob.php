<?php

namespace App\Jobs;

use App\Models\Composition;
use App\Models\User;
use App\Services\Studio\StudioCompositionHeroThumbnailRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes a composition rail thumbnail from the hero product image after async generation completes.
 */
class RefreshCompositionThumbnailFromProductLayerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(
        public int $compositionId,
        public int $actingUserId,
    ) {
        $connection = (string) config('queue.default', 'sync');
        $this->onQueue((string) config("queue.connections.{$connection}.queue", 'default'));
    }

    public function handle(StudioCompositionHeroThumbnailRefreshService $refresher): void
    {
        $composition = Composition::query()
            ->whereKey($this->compositionId)
            ->with(['tenant', 'brand', 'user'])
            ->first();
        if (! $composition) {
            return;
        }

        $user = $composition->user ?? User::query()->find($this->actingUserId);
        if (! $user) {
            Log::warning('[RefreshCompositionThumbnailFromProductLayerJob] missing_user', [
                'composition_id' => $this->compositionId,
            ]);

            return;
        }

        $previousTenant = app()->bound('tenant') ? app('tenant') : null;
        $previousBrand = app()->bound('brand') ? app('brand') : null;

        try {
            $tenant = $composition->tenant;
            $brand = $composition->brand;
            if (! $tenant || ! $brand) {
                return;
            }

            app()->instance('tenant', $tenant);
            app()->instance('brand', $brand);
            Auth::login($user);

            $refresher->refreshFromHeroProductLayer($composition->fresh(), $user);
        } catch (\Throwable $e) {
            Log::warning('[RefreshCompositionThumbnailFromProductLayerJob] failed', [
                'composition_id' => $this->compositionId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if (Auth::check()) {
                Auth::logout();
            }
            if ($previousTenant !== null) {
                app()->instance('tenant', $previousTenant);
            } else {
                app()->forgetInstance('tenant');
            }
            if ($previousBrand !== null) {
                app()->instance('brand', $previousBrand);
            } else {
                app()->forgetInstance('brand');
            }
        }
    }
}
