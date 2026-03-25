<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * User notification preference groups (activity / account / system) with per-channel keys.
 *
 * Future: add email (and in-app) toggles per group; per-event overrides; tenant-level defaults;
 * digest frequency instead of instant.
 */
class UserNotificationPreferencesController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'preferences' => $user->getNotificationPreferences(),
            'push_enabled' => (bool) $user->push_enabled,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => 'required|array',
            'preferences.activity' => 'sometimes|array',
            'preferences.activity.push' => 'sometimes|boolean',
            'preferences.account' => 'sometimes|array',
            'preferences.account.push' => 'sometimes|boolean',
            'preferences.system' => 'sometimes|array',
            'preferences.system.push' => 'sometimes|boolean',
        ]);

        $user = $request->user();
        $current = is_array($user->notification_preferences) ? $user->notification_preferences : [];
        $incoming = $validated['preferences'];
        $merged = array_replace_recursive($current, $incoming);

        $user->notification_preferences = $merged;
        $user->syncPushEnabledFromNotificationPreferences();
        $user->save();

        return response()->json([
            'preferences' => $user->getNotificationPreferences(),
            'push_enabled' => (bool) $user->push_enabled,
        ]);
    }
}
