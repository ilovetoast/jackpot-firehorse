<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\AuthPermissionService;
use App\Services\HelpActionService;
use App\Services\HelpAiAskService;
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

        $rawQ = $request->query('q');
        $query = is_string($rawQ) ? $rawQ : null;

        $payload = $helpActionService->forRequest(
            $query,
            $permissions,
            $brand
        );

        return response()->json($payload);
    }

    public function ask(Request $request, AuthPermissionService $authPermissionService, HelpAiAskService $helpAiAskService): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        /** @var Tenant $tenant */
        $tenant = app('tenant');
        $brand = app()->bound('brand') ? app('brand') : null;

        $permissions = $authPermissionService->effectivePermissions($user, $tenant, $brand);

        $payload = $helpAiAskService->ask(
            $validated['question'],
            $permissions,
            $brand,
            $tenant,
            $user
        );

        return response()->json($payload);
    }
}
