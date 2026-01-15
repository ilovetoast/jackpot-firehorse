<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
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

        // Check if category column exists
        $hasCategoryColumn = \Schema::hasColumn('notification_templates', 'category');
        
        if ($hasCategoryColumn) {
            $templates = NotificationTemplate::orderBy('category')->orderBy('name')->get();
            // Group templates by category
            $systemTemplates = $templates->where('category', 'system')->values();
            $tenantTemplates = $templates->where('category', 'tenant')->values();
        } else {
            // Fallback if category column doesn't exist yet
            $templates = NotificationTemplate::orderBy('name')->get();
            // Default grouping - assume all are system for now
            $systemTemplates = $templates->values();
            $tenantTemplates = collect();
        }
        
        // Check if invite_member template exists, if not suggest seeding
        $hasInviteMember = $templates->where('key', 'invite_member')->isNotEmpty();

        return Inertia::render('Admin/Notifications', [
            'templates' => $templates,
            'system_templates' => $systemTemplates,
            'tenant_templates' => $tenantTemplates,
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

        // Get tenants for company selection (for tenant emails)
        $tenants = collect();
        $templateCategory = $template->category ?? 'system'; // Default to system if category doesn't exist
        if ($templateCategory === 'tenant') {
            $tenants = \App\Models\Tenant::with(['brands' => function ($query) {
                $query->orderBy('is_default', 'desc')->orderBy('name');
            }])->orderBy('name')->get()->map(function ($tenant) {
                $firstBrand = $tenant->brands->first();
                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'first_brand' => $firstBrand ? [
                        'id' => $firstBrand->id,
                        'name' => $firstBrand->name,
                        'primary_color' => $firstBrand->primary_color ?? '#6366f1',
                    ] : null,
                ];
            });
        }

        return Inertia::render('Admin/NotificationEdit', [
            'template' => $template,
            'app_name' => config('app.name', 'Jackpot'),
            'app_url' => config('app.url', url('/')),
            'tenants' => $tenants,
            'saas_primary_color' => '#6366f1', // Default SaaS primary color
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
