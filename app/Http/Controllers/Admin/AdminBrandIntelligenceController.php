<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandIntelligenceScore;
use App\Models\BrandVisualReference;
use App\Services\BrandIntelligence\BrandIntelligenceEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Internal Brand Intelligence debugger (site admin / engineering).
 */
class AdminBrandIntelligenceController extends Controller
{
    public function __construct(
        protected BrandIntelligenceEngine $brandIntelligenceEngine
    ) {}

    protected function authorizeAdmin(): void
    {
        $user = Auth::user();
        if (! $user) {
            abort(403);
        }
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);
        $isSiteEngineering = in_array('site_engineering', $siteRoles);
        $canRegenerate = $user->can('assets.regenerate_thumbnails_admin');

        if (! $isSiteOwner && ! $isSiteAdmin && ! $isSiteEngineering && ! $canRegenerate) {
            abort(403, 'Only site owners, site admins, site engineering, or users with assets.regenerate_thumbnails_admin can access this page.');
        }
    }

    /**
     * GET /app/admin/brand-intelligence
     */
    public function index(Request $request): Response
    {
        $this->authorizeAdmin();

        $perPage = min((int) $request->get('per_page', 40), 100);

        $paginator = BrandIntelligenceScore::query()
            ->whereNull('execution_id')
            ->whereNotNull('asset_id')
            ->with([
                'asset' => fn ($q) => $q->withTrashed()->with(['brand:id,name']),
            ])
            ->orderByDesc('updated_at')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->getCollection()->transform(function (BrandIntelligenceScore $bis) {
            $asset = $bis->asset;
            $categoryName = $asset?->category?->name ?? '—';

            return [
                'asset_id' => $bis->asset_id,
                'asset_name' => $asset?->original_filename ?? $asset?->title ?? (string) $bis->asset_id,
                'category_name' => $categoryName,
                'level' => $bis->level,
                'confidence' => $bis->confidence,
                'engine_version' => $bis->engine_version,
                'updated_at' => $bis->updated_at?->toIso8601String(),
            ];
        });

        return Inertia::render('Admin/BrandIntelligence/Index', [
            'rows' => $paginator,
        ]);
    }

    /**
     * GET /app/admin/brand-intelligence/assets/{asset}
     */
    public function show(Request $request, string $asset): Response
    {
        $this->authorizeAdmin();

        $assetModel = Asset::withTrashed()
            ->with([
                'brand.brandModel.activeVersion',
                'tenant:id,name,slug',
                'latestBrandIntelligenceScore',
            ])
            ->findOrFail($asset);

        if ($assetModel->tenant?->uuid) {
            $request->attributes->set('admin_tenants', [$assetModel->tenant->uuid]);
        }

        $score = $assetModel->latestBrandIntelligenceScore;
        $breakdown = $score?->breakdown_json ?? [];

        return Inertia::render('Admin/BrandIntelligence/Show', [
            'asset' => [
                'id' => $assetModel->id,
                'name' => $assetModel->original_filename ?? $assetModel->title ?? $assetModel->id,
                'tenant' => $assetModel->tenant ? ['id' => $assetModel->tenant->id, 'name' => $assetModel->tenant->name] : null,
                'brand' => $assetModel->brand ? ['id' => $assetModel->brand->id, 'name' => $assetModel->brand->name] : null,
                'deleted_at' => $assetModel->deleted_at?->toIso8601String(),
            ],
            'metadata' => $assetModel->metadata ?? [],
            'score' => $score ? [
                'level' => $score->level,
                'confidence' => $score->confidence,
                'engine_version' => $score->engine_version,
                'overall_score' => $score->overall_score,
                'ai_used' => (bool) $score->ai_used,
                'breakdown_json' => $breakdown,
                'updated_at' => $score->updated_at?->toIso8601String(),
            ] : null,
            'dna_warnings' => $this->buildDnaWarnings($assetModel->brand),
        ]);
    }

    /**
     * POST /app/admin/brand-intelligence/assets/{asset}/simulate — score in-process, no DB write.
     */
    public function simulate(string $asset): JsonResponse
    {
        $this->authorizeAdmin();

        $assetModel = Asset::withTrashed()
            ->with('brand')
            ->findOrFail($asset);

        $payload = $this->brandIntelligenceEngine->scoreAsset($assetModel, dryRun: true);

        return response()->json([
            'ok' => true,
            'payload' => $payload,
        ]);
    }

    /**
     * @return list<string>
     */
    protected function buildDnaWarnings(?Brand $brand): array
    {
        if (! $brand) {
            return ['Asset has no brand.'];
        }

        $warnings = [];

        $refCount = BrandVisualReference::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('embedding_vector')
            ->count();

        if ($refCount === 0) {
            $warnings[] = 'No brand visual references with embeddings — reference similarity is limited.';
        }

        $brand->loadMissing('brandModel.activeVersion');
        $payload = $brand->brandModel?->activeVersion?->model_payload ?? [];
        if (! is_array($payload)) {
            $payload = [];
        }

        $rules = is_array($payload['scoring_rules'] ?? null) ? $payload['scoring_rules'] : [];
        $personality = is_array($payload['personality'] ?? null) ? $payload['personality'] : [];
        $toneKeywords = $rules['tone_keywords'] ?? null;
        $hasToneKeywords = false;
        if (is_array($toneKeywords)) {
            $hasToneKeywords = count(array_filter($toneKeywords, fn ($v) => $v !== null && $v !== '' && $v !== [])) > 0;
        } elseif (is_string($toneKeywords)) {
            $hasToneKeywords = trim($toneKeywords) !== '';
        }
        $hasTone = ! empty($personality['tone'] ?? null) || ! empty($personality['voice'] ?? null);

        if (! $hasToneKeywords && ! $hasTone) {
            $warnings[] = 'No tone keywords in DNA — tone context for scoring/AI is thin.';
        }

        $typography = is_array($payload['typography'] ?? null) ? $payload['typography'] : [];
        $hasTypographyRules = ! empty($typography['primary_font'] ?? null)
            || ! empty($typography['secondary_font'] ?? null)
            || ! empty($typography['fonts'] ?? null);

        if (! $hasTypographyRules) {
            $warnings[] = 'No typography rules in DNA — typography signals may be weak.';
        }

        return $warnings;
    }
}
