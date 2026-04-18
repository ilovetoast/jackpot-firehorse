<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\BrandModelVersionAsset;
use App\Models\Category;
use App\Services\AssetPathGenerator;
use App\Services\BrandDNA\BrandVersionService;
use App\Services\BrandDNA\BrandWebsiteCrawlerService;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class OnboardingController extends Controller
{
    public function __construct(
        protected OnboardingService $onboarding,
    ) {}

    public function verifyEmailGate(Request $request): InertiaResponse|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        $brand = app()->bound('brand') ? app('brand') : null;

        // If the user no longer falls under the verification gate (verified
        // already, or a non-owner member of a paid tenant), bounce them back
        // to the app so they aren't stuck on a dead page.
        if (! $this->onboarding->shouldShowVerificationGate($user, $brand)) {
            return redirect('/app/overview');
        }

        return Inertia::render('Auth/VerifyEmail', [
            'email' => $user->email,
        ]);
    }

    public function show(Request $request): InertiaResponse|\Illuminate\Http\RedirectResponse
    {
        $user = $request->user();
        $brand = app('brand');

        if ($this->onboarding->shouldShowVerificationGate($user, $brand)) {
            return redirect('/app/verify-email');
        }

        $progress = $this->onboarding->getOrCreateProgress($brand);

        // Hide the onboarding flow once the brand is either activated (minimum setup done)
        // or fully completed. Previously this required BOTH, so users who hit "Finish" but
        // never triggered the recommended-completion path kept landing back on onboarding.
        if ($progress->isActivated() || $progress->isCompleted()) {
            return redirect('/app/overview');
        }

        $categories = Category::where('brand_id', $brand->id)
            ->whereNull('deleted_at')
            ->whereIn('asset_type', [AssetType::ASSET, AssetType::DELIVERABLE])
            ->orderBy('asset_type')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug', 'icon', 'asset_type', 'is_hidden', 'is_system', 'sort_order']);

        return Inertia::render('Onboarding/Index', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logo_path' => $brand->logo_path,
                'logo_dark_path' => $brand->logo_dark_path,
                'logo_id' => $brand->getRawOriginal('logo_id'),
                'logo_dark_id' => $brand->getRawOriginal('logo_dark_id'),
                'logo_horizontal_path' => $brand->logo_horizontal_path,
                'logo_horizontal_id' => $brand->getRawOriginal('logo_horizontal_id'),
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'accent_color' => $brand->accent_color,
                'icon_style' => $brand->icon_style,
                'icon_bg_color' => $brand->icon_bg_color,
            ],
            'categories' => $categories,
            'progress' => $this->onboarding->getStatusPayload($brand),
            'checklist' => $this->onboarding->getChecklistItems($brand),
        ]);
    }

    public function saveBrandShell(Request $request): JsonResponse
    {
        $brand = app('brand');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'primary_color' => ['sometimes', 'string', 'max:30'],
            'secondary_color' => ['nullable', 'string', 'max:30'],
            'accent_color' => ['nullable', 'string', 'max:30'],
            'logo_id' => ['nullable', 'string'],
            'logo_dark_id' => ['nullable', 'string'],
            'use_monogram' => ['nullable', 'boolean'],
            'mark_type' => ['nullable', 'string', 'in:logo,monogram'],
            'icon_bg_color' => ['nullable', 'string', 'max:30'],
        ]);

        $this->onboarding->saveBrandShell($brand, $validated);

        $fresh = $brand->fresh();
        return response()->json([
            'progress' => $this->onboarding->getStatusPayload($brand),
            'brand' => [
                'id' => $fresh->id,
                'name' => $fresh->name,
                'primary_color' => $fresh->primary_color,
                'secondary_color' => $fresh->secondary_color,
                'accent_color' => $fresh->accent_color,
                'logo_path' => $fresh->logo_path,
                'logo_dark_path' => $fresh->logo_dark_path,
                'logo_id' => $fresh->getRawOriginal('logo_id'),
                'icon_style' => $fresh->icon_style,
                'icon_bg_color' => $fresh->icon_bg_color,
            ],
        ]);
    }

    public function saveStarterAssets(Request $request): JsonResponse
    {
        $brand = app('brand');

        $validated = $request->validate([
            'count' => ['required', 'integer', 'min:0'],
        ]);

        $this->onboarding->recordStarterAssets($brand, $validated['count']);

        return response()->json([
            'progress' => $this->onboarding->getStatusPayload($brand),
        ]);
    }

    public function saveEnrichment(Request $request): JsonResponse
    {
        $brand = app('brand');

        $validated = $request->validate([
            'website_url' => ['nullable', 'string', 'max:500'],
            'industry' => ['nullable', 'string', 'max:255'],
            'guideline_uploaded' => ['nullable', 'boolean'],
            'guideline_asset_id' => ['nullable', 'string', 'uuid'],
        ]);

        $this->onboarding->saveEnrichment($brand, $validated);

        return response()->json([
            'progress' => $this->onboarding->getStatusPayload($brand),
        ]);
    }

    public function activate(Request $request): JsonResponse
    {
        $brand = app('brand');
        $user = $request->user();

        $this->onboarding->activate($brand, $user);

        return response()->json([
            'progress' => $this->onboarding->getStatusPayload($brand),
            'redirect' => '/app/overview',
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        $brand = app('brand');
        $user = $request->user();

        $this->onboarding->completeOnboarding($brand, $user);

        return response()->json([
            'progress' => $this->onboarding->getStatusPayload($brand),
            'redirect' => '/app/overview',
        ]);
    }

    public function dismiss(Request $request): JsonResponse
    {
        $brand = app('brand');
        $this->onboarding->dismissCinematicFlow($brand);

        return response()->json([
            'progress' => $this->onboarding->getStatusPayload($brand),
            'redirect' => '/app/overview',
        ]);
    }

    /**
     * Permanently hide the onboarding card from Overview.
     */
    public function dismissCard(Request $request): JsonResponse
    {
        $brand = app('brand');
        $this->onboarding->dismissCard($brand);

        return response()->json([
            'progress' => $this->onboarding->getStatusPayload($brand),
        ]);
    }

    /**
     * Direct logo file upload for onboarding.
     * Accepts a single image file, creates an Asset, and links it as brand logo.
     */
    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,svg'],
        ]);

        $brand = app('brand');
        $tenant = $brand->tenant;
        $file = $request->file('logo');

        try {
            $mimeType = $file->getMimeType() ?? 'image/png';
            $ext = $file->getClientOriginalExtension() ?: 'png';
            $originalFilename = $file->getClientOriginalName() ?: 'logo.' . $ext;
            $fileSize = $file->getSize();

            $pathGenerator = app(AssetPathGenerator::class);
            $assetId = (string) Str::uuid();
            $path = $pathGenerator->generateOriginalPathForAssetId($tenant, $assetId, 1, $ext);

            $logosCategory = Category::where('brand_id', $brand->id)->where('slug', 'logos')->first();

            $asset = Asset::create([
                'tenant_id' => $brand->tenant_id,
                'brand_id' => $brand->id,
                'status' => AssetStatus::VISIBLE,
                'type' => AssetType::ASSET,
                'title' => 'Brand Logo',
                'original_filename' => $originalFilename,
                'mime_type' => $mimeType,
                'size_bytes' => $fileSize,
                'thumbnail_status' => ThumbnailStatus::PENDING,
                'intake_state' => 'normal',
                'source' => 'onboarding_upload',
                'storage_root_path' => $path,
                'metadata' => $logosCategory ? ['category_id' => $logosCategory->id] : [],
            ]);

            $fileContents = file_get_contents($file->getRealPath());
            Storage::disk('s3')->put($path, $fileContents, 'private');

            AssetVersion::create([
                'id' => (string) Str::uuid(),
                'asset_id' => $asset->id,
                'version_number' => 1,
                'file_path' => $path,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
                'checksum' => hash('sha256', $fileContents),
                'is_current' => true,
                'pipeline_status' => 'pending',
            ]);

            // Dispatch processing for thumbnails
            $version = $asset->fresh()->currentVersion;
            if ($version && ! str_contains($mimeType, 'svg')) {
                $version->update(['pipeline_status' => 'processing']);
                \App\Jobs\ProcessAssetJob::dispatch($version->id)->onQueue(config('queue.images_queue', 'images'));
            }

            // Link as brand logo
            $brand->update(['logo_id' => $asset->id]);

            // Update onboarding progress
            $this->onboarding->confirmLogoMark($brand->fresh(), (string) $asset->id);

            $fresh = $brand->fresh();

            return response()->json([
                'asset_id' => (string) $asset->id,
                'logo_path' => $fresh->logo_path,
                'progress' => $this->onboarding->getStatusPayload($fresh),
            ]);
        } catch (\Throwable $e) {
            Log::error('[OnboardingController] Logo upload failed', [
                'error' => $e->getMessage(),
                'brand_id' => $brand->id,
            ]);

            return response()->json(['error' => 'Upload failed. Please try again.'], 500);
        }
    }

    /**
     * Fetch logo from a website URL using the brand crawler.
     */
    public function fetchLogoFromUrl(Request $request): JsonResponse
    {
        $request->validate([
            'url' => ['required', 'string', 'max:500', 'url'],
        ]);

        $brand = app('brand');
        $url = $request->input('url');

        try {
            $crawler = app(BrandWebsiteCrawlerService::class);
            $crawlResult = $crawler->crawl($url);

            $logoUrl = $crawlResult['logo_url'] ?? null;
            $logoSvg = $crawlResult['logo_svg'] ?? null;

            if (! $logoUrl && ! $logoSvg) {
                return response()->json([
                    'found' => false,
                    'message' => 'No logo found on that website. Try uploading directly instead.',
                ]);
            }

            // Return candidates for user to preview/confirm
            $candidates = [];
            if ($logoSvg) {
                $candidates[] = [
                    'type' => 'svg',
                    'preview' => 'data:image/svg+xml;base64,' . base64_encode($logoSvg),
                    'source' => 'inline_svg',
                ];
            }
            if ($logoUrl) {
                $candidates[] = [
                    'type' => 'url',
                    'preview' => $logoUrl,
                    'url' => $logoUrl,
                    'source' => 'website',
                ];
            }

            // Also include top raster candidates (score > 70) for variety
            foreach (array_slice($crawlResult['logo_candidate_entries'] ?? [], 0, 4) as $entry) {
                $entryUrl = $entry['url'] ?? '';
                if ($entryUrl !== '' && $entryUrl !== $logoUrl && ($entry['score'] ?? 0) >= 70) {
                    $candidates[] = [
                        'type' => 'url',
                        'preview' => $entryUrl,
                        'url' => $entryUrl,
                        'source' => $entry['source'] ?? 'website',
                    ];
                }
            }

            return response()->json([
                'found' => true,
                'candidates' => array_slice($candidates, 0, 5),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[OnboardingController] Logo fetch failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'found' => false,
                'message' => 'Could not reach that website. Check the URL and try again.',
            ]);
        }
    }

    /**
     * Confirm a fetched logo candidate — download it and set as brand logo.
     */
    public function confirmFetchedLogo(Request $request): JsonResponse
    {
        $request->validate([
            'type' => ['required', 'string', 'in:svg,url'],
            'data' => ['required', 'string'],
        ]);

        $brand = app('brand');
        $tenant = $brand->tenant;
        $type = $request->input('type');
        $data = $request->input('data');

        if (! $tenant?->uuid) {
            return response()->json(['error' => 'Tenant configuration issue.'], 422);
        }

        try {
            if ($type === 'svg') {
                $svgCode = base64_decode(preg_replace('/^data:image\/svg\+xml;base64,/', '', $data));
                if (empty($svgCode) || (! str_starts_with(trim($svgCode), '<svg') && ! str_starts_with(trim($svgCode), '<?xml'))) {
                    return response()->json(['error' => 'Invalid SVG data.'], 422);
                }
                $asset = $this->createLogoAssetFromContent(
                    $brand,
                    $svgCode,
                    'image/svg+xml',
                    'svg',
                    'Website Logo (SVG)'
                );
            } else {
                $response = \Illuminate\Support\Facades\Http::timeout(15)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                        'Accept' => 'image/*,*/*;q=0.8',
                    ])
                    ->withOptions(['allow_redirects' => true])
                    ->get($data);

                if (! $response->successful()) {
                    Log::warning('[OnboardingController] Logo download HTTP error', [
                        'url' => $data,
                        'status' => $response->status(),
                    ]);

                    return response()->json(['error' => 'Could not download that logo (HTTP ' . $response->status() . '). Try uploading it directly.'], 422);
                }

                $body = $response->body();
                if (strlen($body) < 50) {
                    return response()->json(['error' => 'Downloaded file was too small to be a logo. Try uploading directly.'], 422);
                }

                $contentType = explode(';', $response->header('Content-Type') ?? 'image/png')[0];

                $isSvg = str_contains($contentType, 'svg')
                    || str_starts_with(trim($body), '<svg')
                    || str_starts_with(trim($body), '<?xml');

                if ($isSvg) {
                    $asset = $this->createLogoAssetFromContent($brand, $body, 'image/svg+xml', 'svg', 'Website Logo (SVG)');
                } else {
                    $ext = match (true) {
                        str_contains($contentType, 'png') => 'png',
                        str_contains($contentType, 'jpeg'), str_contains($contentType, 'jpg') => 'jpg',
                        str_contains($contentType, 'webp') => 'webp',
                        str_contains($contentType, 'gif') => 'gif',
                        default => 'png',
                    };
                    $asset = $this->createLogoAssetFromContent($brand, $body, $contentType, $ext, 'Website Logo');
                }
            }

            if (! $asset) {
                return response()->json(['error' => 'Failed to save logo. Try uploading it directly instead.'], 422);
            }

            $brand->update(['logo_id' => $asset->id]);
            $this->onboarding->confirmLogoMark($brand->fresh(), (string) $asset->id);

            $fresh = $brand->fresh();

            return response()->json([
                'asset_id' => (string) $asset->id,
                'logo_path' => $fresh->logo_path,
                'progress' => $this->onboarding->getStatusPayload($fresh),
            ]);
        } catch (\Throwable $e) {
            Log::error('[OnboardingController] Confirm fetched logo failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'brand_id' => $brand->id,
                'type' => $type,
                'data_length' => strlen($data),
            ]);

            return response()->json([
                'error' => 'Something went wrong saving that logo. Try uploading it directly instead.',
            ], 422);
        }
    }

    private function createLogoAssetFromContent(
        \App\Models\Brand $brand,
        string $content,
        string $mimeType,
        string $ext,
        string $title,
    ): ?Asset {
        $tenant = $brand->tenant;
        $pathGenerator = app(AssetPathGenerator::class);
        $assetId = (string) Str::uuid();
        $path = $pathGenerator->generateOriginalPathForAssetId($tenant, $assetId, 1, $ext);

        $logosCategory = Category::where('brand_id', $brand->id)->where('slug', 'logos')->first();

        $asset = Asset::create([
            'tenant_id' => $brand->tenant_id,
            'brand_id' => $brand->id,
            'status' => AssetStatus::VISIBLE,
            'type' => AssetType::ASSET,
            'title' => $title,
            'original_filename' => 'logo.' . $ext,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($content),
            'thumbnail_status' => ThumbnailStatus::PENDING,
            'intake_state' => 'normal',
            'source' => 'onboarding_website_fetch',
            'storage_root_path' => $path,
            'metadata' => $logosCategory ? ['category_id' => $logosCategory->id] : [],
        ]);

        Storage::disk('s3')->put($path, $content, 'private');

        AssetVersion::create([
            'id' => (string) Str::uuid(),
            'asset_id' => $asset->id,
            'version_number' => 1,
            'file_path' => $path,
            'file_size' => strlen($content),
            'mime_type' => $mimeType,
            'checksum' => hash('sha256', $content),
            'is_current' => true,
            'pipeline_status' => 'pending',
        ]);

        if (! str_contains($mimeType, 'svg')) {
            $version = $asset->fresh()->currentVersion;
            if ($version) {
                $version->update(['pipeline_status' => 'processing']);
                \App\Jobs\ProcessAssetJob::dispatch($version->id)->onQueue(config('queue.images_queue', 'images'));
            }
        }

        return $asset;
    }

    /**
     * Upload starter assets from the onboarding flow.
     * Accepts multiple files, assigns each to the appropriate category based on
     * the frontend-supplied category label, then dispatches processing.
     */
    public function uploadStarterAssets(Request $request): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'max:102400'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['nullable', 'string'],
        ]);

        $brand = app('brand');
        $tenant = $brand->tenant;
        $pathGenerator = app(AssetPathGenerator::class);

        $categoryMap = $this->buildCategorySlugMap($brand);
        $created = [];

        foreach ($request->file('files') as $idx => $file) {
            try {
                $mimeType = $file->getMimeType() ?? 'application/octet-stream';
                $ext = $file->getClientOriginalExtension() ?: 'bin';
                $originalFilename = $file->getClientOriginalName() ?: "file_{$idx}.{$ext}";
                $fileSize = $file->getSize();

                $assetId = (string) Str::uuid();
                $path = $pathGenerator->generateOriginalPathForAssetId($tenant, $assetId, 1, $ext);

                $categoryLabel = $request->input("categories.{$idx}");
                $categoryId = $this->resolveCategoryId($categoryMap, $categoryLabel);

                $metadata = $categoryId ? ['category_id' => $categoryId] : [];

                $asset = Asset::create([
                    'tenant_id' => $brand->tenant_id,
                    'brand_id' => $brand->id,
                    'status' => AssetStatus::VISIBLE,
                    'type' => AssetType::ASSET,
                    'title' => pathinfo($originalFilename, PATHINFO_FILENAME),
                    'original_filename' => $originalFilename,
                    'mime_type' => $mimeType,
                    'size_bytes' => $fileSize,
                    'thumbnail_status' => ThumbnailStatus::PENDING,
                    'intake_state' => $categoryId ? 'normal' : 'staged',
                    'source' => 'onboarding_upload',
                    'storage_root_path' => $path,
                    'metadata' => $metadata,
                ]);

                $fileContents = file_get_contents($file->getRealPath());
                Storage::disk('s3')->put($path, $fileContents, 'private');

                AssetVersion::create([
                    'id' => (string) Str::uuid(),
                    'asset_id' => $asset->id,
                    'version_number' => 1,
                    'file_path' => $path,
                    'file_size' => $fileSize,
                    'mime_type' => $mimeType,
                    'checksum' => hash('sha256', $fileContents),
                    'is_current' => true,
                    'pipeline_status' => 'pending',
                ]);

                if (! str_contains($mimeType, 'svg')) {
                    $version = $asset->fresh()->currentVersion;
                    if ($version) {
                        $version->update(['pipeline_status' => 'processing']);
                        \App\Jobs\ProcessAssetJob::dispatch($version->id)
                            ->onQueue(config('queue.images_queue', 'images'));
                    }
                }

                $created[] = (string) $asset->id;
            } catch (\Throwable $e) {
                Log::error('[OnboardingController] Starter asset upload failed', [
                    'error' => $e->getMessage(),
                    'file_index' => $idx,
                    'brand_id' => $brand->id,
                ]);
            }
        }

        return response()->json([
            'uploaded' => count($created),
            'asset_ids' => $created,
        ]);
    }

    /**
     * Upload a brand guidelines PDF during onboarding.
     * Creates a REFERENCE asset, links it to the working brand model version
     * as `guidelines_pdf`, and returns the asset ID so saveEnrichment can
     * dispatch the pipeline job.
     */
    public function uploadGuideline(Request $request): JsonResponse
    {
        $request->validate([
            'guideline' => ['required', 'file', 'max:102400', 'mimes:pdf'],
        ]);

        $brand = app('brand');
        $tenant = $brand->tenant;
        $file = $request->file('guideline');

        try {
            $mimeType = $file->getMimeType() ?? 'application/pdf';
            $ext = 'pdf';
            $originalFilename = $file->getClientOriginalName() ?: 'brand-guidelines.pdf';
            $fileSize = $file->getSize();

            $pathGenerator = app(AssetPathGenerator::class);
            $assetId = (string) Str::uuid();
            $path = $pathGenerator->generateOriginalPathForAssetId($tenant, $assetId, 1, $ext);

            $refCategory = Category::where('brand_id', $brand->id)
                ->where('slug', 'reference_material')
                ->first();

            $asset = Asset::create([
                'tenant_id' => $brand->tenant_id,
                'brand_id' => $brand->id,
                'status' => AssetStatus::VISIBLE,
                'type' => AssetType::REFERENCE,
                'title' => 'Brand Guidelines',
                'original_filename' => $originalFilename,
                'mime_type' => $mimeType,
                'size_bytes' => $fileSize,
                'thumbnail_status' => ThumbnailStatus::PENDING,
                'intake_state' => 'normal',
                'source' => 'onboarding_guidelines',
                'storage_root_path' => $path,
                'builder_staged' => true,
                'builder_context' => 'guidelines_pdf',
                'metadata' => $refCategory ? ['category_id' => $refCategory->id] : [],
            ]);

            $fileContents = file_get_contents($file->getRealPath());
            Storage::disk('s3')->put($path, $fileContents, 'private');

            AssetVersion::create([
                'id' => (string) Str::uuid(),
                'asset_id' => $asset->id,
                'version_number' => 1,
                'file_path' => $path,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
                'checksum' => hash('sha256', $fileContents),
                'is_current' => true,
                'pipeline_status' => 'pending',
            ]);

            // Link to the working brand model version as guidelines_pdf
            $versionService = app(BrandVersionService::class);
            $draft = $versionService->getWorkingVersion($brand);

            BrandModelVersionAsset::where('brand_model_version_id', $draft->id)
                ->where('builder_context', 'guidelines_pdf')
                ->delete();

            BrandModelVersionAsset::create([
                'brand_model_version_id' => $draft->id,
                'asset_id' => $asset->id,
                'builder_context' => 'guidelines_pdf',
            ]);

            return response()->json([
                'asset_id' => (string) $asset->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[OnboardingController] Guideline upload failed', [
                'error' => $e->getMessage(),
                'brand_id' => $brand->id,
            ]);

            return response()->json(['error' => 'Upload failed. Please try again.'], 500);
        }
    }

    /**
     * Save category visibility selections from onboarding.
     */
    public function saveCategoryPreferences(Request $request): JsonResponse
    {
        $request->validate([
            'visible_category_ids' => ['required', 'array'],
            'visible_category_ids.*' => ['integer'],
            'custom_suggestions' => ['nullable', 'array', 'max:3'],
            'custom_suggestions.*' => ['nullable', 'string', 'max:100'],
        ]);

        $brand = app('brand');
        $visibleIds = collect($request->input('visible_category_ids'));

        $categories = Category::where('brand_id', $brand->id)
            ->whereNull('deleted_at')
            ->get();

        foreach ($categories as $category) {
            $shouldBeVisible = $visibleIds->contains($category->id);
            if ($shouldBeVisible && $category->is_hidden) {
                $category->update(['is_hidden' => false]);
            } elseif (! $shouldBeVisible && ! $category->is_hidden) {
                $category->update(['is_hidden' => true]);
            }
        }

        $suggestions = array_filter($request->input('custom_suggestions', []), fn ($s) => $s && trim($s));
        if (! empty($suggestions)) {
            $progress = $this->onboarding->getOrCreateProgress($brand);
            $meta = $progress->metadata ?? [];
            $meta['custom_category_suggestions'] = array_values($suggestions);
            $progress->metadata = $meta;
            $progress->save();
        }

        $this->onboarding->recordCategoryPreferences($brand);

        return response()->json([
            'success' => true,
            'progress' => $this->onboarding->getStatusPayload($brand),
        ]);
    }

    /**
     * Reset onboarding so a user who completed it can re-run the guided setup.
     */
    public function resetOnboarding(Request $request): JsonResponse
    {
        $brand = app('brand');
        $progress = $this->onboarding->getOrCreateProgress($brand);

        $progress->current_step = OnboardingService::STEP_WELCOME;
        $progress->dismissed_at = null;
        $progress->card_dismissed_at = null;
        $progress->completed_at = null;
        $progress->save();

        return response()->json([
            'progress' => $this->onboarding->getStatusPayload($brand),
            'redirect' => '/app/onboarding',
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $brand = app('brand');

        return response()->json([
            'progress' => $this->onboarding->getStatusPayload($brand),
            'checklist' => $this->onboarding->getChecklistItems($brand),
        ]);
    }

    private function buildCategorySlugMap(\App\Models\Brand $brand): array
    {
        $map = [];
        $categories = Category::where('brand_id', $brand->id)->whereNull('deleted_at')->get();
        foreach ($categories as $cat) {
            $map[strtolower($cat->slug)] = $cat->id;
            $map[strtolower($cat->name)] = $cat->id;
        }

        return $map;
    }

    private function resolveCategoryId(array $map, ?string $label): ?int
    {
        if (! $label) {
            return null;
        }

        $key = strtolower(trim($label));

        return $map[$key] ?? null;
    }
}
