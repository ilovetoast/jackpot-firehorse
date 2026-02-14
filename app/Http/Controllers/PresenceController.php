<?php

namespace App\Http\Controllers;

use App\Services\AuthPermissionService;
use App\Services\PresenceService;
use Illuminate\Http\Request;

class PresenceController extends Controller
{
    public function heartbeat(Request $request): \Illuminate\Http\Response
    {
        $user = auth()->user();
        $tenant = app('tenant');
        $brand = app()->bound('brand') ? app('brand') : null;

        if (! $user || ! $tenant) {
            return response()->noContent();
        }

        $authService = app(AuthPermissionService::class);
        $can = $authService->can($user, 'team.manage', $tenant, $brand)
            || $authService->can($user, 'brand_settings.manage', $tenant, $brand);

        if (! $can) {
            return response()->noContent();
        }

        app(PresenceService::class)->heartbeat($user, $tenant, $brand, ['page' => $request->input('page')]);

        return response()->noContent();
    }

    public function online(): \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
    {
        $user = auth()->user();
        $tenant = app('tenant');
        $brand = app()->bound('brand') ? app('brand') : null;

        if (! $user || ! $tenant) {
            abort(403);
        }

        $authService = app(AuthPermissionService::class);
        $can = $authService->can($user, 'team.manage', $tenant, $brand)
            || $authService->can($user, 'brand_settings.manage', $tenant, $brand);

        if (! $can) {
            abort(403);
        }

        $online = app(PresenceService::class)->online($tenant, $brand);

        return response()->json($online);
    }
}
