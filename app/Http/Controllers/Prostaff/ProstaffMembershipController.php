<?php

namespace App\Http\Controllers\Prostaff;

use App\Enums\EventType;
use App\Exceptions\CreatorModuleInactiveException;
use App\Http\Controllers\Controller;
use App\Mail\InviteMember;
use App\Models\Brand;
use App\Models\BrandInvitation;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Prostaff\AssignProstaffMember;
use App\Services\Prostaff\EnsureCreatorApproversConfigured;
use App\Services\Prostaff\EnsureCreatorModuleEnabled;
use App\Services\Prostaff\ResolveCreatorsDashboardAccess;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Assign or invite creators (Creator module required).
 */
class ProstaffMembershipController extends Controller
{
    /**
     * POST /app/api/brands/{brand}/prostaff/members
     *
     * Body: email (required), target_uploads?, period_type?, period_start?
     * Existing workspace user → assign as creator. Otherwise → brand invite; prostaff is applied when they accept.
     */
    public function store(Request $request, Brand $brand): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $tenant = app('tenant');

        if ($brand->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Brand does not belong to this workspace.'], 403);
        }

        $this->authorize('view', $brand);

        if (! app(ResolveCreatorsDashboardAccess::class)->canManage($user, $tenant, $brand)) {
            return response()->json(['error' => 'You do not have permission to manage prostaff assignments.'], 403);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'target_uploads' => ['nullable', 'integer', 'min:0'],
            'period_type' => ['nullable', 'string', 'max:32'],
            'period_start' => ['nullable', 'date'],
        ]);

        try {
            app(EnsureCreatorApproversConfigured::class)->assert($brand);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        $email = strtolower(trim($validated['email']));

        $assignPayload = array_filter([
            'target_uploads' => $validated['target_uploads'] ?? null,
            'period_type' => $validated['period_type'] ?? null,
            'period_start' => $validated['period_start'] ?? null,
            'assigned_by_user_id' => $user->id,
        ], static fn ($v) => $v !== null);

        $subject = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereHas('tenants', static fn ($q) => $q->where('tenants.id', $tenant->id))
            ->first();

        if ($subject !== null) {
            if ($subject->isProstaffForBrand($brand)) {
                return response()->json(['error' => 'This user is already a creator for this brand.'], 422);
            }

            try {
                $membership = app(AssignProstaffMember::class)->assign($subject, $brand, $assignPayload);
            } catch (CreatorModuleInactiveException $e) {
                return response()->json($e->clientPayload(), 403);
            } catch (DomainException $e) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            return response()->json([
                'assigned' => true,
                'id' => $membership->id,
                'user_id' => $membership->user_id,
                'brand_id' => $membership->brand_id,
                'status' => $membership->status,
            ], 201);
        }

        $existingInvitation = $brand->invitations()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->first();

        if ($existingInvitation) {
            return response()->json(['error' => 'An invitation is already pending for this email.'], 422);
        }

        $token = Str::random(64);
        BrandInvitation::create([
            'brand_id' => $brand->id,
            'email' => $validated['email'],
            'role' => 'contributor',
            'metadata' => [
                'assign_prostaff_after_accept' => true,
                'prostaff_target_uploads' => $validated['target_uploads'] ?? null,
                'prostaff_period_type' => $validated['period_type'] ?? 'month',
            ],
            'token' => $token,
            'invited_by' => $user->id,
            'sent_at' => now(),
        ]);

        $inviteUrl = route('gateway.invite', ['token' => $token]);
        Mail::to($validated['email'])->send(new InviteMember($tenant, $user, $inviteUrl));

        $invitedExistingUser = User::whereRaw('LOWER(email) = ?', [$email])->first();

        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_INVITED,
            subject: $invitedExistingUser,
            actor: $user,
            brand: $brand,
            metadata: [
                'email' => $validated['email'],
                'role' => 'contributor',
                'brand_id' => $brand->id,
                'brand_name' => $brand->name,
                'prostaff_invite' => true,
            ]
        );

        return response()->json([
            'invited' => true,
            'email' => $validated['email'],
        ], 201);
    }

    /**
     * PUT /app/api/brands/{brand}/prostaff/members/{user}
     */
    public function update(Request $request, Brand $brand, User $member): JsonResponse
    {
        /** @var User $authUser */
        $authUser = Auth::user();
        $tenant = app('tenant');

        if ($brand->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Brand does not belong to this workspace.'], 403);
        }

        $this->authorize('view', $brand);

        if (! app(ResolveCreatorsDashboardAccess::class)->canManage($authUser, $tenant, $brand)) {
            return response()->json(['error' => 'You do not have permission to manage creators.'], 403);
        }

        try {
            app(EnsureCreatorModuleEnabled::class)->assertEnabled($tenant);
        } catch (CreatorModuleInactiveException $e) {
            return response()->json($e->clientPayload(), 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        $validated = $request->validate([
            'target_uploads' => ['required', 'integer', 'min:0'],
            'period_type' => ['nullable', 'string', 'in:month,quarter,year'],
        ]);

        $membership = $member->activeProstaffMembership($brand);
        if ($membership === null) {
            return response()->json(['error' => 'Not an active creator for this brand.'], 404);
        }

        $membership->update([
            'target_uploads' => $validated['target_uploads'],
            'period_type' => $validated['period_type'] ?? $membership->period_type,
        ]);

        return response()->json([
            'ok' => true,
            'target_uploads' => $membership->target_uploads,
            'period_type' => $membership->period_type,
        ]);
    }

    /**
     * DELETE /app/api/brands/{brand}/prostaff/members/{user}
     */
    public function destroy(Request $request, Brand $brand, User $member): JsonResponse
    {
        /** @var User $authUser */
        $authUser = Auth::user();
        $tenant = app('tenant');

        if ($brand->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Brand does not belong to this workspace.'], 403);
        }

        $this->authorize('view', $brand);

        if (! app(ResolveCreatorsDashboardAccess::class)->canManage($authUser, $tenant, $brand)) {
            return response()->json(['error' => 'You do not have permission to manage creators.'], 403);
        }

        try {
            app(EnsureCreatorModuleEnabled::class)->assertEnabled($tenant);
        } catch (CreatorModuleInactiveException $e) {
            return response()->json($e->clientPayload(), 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        $membership = $member->activeProstaffMembership($brand);
        if ($membership === null) {
            return response()->json(['error' => 'Not an active creator for this brand.'], 404);
        }

        $membership->update([
            'status' => 'removed',
            'ended_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
