<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\SupportTicket;
use App\Models\SystemIncident;
use App\Services\Assets\AssetStateReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * P5: Resolve & Reconcile for asset processing SupportTickets.
 */
class SupportTicketResolveController extends Controller
{
    protected function authorizeAdmin(): void
    {
        $user = Auth::user();
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);
        if (!$isSiteOwner && !$isSiteAdmin) {
            abort(403, 'Only system administrators can access this.');
        }
    }

    /**
     * POST /admin/support-tickets/{supportTicket}/resolve-and-reconcile
     */
    public function resolveAndReconcile(SupportTicket $supportTicket): JsonResponse
    {
        $this->authorizeAdmin();

        if ($supportTicket->source_type !== 'asset' || !$supportTicket->source_id) {
            return response()->json(['message' => 'Ticket is not an asset processing ticket'], 422);
        }

        $asset = Asset::find($supportTicket->source_id);
        if (!$asset) {
            return response()->json(['message' => 'Asset not found'], 404);
        }

        $asset->refresh();
        $analysisStatusBefore = $asset->analysis_status ?? 'uploading';
        $thumbnailStatusBefore = $asset->thumbnail_status?->value ?? null;

        $reconciliationService = app(AssetStateReconciliationService::class);
        $result = $reconciliationService->reconcile($asset->fresh());

        $asset->refresh();
        $analysisStatusAfter = $asset->analysis_status ?? 'uploading';
        $thumbnailStatusAfter = $asset->thumbnail_status?->value ?? null;

        SystemIncident::whereNull('resolved_at')
            ->where(function ($q) use ($asset) {
                $q->where('source_type', 'asset')->where('source_id', $asset->id)
                    ->orWhere(function ($q2) use ($asset) {
                        $q2->where('source_type', 'job')->where('source_id', $asset->id);
                    });
            })
            ->update(['resolved_at' => now(), 'auto_resolved' => true]);

        $metadata = $supportTicket->payload ?? [];
        $metadata['reconciliation'] = [
            'analysis_status_before' => $analysisStatusBefore,
            'analysis_status_after' => $analysisStatusAfter,
            'thumbnail_status_before' => $thumbnailStatusBefore,
            'thumbnail_status_after' => $thumbnailStatusAfter,
            'resolved_at' => now()->toIso8601String(),
        ];
        $metadata['reconciliation_changes'] = $result['changes'];

        $supportTicket->update([
            'status' => 'resolved',
            'payload' => $metadata,
        ]);

        return response()->json([
            'status' => 'resolved',
            'reconciliation' => [
                'updated' => $result['updated'],
                'changes' => $result['changes'],
            ],
        ]);
    }
}
