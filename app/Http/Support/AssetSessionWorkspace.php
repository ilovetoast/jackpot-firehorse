<?php

namespace App\Http\Support;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ensures the bound session tenant/brand matches the asset when serving app routes.
 * Cross-tenant users who belong to the asset's tenant get a 409 with actionable copy;
 * others keep a generic 404 to avoid leaking existence across companies.
 */
final class AssetSessionWorkspace
{
    public const ABORT_WRONG_TENANT = 'asset.workspace_mismatch';

    public const ABORT_WRONG_BRAND = 'asset.brand_mismatch';

    public static function friendlyTitle(string $code): string
    {
        return match ($code) {
            self::ABORT_WRONG_TENANT => 'Wrong company workspace',
            self::ABORT_WRONG_BRAND => 'Wrong brand workspace',
            default => 'Something went wrong',
        };
    }

    public static function friendlyMessage(string $code): string
    {
        return match ($code) {
            self::ABORT_WRONG_TENANT => 'This asset belongs to a different company than the workspace you have open. Use the company switcher in the header to open that company, then try again.',
            self::ABORT_WRONG_BRAND => 'This asset belongs to a different brand than the one you have open. Switch brand in the header, then try again.',
            default => 'This asset could not be opened in the current workspace.',
        };
    }

    public static function jsonMismatchResponse(Request $request, Asset $asset, bool $matchBrandIfBound = true): ?JsonResponse
    {
        return match (self::evaluate($request, $asset, $matchBrandIfBound)) {
            'ok' => null,
            'not_found' => response()->json(['message' => 'Asset not found', 'code' => 404], 404),
            'wrong_tenant' => response()->json([
                'message' => self::friendlyMessage(self::ABORT_WRONG_TENANT),
                'title' => self::friendlyTitle(self::ABORT_WRONG_TENANT),
                'code' => 409,
                'error_code' => 'asset_workspace_mismatch',
            ], 409),
            'wrong_brand' => response()->json([
                'message' => self::friendlyMessage(self::ABORT_WRONG_BRAND),
                'title' => self::friendlyTitle(self::ABORT_WRONG_BRAND),
                'code' => 409,
                'error_code' => 'asset_brand_mismatch',
            ], 409),
        };
    }

    public static function assertMatchesSession(Request $request, Asset $asset, bool $matchBrandIfBound = true): void
    {
        match (self::evaluate($request, $asset, $matchBrandIfBound)) {
            'ok' => null,
            'not_found' => abort(404, 'Asset not found.'),
            'wrong_tenant' => abort(409, self::ABORT_WRONG_TENANT),
            'wrong_brand' => abort(409, self::ABORT_WRONG_BRAND),
        };
    }

    /**
     * @return 'ok'|'not_found'|'wrong_tenant'|'wrong_brand'
     */
    private static function evaluate(Request $request, Asset $asset, bool $matchBrandIfBound): string
    {
        if (! app()->bound('tenant') || ! app('tenant')) {
            return 'not_found';
        }

        $tenant = app('tenant');
        if ((int) $asset->tenant_id !== (int) $tenant->id) {
            $user = $request->user();
            if ($user instanceof User && $user->belongsToTenant((int) $asset->tenant_id)) {
                return 'wrong_tenant';
            }

            return 'not_found';
        }

        if ($matchBrandIfBound && app()->bound('brand') && app('brand')) {
            $brand = app('brand');
            if ((int) $asset->brand_id !== (int) $brand->id) {
                $user = $request->user();
                if ($user instanceof User && $user->belongsToTenant((int) $asset->tenant_id)) {
                    return 'wrong_brand';
                }

                return 'not_found';
            }
        }

        return 'ok';
    }
}
