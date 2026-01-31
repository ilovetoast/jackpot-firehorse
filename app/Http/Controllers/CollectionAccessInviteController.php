<?php

namespace App\Http\Controllers;

use App\Mail\InviteCollectionAccess;
use App\Models\Collection;
use App\Models\CollectionInvitation;
use App\Models\CollectionUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase C12.0: Private collection invitations — collection-only access (no brand membership).
 * For PRIVATE collections only. Creates collection_user grants, NOT brand membership.
 */
class CollectionAccessInviteController extends Controller
{
    /**
     * Invite by email to a private collection (collection-only access).
     * Creates pending CollectionInvitation; on accept, creates CollectionUser grant only.
     */
    public function invite(Request $request, Collection $collection): RedirectResponse|JsonResponse
    {
        if (($collection->visibility ?? 'brand') !== 'private') {
            abort(403, 'Only private collections can have collection-only invites.');
        }

        Gate::forUser($request->user())->authorize('invite', $collection);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $tenant = app('tenant');
        $brand = app('brand');
        if (! $tenant || ! $brand || $collection->tenant_id !== $tenant->id || $collection->brand_id !== $brand->id) {
            abort(404, 'Collection not found.');
        }

        // Already has a grant
        $existingUser = User::where('email', $validated['email'])->first();
        if ($existingUser && $collection->collectionAccessGrants()->where('user_id', $existingUser->id)->exists()) {
            return $request->wantsJson()
                ? response()->json(['errors' => ['email' => ['This user already has access to this collection.']]], 422)
                : back()->withErrors(['email' => 'This user already has access to this collection.']);
        }

        // Pending invite exists
        $existing = $collection->collectionInvitations()->where('email', $validated['email'])->first();
        if ($existing) {
            return $request->wantsJson()
                ? response()->json(['errors' => ['email' => ['An invitation has already been sent to this email.']]], 422)
                : back()->withErrors(['email' => 'An invitation has already been sent to this email.']);
        }

        $token = Str::random(64);
        $invitation = CollectionInvitation::create([
            'collection_id' => $collection->id,
            'email' => $validated['email'],
            'token' => $token,
            'invited_by_user_id' => $request->user()->id,
            'sent_at' => now(),
        ]);

        $inviteUrl = route('collection-invite.accept', ['token' => $token]);
        $inviter = $request->user();
        Mail::to($validated['email'])->send(new InviteCollectionAccess($collection->fresh(), $inviter, $inviteUrl));

        if ($request->wantsJson()) {
            return response()->json([
                'id' => $invitation->id,
                'email' => $invitation->email,
                'sent_at' => $invitation->sent_at?->toIso8601String(),
            ], 201);
        }

        return back()->with('success', 'Invitation sent successfully.');
    }

    /**
     * List collection access grants and pending invitations (for Edit Collection modal).
     */
    public function index(Request $request, Collection $collection): Response|JsonResponse|array
    {
        if (($collection->visibility ?? 'brand') !== 'private') {
            if ($request->wantsJson()) {
                return response()->json(['grants' => [], 'pending' => []], 200);
            }
            return Inertia::location(url()->previous());
        }

        Gate::forUser($request->user())->authorize('invite', $collection);

        $grants = $collection->collectionAccessGrants()
            ->with(['user:id,first_name,last_name,email,avatar_url', 'invitedBy:id,first_name,last_name'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (CollectionUser $g) => [
                'id' => $g->id,
                'user_id' => $g->user_id,
                'user' => $g->user ? [
                    'id' => $g->user->id,
                    'name' => $g->user->name,
                    'email' => $g->user->email,
                    'first_name' => $g->user->first_name,
                    'last_name' => $g->user->last_name,
                    'avatar_url' => $g->user->avatar_url,
                ] : null,
                'invited_by' => $g->invitedBy ? ['id' => $g->invitedBy->id, 'name' => $g->invitedBy->name] : null,
                'accepted_at' => $g->accepted_at?->toIso8601String(),
                'created_at' => $g->created_at->toIso8601String(),
            ]);

        $pending = $collection->collectionInvitations()
            ->orderBy('sent_at', 'desc')
            ->get()
            ->map(fn (CollectionInvitation $i) => [
                'id' => $i->id,
                'email' => $i->email,
                'sent_at' => $i->sent_at?->toIso8601String(),
            ]);

        if ($request->wantsJson()) {
            return ['grants' => $grants, 'pending' => $pending];
        }

        return Inertia::render('Collections/AccessInvites', [
            'collection' => [
                'id' => $collection->id,
                'name' => $collection->name,
            ],
            'grants' => $grants,
            'pending' => $pending,
        ]);
    }

    /**
     * Revoke collection-only access (remove grant). Does NOT affect brand membership.
     */
    public function revoke(Request $request, Collection $collection, CollectionUser $collection_user): RedirectResponse
    {
        if (($collection->visibility ?? 'brand') !== 'private') {
            abort(403, 'Only private collections have collection-only access.');
        }

        Gate::forUser($request->user())->authorize('removeMember', $collection);

        if ($collection_user->collection_id !== (int) $collection->id) {
            abort(404, 'Grant not found for this collection.');
        }

        $collection_user->delete();

        return back()->with('success', 'Access revoked.');
    }

    /**
     * Show accept page for collection invite (by token). Guest or auth.
     */
    public function acceptShow(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = CollectionInvitation::where('token', $token)->with('collection.brand.tenant')->first();

        if (! $invitation) {
            return redirect()->route('login')->withErrors(['invitation' => 'Invalid or expired invitation link.']);
        }

        $collection = $invitation->collection;
        if (($collection->visibility ?? 'brand') !== 'private') {
            return redirect()->route('login')->withErrors(['invitation' => 'This invitation is no longer valid.']);
        }

        if (Auth::check()) {
            $user = Auth::user();
            if (strtolower($user->email) !== strtolower($invitation->email)) {
                return redirect()->route('login')->withErrors([
                    'invitation' => 'This invitation is for ' . $invitation->email . '. Please log in with that account.',
                ]);
            }
            // Already accepted? Redirect to collection
            $grant = CollectionUser::where('collection_id', $collection->id)->where('user_id', $user->id)->first();
            if ($grant) {
                return redirect()->route('collection-invite.landing', ['collection' => $collection->id]);
            }
            return Inertia::render('Auth/CollectionInviteAccept', [
                'token' => $token,
                'collection' => [
                    'id' => $collection->id,
                    'name' => $collection->name,
                ],
                'email' => $invitation->email,
                'inviter' => $invitation->invitedBy ? [
                    'name' => $invitation->invitedBy->name,
                ] : null,
            ]);
        }

        $user = User::where('email', $invitation->email)->first();
        if ($user) {
            return redirect()->route('login')->with('url.intended', route('collection-invite.accept', ['token' => $token]));
        }

        return Inertia::render('Auth/CollectionInviteRegistration', [
            'token' => $token,
            'collection' => [
                'id' => $collection->id,
                'name' => $collection->name,
            ],
            'email' => $invitation->email,
            'inviter' => $invitation->invitedBy ? [
                'name' => $invitation->invitedBy->name,
            ] : null,
        ]);
    }

    /**
     * Accept collection invite (authenticated user). Creates CollectionUser grant only.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $request->validate(['token' => 'sometimes']);

        if (! Auth::check()) {
            return redirect()->route('login')->with('url.intended', route('collection-invite.accept', ['token' => $token]));
        }

        $invitation = CollectionInvitation::where('token', $token)->with('collection')->first();
        if (! $invitation) {
            return redirect()->route('login')->withErrors(['invitation' => 'Invalid or expired invitation link.']);
        }

        $user = Auth::user();
        if (strtolower($user->email) !== strtolower($invitation->email)) {
            return back()->withErrors(['email' => 'This invitation is for a different email address.']);
        }

        $collection = $invitation->collection;
        if (($collection->visibility ?? 'brand') !== 'private') {
            return redirect()->route('login')->withErrors(['invitation' => 'This invitation is no longer valid.']);
        }

        CollectionUser::create([
            'user_id' => $user->id,
            'collection_id' => $collection->id,
            'invited_by_user_id' => $invitation->invited_by_user_id,
            'accepted_at' => now(),
        ]);

        $invitation->delete();

        // C12: Tenant attachment is STRUCTURAL ONLY — for ResolveTenant/session (user must belong to a tenant).
        // Invariant: Tenant membership ≠ Brand access. We do NOT touch brand_user. Brand visibility, brand
        // selector, and deliverables access all require the brand pivot; tenant membership alone grants none of that.
        $tenant = $collection->tenant;
        if ($tenant && ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            $user->tenants()->attach($tenant->id, ['role' => 'viewer']);
        }
        session([
            'tenant_id' => $collection->tenant_id,
            'collection_id' => $collection->id,
        ]);
        session()->forget('brand_id'); // C12: Ensure collection-only mode; ResolveTenant will set collection_only when no brand

        return redirect()->route('collection-invite.landing', ['collection' => $collection->id])
            ->with('success', 'You now have access to this collection.');
    }

    /**
     * Complete registration for collection invite (new user). Creates User + CollectionUser; no brand membership.
     */
    public function complete(Request $request, string $token): RedirectResponse
    {
        $invitation = CollectionInvitation::where('token', $token)->with('collection')->first();

        if (! $invitation) {
            return back()->withErrors(['invitation' => 'Invalid or expired invitation link.'])->withInput($request->only(['first_name', 'last_name']));
        }

        $collection = $invitation->collection;
        if (($collection->visibility ?? 'brand') !== 'private') {
            return redirect()->route('login')->withErrors(['invitation' => 'This invitation is no longer valid.']);
        }

        try {
            $validated = $request->validate([
                'first_name' => ['required', 'string', 'max:255'],
                'last_name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', PasswordRule::defaults()],
            ]);
        } catch (ValidationException $e) {
            return redirect()->route('collection-invite.accept', ['token' => $token])
                ->withErrors($e->errors())
                ->withInput($request->only(['first_name', 'last_name']));
        }

        $user = User::where('email', $invitation->email)->first();
        if (! $user) {
            $user = User::create([
                'email' => $invitation->email,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'password' => bcrypt($validated['password']),
            ]);
        } else {
            $user->update([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'password' => bcrypt($validated['password']),
            ]);
        }

        CollectionUser::create([
            'user_id' => $user->id,
            'collection_id' => $collection->id,
            'invited_by_user_id' => $invitation->invited_by_user_id,
            'accepted_at' => now(),
        ]);

        $invitation->delete();

        // C12: Tenant attachment is STRUCTURAL ONLY (same invariant as in accept()). Tenant membership ≠ Brand access.
        $tenant = $collection->tenant;
        if ($tenant && ! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            $user->tenants()->attach($tenant->id, ['role' => 'viewer']);
        }

        Auth::login($user);

        session([
            'tenant_id' => $collection->tenant_id,
            'collection_id' => $collection->id,
        ]);
        session()->forget('brand_id'); // C12: Ensure collection-only mode; ResolveTenant will set collection_only when no brand

        return redirect()->route('collection-invite.landing', ['collection' => $collection->id])
            ->with('success', 'Welcome. You now have access to this collection.');
    }

    /**
     * Landing page after accepting collection invite (collection-only access).
     * C12: For collection-only users this is their entry; later middleware will gate the rest of the app.
     */
    public function landing(Request $request, Collection $collection): Response|RedirectResponse
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        Gate::forUser($request->user())->authorize('view', $collection);

        return Inertia::render('Collections/AccessLanding', [
            'collection' => [
                'id' => $collection->id,
                'name' => $collection->name,
                'slug' => $collection->slug,
            ],
            'brand' => $collection->brand ? [
                'id' => $collection->brand->id,
                'name' => $collection->brand->name,
                'slug' => $collection->brand->slug,
            ] : null,
        ]);
    }

    /**
     * C12: Switch which collection is active (collection-only users with multiple collections).
     * Sets session collection_id and redirects to that collection's landing.
     */
    public function switchCollection(Request $request, Collection $collection): RedirectResponse
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        Gate::forUser($request->user())->authorize('view', $collection);

        session(['collection_id' => $collection->id]);
        session()->forget('brand_id');

        return redirect()->route('collection-invite.landing', ['collection' => $collection->id]);
    }

    /**
     * C12: View collection and its assets (collection-only users). Read-only.
     */
    public function viewCollection(Request $request, Collection $collection): Response|RedirectResponse
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        Gate::forUser($request->user())->authorize('view', $collection);

        $collectionController = app(\App\Http\Controllers\CollectionController::class);
        $assets = $collectionController->getCollectionAssetsGridData($collection, $request->user());

        return Inertia::render('Collections/CollectionOnlyView', [
            'collection' => [
                'id' => $collection->id,
                'name' => $collection->name,
                'slug' => $collection->slug,
            ],
            'assets' => $assets,
        ]);
    }
}
