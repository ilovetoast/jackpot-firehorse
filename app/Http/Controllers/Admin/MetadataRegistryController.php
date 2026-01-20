<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemMetadataRegistryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Metadata Registry Controller
 *
 * Phase C1, Step 1: Admin-only read-only interface for inspecting
 * system metadata fields and their behavior.
 *
 * This controller provides observability only - no mutations allowed.
 *
 * Authorization:
 * - All methods check MetadataRegistryPolicy
 * - metadata.registry.view: Read-only access
 */
class MetadataRegistryController extends Controller
{
    public function __construct(
        protected SystemMetadataRegistryService $registryService
    ) {
    }

    /**
     * Display the System Metadata Registry.
     *
     * GET /admin/metadata/registry
     */
    public function index(): Response
    {
        if (!Auth::user()->can('metadata.registry.view')) {
            abort(403);
        }

        // Get all system metadata fields with metrics
        $fields = $this->registryService->getSystemFields();

        return Inertia::render('Admin/MetadataRegistry/Index', [
            'fields' => $fields,
        ]);
    }
}
