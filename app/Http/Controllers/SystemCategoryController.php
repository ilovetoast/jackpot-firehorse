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
            'templates' => $templates->map(fn ($template) => [
                'id' => $template->id,
                'name' => $template->name,
                'slug' => $template->slug,
                'asset_type' => $template->asset_type->value,
                'is_private' => $template->is_private,
                'is_hidden' => $template->is_hidden,
                'sort_order' => $template->sort_order,
            ]),
            'asset_types' => [
                ['value' => AssetType::BASIC->value, 'label' => 'Basic'],
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
            'asset_type' => 'required|string|in:' . implode(',', AssetType::values()),
            'is_private' => 'boolean',
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
            'asset_type' => 'required|string|in:' . implode(',', AssetType::values()),
            'is_private' => 'boolean',
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
}
