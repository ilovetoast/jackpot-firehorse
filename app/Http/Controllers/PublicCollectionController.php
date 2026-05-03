<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesGuestCollectionShare;
use App\Models\Asset;
use App\Models\Collection;
use App\Services\AssetSearchService;
use App\Services\AssetSortService;
use App\Services\AssetUrlService;
use App\Services\CollectionAssetQueryService;
use App\Services\CollectionPublicShareGuestAccess;
use App\Services\CollectionZipBuilderService;
use App\Services\DownloadNameResolver;
use App\Services\FeatureGate;
use App\Services\PlanService;
use App\Services\PublicCollectionPageBrandingResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Password-protected collection share links (V1): is_public + public_password_hash + optional token URL.
 * Legacy is_public without a password hash is not served to guests until a password is set.
 *
 * C10: Gated by tenant feature; when disabled, return 404 (not unauthorized).
 * D6: createDownload — collection-scoped ZIP from share page.
 */
class PublicCollectionController extends Controller
{
    use HandlesGuestCollectionShare;

    public function __construct(
        protected AssetUrlService $assetUrlService,
        protected CollectionAssetQueryService $collectionAssetQueryService,
        protected AssetSearchService $assetSearchService,
        protected AssetSortService $assetSortService,
        protected CollectionZipBuilderService $zipBuilder,
        protected FeatureGate $featureGate,
        protected PlanService $planService,
        protected DownloadNameResolver $downloadNameResolver,
        protected PublicCollectionPageBrandingResolver $brandingResolver,
        protected CollectionPublicShareGuestAccess $shareGuestAccess,
    ) {}

    /**
     * Show share collection page by brand slug + collection slug (legacy URL; still supported).
     */
    public function show(Request $request, string $brand_slug, string $collection_slug): Response|RedirectResponse|JsonResponse
    {
        $collection = $this->resolveCollectionByPublicSlug($brand_slug, $collection_slug);
        if (! $collection) {
            abort(404, 'Collection not found.');
        }

        return $this->respondGuestShareCollectionPage($collection, $request, [
            'kind' => 'slug',
            'brand_slug' => $brand_slug,
            'collection_slug' => $collection_slug,
        ]);
    }

    public function unlockSlug(Request $request, string $brand_slug, string $collection_slug): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'max:500'],
        ]);

        $collection = $this->resolveCollectionByPublicSlug($brand_slug, $collection_slug);
        if (! $collection) {
            abort(404, 'Collection not found.');
        }

        $target = route('public.collections.show', [
            'brand_slug' => $brand_slug,
            'collection_slug' => $collection_slug,
        ], false);
        $qs = $request->getQueryString();
        if (is_string($qs) && $qs !== '') {
            $target .= '?'.$qs;
        }

        return $this->finishShareUnlockAttempt(
            $collection,
            $request->string('password')->toString(),
            redirect()->to($target)
        );
    }

    public function lockSlug(string $brand_slug, string $collection_slug): RedirectResponse
    {
        $collection = $this->resolveCollectionByPublicSlug($brand_slug, $collection_slug);
        if (! $collection) {
            abort(404, 'Collection not found.');
        }
        $this->shareGuestAccess->lock($collection);

        return redirect()->route('public.collections.show', [
            'brand_slug' => $brand_slug,
            'collection_slug' => $collection_slug,
        ]);
    }

    /**
     * D6: Create a download (ZIP) for the public collection. No auth.
     */
    public function createDownload(string $brand_slug, string $collection_slug, Request $request): RedirectResponse|JsonResponse
    {
        $collection = $this->resolveCollectionByPublicSlug($brand_slug, $collection_slug);
        if (! $collection) {
            abort(404, 'Collection not found.');
        }

        return $this->performCreateDownload($collection, $request);
    }

    /**
     * D6: Stream collection ZIP. Signed URL required; no Download record.
     */
    public function streamZip(string $brand_slug, string $collection_slug, Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
    {
        $collection = $this->resolveCollectionByPublicSlug($brand_slug, $collection_slug);
        if (! $collection) {
            abort(404, 'Collection not found.');
        }

        return $this->performStreamZip($collection, $request);
    }

    /**
     * Download a single asset from a share collection. No auth.
     */
    public function download(string $brand_slug, string $collection_slug, Asset $asset, Request $request): RedirectResponse
    {
        $collection = $this->resolveCollectionByPublicSlug($brand_slug, $collection_slug);
        if (! $collection) {
            abort(404, 'Collection not found.');
        }

        return $this->performAssetDownload($collection, $asset, $brand_slug, $request);
    }
}
