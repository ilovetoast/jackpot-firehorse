<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\CollectionMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Collection invites (C7). Internal users only.
 * Invite / accept / decline. No email invites, no external users.
 */
class CollectionInviteController extends Controller
{
    /**
     * Invite an existing user to the collection (C7).
     * Creates collection_members row with invited_at; accepted_at = null.
     */
    public function invite(Request $request, Collection $collection): JsonResponse
    {
        Gate::forUser($request->user())->authorize('invite', $collection);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = User::find($validated['user_id']);
        if (! $user) {
            throw ValidationException::withMessages(['user_id' => ['User not found.']]);
        }

        // User must be in same tenant (do not grant brand access - internal users only)
        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant || ! $brand) {
            abort(403, 'Tenant or brand not resolved.');
        }
        if ($collection->tenant_id !== $tenant->id || $collection->brand_id !== $brand->id) {
            abort(404, 'Collection not found.');
        }
        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            throw ValidationException::withMessages(['user_id' => ['User must belong to the same tenant.']]);
        }

        $member = CollectionMember::query()
            ->where('collection_id', $collection->id)
            ->where('user_id', $user->id)
            ->first();

        if ($member) {
            // Re-invite: update invited_at, clear accepted_at so they must accept again (or leave as-is per product choice)
            $member->invited_at = now();
            $member->accepted_at = null;
            $member->save();

            return response()->json(['member' => [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'invited_at' => $member->invited_at?->toIso8601String(),
                'accepted_at' => null,
            ]], 200);
        }

        $member = CollectionMember::create([
            'collection_id' => $collection->id,
            'user_id' => $user->id,
            'invited_at' => now(),
            'accepted_at' => null,
        ]);

        return response()->json(['member' => [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'invited_at' => $member->invited_at?->toIso8601String(),
            'accepted_at' => null,
        ]], 201);
    }

    /**
     * Accept an invite (C7). User must be the invited user_id.
     */
    public function accept(Request $request, Collection $collection): JsonResponse
    {
        $user = $request->user();
        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant || ! $brand) {
            abort(403, 'Tenant or brand not resolved.');
        }
        if ($collection->tenant_id !== $tenant->id || $collection->brand_id !== $brand->id) {
            abort(404, 'Collection not found.');
        }

        $member = CollectionMember::query()
            ->where('collection_id', $collection->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $member) {
            return response()->json(['message' => 'No pending invite found for this collection.'], 404);
        }

        $member->accepted_at = now();
        $member->save();

        return response()->json(['member' => [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'invited_at' => $member->invited_at?->toIso8601String(),
            'accepted_at' => $member->accepted_at?->toIso8601String(),
        ]]);
    }

    /**
     * Decline an invite (C7). User must be the invited user_id. Deletes the row.
     */
    public function decline(Request $request, Collection $collection): JsonResponse
    {
        $user = $request->user();
        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant || ! $brand) {
            abort(403, 'Tenant or brand not resolved.');
        }
        if ($collection->tenant_id !== $tenant->id || $collection->brand_id !== $brand->id) {
            abort(404, 'Collection not found.');
        }

        $member = CollectionMember::query()
            ->where('collection_id', $collection->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $member) {
            return response()->json(['message' => 'No pending invite found for this collection.'], 404);
        }

        $member->delete();

        return response()->json(['declined' => true]);
    }
}
