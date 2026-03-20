<?php

namespace App\Http\Controllers;

use App\Jobs\DeleteAvatarJob;
use App\Services\CloudFrontSignedUrlService;
use App\Services\TenantBucketService;
use App\Traits\HandlesFlashMessages;
use Aws\S3\S3Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    use HandlesFlashMessages;

    /**
     * Display the user's profile form.
     */
    public function index(): Response
    {
        $user = Auth::user();

        return Inertia::render('Profile/Index', [
            'user' => [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'country' => $user->country,
                'timezone' => $user->timezone,
                'address' => $user->address,
                'city' => $user->city,
                'state' => $user->state,
                'zip' => $user->zip,
            ],
        ]);
    }

    /**
     * Generate a presigned PUT URL for avatar upload to S3.
     */
    public function presignAvatarUpload(Request $request)
    {
        $request->validate([
            'content_type' => 'required|string|in:image/jpeg,image/png,image/gif,image/webp',
            'extension' => 'required|string|in:jpg,jpeg,png,gif,webp',
        ]);

        $user = $request->user();
        $tenant = app()->bound('tenant') ? app('tenant') : null;

        if (! $tenant) {
            return response()->json(['error' => 'No active tenant context.'], 403);
        }

        try {
            $bucketService = app(TenantBucketService::class);
            $bucket = $bucketService->resolveSharedBucketOrFail($tenant);
        } catch (\Throwable $e) {
            Log::error('[AVATAR_PRESIGN] Shared bucket resolution failed', [
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Storage not available. Please try again later.'], 503);
        }

        $ext = $request->input('extension');
        $key = 'platform/avatars/' . $user->id . '/' . Str::uuid() . '.' . $ext;

        $s3 = $this->createS3Client();
        $command = $s3->getCommand('PutObject', [
            'Bucket' => $bucket->name,
            'Key' => $key,
            'ContentType' => $request->input('content_type'),
        ]);

        $presigned = $s3->createPresignedRequest($command, '+30 minutes');

        return response()->json([
            'upload_url' => (string) $presigned->getUri(),
            'key' => $key,
            'bucket' => $bucket->name,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'avatar_s3_key' => 'nullable|string|max:500',
            'country' => 'nullable|string|max:255',
            'timezone' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'zip' => 'nullable|string|max:255',
        ]);

        // S3 avatar (preferred path for staging/production)
        if ($request->filled('avatar_s3_key')) {
            $newKey = $validated['avatar_s3_key'];

            if (! str_starts_with($newKey, 'platform/avatars/' . $user->id . '/')) {
                abort(403, 'Invalid avatar path.');
            }

            $this->dispatchAvatarDeletion($user);
            $validated['avatar_url'] = 's3://' . $newKey;
        }
        // Legacy local file upload (fallback for local dev)
        elseif ($request->hasFile('avatar')) {
            $this->dispatchAvatarDeletion($user);
            $path = $request->file('avatar')->store('avatars', 'public');
            $validated['avatar_url'] = '/storage/' . $path;
        }

        if (! isset($validated['avatar_url'])) {
            unset($validated['avatar_url']);
        }
        unset($validated['avatar'], $validated['avatar_s3_key']);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return $this->backWithSuccess('Profile updated successfully.');
    }

    /**
     * Remove the user's avatar.
     */
    public function removeAvatar(Request $request)
    {
        $user = $request->user();
        $rawUrl = $user->getRawAvatarUrl();

        if ($rawUrl) {
            $this->dispatchAvatarDeletion($user);
            $user->avatar_url = null;
            $user->save();
        }

        return $this->backWithSuccess('Avatar removed successfully.');
    }

    /**
     * Update the user's password.
     */
    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|current_password',
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return $this->backWithSuccess('Password updated successfully.');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request)
    {
        $request->validate([
            'password' => 'required|current_password',
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Resolve the display URL for a user's avatar.
     *
     * IMPORTANT: This method must NOT access any relationships (no $user->tenants()).
     * It is called from the User model's avatarUrl accessor, which runs on every
     * serialization — including inside loops over eager-loaded collections.
     * Lazy loading a relationship here would violate preventLazyLoading() and
     * cause N+1 queries on team/member pages.
     *
     * Resolution paths (all relationship-free):
     *   - Local/testing: S3 presigned URL via Storage facade (uses config, not tenant)
     *   - Staging/production with CDN: CloudFront signed URL (local RSA signing, no DB)
     *   - Staging/production without CDN: S3 presigned URL via Storage facade
     *   - Legacy /storage/ paths: pass through unchanged
     */
    public static function resolveAvatarUrl($user): ?string
    {
        $url = $user->getRawAvatarUrl();
        if (! $url) {
            return null;
        }

        // Legacy /storage/ paths only work in local dev (symlinked public disk).
        // On staging/production they 403 — return null so the UI falls back to initials.
        if (! str_starts_with($url, 's3://')) {
            if (app()->environment('local', 'testing')) {
                return $url;
            }

            return null;
        }

        $key = substr($url, 5);

        // Local/testing: S3 presigned GET via Storage facade (no tenant needed)
        if (app()->environment('local', 'testing')) {
            try {
                $ttl = (int) config('assets.delivery.local_presign_ttl', 900);

                return Storage::disk('s3')->temporaryUrl($key, now()->addSeconds($ttl));
            } catch (\Throwable $e) {
                Log::warning('[AVATAR_URL] Local presign failed', [
                    'user_id' => $user->id,
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        // Staging/production: prefer CloudFront signed URL (cross-tenant, no DB access)
        $cdnDomain = config('cloudfront.domain');
        if (! empty($cdnDomain)) {
            try {
                $cdnUrl = 'https://' . $cdnDomain . '/' . ltrim($key, '/');
                $expiresAt = time() + 3600;

                return app(CloudFrontSignedUrlService::class)->sign($cdnUrl, $expiresAt);
            } catch (\Throwable $e) {
                Log::warning('[AVATAR_URL] CloudFront signed URL failed', [
                    'user_id' => $user->id,
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        // Fallback: S3 presigned URL via Storage facade (no tenant/bucket lookup needed)
        try {
            return Storage::disk('s3')->temporaryUrl($key, now()->addMinutes(60));
        } catch (\Throwable $e) {
            Log::warning('[AVATAR_URL] S3 presign fallback failed', [
                'user_id' => $user->id,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Dispatch avatar file deletion to a queued job.
     * The worker has S3 delete permissions; the web server does not.
     */
    protected function dispatchAvatarDeletion($user): void
    {
        $rawUrl = $user->getRawAvatarUrl();
        if (! $rawUrl) {
            return;
        }

        $bucket = null;
        if (str_starts_with($rawUrl, 's3://')) {
            $bucket = config('storage.shared_bucket');
        }

        DeleteAvatarJob::dispatch($rawUrl, $bucket);
    }

    protected function createS3Client(): S3Client
    {
        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];

        if (config('filesystems.disks.s3.key')) {
            $config['credentials'] = [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ];
        }

        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        return new S3Client($config);
    }
}
