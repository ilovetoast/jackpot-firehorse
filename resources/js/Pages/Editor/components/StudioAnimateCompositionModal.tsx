import { useCallback, useEffect, useMemo, useState } from 'react'
import {
    postStudioAnimation,
    postStudioAnimationPreflight,
    type PostStudioAnimationPayload,
    type PreflightRiskDto,
} from '../editorStudioAnimationBridge'
import { PLACEHOLDER_IMAGE_SRC, type DocumentModel, type Layer } from '../documentModel'

const MOTION_PRESETS: ReadonlyArray<{ id: string; label: string }> = [
    { id: 'cinematic_pan', label: 'Cinematic pan' },
    { id: 'subtle_alive', label: 'Subtle alive' },
    { id: 'hero_reveal', label: 'Hero reveal' },
    { id: 'product_orbit', label: 'Product orbit' },
]

const DURATIONS = [3, 4, 5, 6, 7, 8, 9, 10] as const
const ASPECTS = ['16:9', '9:16', '1:1', '4:5', '3:4'] as const

type FixedAspect = (typeof ASPECTS)[number]
type AspectChoice = 'canvas' | FixedAspect

function layerDisplayName(l: Layer): string {
    const n = (l as { name?: string }).name?.trim()
    return n && n.length > 0 ? n : l.id
}

/** URL to show in the modal for a raster layer (image or generative still). */
function rasterLayerPreviewUrl(l: Layer): string | null {
    if (l.type === 'image') {
        const u = l.src?.trim()
        if (!u || u === PLACEHOLDER_IMAGE_SRC) {
            return null
        }
        return u
    }
    if (l.type === 'generative_image') {
        const u = l.resultSrc?.trim()
        return u && u.length > 0 ? u : null
    }
    return null
}

function aspectKeyToRatio(key: string): number {
    const parts = key.split(':').map((x) => Number(x))
    const w = parts[0]
    const h = parts[1]
    if (!Number.isFinite(w) || !Number.isFinite(h) || h === 0) {
        return 16 / 9
    }
    return w / h
}

/** Map document pixel size to the closest supported provider aspect label (must match server `supported_aspect_ratios`). */
function nearestSupportedAnimationAspect(canvasWidth: number, canvasHeight: number): FixedAspect {
    if (!Number.isFinite(canvasWidth) || !Number.isFinite(canvasHeight) || canvasWidth <= 0 || canvasHeight <= 0) {
        return '16:9'
    }
    const r = canvasWidth / canvasHeight
    let best: FixedAspect = '16:9'
    let bestScore = Number.POSITIVE_INFINITY
    for (const k of ASPECTS) {
        const kr = aspectKeyToRatio(k)
        const score = Math.abs(Math.log(r / kr))
        if (score < bestScore) {
            bestScore = score
            best = k
        }
    }
    return best
}

export type SourceKind = 'full_composition' | 'layer_context' | 'layer_isolated'

type Props = {
    open: boolean
    compositionId: string
    document: DocumentModel
    textLayerCount: number
    getStageEl: () => HTMLElement | null
    onClose: () => void
    onQueued: (jobId: string) => void
    /** Fires as soon as Animate is clicked (after canvas is ready), before the network request — use to expand the Versions rail. */
    onAnimateSubmitStart?: () => void
    /** When opening from “AI Video” or a layer action, preselect source mode and raster layer. */
    initialSourceKind?: SourceKind | null
    initialLayerId?: string | null
}

export function StudioAnimateCompositionModal(props: Props) {
    const {
        open,
        compositionId,
        document,
        textLayerCount,
        getStageEl,
        onClose,
        onQueued,
        onAnimateSubmitStart,
        initialSourceKind = null,
        initialLayerId = null,
    } = props
    const [provider] = useState('kling')
    const [providerModel] = useState('kling_v3_standard_image_to_video')
    const [motionPreset, setMotionPreset] = useState('cinematic_pan')
    const [prompt, setPrompt] = useState('')
    const [duration, setDuration] = useState<number>(5)
    const [aspect, setAspect] = useState<AspectChoice>('canvas')
    const [audio, setAudio] = useState(false)
    const [negative, setNegative] = useState('')
    const [advancedOpen, setAdvancedOpen] = useState(false)
    const [busy, setBusy] = useState(false)
    const [err, setErr] = useState<string | null>(null)
    const [preflight, setPreflight] = useState<PreflightRiskDto | null>(null)
    const [preflightErr, setPreflightErr] = useState<string | null>(null)
    const animatableLayers = useMemo(() => {
        return document.layers.filter((l: Layer) => l.type === 'image' || l.type === 'generative_image')
    }, [document.layers])
    const [sourceKind, setSourceKind] = useState<SourceKind>(() => {
        const k = initialSourceKind
        if (k === 'full_composition' || k === 'layer_context' || k === 'layer_isolated') {
            return k
        }
        return 'full_composition'
    })
    const [selectedLayerId, setSelectedLayerId] = useState<string>(() => {
        const firstAnim = document.layers.find((l) => l.type === 'image' || l.type === 'generative_image')
        if (
            initialLayerId &&
            document.layers.some(
                (l) => l.id === initialLayerId && (l.type === 'image' || l.type === 'generative_image')
            )
        ) {
            return initialLayerId
        }
        return firstAnim?.id ?? ''
    })
    const selectedRaster = useMemo(() => {
        const ly = document.layers.find((l) => l.id === selectedLayerId)
        if (ly && (ly.type === 'image' || ly.type === 'generative_image')) {
            return ly
        }
        return null
    }, [document.layers, selectedLayerId])
    const selectedRasterLabel = selectedRaster ? layerDisplayName(selectedRaster) : '—'
    const selectedRasterPreview = selectedRaster ? rasterLayerPreviewUrl(selectedRaster) : null

    const canvasMatchedAspect = useMemo(
        () => nearestSupportedAnimationAspect(document.width, document.height),
        [document.width, document.height],
    )

    useEffect(() => {
        if (open) {
            setAspect('canvas')
        }
    }, [open])

    useEffect(() => {
        if (animatableLayers.length > 0 && !animatableLayers.some((l) => l.id === selectedLayerId)) {
            setSelectedLayerId(animatableLayers[0].id)
        }
    }, [animatableLayers, selectedLayerId])

    useEffect(() => {
        if (!open || !compositionId) {
            return
        }
        let cancelled = false
        setPreflightErr(null)
        void postStudioAnimationPreflight(compositionId, {
            document_json: document,
            canvas_width: document.width,
            canvas_height: document.height,
        })
            .then((r) => {
                if (!cancelled) {
                    setPreflight(r.preflight)
                }
            })
            .catch((e: unknown) => {
                if (!cancelled) {
                    setPreflight(null)
                    setPreflightErr(e instanceof Error ? e.message : 'Preflight unavailable')
                }
            })
        return () => {
            cancelled = true
        }
    }, [open, compositionId, document])

    const textHeavyHeuristic = useMemo(() => textLayerCount >= 3, [textLayerCount])
    const showAiLayoutHint = useMemo(() => {
        const lvl = preflight?.risk_level
        if (lvl === 'medium' || lvl === 'high') {
            return true
        }
        return textHeavyHeuristic
    }, [preflight?.risk_level, textHeavyHeuristic])

    const serverRiskWarnings = preflight?.warning_messages ?? []

    const onSubmit = useCallback(async () => {
        const stage = getStageEl()
        if (!stage) {
            setErr('Canvas is not ready. Try again in a moment.')
            return
        }
        onAnimateSubmitStart?.()
        setBusy(true)
        setErr(null)
        try {
            const {
                captureCompositionStudioAnimationSnapshotBase64,
                captureStudioAnimationLayerIsolatedBase64,
            } = await import('../editorCompositionThumbnail')
            const resolvedAspect = aspect === 'canvas' ? canvasMatchedAspect : aspect
            let sourceStrategy: PostStudioAnimationPayload['source_strategy'] = 'composition_snapshot'
            let sourceLayerId: string | undefined
            let layerBounds: { x: number; y: number; width: number; height: number } | undefined
            let b64: string | null
            let snapshotW = document.width
            let snapshotH = document.height

            if (sourceKind === 'layer_isolated') {
                const ly = document.layers.find((l) => l.id === selectedLayerId)
                if (!ly || (ly.type !== 'image' && ly.type !== 'generative_image')) {
                    throw new Error('Select a raster layer to animate.')
                }
                sourceLayerId = ly.id
                sourceStrategy = 'selected_layer_isolated'
                const t = ly.transform
                const tw = Math.max(2, Math.round(t.width))
                const th = Math.max(2, Math.round(t.height))
                let layerNode: HTMLElement | null = null
                const candidates = stage.querySelectorAll<HTMLElement>('[data-studio-layer-id]')
                for (const el of candidates) {
                    if (el.getAttribute('data-studio-layer-id') === ly.id) {
                        layerNode = el
                        break
                    }
                }
                b64 = layerNode
                    ? await captureStudioAnimationLayerIsolatedBase64(layerNode, tw, th)
                    : null
                if (!b64) {
                    throw new Error(
                        'Could not capture this layer in isolation. Try closing and reopening the editor, or use "One layer (in context)."'
                    )
                }
                // Snapshot is already the layer’s pixels; server crop is a no-op (0,0, tw, th).
                snapshotW = tw
                snapshotH = th
                layerBounds = { x: 0, y: 0, width: tw, height: th }
            } else {
                b64 = await captureCompositionStudioAnimationSnapshotBase64(stage, document)
                if (!b64) {
                    throw new Error('Could not capture the composition snapshot.')
                }
                if (sourceKind === 'layer_context') {
                    const ly = document.layers.find((l) => l.id === selectedLayerId)
                    if (!ly || (ly.type !== 'image' && ly.type !== 'generative_image')) {
                        throw new Error('Select a raster layer to animate.')
                    }
                    sourceLayerId = ly.id
                    sourceStrategy = 'selected_layer_with_context'
                }
            }
            const payload: PostStudioAnimationPayload = {
                provider,
                provider_model: providerModel,
                source_strategy: sourceStrategy,
                prompt: prompt.trim() || null,
                negative_prompt: negative.trim() || null,
                motion_preset: motionPreset,
                duration_seconds: duration,
                aspect_ratio: resolvedAspect,
                generate_audio: audio,
                composition_snapshot_png_base64: b64,
                snapshot_width: snapshotW,
                snapshot_height: snapshotH,
                document_json: document,
                // Do not send source_composition_version_id here: the server requires it to match
                // document_json byte-for-byte; the live canvas often has unsaved edits vs. last version row.
                source_layer_id: sourceLayerId ?? null,
                layer_bounds: layerBounds ?? null,
            }
            const job = await postStudioAnimation(compositionId, payload)
            onQueued(job.id)
            onClose()
        } catch (e: unknown) {
            setErr(e instanceof Error ? e.message : 'Could not start animation')
        } finally {
            setBusy(false)
        }
    }, [
        aspect,
        audio,
        canvasMatchedAspect,
        compositionId,
        document,
        duration,
        getStageEl,
        motionPreset,
        negative,
        onAnimateSubmitStart,
        onClose,
        onQueued,
        prompt,
        provider,
        providerModel,
        sourceKind,
        selectedLayerId,
    ])

    if (!open) {
        return null
    }

    const isLayerDriven =
        sourceKind === 'layer_context' || sourceKind === 'layer_isolated'
    const heading = isLayerDriven ? 'Still image → AI video' : 'Animate composition'
    const subhead = isLayerDriven
        ? 'The model turns a flat snapshot of your design into a short clip. Pick which raster drives the motion; your type and logos stay as real layers on top when you insert the result behind them.'
        : 'Best for image-led layouts with minimal copy. We send a deterministic snapshot of your canvas to the video model — not raw layer animation.'

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 p-4">
            <div
                role="dialog"
                aria-labelledby="studio-animate-title"
                className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl border border-gray-700 bg-gray-900 p-5 shadow-2xl"
            >
                <h2 id="studio-animate-title" className="text-lg font-semibold text-white">
                    {heading}
                </h2>
                <p className="mt-1 text-sm text-gray-400">{subhead}</p>

                <div className="mt-3 rounded-lg border border-indigo-800/60 bg-indigo-950/25 px-3 py-2 text-xs text-indigo-100">
                    AI video can warp small type, fine print, and logos. Prefer bold imagery and short headlines when
                    possible.
                </div>

                {preflightErr && <p className="mt-2 text-xs text-amber-300/90">{preflightErr}</p>}

                {(preflight?.risk_level === 'medium' || preflight?.risk_level === 'high') && (
                    <div className="mt-3 rounded-lg border border-amber-700/50 bg-amber-950/30 px-3 py-2 text-xs text-amber-100">
                        <div className="font-semibold text-amber-200">
                            Preflight: {preflight.risk_level === 'high' ? 'High' : 'Medium'} composition risk
                        </div>
                        {serverRiskWarnings.length > 0 ? (
                            <ul className="mt-1 list-inside list-disc text-amber-100/95">
                                {serverRiskWarnings.map((w) => (
                                    <li key={w}>{w}</li>
                                ))}
                            </ul>
                        ) : (
                            <p className="mt-1">This layout may be challenging for AI motion.</p>
                        )}
                    </div>
                )}

                {textHeavyHeuristic && preflight?.risk_level !== 'medium' && preflight?.risk_level !== 'high' && (
                    <div className="mt-3 rounded-lg border border-amber-700/50 bg-amber-950/30 px-3 py-2 text-xs text-amber-100">
                        This composition has several text layers. AI motion may distort typography or small logos.
                    </div>
                )}

                {showAiLayoutHint && (
                    <p className="mt-2 text-[11px] text-gray-500">You can still submit — this is guidance, not a hard block.</p>
                )}

                <div className="mt-4 space-y-3 text-sm">
                    <div>
                        <label className="text-xs font-semibold uppercase tracking-wide text-gray-500">Provider</label>
                        <select
                            className="mt-1 w-full rounded-md border border-gray-700 bg-gray-800 px-2 py-1.5 text-gray-100"
                            value={provider}
                            disabled
                            aria-label="Provider"
                        >
                            <option value="kling">Kling 3.0</option>
                        </select>
                    </div>
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">What the model sees</p>
                        <p className="mt-0.5 text-[11px] leading-snug text-gray-500">
                            Video AI gets <strong className="font-medium text-gray-400">one flat snapshot</strong> — not
                            separate layers. Pick what fills that image; your text and logos stay in the real document
                            for when you <strong className="font-medium text-gray-400">insert</strong> the clip
                            (insert adds the MP4 <strong className="font-medium text-gray-400">behind</strong> your
                            other layers).
                        </p>
                        <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3" role="group" aria-label="Snapshot source">
                            <button
                                type="button"
                                onClick={() => setSourceKind('full_composition')}
                                className={`rounded-lg border px-2.5 py-2 text-left text-xs transition-colors ${
                                    sourceKind === 'full_composition'
                                        ? 'border-indigo-500/80 bg-indigo-950/40 text-indigo-50 ring-1 ring-indigo-500/50'
                                        : 'border-gray-700 bg-gray-800/60 text-gray-200 hover:border-gray-600'
                                }`}
                            >
                                <span className="block font-semibold">Full canvas</span>
                                <span className="mt-0.5 block text-[10px] font-normal leading-snug text-gray-500">
                                    Entire ad as one frame — all layers visible. Use when the whole layout should drive
                                    motion.
                                </span>
                            </button>
                            <button
                                type="button"
                                disabled={animatableLayers.length === 0}
                                onClick={() => setSourceKind('layer_context')}
                                className={`rounded-lg border px-2.5 py-2 text-left text-xs transition-colors disabled:cursor-not-allowed disabled:opacity-40 ${
                                    sourceKind === 'layer_context'
                                        ? 'border-indigo-500/80 bg-indigo-950/40 text-indigo-50 ring-1 ring-indigo-500/50'
                                        : 'border-gray-700 bg-gray-800/60 text-gray-200 hover:border-gray-600'
                                }`}
                                title={animatableLayers.length === 0 ? 'Add an image or generative image layer first' : undefined}
                            >
                                <span className="block font-semibold">One layer (in context)</span>
                                <span className="mt-0.5 block text-[10px] font-normal leading-snug text-gray-500">
                                    Full-canvas snapshot; motion follows the <strong className="text-gray-400">raster you
                                    pick</strong> (e.g. a stock background). Type may still be in the shot.
                                </span>
                            </button>
                            <button
                                type="button"
                                disabled={animatableLayers.length === 0}
                                onClick={() => setSourceKind('layer_isolated')}
                                className={`rounded-lg border px-2.5 py-2 text-left text-xs transition-colors disabled:cursor-not-allowed disabled:opacity-40 ${
                                    sourceKind === 'layer_isolated'
                                        ? 'border-indigo-500/80 bg-indigo-950/40 text-indigo-50 ring-1 ring-indigo-500/50'
                                        : 'border-gray-700 bg-gray-800/60 text-gray-200 hover:border-gray-600'
                                }`}
                            >
                                <span className="block font-semibold">One layer (cropped)</span>
                                <span className="mt-0.5 block text-[10px] font-normal leading-snug text-gray-500">
                                    Sends <strong className="text-gray-400">only that layer&rsquo;s image</strong> (not a crop of
                                    the full ad), so other layers&rsquo; type/logos are not in the frame. Baked-in text
                                    in the image file itself can still appear.
                                </span>
                            </button>
                        </div>
                    </div>
                    <div className="rounded-md border border-sky-800/50 bg-sky-950/25 px-2.5 py-2 text-[11px] leading-snug text-sky-100/90">
                        <p className="font-semibold text-sky-200/95">Workflow tip</p>
                        <p className="mt-1 text-sky-100/88">
                            For the cleanest results, use <strong className="text-sky-50">One layer (cropped)</strong> on a
                            full-bleed art layer (e.g. &ldquo;Shutterstock…&rdquo;) so the AI never sees type. If you
                            use <strong className="text-sky-50">Full canvas</strong>, the model still sees your
                            headlines. After the job finishes, <strong className="text-sky-50">Insert as composition
                            video layer</strong> places the file <strong className="text-sky-50">under</strong> your text
                            and other layers, then you can <strong className="text-sky-50">Export final video</strong> to
                            bake one MP4.
                        </p>
                    </div>
                    {sourceKind !== 'full_composition' && animatableLayers.length > 0 && (
                        <div>
                            <label className="text-xs font-semibold uppercase tracking-wide text-gray-500">Raster layer</label>
                            <p className="mt-0.5 text-[10px] text-gray-500">Currently: {selectedRasterLabel}</p>
                            <div className="mt-2 flex flex-col gap-2 sm:flex-row sm:items-stretch">
                                <div
                                    className="flex shrink-0 items-center justify-center overflow-hidden rounded-lg border border-gray-600 bg-gray-950 sm:w-[7.5rem]"
                                    title={selectedRasterLabel}
                                >
                                    {selectedRasterPreview ? (
                                        <img
                                            key={selectedRasterPreview}
                                            src={selectedRasterPreview}
                                            alt={`Preview: ${selectedRasterLabel}`}
                                            className="max-h-28 w-full max-w-full object-contain sm:h-28 sm:w-28"
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    ) : (
                                        <div className="flex min-h-[6.5rem] w-full flex-col items-center justify-center px-2 py-3 text-center text-[10px] leading-snug text-gray-500 sm:min-h-0 sm:h-28 sm:w-28">
                                            No image preview (add a source or generate first).
                                        </div>
                                    )}
                                </div>
                                <select
                                    className="min-h-[2.75rem] w-full min-w-0 flex-1 rounded-md border border-gray-700 bg-gray-800 px-2 py-1.5 text-sm text-gray-100 sm:self-center"
                                    value={selectedLayerId}
                                    onChange={(e) => setSelectedLayerId(e.target.value)}
                                    aria-label="Choose raster for animation source"
                                >
                                    {animatableLayers.map((l) => (
                                        <option key={l.id} value={l.id} title={layerDisplayName(l)}>
                                            {layerDisplayName(l)}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                    )}
                    <div>
                        <label className="text-xs font-semibold uppercase tracking-wide text-gray-500">Motion preset</label>
                        <select
                            className="mt-1 w-full rounded-md border border-gray-700 bg-gray-800 px-2 py-1.5 text-gray-100"
                            value={motionPreset}
                            onChange={(e) => setMotionPreset(e.target.value)}
                        >
                            {MOTION_PRESETS.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="text-xs font-semibold uppercase tracking-wide text-gray-500">Prompt</label>
                        <textarea
                            className="mt-1 w-full rounded-md border border-gray-700 bg-gray-800 px-2 py-1.5 text-gray-100"
                            rows={3}
                            value={prompt}
                            onChange={(e) => setPrompt(e.target.value)}
                            placeholder="Describe the motion you want (optional — motion preset adds guidance)"
                        />
                        <p className="mt-1 text-[10px] leading-snug text-gray-500">
                            Only this text (and the motion preset) go to the video model — not your AI image edit
                            prompt in the properties panel.
                        </p>
                    </div>
                    <div className="flex gap-3">
                        <div className="flex-1">
                            <label className="text-xs font-semibold uppercase tracking-wide text-gray-500">Duration</label>
                            <select
                                className="mt-1 w-full rounded-md border border-gray-700 bg-gray-800 px-2 py-1.5 text-gray-100"
                                value={duration}
                                onChange={(e) => setDuration(Number(e.target.value))}
                            >
                                {DURATIONS.map((d) => (
                                    <option key={d} value={d}>
                                        {d}s
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="flex-1">
                            <label className="text-xs font-semibold uppercase tracking-wide text-gray-500">Aspect ratio</label>
                            <select
                                className="mt-1 w-full rounded-md border border-gray-700 bg-gray-800 px-2 py-1.5 text-gray-100"
                                value={aspect}
                                onChange={(e) => setAspect(e.target.value as AspectChoice)}
                            >
                                <option value="canvas">
                                    Match canvas ({document.width}×{document.height} → {canvasMatchedAspect})
                                </option>
                                {ASPECTS.map((a) => (
                                    <option key={a} value={a}>
                                        {a}
                                    </option>
                                ))}
                            </select>
                            <p className="mt-1 text-[10px] leading-snug text-gray-500">
                                The model returns a fixed <strong className="text-gray-400">aspect ratio</strong> (above), not your exact
                                canvas pixel size. Output resolution (e.g. 720p/1080p) is defined by the provider — resize the clip after
                                it lands in the library if you need a different pixel size.
                            </p>
                        </div>
                    </div>
                    <label className="flex items-center gap-2 text-gray-200">
                        <input type="checkbox" checked={audio} onChange={(e) => setAudio(e.target.checked)} />
                        Generate audio (experimental)
                    </label>
                    <button
                        type="button"
                        className="flex w-full items-center justify-between rounded-md border border-gray-700 bg-gray-800 px-2 py-1.5 text-left text-xs text-gray-300 hover:bg-gray-700"
                        onClick={() => setAdvancedOpen((v) => !v)}
                    >
                        <span>Advanced</span>
                        <span>{advancedOpen ? '▾' : '▸'}</span>
                    </button>
                    {advancedOpen && (
                        <div>
                            <label className="text-xs font-semibold uppercase tracking-wide text-gray-500">Negative prompt</label>
                            <textarea
                                className="mt-1 w-full rounded-md border border-gray-700 bg-gray-800 px-2 py-1.5 text-gray-100"
                                rows={2}
                                value={negative}
                                onChange={(e) => setNegative(e.target.value)}
                            />
                        </div>
                    )}
                </div>

                {err && <p className="mt-3 text-sm text-red-400">{err}</p>}

                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" className="rounded-md px-3 py-1.5 text-sm text-gray-300 hover:bg-gray-800" onClick={onClose}>
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => void onSubmit()}
                        className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                    >
                        {busy ? 'Starting…' : 'Animate'}
                    </button>
                </div>
            </div>
        </div>
    )
}
