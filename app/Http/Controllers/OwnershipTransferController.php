<?php

namespace App\Http\Controllers;

use App\Models\OwnershipTransfer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OwnershipTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

class OwnershipTransferController extends Controller
{
    protected OwnershipTransferService $transferService;

    public function __construct(OwnershipTransferService $transferService)
    {
        $this->transferService = $transferService;
    }

    /**
     * Initiate an ownership transfer.
     */
    public function initiate(Request $request, Tenant $tenant)
    {
        $user = Auth::user();

        // Check authorization - only current tenant owner can initiate
        $policy = new \App\Policies\OwnershipTransferPolicy();
        if (!$policy->initiate($user, $tenant)) {
            abort(403, 'Only the current tenant owner can initiate an ownership transfer.');
        }

        $validated = $request->validate([
            'new_owner_id' => 'required|exists:users,id',
        ]);

        $newOwner = User::findOrFail($validated['new_owner_id']);

        try {
            $transfer = $this->transferService->initiateTransfer($tenant, $user, $newOwner);

            return back()->with('success', 'Ownership transfer initiated. Please check your email to confirm.');
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Confirm the transfer (via signed URL from email).
     */
    public function confirm(Request $request, OwnershipTransfer $transfer)
    {
        // Verify signed URL
        if (!URL::hasValidSignature($request)) {
            abort(403, 'Invalid or expired confirmation link.');
        }

        $user = Auth::user();
        
        if (!$user) {
            // Redirect to login, then back to this URL
            return redirect()->route('login')->with('intended', $request->url());
        }

        // Ensure user is viewing the correct tenant
        $tenant = $transfer->tenant;
        if (session('tenant_id') !== $tenant->id) {
            session(['tenant_id' => $tenant->id]);
        }

        // Check authorization
        if (!Gate::forUser($user)->allows('confirm', $transfer)) {
            abort(403, 'You are not authorized to confirm this transfer.');
        }

        try {
            $this->transferService->confirmTransfer($transfer, $user);

            return redirect()->route('companies.settings')
                ->with('success', 'Ownership transfer confirmed. The new owner will receive an acceptance email.');
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('companies.settings')
                ->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Accept the transfer (via signed URL from email).
     */
    public function accept(Request $request, OwnershipTransfer $transfer)
    {
        // Verify signed URL
        if (!URL::hasValidSignature($request)) {
            abort(403, 'Invalid or expired acceptance link.');
        }

        $user = Auth::user();
        
        if (!$user) {
            // Redirect to login, then back to this URL
            return redirect()->route('login')->with('intended', $request->url());
        }

        // Ensure user is viewing the correct tenant
        $tenant = $transfer->tenant;
        if (session('tenant_id') !== $tenant->id) {
            session(['tenant_id' => $tenant->id]);
        }

        // Check authorization
        if (!Gate::forUser($user)->allows('accept', $transfer)) {
            abort(403, 'You are not authorized to accept this transfer.');
        }

        try {
            $this->transferService->acceptTransfer($transfer, $user);

            return redirect()->route('companies.settings')
                ->with('success', 'Ownership transfer completed. You are now the owner of this company.');
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('companies.settings')
                ->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Cancel a transfer.
     */
    public function cancel(Request $request, OwnershipTransfer $transfer)
    {
        $user = Auth::user();

        // Check authorization
        if (!Gate::forUser($user)->allows('cancel', $transfer)) {
            abort(403, 'You are not authorized to cancel this transfer.');
        }

        try {
            $this->transferService->cancelTransfer($transfer, $user);

            return back()->with('success', 'Ownership transfer cancelled.');
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}
