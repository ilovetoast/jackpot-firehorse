import { loadEditorBrandTypography } from '../../../Pages/Editor/editorBrandFonts'
import type { BrandContext } from '../../../Pages/Editor/documentModel'

export type ExportFontLoadReport = {
    ok: boolean
    error?: string
}

/**
 * Deterministic typography preload for export mode (stylesheets + binary FontFace).
 * Reuses {@link loadEditorBrandTypography} — same network surface as the interactive editor.
 */
export async function loadExportBrandTypography(brandContext: BrandContext | null): Promise<ExportFontLoadReport> {
    if (!brandContext?.typography) {
        return { ok: true }
    }
    try {
        await loadEditorBrandTypography(brandContext.typography)
        return { ok: true }
    } catch (e) {
        return {
            ok: false,
            error: e instanceof Error ? e.message : 'loadEditorBrandTypography failed',
        }
    }
}
