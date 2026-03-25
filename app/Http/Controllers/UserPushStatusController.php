<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Device-level push opt-in/out (OneSignal subscription + users.push_enabled).
 *
 * Future: tie to unified notification_preferences + email; tenant defaults.
 */
class UserPushStatusController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $user = $request->user();

        if ($request->boolean('enabled')) {
            $user->push_enabled = true;
            $user->push_prompted_at = now();
        } else {
            $user->push_enabled = false;
            // Record that we went through the consent flow so initPush does not re-prompt every load (e.g. deny).
            if ($user->push_prompted_at === null) {
                $user->push_prompted_at = now();
            }
        }

        $user->save();

        return response()->json([
            'success' => true,
            'push_enabled' => (bool) $user->push_enabled,
            'push_prompted_at' => $user->push_prompted_at?->toIso8601String(),
        ]);
    }
}
