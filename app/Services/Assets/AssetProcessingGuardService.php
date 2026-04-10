<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetProcessingLog;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Cooldowns, per-user rate limits, and inflight dedupe for asset processing dispatches.
 */
class AssetProcessingGuardService
{
    public const ACTION_FULL_PIPELINE = 'full_pipeline';

    public const ACTION_THUMBNAILS = 'thumbnails';

    public const ACTION_AI_METADATA = 'ai_metadata';

    public const ACTION_SYSTEM_METADATA = 'system_metadata';

    public const ACTION_VIDEO_PREVIEW = 'video_preview';

    public const ACTION_VIDEO_THUMBNAIL = 'video_thumbnail';

    /**
     * @throws HttpResponseException When the request must be blocked (JSON body + HTTP status).
     */
    public function assertCanDispatch(?User $user, Asset $asset, string $actionType): void
    {
        if (! $user) {
            throw new HttpResponseException(response()->json(['message' => 'Unauthenticated.'], 401));
        }

        $cooldownMin = max(0, (int) config('asset_processing.cooldown_minutes', 15));
        $inflightTtl = max(30, (int) config('asset_processing.inflight_lock_seconds', 300));
        $maxPerHour = max(1, (int) config('asset_processing.max_dispatches_per_user_per_hour', 40));

        $log = AssetProcessingLog::query()
            ->where('asset_id', $asset->id)
            ->where('action_type', $actionType)
            ->first();

        if ($cooldownMin > 0 && $log && $log->last_run_at) {
            $elapsedMin = $log->last_run_at->diffInMinutes(now());
            if ($elapsedMin < $cooldownMin) {
                $waitMin = $cooldownMin - $elapsedMin;
                throw new HttpResponseException(response()->json([
                    'message' => 'This action is temporarily unavailable. Try again in '.$waitMin.' minute(s).',
                    'retry_after_minutes' => max(1, $waitMin),
                    'code' => 'cooldown',
                ], 429));
            }
        }

        $rateKey = 'asset-processing:user:'.$user->id;
        if (RateLimiter::tooManyAttempts($rateKey, $maxPerHour)) {
            $sec = RateLimiter::availableIn($rateKey);
            throw new HttpResponseException(response()->json([
                'message' => 'You have reached the hourly limit for processing actions. Try again later.',
                'retry_after_seconds' => $sec,
                'code' => 'rate_limit',
            ], 429));
        }

        $lockKey = $this->inflightCacheKey($asset->id, $actionType);
        if (! Cache::add($lockKey, 1, $inflightTtl)) {
            throw new HttpResponseException(response()->json([
                'message' => 'A similar job is already queued or running for this asset.',
                'code' => 'inflight',
            ], 409));
        }
    }

    public function markDispatched(User $user, Asset $asset, string $actionType): void
    {
        RateLimiter::hit('asset-processing:user:'.$user->id, 3600);

        AssetProcessingLog::query()->updateOrCreate(
            [
                'asset_id' => $asset->id,
                'action_type' => $actionType,
            ],
            ['last_run_at' => now()]
        );
    }

    public function inflightCacheKey(string $assetId, string $actionType): string
    {
        return 'asset_proc_inflight:'.$assetId.':'.$actionType;
    }

    /**
     * @return array<string, array{last_run_at: ?string, cooldown_remaining_minutes: int, blocked: bool}>
     */
    public function statusForAsset(Asset $asset, array $actionTypes): array
    {
        $cooldownMin = max(0, (int) config('asset_processing.cooldown_minutes', 15));
        $logs = AssetProcessingLog::query()
            ->where('asset_id', $asset->id)
            ->whereIn('action_type', $actionTypes)
            ->get()
            ->keyBy('action_type');

        $out = [];
        foreach ($actionTypes as $type) {
            $log = $logs->get($type);
            $last = $log?->last_run_at;
            $remaining = 0;
            if ($cooldownMin > 0 && $last) {
                $elapsed = $last->diffInMinutes(now());
                if ($elapsed < $cooldownMin) {
                    $remaining = $cooldownMin - $elapsed;
                }
            }
            $out[$type] = [
                'last_run_at' => $last?->toIso8601String(),
                'cooldown_remaining_minutes' => $remaining,
                'blocked' => $remaining > 0,
            ];
        }

        return $out;
    }
}
