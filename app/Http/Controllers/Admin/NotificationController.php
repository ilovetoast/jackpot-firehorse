<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * Display the notifications management page.
     */
    public function index(): Response
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        $templates = NotificationTemplate::orderBy('name')->get();
        
        // Check if invite_member template exists, if not suggest seeding
        $hasInviteMember = $templates->where('key', 'invite_member')->isNotEmpty();

        return Inertia::render('Admin/Notifications', [
            'templates' => $templates,
            'has_invite_member' => $hasInviteMember,
        ]);
    }

    /**
     * Show the edit form for a notification template.
     */
    public function edit(NotificationTemplate $template): Response
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        return Inertia::render('Admin/NotificationEdit', [
            'template' => $template,
            'app_name' => config('app.name', 'Jackpot'),
            'app_url' => config('app.url', url('/')),
        ]);
    }

    /**
     * Update a notification template.
     */
    public function update(Request $request, NotificationTemplate $template)
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'body_html' => 'required|string',
            'body_text' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $template->update($validated);

        return redirect()->route('admin.notifications')->with('success', 'Notification template updated successfully.');
    }

    /**
     * Seed notification templates.
     */
    public function seed()
    {
        // Only user ID 1 (Site Owner) can access
        if (Auth::id() !== 1) {
            abort(403, 'Only site owners can access this page.');
        }

        try {
            Artisan::call('db:seed', ['--class' => 'NotificationTemplateSeeder']);
            return redirect()->route('admin.notifications')->with('success', 'Notification templates seeded successfully.');
        } catch (\Exception $e) {
            return redirect()->route('admin.notifications')->withErrors(['error' => 'Failed to seed templates: ' . $e->getMessage()]);
        }
    }
}
