<?php

namespace App\Support;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;

/**
 * Short-lived encrypted cookie storing last tenant+brand for the authenticated user.
 * It is **not** applied on the primary gateway route (`GET /gateway`): that screen must
 * always offer workspace/brand choice when the user has multiple brands. The cookie is
 * still queued from /app session for TTL continuity across other navigation patterns.
 *
 * @see docs/GATEWAY_ENTRY_CONTROLS_DEFERRED.md
 */
class GatewayResumeCookie
{
    public const NAME = 'jp_gateway_resume';

    public static function ttlMinutes(): int
    {
        $m = (int) config('gateway.resume_ttl_minutes', 240);

        return max(15, min(24 * 60, $m));
    }

    /**
     * Queue forgetting the resume cookie on the outgoing response.
     */
    public static function queueForget(): void
    {
        Cookie::queue(cookie()->forget(self::NAME));
    }

    /**
     * Queue cookie from current session tenant + brand for the authenticated user.
     */
    public static function queueFromSession(): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }
        $tenantId = session('tenant_id');
        $brandId = session('brand_id');
        if (! $tenantId || ! $brandId) {
            return;
        }
        self::queueForUserBrand($user, (int) $tenantId, (int) $brandId);
    }

    public static function queueForUserBrand(User $user, int $tenantId, int $brandId): void
    {
        $payload = [
            'uid' => (int) $user->id,
            'tid' => $tenantId,
            'bid' => $brandId,
            'exp' => now()->addMinutes(self::ttlMinutes())->getTimestamp(),
        ];
        $value = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
        Cookie::queue(
            cookie(
                self::NAME,
                $value,
                self::ttlMinutes(),
                '/',
                null,
                (bool) config('session.secure', false),
                true,
                false,
                config('session.same_site', 'lax')
            )
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $availableCompanies
     * @return array{tenant: Tenant, brand: Brand}|null
     */
    public static function tryDecodeAndAuthorize(
        Request $request,
        User $user,
        array $availableCompanies,
    ): ?array {
        if ($request->query('switch')) {
            return null;
        }
        if ($request->query('brand') || $request->query('company') || $request->query('tenant')) {
            return null;
        }
        if ($request->query('mode') && in_array($request->query('mode'), ['login', 'register'], true)) {
            return null;
        }

        // Never pin workspace from resume on the main gateway page — multi-brand users must
        // choose there; session + normal gateway resolution still apply.
        if ($request->routeIs('gateway')) {
            return null;
        }

        $raw = $request->cookie(self::NAME);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $json = Crypt::decryptString($raw);
            /** @var array{uid?: int, tid?: int, bid?: int, exp?: int} $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (($data['uid'] ?? null) !== (int) $user->id) {
            return null;
        }
        $tenantId = (int) ($data['tid'] ?? 0);
        $brandId = (int) ($data['bid'] ?? 0);
        $exp = (int) ($data['exp'] ?? 0);
        if ($tenantId <= 0 || $brandId <= 0 || $exp < now()->getTimestamp()) {
            return null;
        }

        $companyIds = array_map(static fn ($c) => (int) ($c['id'] ?? 0), $availableCompanies);
        if (! in_array($tenantId, $companyIds, true)) {
            return null;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return null;
        }

        $brand = Brand::query()
            ->where('id', $brandId)
            ->where('tenant_id', $tenantId)
            ->first();
        if (! $brand) {
            return null;
        }

        $tenantRole = $user->getRoleForTenant($tenant);
        $isElevatedTenantUser = in_array($tenantRole, ['owner', 'admin', 'agency_admin'], true);
        if (! $isElevatedTenantUser && ! $user->hasActiveBrandUserAssignment($brand)) {
            return null;
        }

        $planService = app(\App\Services\PlanService::class);
        if ($planService->isBrandDisabledByPlanLimit($brand, $tenant)) {
            return null;
        }

        return ['tenant' => $tenant, 'brand' => $brand];
    }
}
