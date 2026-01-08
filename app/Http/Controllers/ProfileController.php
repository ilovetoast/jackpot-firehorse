<?php

namespace App\Http\Controllers;

use App\Traits\HandlesFlashMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
            'country' => 'nullable|string|max:255',
            'timezone' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'zip' => 'nullable|string|max:255',
        ]);

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            // Delete old avatar if exists
            if ($user->avatar_url) {
                $oldPath = str_replace('/storage/', '', $user->avatar_url);
                if (str_starts_with($oldPath, 'avatars/')) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            // Store new avatar
            $path = $request->file('avatar')->store('avatars', 'public');
            $validated['avatar_url'] = '/storage/' . $path;
        }

        // Remove avatar from validated if not set (to avoid overwriting with null)
        if (!isset($validated['avatar_url'])) {
            unset($validated['avatar_url']);
        }
        unset($validated['avatar']); // Remove the file from validated array

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

        if ($user->avatar_url) {
            $oldPath = str_replace('/storage/', '', $user->avatar_url);
            if (str_starts_with($oldPath, 'avatars/')) {
                Storage::disk('public')->delete($oldPath);
            }
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
}
