<?php

namespace App\Http\Controllers;

use App\Enums\AssetType;
use App\Models\SystemCategory;
use App\Services\SystemCategoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SystemCategoryController extends Controller
{
    public function __construct(
        protected SystemCategoryService $systemCategoryService
    ) {
    }

    /**
     * Check if the current user is a site owner/admin.
     */
    protected function checkSiteOwnerAccess(): void
    {
        $user = Auth::user();
        if (! $user || ($user->id !== 1 && ! $user->can('site owner') && ! $user->can('site admin'))) {
            abort(403, 'Only site owners can manage system categories.');
        }
    }

    /**
     * Display a listing of system category templates.
     */
    public function index(): Response
    {
        $this->checkSiteOwnerAccess();
        $templates = $this->systemCategoryService->getAllTemplates();

        return Inertia::render('Admin/SystemCategories', [
            'templates' => $templates->map(function ($template) {
                $stats = $this->systemCategoryService->getUpgradeStatistics($template);
                return [
                    'id' => $template->id,
                    'name' => $template->name,
                    'slug' => $template->slug,
                    'icon' => $template->icon,
                    'asset_type' => $template->asset_type->value,
                    'is_hidden' => $template->is_hidden,
                    'sort_order' => $template->sort_order,
                    'version' => $template->version,
                    'upgrade_stats' => $stats,
                ];
            }),
            'asset_types' => [
                ['value' => AssetType::ASSET->value, 'label' => 'ASSET'],
                ['value' => AssetType::MARKETING->value, 'label' => 'Marketing'],
            ],
        ]);
    }

    /**
     * Store a newly created system category template.
     */
    public function store(Request $request)
    {
        $this->checkSiteOwnerAccess();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:255',
            'asset_type' => 'required|string|in:' . implode(',', AssetType::values()),
            'is_hidden' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        try {
            $template = $this->systemCategoryService->createTemplate($validated);

            return redirect()->route('admin.system-categories.index')
                ->with('success', 'System category template created successfully.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'error' => $e->getMessage(),
            ])->withInput();
        }
    }

    /**
     * Update the specified system category template.
     */
    public function update(Request $request, SystemCategory $systemCategory)
    {
        $this->checkSiteOwnerAccess();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:255',
            'asset_type' => 'required|string|in:' . implode(',', AssetType::values()),
            'is_hidden' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        try {
            $this->systemCategoryService->updateTemplate($systemCategory, $validated);

            return redirect()->route('admin.system-categories.index')
                ->with('success', 'System category template updated successfully.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'error' => $e->getMessage(),
            ])->withInput();
        }
    }

    /**
     * Remove the specified system category template.
     */
    public function destroy(SystemCategory $systemCategory)
    {
        $this->checkSiteOwnerAccess();
        try {
            $this->systemCategoryService->deleteTemplate($systemCategory);

            return redirect()->route('admin.system-categories.index')
                ->with('success', 'System category template deleted successfully.');
        } catch (\Exception $e) {
            return back()->withErrors([
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Update the sort order of system category templates.
     */
    public function updateOrder(Request $request)
    {
        $this->checkSiteOwnerAccess();
        $validated = $request->validate([
            'templates' => 'required|array',
            'templates.*.id' => 'required|integer|exists:system_categories,id',
            'templates.*.sort_order' => 'required|integer|min:0',
        ]);

        try {
            foreach ($validated['templates'] as $item) {
                $template = SystemCategory::find($item['id']);
                if ($template && $template->isLatestVersion()) {
                    // Only update sort_order for the latest version
                    $template->update(['sort_order' => $item['sort_order']]);
                }
            }

            // Return JSON for non-Inertia requests, redirect for Inertia requests
            if ($request->header('X-Inertia')) {
                return back();
            }

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            // Return JSON for non-Inertia requests, redirect for Inertia requests
            if ($request->header('X-Inertia')) {
                return back()->withErrors([
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
