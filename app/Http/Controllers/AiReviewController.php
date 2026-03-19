<?php

namespace App\Http\Controllers;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\Category;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AI Review Workspace — split view for tag and category suggestions.
 * GET /app/insights/review — page
 * GET /app/api/ai/review?type=tags|categories — data
 */
class AiReviewController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Insights/Review', [
            'initialTab' => $request->query('tab', 'tags'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = Auth::user();
        $type = $request->query('type', 'tags');

        if (!$tenant || !$brand) {
            return response()->json(['message' => 'Tenant and brand must be selected'], 403);
        }

        if (!$user->hasPermissionForTenant($tenant, 'metadata.suggestions.view')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        if ($type === 'tags') {
            return $this->getTagSuggestions($tenant, $brand);
        }
        if ($type === 'categories') {
            return $this->getCategorySuggestions($tenant, $brand);
        }

        return response()->json(['message' => 'Invalid type. Use tags or categories.'], 400);
    }

    protected function getTagSuggestions($tenant, $brand): JsonResponse
    {
        $candidates = DB::table('asset_tag_candidates')
            ->join('assets', 'asset_tag_candidates.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->where('asset_tag_candidates.producer', 'ai')
            ->whereNull('asset_tag_candidates.resolved_at')
            ->whereNull('asset_tag_candidates.dismissed_at')
            ->select(
                'asset_tag_candidates.id',
                'asset_tag_candidates.asset_id',
                'asset_tag_candidates.tag as value',
                'asset_tag_candidates.confidence',
                'asset_tag_candidates.source',
                'assets.title as asset_title',
                'assets.original_filename as asset_filename',
                'assets.thumbnail_status',
                'assets.metadata'
            )
            ->orderByDesc('asset_tag_candidates.confidence')
            ->orderByDesc('asset_tag_candidates.created_at')
            ->get();

        return $this->formatTagResponse($candidates);
    }

    protected function getCategorySuggestions($tenant, $brand): JsonResponse
    {
        $candidates = DB::table('asset_metadata_candidates')
            ->join('assets', 'asset_metadata_candidates.asset_id', '=', 'assets.id')
            ->join('metadata_fields', 'asset_metadata_candidates.metadata_field_id', '=', 'metadata_fields.id')
            ->where('assets.tenant_id', $tenant->id)
            ->where('assets.brand_id', $brand->id)
            ->whereNull('asset_metadata_candidates.resolved_at')
            ->whereNull('asset_metadata_candidates.dismissed_at')
            ->where('asset_metadata_candidates.producer', 'ai')
            ->select(
                'asset_metadata_candidates.id',
                'asset_metadata_candidates.asset_id',
                'asset_metadata_candidates.metadata_field_id',
                'asset_metadata_candidates.value_json',
                'asset_metadata_candidates.confidence',
                'asset_metadata_candidates.source',
                'metadata_fields.key as field_key',
                'metadata_fields.system_label as field_label',
                'metadata_fields.type as field_type',
                'assets.title as asset_title',
                'assets.original_filename as asset_filename',
                'assets.thumbnail_status',
                'assets.metadata'
            )
            ->orderByDesc('asset_metadata_candidates.confidence')
            ->orderByDesc('asset_metadata_candidates.created_at')
            ->get();

        $fieldIds = $candidates->pluck('metadata_field_id')->unique();
        $optionsMap = [];
        if ($fieldIds->isNotEmpty()) {
            $options = DB::table('metadata_options')
                ->whereIn('metadata_field_id', $fieldIds)
                ->select('metadata_field_id', 'value', 'system_label as display_label')
                ->get()
                ->groupBy('metadata_field_id');
            foreach ($options as $fieldId => $opts) {
                $optionsMap[$fieldId] = $opts->map(fn ($opt) => [
                    'value' => $opt->value,
                    'display_label' => $opt->display_label,
                ])->toArray();
            }
        }

        return $this->formatCategoryResponse($candidates, $optionsMap);
    }

    protected function formatTagResponse($candidates): JsonResponse
    {
        $assetIds = $candidates->pluck('asset_id')->unique()->all();
        $assets = Asset::whereIn('id', $assetIds)->get()->keyBy('id');
        $categoryIds = $assets->pluck('metadata')->map(fn ($m) => (is_array($m ?? null) ? ($m['category_id'] ?? null) : null))->filter()->unique()->values()->all();
        $categories = $categoryIds ? Category::whereIn('id', $categoryIds)->get()->keyBy('id') : collect();

        $items = [];
        foreach ($candidates as $c) {
            $asset = $assets->get($c->asset_id);
            $thumbnailUrls = $this->getThumbnailUrls($asset);
            $metadata = $asset ? ($asset->metadata ?? []) : [];
            $categoryId = $metadata['category_id'] ?? null;

            $items[] = [
                'id' => $c->id,
                'asset_id' => $c->asset_id,
                'type' => 'tag',
                'suggestion' => $c->value,
                'value' => $c->value,
                'confidence' => $c->confidence ? (float) $c->confidence : null,
                'asset_title' => $c->asset_title,
                'asset_filename' => $c->asset_filename,
                'asset_category' => $categoryId ? ($categories->get($categoryId)?->name ?? null) : null,
                'thumbnail_url' => $thumbnailUrls['final'] ?? $thumbnailUrls['preview'] ?? null,
                'thumbnail_status' => $asset ? ($asset->thumbnail_status ?? 'pending') : 'pending',
            ];
        }

        return response()->json(['items' => $items, 'total' => count($items)]);
    }

    protected function formatCategoryResponse($candidates, array $optionsMap): JsonResponse
    {
        $assetIds = $candidates->pluck('asset_id')->unique()->all();
        $assets = Asset::whereIn('id', $assetIds)->get()->keyBy('id');
        $categoryIds = $assets->pluck('metadata')->map(fn ($m) => (is_array($m ?? null) ? ($m['category_id'] ?? null) : null))->filter()->unique()->values()->all();
        $categories = $categoryIds ? Category::whereIn('id', $categoryIds)->get()->keyBy('id') : collect();

        $items = [];
        foreach ($candidates as $c) {
            $asset = $assets->get($c->asset_id);
            $thumbnailUrls = $this->getThumbnailUrls($asset);
            $value = json_decode($c->value_json, true);
            $metadata = $asset ? ($asset->metadata ?? []) : [];
            $categoryId = $metadata['category_id'] ?? null;

            $displayValue = is_array($value) ? ($value['value'] ?? $value['id'] ?? json_encode($value)) : (string) $value;
            if (isset($optionsMap[$c->metadata_field_id])) {
                foreach ($optionsMap[$c->metadata_field_id] as $opt) {
                    if (($opt['value'] ?? null) == $displayValue) {
                        $displayValue = $opt['display_label'] ?? $displayValue;
                        break;
                    }
                }
            }

            $items[] = [
                'id' => $c->id,
                'asset_id' => $c->asset_id,
                'type' => 'metadata_candidate',
                'suggestion' => $displayValue,
                'field_key' => $c->field_key,
                'field_label' => $c->field_label,
                'field_type' => $c->field_type,
                'value' => $value,
                'confidence' => $c->confidence ? (float) $c->confidence : null,
                'asset_title' => $c->asset_title,
                'asset_filename' => $c->asset_filename,
                'asset_category' => $categoryId ? ($categories->get($categoryId)?->name ?? null) : null,
                'thumbnail_url' => $thumbnailUrls['final'] ?? $thumbnailUrls['preview'] ?? null,
                'thumbnail_status' => $asset ? ($asset->thumbnail_status ?? 'pending') : 'pending',
                'options' => $optionsMap[$c->metadata_field_id] ?? [],
            ];
        }

        return response()->json(['items' => $items, 'total' => count($items)]);
    }

    protected function getThumbnailUrls(?Asset $asset): array
    {
        if (!$asset) {
            return ['preview' => null, 'final' => null];
        }
        $metadata = $asset->metadata ?? [];
        $thumbnailStatus = $asset->thumbnail_status instanceof ThumbnailStatus
            ? $asset->thumbnail_status->value
            : ($asset->thumbnail_status ?? 'pending');

        $preview = $asset->deliveryUrl(AssetVariant::THUMB_PREVIEW, DeliveryContext::AUTHENTICATED) ?: null;
        $final = null;
        $thumbnails = $metadata['thumbnails'] ?? [];
        $thumbnailsExist = !empty($thumbnails) && (isset($thumbnails['thumb']) || isset($thumbnails['medium']));

        if ($thumbnailStatus === 'completed' || $thumbnailsExist) {
            $mediumPath = $asset->thumbnailPathForStyle('medium');
            $variant = $mediumPath ? AssetVariant::THUMB_MEDIUM : AssetVariant::THUMB_SMALL;
            $final = $asset->deliveryUrl($variant, DeliveryContext::AUTHENTICATED);
        }

        return ['preview' => $preview, 'final' => $final];
    }
}