<?php

namespace App\Http\Controllers;

use App\Models\Consent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CookieConsentController extends Controller
{
    /**
     * Store cookie / similar-technology consent choices (Art. 6(1)(a), ePrivacy).
     */
    public function store(Request $request): JsonResponse
    {
        $policyVersion = (string) config('privacy.cookie_policy_version', '1');

        $validated = $request->validate([
            'purposes' => ['required', 'array'],
            'purposes.functional' => ['sometimes', 'boolean'],
            'purposes.analytics' => ['sometimes', 'boolean'],
            'purposes.marketing' => ['sometimes', 'boolean'],
            'policy_version' => ['sometimes', 'string', 'max:16'],
        ]);

        $purposes = array_merge(
            ['functional' => false, 'analytics' => false, 'marketing' => false],
            $validated['purposes']
        );
        $functional = (bool) $purposes['functional'];
        $analytics = (bool) $purposes['analytics'];
        $marketing = (bool) $purposes['marketing'];

        if (privacy_global_gpc($request)) {
            $analytics = false;
            $marketing = false;
        }

        $version = $validated['policy_version'] ?? $policyVersion;
        $now = now();
        $ip = $request->ip();
        $ua = substr((string) $request->userAgent(), 0, 2048);

        $user = $request->user();

        DB::transaction(function () use ($user, $functional, $analytics, $marketing, $version, $now, $ip, $ua) {
            foreach (
                [
                    'functional' => $functional,
                    'analytics' => $analytics,
                    'marketing' => $marketing,
                ] as $purpose => $granted
            ) {
                Consent::query()->create([
                    'user_id' => $user?->id,
                    'purpose' => $purpose,
                    'granted' => $granted,
                    'policy_version' => $version,
                    'granted_at' => $now,
                    'ip_address' => $ip,
                    'user_agent' => $ua,
                ]);
            }
        });

        return response()->json([
            'ok' => true,
            'policy_version' => $version,
            'purposes' => [
                'functional' => $functional,
                'analytics' => $analytics,
                'marketing' => $marketing,
            ],
            'gpc_applied' => privacy_global_gpc($request),
        ]);
    }
}
