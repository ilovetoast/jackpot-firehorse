<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Manual OneSignal smoke test (staging/local only unless PUSH_TEST_ROUTE_ENABLED=true).
 * Uses REST Key header format expected by api.onesignal.com/notifications.
 */
class PushTestController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! filter_var(env('PUSH_TEST_ROUTE_ENABLED', false), FILTER_VALIDATE_BOOLEAN) && ! app()->environment('local')) {
            abort(404);
        }

        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $appId = config('services.onesignal.app_id');
        $apiKey = config('services.onesignal.rest_api_key');
        if (empty($appId) || empty($apiKey)) {
            return response()->json(['error' => 'OneSignal not configured'], 503);
        }

        $externalId = 'user_'.$user->id;

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Authorization' => 'Key '.$apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post('https://api.onesignal.com/notifications', [
                    'app_id' => $appId,
                    'target_channel' => 'push',
                    'include_aliases' => [
                        'external_id' => [$externalId],
                    ],
                    'headings' => ['en' => 'Test Push'],
                    'contents' => ['en' => 'Push is working'],
                ]);

            Log::info('[PushTest] OneSignal test request', [
                'user_id' => $user->id,
                'status' => $response->status(),
            ]);

            return response()->json([
                'ok' => $response->successful(),
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ], $response->successful() ? 200 : 502);
        } catch (\Throwable $e) {
            Log::error('[PushTest] Failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => $e->getMessage()], 502);
        }
    }
}
