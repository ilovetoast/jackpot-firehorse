<?php

namespace App\Http\Controllers;

use App\Enums\AssetType;
use App\Models\Category;
use App\Services\PlanService;
use App\Services\SystemCategoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssetController extends Controller
{
    public function __construct(
        protected SystemCategoryService $systemCategoryService,
        protected PlanService $planService
    ) {
    }

    /**
     * Display a listing of assets.
     */
    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $brand = app('brand');
        $user = $request->user();

        if (!$tenant || !$brand) {
            // Handle case where tenant or brand is not resolved (e.g., no active tenant/brand)
            return Inertia::render('Assets/Index', [
                'categories' => [],
                'categories_by_type' => ['all' => []],
                'selected_category' => null,
            ]);
        }

        // Get only BASIC categories for the brand
        // Filter out hidden categories unless user has permission
        $query = Category::where('tenant_id', $tenant->id)
            ->where('brand_id', $brand->id)
            ->where('asset_type', AssetType::BASIC);

        // If user does not have 'manage categories' permission, filter out hidden categories
        if (! $user || ! $user->can('manage categories')) {
            $query->visible();
        }

        $categories = $query->get();

        // Get only BASIC system category templates
        $systemTemplates = $this->systemCategoryService->getTemplatesByAssetType(AssetType::BASIC)
            ->filter(fn ($template) => ! $template->is_hidden || ($user && $user->can('manage categories')));

        // Create merged list of categories
        $allCategories = collect();

        // Add existing categories
        foreach ($categories as $category) {
            // Find matching system template to get sort_order
            $matchingTemplate = $systemTemplates->first(function ($template) use ($category) {
                return $category->slug === $template->slug && 
                       $category->asset_type->value === $template->asset_type->value;
            });
            
            $allCategories->push([
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'icon' => $category->icon,
                'asset_type' => $category->asset_type->value,
                'is_system' => $category->is_system,
                'is_private' => $category->is_private,
                'is_locked' => $category->is_locked,
                'is_hidden' => $category->is_hidden,
                'sort_order' => $matchingTemplate ? $matchingTemplate->sort_order : 999, // Use template sort_order or high default
            ]);
        }

        // Add system templates that don't have matching brand categories
        foreach ($systemTemplates as $template) {
            $exists = $categories->contains(function ($category) use ($template) {
                return $category->slug === $template->slug && 
                       $category->asset_type->value === $template->asset_type->value;
            });

            if (! $exists) {
                $allCategories->push([
                    'id' => null, // Template, no ID
                    'name' => $template->name,
                    'slug' => $template->slug,
                    'icon' => $template->icon ?? 'folder',
                    'asset_type' => $template->asset_type->value,
                    'is_system' => true,
                    'is_private' => $template->is_private,
                    'is_locked' => true,
                    'is_hidden' => $template->is_hidden,
                    'sort_order' => $template->sort_order,
                ]);
            }
        }

        // Sort by sort_order first, then by name
        $allCategories = $allCategories->sortBy([
            ['sort_order', 'asc'],
            ['name', 'asc'],
        ])->values();

        // Check if plan is not free (to show "All" button)
        $currentPlan = $this->planService->getCurrentPlan($tenant);
        $showAllButton = $currentPlan !== 'free';

        return Inertia::render('Assets/Index', [
            'categories' => $allCategories,
            'categories_by_type' => [
                'all' => $allCategories,
            ],
            'selected_category' => $request->get('category'),
            'show_all_button' => $showAllButton,
        ]);
    }
}
