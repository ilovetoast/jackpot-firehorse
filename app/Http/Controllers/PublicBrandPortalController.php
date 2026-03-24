<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Collection;
use App\Services\ActivityRecorder;
use App\Services\BrandGateway\BrandThemeBuilder;
use App\Services\FeatureGate;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class PublicBrandPortalController extends Controller
{
    private const LINK_TOKEN_TTL_HOURS = 24;

    public function __construct(
        protected BrandThemeBuilder $themeBuilder,
        protected FeatureGate $featureGate,
    ) {}

    /**
     * Public portal landing page.
     * No auth required — access governed entirely by portal_settings.
     */
    public function index(Request $request, string $brandSlug)
    {
        $brand = $this->resolveBrand($brandSlug);
        $this->enforcePublicAccess($request, $brand);

        $portal = $brand->portal_settings ?? [];
        $theme = $this->themeBuilder->build($brand->tenant, $brand, true);

        $collections = $this->loadPublicCollections($brand);
        $recentAssets = $this->loadPublicAssets($brand, 12);

        $indexable = data_get($portal, 'public.indexable', false);
        $hasContent = count($collections) > 0 || count($recentAssets) > 0;

        $this->trackPortalEvent(EventType::PORTAL_VIEWED, $brand, [
            'has_content' => $hasContent,
            'collection_count' => count($collections),
            'asset_count' => count($recentAssets),
        ]);

        return Inertia::render('PublicPortal/Index', [
            'brand' => $this->serializeBrand($brand),
            'theme' => $theme,
            'collections' => $collections,
            'recentAssets' => $recentAssets,
            'portalConfig' => [
                'showCollections' => count($collections) > 0,
                'showAssets' => count($recentAssets) > 0,
                'noindex' => ! $indexable,
            ],
        ]);
    }

    /**
     * Public collection detail page.
     */
    public function collection(Request $request, string $brandSlug, Collection $collection)
    {
        $brand = $this->resolveBrand($brandSlug);
        $this->enforcePublicAccess($request, $brand);

        if ($collection->brand_id !== $brand->id || ! $collection->is_public) {
            abort(404);
        }

        $theme = $this->themeBuilder->build($brand->tenant, $brand, true);
        $portal = $brand->portal_settings ?? [];

        $assets = $collection->assets()
            ->whereNull('assets.deleted_at')
            ->whereNull('assets.archived_at')
            ->whereNotNull('assets.published_at')
            ->orderByDesc('assets.created_at')
            ->limit(60)
            ->get()
            ->map(fn (Asset $a) => $this->serializeAsset($a, $brand));

        $indexable = data_get($portal, 'public.indexable', false);

        $this->trackPortalEvent(EventType::PORTAL_COLLECTION_VIEWED, $brand, [
            'collection_id' => $collection->id,
            'asset_count' => $assets->count(),
        ]);

        return Inertia::render('PublicPortal/Collection', [
            'brand' => $this->serializeBrand($brand),
            'theme' => $theme,
            'collection' => [
                'id' => $collection->id,
                'name' => $collection->name,
                'slug' => $collection->slug,
                'description' => $collection->description,
            ],
            'assets' => $assets,
            'noindex' => ! $indexable,
        ]);
    }

    /**
     * Secure asset thumbnail/preview delivery for public portal.
     * Never exposes raw S3 URLs — returns time-limited signed URLs.
     */
    public function asset(Request $request, string $brandSlug, string $assetId)
    {
        $brand = $this->resolveBrand($brandSlug);
        $this->enforcePublicAccess($request, $brand);

        $asset = Asset::where('id', $assetId)
            ->where('brand_id', $brand->id)
            ->whereNull('deleted_at')
            ->whereNull('archived_at')
            ->whereNotNull('published_at')
            ->firstOrFail();

        $inPublicCollection = $asset->collections()
            ->where('brand_id', $brand->id)
            ->where('is_public', true)
            ->exists();

        if (! $inPublicCollection) {
            abort(403, 'Asset is not publicly accessible.');
        }

        $variant = $request->query('variant', 'thumbnail_medium');
        $allowedVariants = ['thumbnail_small', 'thumbnail_medium', 'thumbnail_large'];
        if (! in_array($variant, $allowedVariants)) {
            $variant = 'thumbnail_medium';
        }

        $this->trackPortalEvent(EventType::PORTAL_ASSET_CLICKED, $brand, [
            'asset_id' => $asset->id,
            'variant' => $variant,
        ]);

        $url = $asset->deliveryUrl(
            AssetVariant::from($variant),
            DeliveryContext::PUBLIC_COLLECTION
        );

        return redirect()->away($url);
    }

    // ─── Brand Resolution (cached) ───────────────────────────

    protected function resolveBrand(string $slug): Brand
    {
        return Cache::remember("portal_brand:{$slug}", 60, function () use ($slug) {
            return Brand::where('slug', $slug)
                ->with('tenant')
                ->firstOrFail();
        });
    }

    // ─── Access Enforcement ──────────────────────────────────

    /**
     * Central access enforcement. Checks:
     *  1. Plan allows public portal
     *  2. portal_settings.public.enabled = true
     *  3. visibility is not 'private'
     *  4. link-only mode requires valid, non-expired HMAC token
     */
    protected function enforcePublicAccess(Request $request, Brand $brand): void
    {
        if (! $this->featureGate->brandPortalPublicAccess($brand->tenant)) {
            abort(404);
        }

        $portal = $brand->portal_settings ?? [];
        $enabled = data_get($portal, 'public.enabled', false);
        $visibility = data_get($portal, 'public.visibility', 'private');

        if (! $enabled || $visibility === 'private') {
            abort(404);
        }

        if ($visibility === 'link_only') {
            $this->validateLinkToken($request, $brand);
        }
    }

    /**
     * Validate a time-limited HMAC link token.
     *
     * URL format: ?payload={brandId}|{expires}&token={hmac}
     * Tokens expire after LINK_TOKEN_TTL_HOURS.
     */
    protected function validateLinkToken(Request $request, Brand $brand): void
    {
        $payload = $request->query('payload');
        $token = $request->query('token');

        if (! $payload || ! $token) {
            abort(403, 'A valid access link is required to view this portal.');
        }

        $parts = explode('|', $payload);
        if (count($parts) !== 2) {
            abort(403, 'Invalid access link.');
        }

        [$brandId, $expires] = $parts;

        if ((int) $brandId !== $brand->id) {
            abort(403, 'Invalid access link.');
        }

        if (now()->timestamp > (int) $expires) {
            abort(403, 'This access link has expired. Please request a new one.');
        }

        $expected = hash_hmac('sha256', $payload, config('app.key'));

        if (! hash_equals($expected, $token)) {
            abort(403, 'Invalid access link.');
        }
    }

    // ─── Token Generation ────────────────────────────────────

    /**
     * Generate a time-limited HMAC token for link-only portal access.
     *
     * @param int $ttlHours Hours until token expires (default 24)
     * @return array{payload: string, token: string, expires_at: string}
     */
    public static function generateLinkToken(Brand $brand, int $ttlHours = self::LINK_TOKEN_TTL_HOURS): array
    {
        $expires = now()->addHours($ttlHours)->timestamp;
        $payload = "{$brand->id}|{$expires}";
        $token = hash_hmac('sha256', $payload, config('app.key'));

        return [
            'payload' => $payload,
            'token' => $token,
            'expires_at' => now()->addHours($ttlHours)->toIso8601String(),
        ];
    }

    // ─── Content Loading ─────────────────────────────────────

    protected function loadPublicCollections(Brand $brand): array
    {
        return Cache::remember("portal_collections:{$brand->id}", 60, function () use ($brand) {
            return Collection::where('brand_id', $brand->id)
                ->where('is_public', true)
                ->orderBy('name')
                ->get()
                ->map(function (Collection $c) {
                    $assetCount = $c->assets()
                        ->whereNull('assets.deleted_at')
                        ->whereNull('assets.archived_at')
                        ->whereNotNull('assets.published_at')
                        ->count();

                    $coverAsset = $c->assets()
                        ->whereNull('assets.deleted_at')
                        ->whereNotNull('assets.published_at')
                        ->orderByDesc('assets.created_at')
                        ->first();

                    $coverUrl = $coverAsset
                        ? $coverAsset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::PUBLIC_COLLECTION)
                        : null;

                    return [
                        'id' => $c->id,
                        'name' => $c->name,
                        'slug' => $c->slug,
                        'description' => $c->description,
                        'asset_count' => $assetCount,
                        'cover_url' => $coverUrl,
                    ];
                })
                ->values()
                ->toArray();
        });
    }

    protected function loadPublicAssets(Brand $brand, int $limit = 12): array
    {
        return Cache::remember("portal_assets:{$brand->id}:{$limit}", 60, function () use ($brand, $limit) {
            $publicCollectionIds = Collection::where('brand_id', $brand->id)
                ->where('is_public', true)
                ->pluck('id');

            if ($publicCollectionIds->isEmpty()) {
                return [];
            }

            return Asset::where('brand_id', $brand->id)
                ->whereNull('deleted_at')
                ->whereNull('archived_at')
                ->whereNotNull('published_at')
                ->whereHas('collections', fn ($q) => $q->whereIn('collections.id', $publicCollectionIds)->where('is_public', true))
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->map(fn (Asset $a) => $this->serializeAsset($a, $brand))
                ->toArray();
        });
    }

    // ─── Serializers ─────────────────────────────────────────

    protected function serializeAsset(Asset $asset, ?Brand $brand = null): array
    {
        $thumbUrl = $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::PUBLIC_COLLECTION);
        $largeUrl = $asset->deliveryUrl(AssetVariant::THUMB_LARGE, DeliveryContext::PUBLIC_COLLECTION);

        return [
            'id' => $asset->id,
            'title' => $asset->title ?? $asset->original_filename,
            'original_filename' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
            'thumbnail_url' => $thumbUrl,
            'preview_url' => $largeUrl,
        ];
    }

    protected function serializeBrand(Brand $brand): array
    {
        return [
            'id' => $brand->id,
            'name' => $brand->name,
            'slug' => $brand->slug,
            'custom_domain' => $brand->custom_domain ?? null,
        ];
    }

    // ─── Portal URL Generation ───────────────────────────────

    /**
     * Generate a shareable link for a brand's public portal.
     * Supports subdomain, path-based, and expiring link-only modes.
     *
     * @param int|null $expiresInHours For link-only mode, hours until link expires
     */
    public static function portalUrl(Brand $brand, ?int $expiresInHours = null): ?string
    {
        $portal = $brand->portal_settings ?? [];
        $enabled = data_get($portal, 'public.enabled', false);
        $visibility = data_get($portal, 'public.visibility', 'private');

        if (! $enabled || $visibility === 'private') {
            return null;
        }

        $base = self::resolveBaseUrl($brand);

        if ($visibility === 'link_only') {
            $ttl = $expiresInHours ?? self::LINK_TOKEN_TTL_HOURS;
            $tokenData = self::generateLinkToken($brand, $ttl);

            return "{$base}?payload=" . urlencode($tokenData['payload']) . "&token={$tokenData['token']}";
        }

        return $base;
    }

    /**
     * Resolve the base portal URL for a brand (no token params).
     */
    public static function resolveBaseUrl(Brand $brand): string
    {
        if ($brand->custom_domain ?? null) {
            $scheme = app()->isProduction() ? 'https' : 'http';
            return "{$scheme}://{$brand->custom_domain}";
        }

        $subdomainEnabled = config('subdomain.enabled');
        $rootDomain = config('app.root_domain', config('subdomain.main_domain'));
        $scheme = app()->isProduction() ? 'https' : 'http';

        if ($subdomainEnabled && $rootDomain) {
            return "{$scheme}://{$brand->slug}.{$rootDomain}";
        }

        return rtrim(config('app.url'), '/') . "/portal/{$brand->slug}";
    }

    // ─── Analytics ───────────────────────────────────────────

    /**
     * Fire a lightweight portal analytics event.
     * Non-blocking: failures are silently swallowed.
     */
    protected function trackPortalEvent(string $eventType, Brand $brand, array $metadata = []): void
    {
        try {
            ActivityRecorder::record(
                tenant: $brand->tenant,
                eventType: $eventType,
                subject: $brand,
                actor: 'guest',
                brand: $brand,
                metadata: array_merge($metadata, [
                    'portal' => true,
                    'brand_slug' => $brand->slug,
                ])
            );
        } catch (\Throwable $e) {
            // Portal analytics must never block the public experience
        }
    }
}
