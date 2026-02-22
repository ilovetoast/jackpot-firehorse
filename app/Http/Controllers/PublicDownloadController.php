<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\AssetUrlService;
use Illuminate\Http\RedirectResponse;

class PublicDownloadController extends Controller
{
    public function __construct(
        protected AssetUrlService $assetUrlService
    ) {
    }

    public function __invoke(Asset $asset): RedirectResponse
    {
        if (! $asset->isPublic()) {
            abort(403, 'Asset is not publicly accessible.');
        }

        $downloadUrl = $this->assetUrlService->getPublicDownloadUrl($asset);
        if (! $downloadUrl) {
            abort(403, 'Asset is not publicly accessible.');
        }

        return redirect()->away($downloadUrl);
    }
}
