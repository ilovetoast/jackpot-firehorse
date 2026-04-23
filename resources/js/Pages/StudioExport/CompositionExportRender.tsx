import { Head } from '@inertiajs/react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { CompositionScene } from '../../components/studio/composition/CompositionScene'
import { documentFromRenderPayloadV1 } from '../../components/studio/composition/payloadAdapter'
import { loadExportBrandTypography } from '../../components/studio/composition/fontLoading'
import {
    collectVisibleRasterSources,
    countLayersByType,
    countVisibleTextLayers,
    listUnsupportedLayerTypes,
    preloadRasterEntry,
    verifyCanvasFontsForVisibleText,
} from '../../components/studio/composition/exportAssetInventory'
import type { BrandContext, DocumentModel } from '../Editor/documentModel'
import type {
    CompositionExportBridge,
    CompositionExportBridgeState,
    CompositionRenderPayloadV1,
    CompositionSceneDiagnostics,
} from './compositionRenderContract'
import { COMPOSITION_RENDER_CONTRACT_VERSION } from './compositionRenderContract'

declare global {
    interface Window {
        __COMPOSITION_EXPORT_BRIDGE__?: CompositionExportBridge
    }
}

const SCENE_CONTRACT_VERSION = 1
const ASSET_PRELOAD_TIMEOUT_MS = 25_000
const TOTAL_READY_TIMEOUT_MS = 45_000

type PageProps = {
    renderPayload: CompositionRenderPayloadV1
    exportJobId: string
    compositionId: string
}

function emptyDiagnostics(): CompositionSceneDiagnostics {
    return {
        sceneContractVersion: SCENE_CONTRACT_VERSION,
        layerCount: 0,
        layerCountByType: {},
        fontsRequested: 0,
        fontsLoaded: 0,
        fontsFailed: [],
        assetsRequested: 0,
        assetsLoaded: 0,
        assetsFailed: [],
        unsupportedLayerTypes: [],
        lastSetTimeMs: 0,
        renderReadyMs: null,
        lastError: null,
    }
}

function parseBrandContext(raw: unknown): BrandContext | null {
    if (!raw || typeof raw !== 'object') {
        return null
    }
    return raw as BrandContext
}

/**
 * Chrome-free render surface for canvas-runtime video export.
 * Shared {@link CompositionScene} + {@link CompositionExportBridge} for Playwright frame capture.
 */
export default function CompositionExportRender({ renderPayload, exportJobId, compositionId }: PageProps) {
    const renderPayloadRef = useRef(renderPayload)
    renderPayloadRef.current = renderPayload

    const [currentTimeMs, setCurrentTimeMs] = useState(0)
    const currentTimeMsRef = useRef(0)
    const readyRef = useRef(false)
    const brandTypographyPreloadedRef = useRef(false)
    const textFontsVerifiedRef = useRef(false)
    const diagnosticsRef = useRef<CompositionSceneDiagnostics>(emptyDiagnostics())

    const parsed = useMemo((): { ok: true; doc: DocumentModel } | { ok: false; error: string } => {
        try {
            return { ok: true, doc: documentFromRenderPayloadV1(renderPayload) }
        } catch (e) {
            return {
                ok: false,
                error: e instanceof Error ? e.message : 'documentFromRenderPayloadV1 failed',
            }
        }
    }, [renderPayload])

    const brandContext = useMemo(() => parseBrandContext(renderPayload.brand_context), [renderPayload.brand_context])

    const mergeDiagnostics = useCallback((patch: Partial<CompositionSceneDiagnostics>) => {
        diagnosticsRef.current = { ...diagnosticsRef.current, ...patch }
    }, [])

    const bridge = useMemo((): CompositionExportBridge => {
        return {
            contractVersion: COMPOSITION_RENDER_CONTRACT_VERSION,
            signalReady: () => {
                readyRef.current = true
                window.dispatchEvent(
                    new CustomEvent('jackpot:composition-export-ready', { detail: { exportJobId } }),
                )
            },
            setTimeMs: (ms: number) => {
                const v = Math.max(0, ms)
                currentTimeMsRef.current = v
                mergeDiagnostics({ lastSetTimeMs: v })
                setCurrentTimeMs(v)
                window.dispatchEvent(
                    new CustomEvent('jackpot:composition-export-time', {
                        detail: { exportJobId, ms: v },
                    }),
                )
            },
            getState: (): CompositionExportBridgeState => {
                const p = renderPayloadRef.current
                return {
                    ...diagnosticsRef.current,
                    ready: readyRef.current,
                    brandTypographyPreloaded: brandTypographyPreloadedRef.current,
                    textFontsVerified: textFontsVerifiedRef.current,
                    currentTimeMs: currentTimeMsRef.current,
                    payloadVersion: p.version,
                    sceneWidth: p.width,
                    sceneHeight: p.height,
                }
            },
        }
    }, [exportJobId, mergeDiagnostics])

    const bridgeRef = useRef(bridge)
    bridgeRef.current = bridge

    useEffect(() => {
        window.__COMPOSITION_EXPORT_BRIDGE__ = bridge
        return () => {
            if (window.__COMPOSITION_EXPORT_BRIDGE__ === bridge) {
                delete window.__COMPOSITION_EXPORT_BRIDGE__
            }
        }
    }, [bridge])

    useEffect(() => {
        readyRef.current = false
        brandTypographyPreloadedRef.current = false
        textFontsVerifiedRef.current = false
        currentTimeMsRef.current = 0
        setCurrentTimeMs(0)

        if (!parsed.ok) {
            mergeDiagnostics({
                ...emptyDiagnostics(),
                lastError: parsed.error,
            })
            return undefined
        }

        const doc = parsed.doc
        const raster = collectVisibleRasterSources(doc.layers)
        const fontSlots = (renderPayload.fonts ?? []).length
        const textVisible = countVisibleTextLayers(doc.layers)
        const fontsRequested = Math.max(fontSlots, textVisible)

        mergeDiagnostics({
            ...emptyDiagnostics(),
            layerCount: doc.layers.length,
            layerCountByType: countLayersByType(doc.layers),
            fontsRequested,
            assetsRequested: raster.length,
            unsupportedLayerTypes: listUnsupportedLayerTypes(doc.layers),
            lastError: null,
        })

        let cancelled = false
        const started = performance.now()

        const fail = (message: string) => {
            if (cancelled) {
                return
            }
            mergeDiagnostics({
                lastError: message,
                renderReadyMs: Math.round(performance.now() - started),
            })
        }

        const succeed = () => {
            if (cancelled) {
                return
            }
            mergeDiagnostics({
                renderReadyMs: Math.round(performance.now() - started),
                fontsLoaded: diagnosticsRef.current.fontsRequested,
                assetsLoaded: diagnosticsRef.current.assetsRequested,
            })
            readyRef.current = true
            brandTypographyPreloadedRef.current = true
            textFontsVerifiedRef.current = true
            bridgeRef.current.signalReady()
        }

        let hardTimeoutId = 0
        const run = async () => {
            hardTimeoutId = window.setTimeout(() => {
                if (!readyRef.current) {
                    fail(`export readiness exceeded ${TOTAL_READY_TIMEOUT_MS}ms`)
                }
            }, TOTAL_READY_TIMEOUT_MS)

            try {
                const typoReport = await loadExportBrandTypography(brandContext)
                if (cancelled) {
                    return
                }
                brandTypographyPreloadedRef.current = typoReport.ok
                if (!typoReport.ok) {
                    mergeDiagnostics({
                        fontsFailed: [
                            {
                                reason: typoReport.error ?? 'loadExportBrandTypography failed',
                            },
                        ],
                    })
                }

                const fontCheckFails = verifyCanvasFontsForVisibleText(doc, brandContext)
                if (fontCheckFails.length > 0) {
                    mergeDiagnostics({
                        fontsFailed: fontCheckFails.map((f) => ({
                            family: f.family,
                            reason: f.reason,
                        })),
                    })
                }

                const assetFails: CompositionSceneDiagnostics['assetsFailed'] = []
                let loaded = 0
                for (const entry of raster) {
                    const r = await preloadRasterEntry(entry, ASSET_PRELOAD_TIMEOUT_MS)
                    if (cancelled) {
                        return
                    }
                    if (r.ok) {
                        loaded += 1
                    } else {
                        assetFails.push({
                            layerId: entry.layerId,
                            url: entry.url,
                            reason: r.reason,
                        })
                    }
                }
                mergeDiagnostics({
                    assetsLoaded: loaded,
                    assetsFailed: assetFails,
                })

                // Raster + brand CSS are hard requirements. Font verification is diagnostic only: Playwright/Chrome
                // may still paint text with metric-compatible fallbacks when document.fonts.check is false in CI
                // or before full hinting settles — blocking here produced exports with missing type.
                if (assetFails.length > 0 || !typoReport.ok) {
                    mergeDiagnostics({
                        fontsLoaded:
                            typoReport.ok && fontCheckFails.length === 0 ? diagnosticsRef.current.fontsRequested : 0,
                    })
                    fail(
                        !typoReport.ok
                            ? 'brand typography preload failed'
                            : 'one or more raster assets failed to preload',
                    )
                    window.clearTimeout(hardTimeoutId)
                    return
                }

                if (cancelled) {
                    window.clearTimeout(hardTimeoutId)
                    return
                }
                mergeDiagnostics({ fontsLoaded: fontsRequested })
                window.clearTimeout(hardTimeoutId)
                succeed()
            } catch (e) {
                window.clearTimeout(hardTimeoutId)
                fail(e instanceof Error ? e.message : 'readiness run failed')
            }
        }

        void run()

        return () => {
            cancelled = true
            if (hardTimeoutId) {
                window.clearTimeout(hardTimeoutId)
            }
        }
    }, [parsed, brandContext, renderPayload, mergeDiagnostics])

    const bg = renderPayload.background?.color ?? '#0a0a0a'

    return (
        <>
            <Head title="Composition export render" />
            <div
                className="relative flex items-center justify-center overflow-hidden"
                style={{
                    width: renderPayload.width,
                    height: renderPayload.height,
                    backgroundColor: bg,
                }}
                data-composition-export-surface
                data-export-job-id={exportJobId}
                data-composition-id={compositionId}
            >
                {parsed.ok ? (
                    <CompositionScene
                        mode="export"
                        payload={renderPayload}
                        currentTimeMs={currentTimeMs}
                        brandContext={brandContext}
                        stageScale={1}
                    />
                ) : (
                    <div className="pointer-events-none px-4 text-center font-mono text-[11px] text-red-200">
                        {parsed.error}
                    </div>
                )}
            </div>
        </>
    )
}
