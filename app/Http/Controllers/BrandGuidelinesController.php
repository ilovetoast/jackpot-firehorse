<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brand Guidelines — read-only render of active Brand DNA.
 * Internal only. No public sharing, PDF export, or WYSIWYG.
 */
class BrandGuidelinesController extends Controller
{
    /**
     * Redirect /brand-guidelines to active brand's guidelines.
     */
    public function redirectToActive(): RedirectResponse
    {
        $brand = app('brand');
        if (! $brand) {
            return redirect()->route('brands.index');
        }

        return redirect()->route('brands.guidelines.index', ['brand' => $brand->id]);
    }

    /**
     * Show Brand Guidelines page (read-only).
     */
    public function index(Brand $brand): Response
    {
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $this->authorize('update', $brand);

        $brandModel = $brand->brandModel;
        $activeVersion = $brandModel?->activeVersion;
        $modelPayload = $activeVersion?->model_payload ?? [];
        $isEnabled = $brandModel?->is_enabled ?? false;

        return Inertia::render('Brands/BrandGuidelines/Index', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'primary_color' => $brand->primary_color ?? '#6366f1',
                'secondary_color' => $brand->secondary_color ?? '#8b5cf6',
                'accent_color' => $brand->accent_color ?? '#06b6d4',
            ],
            'brandModel' => [
                'is_enabled' => $isEnabled,
            ],
            'modelPayload' => $modelPayload,
            'hasActiveVersion' => $activeVersion !== null,
        ]);
    }
}
