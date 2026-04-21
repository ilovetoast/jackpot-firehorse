<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AnonymizeUserPersonalDataJob;
use App\Models\DataSubjectRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DataSubjectRequestController extends Controller
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
        if (! $isSiteOwner && ! $isSiteAdmin) {
            abort(403, 'Only system administrators can access this page.');
        }
    }

    public function index(): Response
    {
        $this->authorizeAdmin();

        $requests = DataSubjectRequest::query()
            ->with('user:id,first_name,last_name,email')
            ->orderByDesc('created_at')
            ->limit(300)
            ->get()
            ->map(fn (DataSubjectRequest $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'status' => $r->status,
                'user_message' => $r->user_message,
                'admin_notes' => $r->admin_notes,
                'failure_reason' => $r->failure_reason,
                'created_at' => $r->created_at?->toIso8601String(),
                'processed_at' => $r->processed_at?->toIso8601String(),
                'user' => $r->user ? [
                    'id' => $r->user->id,
                    'name' => $r->user->name,
                    'email' => $r->user->email,
                ] : null,
            ]);

        return Inertia::render('Admin/DataSubjectRequests/Index', [
            'requests' => $requests,
        ]);
    }

    public function approveErasure(Request $request, DataSubjectRequest $dataSubjectRequest)
    {
        $this->authorizeAdmin();

        if ($dataSubjectRequest->type !== DataSubjectRequest::TYPE_ERASURE) {
            abort(404);
        }
        if ($dataSubjectRequest->status !== DataSubjectRequest::STATUS_PENDING) {
            return redirect()->back()->with('error', 'This request is no longer pending.');
        }

        AnonymizeUserPersonalDataJob::dispatch($dataSubjectRequest->id, (int) $request->user()->id);

        return redirect()->back()->with('success', 'Erasure processing has been queued.');
    }

    public function reject(Request $request, DataSubjectRequest $dataSubjectRequest)
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($dataSubjectRequest->status !== DataSubjectRequest::STATUS_PENDING) {
            return redirect()->back()->with('error', 'Only pending requests can be rejected.');
        }

        $dataSubjectRequest->update([
            'status' => DataSubjectRequest::STATUS_REJECTED,
            'processed_at' => now(),
            'processed_by_user_id' => $request->user()->id,
            'admin_notes' => $validated['admin_notes'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Request marked as rejected.');
    }
}
