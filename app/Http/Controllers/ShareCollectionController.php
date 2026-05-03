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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Token-based share URLs: /share/collections/{public_share_token}
 *
 * Does not extend PublicCollectionController — route action signatures differ (PHP LSP).
 */
class ShareCollectionController extends Controller
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

    public function show(Request $request, string $token): Response|RedirectResponse|JsonResponse
    {
        $collection = Collection::query()
            ->where('public_share_token', $token)
            ->with(['brand', 'tenant'])
            ->first();

        if (! $collection) {
            abort(404, 'Collection not found.');
        }

        return $this->respondGuestShareCollectionPage($collection, $request, [
            'kind' => 'token',
            'token' => $token,
        ]);
    }

    public function unlock(Request $request, string $token): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'max:500'],
        ]);

        $collection = Collection::query()
            ->where('public_share_token', $token)
            ->with(['brand', 'tenant'])
            ->first();

        if (! $collection) {
            abort(404, 'Collection not found.');
        }

        $target = route('share.collections.show', ['token' => $token], false);
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

    public function lock(Request $request, string $token): RedirectResponse
    {
        $collection = Collection::query()
            ->where('public_share_token', $token)
            ->first();

        if (! $collection) {
            abort(404, 'Collection not found.');
        }

        $this->shareGuestAccess->lock($collection);

        return redirect()->route('share.collections.show', ['token' => $token]);
    }

    public function createDownload(Request $request, string $token): RedirectResponse|JsonResponse
    {
        $collection = Collection::query()
            ->where('public_share_token', $token)
            ->with(['brand', 'tenant'])
            ->first();

        if (! $collection) {
            abort(404, 'Collection not found.');
        }

        return $this->performCreateDownload($collection, $request);
    }

    public function streamZip(Request $request, string $token): \Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
    {
        $collection = Collection::query()
            ->where('public_share_token', $token)
            ->with(['brand', 'tenant'])
            ->first();

        if (! $collection) {
            abort(404, 'Collection not found.');
        }

        return $this->performStreamZip($collection, $request);
    }

    public function download(Request $request, string $token, Asset $asset): RedirectResponse|StreamedResponse
    {
        $collection = Collection::query()
            ->where('public_share_token', $token)
            ->with(['brand', 'tenant'])
            ->first();

        if (! $collection) {
            abort(404, 'Collection not found.');
        }

        return $this->performAssetDownload($collection, $asset, $collection->brand?->slug ?? '', $request);
    }
}
