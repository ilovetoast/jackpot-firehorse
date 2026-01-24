<?php

namespace App\Http\Controllers;

use App\Models\DeletionError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class DeletionErrorController extends Controller
{
    /**
     * Display a listing of deletion errors.
     */
    public function index(Request $request)
    {
        Gate::authorize('viewAny', DeletionError::class);

        $query = DeletionError::with(['tenant', 'resolver'])
            ->where('tenant_id', auth()->user()->tenant_id)
            ->orderBy('created_at', 'desc');

        // Filter by resolution status
        if ($request->has('status')) {
            if ($request->status === 'unresolved') {
                $query->unresolved();
            } elseif ($request->status === 'resolved') {
                $query->resolved();
            }
        } else {
            // Default to unresolved
            $query->unresolved();
        }

        // Filter by error type
        if ($request->filled('error_type')) {
            $query->where('error_type', $request->error_type);
        }

        // Search by filename
        if ($request->filled('search')) {
            $query->where('original_filename', 'like', '%' . $request->search . '%');
        }

        $errors = $query->paginate(25)->withQueryString();

        // Transform for frontend
        $errors->through(function ($error) {
            return [
                'id' => $error->id,
                'asset_id' => $error->asset_id,
                'original_filename' => $error->original_filename,
                'deletion_type' => $error->deletion_type,
                'error_type' => $error->error_type,
                'error_message' => $error->error_message,
                'user_friendly_message' => $error->getUserFriendlyMessage(),
                'severity_level' => $error->getSeverityLevel(),
                'attempts' => $error->attempts,
                'created_at' => $error->created_at->toIso8601String(),
                'resolved_at' => $error->resolved_at?->toIso8601String(),
                'resolver' => $error->resolver ? [
                    'id' => $error->resolver->id,
                    'name' => $error->resolver->name,
                ] : null,
                'resolution_notes' => $error->resolution_notes,
            ];
        });

        // Get error type counts for filtering
        $errorTypeCounts = DeletionError::where('tenant_id', auth()->user()->tenant_id)
            ->unresolved()
            ->selectRaw('error_type, count(*) as count')
            ->groupBy('error_type')
            ->pluck('count', 'error_type')
            ->toArray();

        return Inertia::render('Admin/DeletionErrors', [
            'errors' => $errors,
            'filters' => $request->only(['status', 'error_type', 'search']),
            'errorTypeCounts' => $errorTypeCounts,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(DeletionError $deletionError)
    {
        Gate::authorize('view', $deletionError);

        $deletionError->load(['tenant', 'resolver']);

        return Inertia::render('Admin/DeletionErrors/Show', [
            'deletionError' => [
                'id' => $deletionError->id,
                'asset_id' => $deletionError->asset_id,
                'original_filename' => $deletionError->original_filename,
                'deletion_type' => $deletionError->deletion_type,
                'error_type' => $deletionError->error_type,
                'error_message' => $deletionError->error_message,
                'error_details' => $deletionError->error_details,
                'user_friendly_message' => $deletionError->getUserFriendlyMessage(),
                'severity_level' => $deletionError->getSeverityLevel(),
                'attempts' => $deletionError->attempts,
                'created_at' => $deletionError->created_at->toIso8601String(),
                'resolved_at' => $deletionError->resolved_at?->toIso8601String(),
                'resolver' => $deletionError->resolver ? [
                    'id' => $deletionError->resolver->id,
                    'name' => $deletionError->resolver->name,
                ] : null,
                'resolution_notes' => $deletionError->resolution_notes,
            ],
        ]);
    }

    /**
     * Mark an error as resolved.
     */
    public function resolve(Request $request, DeletionError $deletionError)
    {
        Gate::authorize('update', $deletionError);

        $request->validate([
            'resolution_notes' => 'nullable|string|max:1000',
        ]);

        $deletionError->markResolved(
            auth()->id(),
            $request->resolution_notes
        );

        return back()->with('success', 'Deletion error marked as resolved.');
    }

    /**
     * Retry deletion for a failed asset.
     */
    public function retry(DeletionError $deletionError)
    {
        Gate::authorize('update', $deletionError);

        // Dispatch a new deletion job
        \App\Jobs\DeleteAssetJob::dispatch($deletionError->asset_id);

        return back()->with('success', 'Deletion retry has been queued.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(DeletionError $deletionError)
    {
        Gate::authorize('delete', $deletionError);

        $deletionError->delete();

        return back()->with('success', 'Deletion error record removed.');
    }

    /**
     * Get deletion error statistics for dashboard.
     */
    public function stats()
    {
        $tenantId = auth()->user()->tenant_id;

        $stats = [
            'total_unresolved' => DeletionError::where('tenant_id', $tenantId)->unresolved()->count(),
            'critical_errors' => DeletionError::where('tenant_id', $tenantId)
                ->unresolved()
                ->where('error_type', 'permission_denied')
                ->count(),
            'recent_errors' => DeletionError::where('tenant_id', $tenantId)
                ->unresolved()
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
            'by_type' => DeletionError::where('tenant_id', $tenantId)
                ->unresolved()
                ->selectRaw('error_type, count(*) as count')
                ->groupBy('error_type')
                ->pluck('count', 'error_type')
                ->toArray(),
        ];

        return response()->json($stats);
    }
}