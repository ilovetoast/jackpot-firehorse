<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StudioLayerExtractionSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin observability for Studio “Extract layers” sessions (floodfill + Fal SAM, etc.).
 */
class AdminStudioLayerExtractionController extends Controller
{
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
     * GET /app/admin/ai/studio-layer-extraction
     */
    public function index(Request $request): Response
    {
        $this->authorizeAdmin();

        $limit = min(200, max(10, (int) $request->query('limit', 100)));
        $statusFilter = $request->query('status');
        $statusFilter = is_string($statusFilter) && $statusFilter !== '' ? $statusFilter : null;

        $q = StudioLayerExtractionSession::query()
            ->with([
                'tenant:id,name',
                // users table has first_name/last_name; `name` is an accessor, not a column
                'user:id,first_name,last_name,email',
            ])
            ->orderByDesc('updated_at');

        if ($statusFilter !== null) {
            $q->where('status', $statusFilter);
        }

        $rows = $q->limit($limit)->get()->map(function (StudioLayerExtractionSession $s) {
            $meta = is_array($s->metadata) ? $s->metadata : [];
            $cands = json_decode((string) $s->candidates_json, true);
            $n = is_array($cands) ? count($cands) : 0;
            $method = null;
            if (isset($meta['extraction_method']) && is_string($meta['extraction_method'])) {
                $method = $meta['extraction_method'];
            }
            $serviceKind = 'unknown';
            if ($method === 'ai') {
                $serviceKind = 'ai';
            } elseif ($method === 'local') {
                $serviceKind = 'local';
            }

            return [
                'id' => $s->id,
                'status' => $s->status,
                'provider' => $s->provider,
                'model' => $s->model,
                'extraction_method' => $method,
                'service_kind' => $serviceKind,
                'candidates_count' => $n,
                'error_message' => $s->error_message !== null && $s->error_message !== ''
                    ? mb_substr((string) $s->error_message, 0, 240)
                    : null,
                'composition_id' => $s->composition_id,
                'source_layer_id' => $s->source_layer_id,
                'source_asset_id' => (string) $s->source_asset_id,
                'tenant_name' => $s->tenant?->name,
                'user_name' => $s->user?->name,
                'created_at' => $s->created_at?->toIso8601String(),
                'updated_at' => $s->updated_at?->toIso8601String(),
            ];
        })->values()->all();

        $counts = [
            'total' => (int) StudioLayerExtractionSession::query()->count(),
            'ready' => (int) StudioLayerExtractionSession::query()->where('status', StudioLayerExtractionSession::STATUS_READY)->count(),
            'failed' => (int) StudioLayerExtractionSession::query()->where('status', StudioLayerExtractionSession::STATUS_FAILED)->count(),
            'pending' => (int) StudioLayerExtractionSession::query()->where('status', StudioLayerExtractionSession::STATUS_PENDING)->count(),
            'confirmed' => (int) StudioLayerExtractionSession::query()->where('status', StudioLayerExtractionSession::STATUS_CONFIRMED)->count(),
        ];

        return Inertia::render('Admin/StudioLayerExtraction/Index', [
            'rows' => $rows,
            'counts' => $counts,
            'status_filter' => $statusFilter,
            'admin_asset_url' => url('/app/admin/assets'),
        ]);
    }

    /**
     * GET /app/admin/ai/studio-layer-extraction/{session} — JSON for troubleshooting.
     */
    public function show(string $session): JsonResponse
    {
        $this->authorizeAdmin();

        $s = StudioLayerExtractionSession::query()
            ->with([
                'tenant:id,name',
                'user:id,first_name,last_name,email',
            ])
            ->whereKey($session)
            ->firstOrFail();

        $candsRaw = json_decode((string) $s->candidates_json, true);
        $candidates = is_array($candsRaw) ? $candsRaw : null;

        return response()->json([
            'id' => $s->id,
            'status' => $s->status,
            'provider' => $s->provider,
            'model' => $s->model,
            'error_message' => $s->error_message,
            'metadata' => $s->metadata,
            'layer_artifacts_storage' => [
                'disk' => (string) config('filesystems.disks.studio_layer_extraction.driver', 'local'),
                's3_path_prefix' => (string) config('studio_layer_extraction.s3_path_prefix', 'studio_layer_extraction'),
            ],
            'candidates' => $candidates,
            'composition_id' => $s->composition_id,
            'source_layer_id' => $s->source_layer_id,
            'source_asset_id' => (string) $s->source_asset_id,
            'tenant' => $s->tenant ? ['id' => $s->tenant->id, 'name' => $s->tenant->name] : null,
            'user' => $s->user ? ['id' => $s->user->id, 'name' => $s->user->name] : null,
            'created_at' => $s->created_at?->toIso8601String(),
            'updated_at' => $s->updated_at?->toIso8601String(),
            'expires_at' => $s->expires_at?->toIso8601String(),
        ]);
    }
}
