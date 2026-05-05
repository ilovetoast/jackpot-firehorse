<?php

namespace App\Http\Controllers;

use App\Services\AuthPermissionService;
use App\Services\HelpActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HelpActionController extends Controller
{
    public function index(Request $request, AuthPermissionService $authPermissionService, HelpActionService $helpActionService): JsonResponse
    {
        $user = $request->user();
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        $brand = app()->bound('brand') ? app('brand') : null;

        $permissions = $authPermissionService->effectivePermissions($user, $tenant, $brand);

        $payload = $helpActionService->forRequest(
            $request->query('q'),
            $permissions,
            $brand
        );

        return response()->json($payload);
    }
}
