<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stores web push onboarding / opt-in preferences on the user record.
 *
 * Future: a full notification preferences screen can add per-channel toggles (email / push / in-app)
 * and merge with tenant-level overrides without changing this endpoint’s shape drastically.
 */
class UserPushPreferenceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'enabled' => 'sometimes|boolean',
            'prompted' => 'sometimes|boolean',
        ]);

        $user = $request->user();

        if ($request->boolean('prompted')) {
            $user->push_prompted_at = now();
        }

        if ($request->has('enabled')) {
            $user->push_enabled = $request->boolean('enabled');
        }

        $user->save();

        return response()->json([
            'success' => true,
            'push_prompted_at' => $user->push_prompted_at?->toIso8601String(),
            'push_enabled' => $user->push_enabled,
        ]);
    }
}
