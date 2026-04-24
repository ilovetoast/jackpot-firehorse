<?php

namespace App\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Studio\StudioEditorFontRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /app/api/editor/studio-fonts — grouped font registry for the editor + stable export keys.
 */
class EditorStudioFontsController extends Controller
{
    public function __construct(
        private StudioEditorFontRegistryService $registry,
        private EditorBrandContextController $brandContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $brand = app('brand');
        $ctx = $brand instanceof Brand
            ? $this->brandContext->serializeBrandContextForBrand($brand)
            : null;

        return response()->json(
            $this->registry->groupedFonts($ctx)
                + ['default_font_key' => (string) config('studio_rendering.fonts.default_key', 'bundled:inter-regular')]
        );
    }
}
