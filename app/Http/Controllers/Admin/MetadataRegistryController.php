<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemMetadataFieldAdminService;
use App\Services\SystemMetadataRegistryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * System Metadata Registry (admin).
 */
class MetadataRegistryController extends Controller
{
    public function __construct(
        protected SystemMetadataRegistryService $registryService,
        protected SystemMetadataFieldAdminService $fieldAdminService
    ) {}

    /**
     * GET /admin/metadata/registry
     */
    public function index(): Response
    {
        if (! Auth::user()->can('metadata.registry.view')) {
            abort(403);
        }

        $fields = $this->registryService->getSystemFields();
        $latestSystemTemplates = $this->registryService->getLatestSystemTemplatesForAdmin();

        return Inertia::render('Admin/MetadataRegistry/Index', [
            'fields' => $fields,
            'latestSystemTemplates' => $latestSystemTemplates,
            /** Gate/Spatie — not derived from Inertia effective_permissions (site-only perm can be missing when tenant context is active). */
            'can_manage_system_fields' => Auth::user()->can('metadata.system.fields.manage'),
        ]);
    }

    /**
     * POST /admin/metadata/fields
     */
    public function store(Request $request): RedirectResponse
    {
        if (! Auth::user()->can('metadata.system.fields.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'system_label' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(['text', 'textarea', 'select', 'multiselect', 'number', 'boolean', 'date'])],
            'applies_to' => ['required', 'string', Rule::in(['all', 'image', 'video', 'document'])],
            'population_mode' => ['nullable', 'string', Rule::in(['manual', 'automatic', 'hybrid'])],
            'show_on_upload' => ['sometimes', 'boolean'],
            'show_on_edit' => ['sometimes', 'boolean'],
            'show_in_filters' => ['sometimes', 'boolean'],
            'readonly' => ['sometimes', 'boolean'],
            'is_filterable' => ['sometimes', 'boolean'],
            'is_user_editable' => ['sometimes', 'boolean'],
            'is_ai_trainable' => ['sometimes', 'boolean'],
            'is_internal_only' => ['sometimes', 'boolean'],
            'group_key' => ['nullable', 'string', 'max:64'],
            'options' => [
                Rule::requiredIf(fn () => in_array($request->input('type'), ['select', 'multiselect'], true)),
                'array',
            ],
            'options.*.value' => ['required', 'string', 'max:255'],
            'options.*.label' => ['required', 'string', 'max:255'],
            'template_defaults' => ['nullable', 'array'],
            'template_defaults.*.system_category_id' => ['required', 'integer', 'exists:system_categories,id'],
            'template_defaults.*.is_hidden' => ['sometimes', 'boolean'],
            'template_defaults.*.is_upload_hidden' => ['sometimes', 'boolean'],
            'template_defaults.*.is_filter_hidden' => ['sometimes', 'boolean'],
            'template_defaults.*.is_edit_hidden' => ['sometimes', 'boolean'],
            'template_defaults.*.is_primary' => ['nullable', 'boolean'],
        ]);

        $this->fieldAdminService->createSystemField($validated);

        return redirect()
            ->route('admin.metadata.registry.index')
            ->with('success', 'System metadata field created.');
    }
}
