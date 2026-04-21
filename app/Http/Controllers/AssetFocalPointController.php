<?php

namespace App\Http\Controllers;

use App\Jobs\ComputeImageFocalPointJob;
use App\Models\Asset;
use App\Support\GuidelinesFocalPoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Library / asset drawer: manual focal point (locks against AI overwrite).
 */
class AssetFocalPointController extends Controller
{
    /**
     * PATCH /assets/{asset}/focal-point
     */
    public function update(Request $request, Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        if (! $tenant || (string) $asset->tenant_id !== (string) $tenant->id) {
            abort(403);
        }

        $this->authorize('update', $asset);

        $validated = $request->validate([
            'clear' => 'sometimes|boolean',
            'x' => 'required_unless:clear,true|numeric|min:0|max:1',
            'y' => 'required_unless:clear,true|numeric|min:0|max:1',
        ]);

        $meta = $asset->metadata ?? [];
        if (! empty($validated['clear'])) {
            unset($meta['focal_point'], $meta['focal_point_locked'], $meta['focal_point_source'], $meta['focal_point_ai_at']);
        } else {
            $meta['focal_point'] = [
                'x' => (float) $validated['x'],
                'y' => (float) $validated['y'],
            ];
            $meta['focal_point_locked'] = true;
            $meta['focal_point_source'] = 'manual';
        }

        $asset->update(['metadata' => $meta]);
        $asset->refresh();

        return response()->json([
            'focal_point' => GuidelinesFocalPoint::generalFocalPointFromAsset($asset),
        ]);
    }

    /**
     * POST /assets/{asset}/focal-point/ai-regenerate
     *
     * Queues a fresh OpenAI vision pass (gpt-4o-mini). Consumes unified AI credits like other vision calls.
     */
    public function regenerateAi(Asset $asset): JsonResponse
    {
        $tenant = app('tenant');
        if (! $tenant || (string) $asset->tenant_id !== (string) $tenant->id) {
            abort(403);
        }

        $this->authorize('update', $asset);

        if (($tenant->settings['ai_enabled'] ?? true) === false) {
            return response()->json(['message' => 'AI features are disabled for this workspace.'], 409);
        }

        $asset->loadMissing('category');
        if ($asset->category?->slug !== 'photography') {
            return response()->json(['message' => 'AI focal point applies to Photography assets only.'], 422);
        }

        $meta = $asset->metadata ?? [];
        if (! empty($meta['focal_point_locked'])) {
            return response()->json(['message' => 'Remove the manual focal point lock before re-running AI.'], 422);
        }

        ComputeImageFocalPointJob::dispatch($asset->id, true, Auth::id())
            ->onQueue(config('queue.images_queue', 'images'));

        return response()->json(['queued' => true]);
    }
}
