import { useCallback, useEffect, useMemo, useState } from 'react'
import {
    postStudioAnimation,
    postStudioAnimationPreflight,
    type PostStudioAnimationPayload,
    type PreflightRiskDto,
} from '../editorStudioAnimationBridge'
import type { DocumentModel } from '../documentModel'

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

type Props = {
    open: boolean
    compositionId: string
    /** Latest saved composition version id (newest in history), if known. */
    latestCompositionVersionId?: string | null
    document: DocumentModel
    textLayerCount: number
    getStageEl: () => HTMLElement | null
    onClose: () => void
    onQueued: (jobId: string) => void
    /** Fires as soon as Animate is clicked (after canvas is ready), before the network request — use to expand the Versions rail. */
    onAnimateSubmitStart?: () => void
}

export function StudioAnimateCompositionModal(props: Props) {
    const {
        open,
        compositionId,
        latestCompositionVersionId,
        document,
        textLayerCount,
        getStageEl,
        onClose,
        onQueued,
        onAnimateSubmitStart,
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
    const [sourceKind, setSourceKind] = useState<'full_composition' | 'selection'>('full_composition')

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
            const { captureCompositionThumbnailBase64 } = await import('../editorCompositionThumbnail')
            const b64 = await captureCompositionThumbnailBase64(stage, document, { pixelRatio: 1 })
            if (!b64) {
                throw new Error('Could not capture the composition snapshot.')
            }
            const resolvedAspect = aspect === 'canvas' ? canvasMatchedAspect : aspect
            const payload: PostStudioAnimationPayload = {
                provider,
                provider_model: providerModel,
                source_strategy: 'composition_snapshot',
                prompt: prompt.trim() || null,
                negative_prompt: negative.trim() || null,
                motion_preset: motionPreset,
                duration_seconds: duration,
                aspect_ratio: resolvedAspect,
                generate_audio: audio,
                composition_snapshot_png_base64: b64,
                snapshot_width: document.width,
                snapshot_height: document.height,
                document_json: document,
                source_composition_version_id: latestCompositionVersionId ?? undefined,
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
        latestCompositionVersionId,
        motionPreset,
        negative,
        onAnimateSubmitStart,
        onClose,
        onQueued,
        prompt,
        provider,
        providerModel,
    ])

    if (!open) {
        return null
    }

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 p-4">
            <div
                role="dialog"
                aria-labelledby="studio-animate-title"
                className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-gray-700 bg-gray-900 p-5 shadow-2xl"
            >
                <h2 id="studio-animate-title" className="text-lg font-semibold text-white">
                    Animate composition
                </h2>
                <p className="mt-1 text-sm text-gray-400">
                    Best for image-led layouts with minimal copy. We send a deterministic snapshot of your canvas to the
                    video model — not raw layer animation.
                </p>

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
                        <label className="text-xs font-semibold uppercase tracking-wide text-gray-500">Source</label>
                        <select
                            className="mt-1 w-full rounded-md border border-gray-700 bg-gray-800 px-2 py-1.5 text-gray-100"
                            value={sourceKind}
                            aria-label="Animation source"
                            onChange={(e) => setSourceKind(e.target.value as 'full_composition' | 'selection')}
                        >
                            <option value="full_composition">Full composition (canvas snapshot)</option>
                            <option value="selection" disabled>
                                Selection / layers (coming soon)
                            </option>
                        </select>
                    </div>
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
