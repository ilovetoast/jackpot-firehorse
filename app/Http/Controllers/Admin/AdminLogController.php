<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin Log Viewer Controller
 *
 * Read-only Redis-backed log viewer. No filesystem access.
 * Max 50 entries per stream. Admin-only.
 */
class AdminLogController extends Controller
{
    /**
     * Display the admin log viewer page.
     */
    public function index(): Response
    {
        $user = Auth::user();
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);

        if (! $isSiteOwner && ! $isSiteAdmin) {
            abort(403, 'Only system administrators can access this page.');
        }

        return Inertia::render('Admin/LogViewer', []);
    }

    /**
     * API: Fetch logs from Redis stream. Max 50 entries. No filesystem access.
     */
    public function api(string $stream): JsonResponse
    {
        $user = Auth::user();
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);

        if (! $isSiteOwner && ! $isSiteAdmin) {
            abort(403, 'Only system administrators can access this page.');
        }

        if (! in_array($stream, ['web', 'worker'])) {
            abort(404);
        }

        try {
            $logs = Redis::lrange("admin_logs:{$stream}", 0, 49);
        } catch (\Throwable $e) {
            // Local may not have Redis
            return response()->json(['logs' => []]);
        }

        $decoded = collect($logs)->map(fn ($l) => json_decode($l, true))->filter()->values()->all();

        return response()->json(['logs' => $decoded]);
    }
}
