/**
 * Canonical render contract for Studio composition export (editor + internal render page + workers).
 * Bump {@link COMPOSITION_RENDER_CONTRACT_VERSION} when adding/removing fields or changing semantics.
 */
export const COMPOSITION_RENDER_CONTRACT_VERSION = 1 as const

/** Diagnostics surfaced to Playwright via {@link CompositionExportBridgeState}. */
export type CompositionSceneDiagnostics = {
    sceneContractVersion: number
    layerCount: number
    layerCountByType: Record<string, number>
    fontsRequested: number
    fontsLoaded: number
    fontsFailed: Array<{ family?: string; assetId?: string | number; reason: string }>
    assetsRequested: number
    assetsLoaded: number
    assetsFailed: Array<{ layerId: string; url?: string; reason: string }>
    unsupportedLayerTypes: string[]
    lastSetTimeMs: number
    renderReadyMs: number | null
    lastError: string | null
}

export type CompositionExportBridgeState = CompositionSceneDiagnostics & {
    ready: boolean
    /** Brand stylesheet + FontFace preload completed without throwing. */
    brandTypographyPreloaded: boolean
    /** `document.fonts.check` succeeded for every visible text layer (or there are none). */
    textFontsVerified: boolean
    currentTimeMs: number
    payloadVersion: number
    sceneWidth: number
    sceneHeight: number
}

export type CompositionRenderPayloadV1 = {
    version: typeof COMPOSITION_RENDER_CONTRACT_VERSION
    width: number
    height: number
    fps: number
    duration_ms: number
    background: {
        type: string
        color?: string
        fillKind?: string
    }
    /** Layers sorted ascending by `z` (paint order). */
    layers: Record<string, unknown>[]
    /** Declarative font-related entries (stylesheets, DNA font_face rows, text-layer families). */
    fonts: Record<string, unknown>[]
    timing: Record<string, unknown>
    export_job_id: number
    composition_id: number
    tenant_id: number
    brand_id: number
    user_id: number | null
    /**
     * Same shape as GET /app/api/editor/brand_context — drives FontFace / CSS on the export surface.
     * When null, text still renders but {@link CompositionExportBridgeState.fontsFailed} may report misses.
     */
    /** Omitted in older payloads; treat as null. */
    brand_context?: Record<string, unknown> | null
}

/** Playwright / automation polls this after navigation. */
export type CompositionExportBridge = {
    contractVersion: number
    signalReady: () => void
    setTimeMs: (ms: number) => void
    getState: () => CompositionExportBridgeState
}
