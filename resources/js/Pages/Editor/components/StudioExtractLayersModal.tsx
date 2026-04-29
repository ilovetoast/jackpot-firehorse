import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
    deleteLayerExtractionCandidate,
    fetchExtractLayerOptions,
    fetchLayerExtractionSession,
    postClearLayerExtractionManualCandidates,
    postClearLayerExtractionPicks,
    postConfirmExtractLayers,
    postExtractLayers,
    postLayerExtractionBox,
    postLayerExtractionPick,
    postLayerExtractionRefine,
    postLayerExtractionResetRefine,
    type ExtractionMethodOption,
    type LocalSourceTooLargePayload,
    type LayerExtractionCandidateDto,
    type LayerExtractionProviderCapabilities,
    type PostExtractLayersError,
} from '../editorStudioLayerExtractionBridge'
import { migrateDocumentIfNeeded, type DocumentModel } from '../documentModel'

type Props = {
    open: boolean
    compositionId: string
    layerId: string
    /** URL for the source raster (layer src / resultSrc) — used for point-pick preview. */
    sourceImageUrl?: string | null
    onClose: () => void
    onDocumentApplied: (doc: DocumentModel, meta: { newLayerIds: string[] }) => void
    onCreditsMayHaveChanged?: () => void
}

/**
 * Map a click on an &lt;img&gt; with object-contain to normalized [0,1] coords on the image bitmap.
 */
function eventToImageNorm(e: React.MouseEvent<HTMLImageElement>): { x: number; y: number } {
    const img = e.currentTarget
    const r = img.getBoundingClientRect()
    const rw = r.width
    const rh = r.height
    const ir = img.naturalWidth / img.naturalHeight
    const cr = rw / rh
    let x0 = 0
    let y0 = 0
    let w = rw
    let h = rh
    if (ir > cr) {
        w = rw
        h = rw / ir
        y0 = (rh - h) / 2
    } else {
        h = rh
        w = rh * ir
        x0 = (rw - w) / 2
    }
    const cx = e.clientX - r.left
    const cy = e.clientY - r.top
    const u = w > 0 ? (cx - x0) / w : 0
    const v = h > 0 ? (cy - y0) / h : 0
    return { x: Math.min(1, Math.max(0, u)), y: Math.min(1, Math.max(0, v)) }
}

function clientToImageNorm(clientX: number, clientY: number, img: HTMLImageElement): { x: number; y: number } {
    const r = img.getBoundingClientRect()
    const rw = r.width
    const rh = r.height
    const ir = img.naturalWidth / img.naturalHeight
    const cr = rw / rh
    let x0 = 0
    let y0 = 0
    let w = rw
    let h = rh
    if (ir > cr) {
        w = rw
        h = rw / ir
        y0 = (rh - h) / 2
    } else {
        h = rh
        w = rh * ir
        x0 = (rw - w) / 2
    }
    const cx = clientX - r.left
    const cy = clientY - r.top
    const u = w > 0 ? (cx - x0) / w : 0
    const v = h > 0 ? (cy - y0) / h : 0
    return { x: Math.min(1, Math.max(0, u)), y: Math.min(1, Math.max(0, v)) }
}

function imageNormToContainerPercent(
    img: HTMLImageElement,
    n: { x: number; y: number }
): { leftPct: number; topPct: number } {
    const r = img.getBoundingClientRect()
    const rw = r.width
    const rh = r.height
    if (rw <= 0 || rh <= 0) {
        return { leftPct: 50, topPct: 50 }
    }
    const ir = img.naturalWidth / Math.max(1, img.naturalHeight)
    const cr = rw / rh
    let x0 = 0
    let y0 = 0
    let w = rw
    let h = rh
    if (ir > cr) {
        w = rw
        h = rw / ir
        y0 = (rh - h) / 2
    } else {
        h = rh
        w = rh * ir
        x0 = (rw - w) / 2
    }
    const leftPct = ((x0 + w * n.x) / rw) * 100
    const topPct = ((y0 + h * n.y) / rh) * 100
    return { leftPct, topPct }
}

function normRectToOverlayStyle(
    img: HTMLImageElement,
    r: { x: number; y: number; width: number; height: number }
): { left: string; top: string; width: string; height: string } {
    const p1 = imageNormToContainerPercent(img, { x: r.x, y: r.y })
    const p2 = imageNormToContainerPercent(img, { x: r.x + r.width, y: r.y + r.height })
    const left = Math.min(p1.leftPct, p2.leftPct)
    const top = Math.min(p1.topPct, p2.topPct)
    const right = Math.max(p1.leftPct, p2.leftPct)
    const bottom = Math.max(p1.topPct, p2.topPct)
    return {
        left: `${left}%`,
        top: `${top}%`,
        width: `${Math.max(0, right - left)}%`,
        height: `${Math.max(0, bottom - top)}%`,
    }
}

function readNormPointList(meta: unknown, key: string): { x: number; y: number }[] {
    if (!meta || typeof meta !== 'object') {
        return []
    }
    const raw = (meta as Record<string, unknown>)[key]
    if (!Array.isArray(raw)) {
        return []
    }
    const out: { x: number; y: number }[] = []
    for (const p of raw) {
        if (p && typeof p === 'object' && 'x' in p && 'y' in p) {
            const o = p as { x: unknown; y: unknown }
            if (typeof o.x === 'number' && typeof o.y === 'number') {
                out.push({ x: o.x, y: o.y })
            }
        }
    }
    return out
}

function hasRefineHistory(c: LayerExtractionCandidateDto): boolean {
    const m = c.metadata && typeof c.metadata === 'object' ? (c.metadata as Record<string, unknown>) : null
    if (!m) {
        return false
    }
    const nNeg = readNormPointList(m, 'negative_points').length
    const nPos = readNormPointList(m, 'positive_points').length
    const rc = m.refine_count
    return nNeg > 0 || nPos > 1 || (typeof rc === 'number' && rc > 0)
}

export function StudioExtractLayersModal(props: Props) {
    const { open, compositionId, layerId, sourceImageUrl, onClose, onDocumentApplied, onCreditsMayHaveChanged } = props
    const creditsCallbackRef = useRef(onCreditsMayHaveChanged)
    creditsCallbackRef.current = onCreditsMayHaveChanged
    const [busy, setBusy] = useState(false)
    const [err, setErr] = useState<string | null>(null)
    const [localTooLargeMeta, setLocalTooLargeMeta] = useState<LocalSourceTooLargePayload | null>(null)
    const [sessionId, setSessionId] = useState<string | null>(null)
    const [candidates, setCandidates] = useState<LayerExtractionCandidateDto[]>([])
    const [selected, setSelected] = useState<Record<string, boolean>>({})
    const [keepOriginalVisible, setKeepOriginalVisible] = useState(true)
    const [createFilledBackground, setCreateFilledBackground] = useState(false)
    const [hideOriginalAfterExtraction, setHideOriginalAfterExtraction] = useState(false)
    const [providerCapabilities, setProviderCapabilities] = useState<LayerExtractionProviderCapabilities | null>(null)
    const [pollNote, setPollNote] = useState<string | null>(null)
    const [extractionMethod, setExtractionMethod] = useState<'local' | 'ai'>('local')
    const [methodOptions, setMethodOptions] = useState<ExtractionMethodOption[] | null>(null)
    const [optionsError, setOptionsError] = useState<string | null>(null)
    const [pickMode, setPickMode] = useState(false)
    const [pickBusy, setPickBusy] = useState(false)
    const [pickWarning, setPickWarning] = useState<string | null>(null)
    const [lastPickPos, setLastPickPos] = useState<{ leftPct: number; topPct: number } | null>(null)
    const [refiningCandidateId, setRefiningCandidateId] = useState<string | null>(null)
    const [refineMode, setRefineMode] = useState<'add' | 'remove'>('remove')
    const [refineLoading, setRefineLoading] = useState(false)
    const [refineWarning, setRefineWarning] = useState<string | null>(null)
    const [boxToolActive, setBoxToolActive] = useState(false)
    const [boxTargetMode, setBoxTargetMode] = useState<'object' | 'text_graphic'>('object')
    const [boxDrag, setBoxDrag] = useState<{ ax: number; ay: number; bx: number; by: number } | null>(null)
    const [boxWarning, setBoxWarning] = useState<string | null>(null)
    /** Card highlight: which candidate is the editing target (refine, etc.). Not the "create layer" checkboxes. */
    const [editingTargetId, setEditingTargetId] = useState<string | null>(null)

    useEffect(() => {
        if (providerCapabilities && !providerCapabilities.supports_background_fill) {
            setCreateFilledBackground(false)
            setHideOriginalAfterExtraction(false)
        }
    }, [providerCapabilities])

    const reset = useCallback(() => {
        setBusy(false)
        setErr(null)
        setSessionId(null)
        setCandidates([])
        setSelected({})
        setKeepOriginalVisible(true)
        setCreateFilledBackground(false)
        setHideOriginalAfterExtraction(false)
        setProviderCapabilities(null)
        setPollNote(null)
        setMethodOptions(null)
        setOptionsError(null)
        setExtractionMethod('local')
        setEditingTargetId(null)
        setPickMode(false)
        setPickBusy(false)
        setPickWarning(null)
        setLastPickPos(null)
        setRefiningCandidateId(null)
        setRefineMode('remove')
        setRefineLoading(false)
        setRefineWarning(null)
        setBoxToolActive(false)
        setBoxTargetMode('object')
        setBoxDrag(null)
        setBoxWarning(null)
        setLocalTooLargeMeta(null)
    }, [])

    const mergeSelectedFromResponse = useCallback((list: LayerExtractionCandidateDto[]) => {
        setSelected((prev) => {
            const next: Record<string, boolean> = {}
            for (const c of list) {
                const was = prev[c.id]
                next[c.id] = was !== undefined ? was : c.selected !== false
            }
            return next
        })
    }, [])

    const applySessionCandidates = useCallback(
        (list: LayerExtractionCandidateDto[], caps?: LayerExtractionProviderCapabilities | null) => {
            setCandidates(list)
            if (caps !== undefined) {
                setProviderCapabilities(caps)
            }
            mergeSelectedFromResponse(list)
        },
        [mergeSelectedFromResponse]
    )

    const runExtraction = useCallback(
        async (forceMethod?: 'local' | 'ai') => {
        if (!compositionId || !layerId) {
            return
        }
        const methodToRun = forceMethod ?? extractionMethod
        if (forceMethod) {
            setExtractionMethod(forceMethod)
        }
        setErr(null)
        setLocalTooLargeMeta(null)
        setBusy(true)
        setPollNote(null)
        setRefiningCandidateId(null)
        setPickMode(false)
        setBoxToolActive(false)
        setEditingTargetId(null)
        try {
            const res = await postExtractLayers(compositionId, layerId, { method: methodToRun })
            if (res.extraction_method) {
                setExtractionMethod(res.extraction_method)
            }
            setSessionId(res.extraction_session_id)
            if (res.status === 'ready' && 'candidates' in res) {
                if (res.candidates.length < 1) {
                    if (res.extraction_method === 'ai') {
                        throw new Error(
                            'AI segmentation found no separable elements. Try Pick point or Draw box.'
                        )
                    }
                    throw new Error('No extraction candidates were produced. Try a different method or image.')
                }
                applySessionCandidates(res.candidates, res.provider_capabilities ?? null)
                creditsCallbackRef.current?.()
            } else {
                setPollNote('Working on segmentation…')
                const isAi = res.extraction_method === 'ai' || methodToRun === 'ai'
                const maxTries = isAi ? 150 : 90
                const delayMs = isAi ? 1000 : 600
                let tries = 0
                const sid = res.extraction_session_id
                while (tries < maxTries) {
                    tries++
                    await new Promise((r) => setTimeout(r, delayMs))
                    if (tries === 15 && isAi) {
                        setPollNote('Still working on segmentation… (this can take a minute)')
                    }
                    const st = await fetchLayerExtractionSession(sid)
                    if (st.extraction_method) {
                        setExtractionMethod(st.extraction_method)
                    }
                    if (st.status === 'ready') {
                        const list = st.candidates ?? []
                        if (list.length > 0) {
                            applySessionCandidates(list, st.provider_capabilities ?? null)
                            setPollNote(null)
                            creditsCallbackRef.current?.()
                        } else {
                            setPollNote(null)
                            if (st.extraction_method === 'ai' || isAi) {
                                throw new Error(
                                    'AI segmentation found no separable elements. Try Pick point or Draw box.'
                                )
                            }
                            throw new Error(
                                'No extraction candidates were produced. Try a different method or image.'
                            )
                        }
                        break
                    }
                    if (st.status === 'failed' || st.status === 'expired') {
                        setPollNote(null)
                        const em = (st.error_message || '').trim()
                        if (st.code === 'local_source_too_large') {
                            const fe: PostExtractLayersError = new Error(
                                em !== '' ? em : 'This image is too large for local extraction.'
                            )
                            fe.code = 'local_source_too_large'
                            fe.method = 'local'
                            fe.can_try_ai = st.can_try_ai === true
                            fe.ai_available = st.ai_available === true
                            fe.ai_unavailable_reason =
                                typeof st.ai_unavailable_reason === 'string' ? st.ai_unavailable_reason : null
                            throw fe
                        }
                        throw new Error(
                            em !== ''
                                ? em
                                : 'AI segmentation failed. Try Local mask detection or try again.'
                        )
                    }
                }
                if (tries >= maxTries) {
                    setPollNote(null)
                    throw new Error(
                        'AI segmentation timed out. Try Draw box or Local mask detection.'
                    )
                }
            }
        } catch (e: unknown) {
            const p = e as PostExtractLayersError
            if (p?.code === 'local_source_too_large') {
                setLocalTooLargeMeta({
                    code: 'local_source_too_large',
                    method: 'local',
                    can_try_ai: p.can_try_ai === true,
                    ai_available: p.ai_available === true,
                    ai_unavailable_reason:
                        typeof p.ai_unavailable_reason === 'string' ? p.ai_unavailable_reason : null,
                })
            } else {
                setLocalTooLargeMeta(null)
            }
            setErr(e instanceof Error ? e.message : 'Extraction failed')
        } finally {
            setBusy(false)
        }
    },
    [compositionId, layerId, extractionMethod, applySessionCandidates]
)

    useEffect(() => {
        if (!open) {
            reset()
        }
    }, [open, reset])

    useEffect(() => {
        if (!open || !compositionId || !layerId) {
            return
        }
        let cancelled = false
        const load = async () => {
            setOptionsError(null)
            setMethodOptions(null)
            try {
                const o = await fetchExtractLayerOptions(compositionId, layerId)
                if (cancelled) {
                    return
                }
                setMethodOptions(o.available_methods)
                setExtractionMethod(o.default_extraction_method)
            } catch (e: unknown) {
                if (!cancelled) {
                    setOptionsError(e instanceof Error ? e.message : 'Could not load extract options')
                }
            }
        }
        void load()
        return () => {
            cancelled = true
        }
    }, [open, compositionId, layerId])

    const selectedIds = useMemo(() => {
        return Object.entries(selected)
            .filter(([, v]) => v)
            .map(([k]) => k)
    }, [selected])

    const aiMethodOption = useMemo(() => methodOptions?.find((m) => m.key === 'ai'), [methodOptions])
    const canUseImageTools = Boolean(sessionId && sourceImageUrl)
    const canRefineTarget = Boolean(
        (editingTargetId?.startsWith('pick_') || editingTargetId?.startsWith('box_')) &&
            providerCapabilities?.supports_point_refine
    )

    useEffect(() => {
        if (candidates.length === 0) {
            if (editingTargetId !== null) {
                setEditingTargetId(null)
            }
            return
        }
        if (!editingTargetId || !candidates.some((c) => c.id === editingTargetId)) {
            setEditingTargetId(candidates[0].id)
        }
    }, [candidates, editingTargetId])

    const imgRef = useRef<HTMLImageElement | null>(null)
    const [imgLayoutVersion, setImgLayoutVersion] = useState(0)

    const refiningCandidate = useMemo(
        () => (refiningCandidateId ? candidates.find((c) => c.id === refiningCandidateId) : undefined),
        [candidates, refiningCandidateId]
    )

    const positiveMarkers = useMemo(() => {
        if (!refiningCandidate?.metadata || typeof refiningCandidate.metadata !== 'object') {
            return [] as { x: number; y: number }[]
        }
        const m = refiningCandidate.metadata as Record<string, unknown>
        const pp = readNormPointList(m, 'positive_points')
        if (pp.length > 0) {
            return pp
        }
        const s = m.seed_point_normalized
        if (s && typeof s === 'object' && 'x' in s && 'y' in s) {
            const o = s as { x: unknown; y: unknown }
            if (typeof o.x === 'number' && typeof o.y === 'number') {
                return [{ x: o.x, y: o.y }]
            }
        }
        const b = m.box_normalized
        if (b && typeof b === 'object' && 'x' in b && 'y' in b && 'width' in b && 'height' in b) {
            const o = b as { x: number; y: number; width: number; height: number }
            if (
                typeof o.x === 'number' &&
                typeof o.y === 'number' &&
                typeof o.width === 'number' &&
                typeof o.height === 'number'
            ) {
                return [{ x: o.x + o.width / 2, y: o.y + o.height / 2 }]
            }
        }
        return []
    }, [refiningCandidate])

    const negativeMarkers = useMemo(() => {
        if (!refiningCandidate?.metadata || typeof refiningCandidate.metadata !== 'object') {
            return [] as { x: number; y: number }[]
        }
        return readNormPointList(refiningCandidate.metadata as Record<string, unknown>, 'negative_points')
    }, [refiningCandidate])

    const handleBoxImageMouseDown = useCallback(
        (e: React.MouseEvent<HTMLImageElement>) => {
            if (!boxToolActive || pickBusy || refineLoading || !sessionId || refiningCandidateId) {
                return
            }
            e.preventDefault()
            e.stopPropagation()
            const start = eventToImageNorm(e)
            setBoxDrag({ ax: start.x, ay: start.y, bx: start.x, by: start.y })
            const onMove = (ev: MouseEvent) => {
                if (!imgRef.current) {
                    return
                }
                const cur = clientToImageNorm(ev.clientX, ev.clientY, imgRef.current)
                setBoxDrag({ ax: start.x, ay: start.y, bx: cur.x, by: cur.y })
            }
            const onUp = (ev: MouseEvent) => {
                window.removeEventListener('mousemove', onMove)
                window.removeEventListener('mouseup', onUp)
                if (!imgRef.current) {
                    setBoxDrag(null)
                    return
                }
                const end = clientToImageNorm(ev.clientX, ev.clientY, imgRef.current)
                setBoxDrag(null)
                setImgLayoutVersion((v) => v + 1)
                const x = Math.min(start.x, end.x)
                const y = Math.min(start.y, end.y)
                const w = Math.abs(end.x - start.x)
                const h = Math.abs(end.y - start.y)
                if (w < 0.012 || h < 0.012) {
                    setBoxWarning('Draw a larger box around the subject.')
                    return
                }
                if (!sessionId) {
                    return
                }
                setPickBusy(true)
                setBoxWarning(null)
                void postLayerExtractionBox(sessionId, {
                    box: { x, y, width: w, height: h },
                    mode: boxTargetMode,
                })
                    .then((out) => {
                        applySessionCandidates(out.candidates, out.provider_capabilities ?? null)
                        if (out.new_candidate) {
                            setSelected((s) => ({ ...s, [out.new_candidate!.id]: true }))
                        } else {
                            setBoxWarning(out.warning ?? 'Could not add a box candidate.')
                        }
                        creditsCallbackRef.current?.()
                    })
                    .catch((er) => {
                        setBoxWarning(er instanceof Error ? er.message : 'Box selection failed')
                    })
                    .finally(() => setPickBusy(false))
            }
            window.addEventListener('mousemove', onMove)
            window.addEventListener('mouseup', onUp)
        },
        [
            boxToolActive,
            boxTargetMode,
            pickBusy,
            refineLoading,
            sessionId,
            refiningCandidateId,
            creditsCallbackRef,
            applySessionCandidates,
        ]
    )

    const handlePreviewClick = useCallback(
        (e: React.MouseEvent<HTMLImageElement>) => {
            if (!sessionId || (refiningCandidateId ? refineLoading : pickBusy)) {
                return
            }
            if (boxToolActive) {
                return
            }
            const n = eventToImageNorm(e)

            if (refiningCandidateId && providerCapabilities?.supports_point_refine) {
                setRefineWarning(null)
                setRefineLoading(true)
                const body =
                    refineMode === 'add'
                        ? { positive_point: { x: n.x, y: n.y } }
                        : { negative_point: { x: n.x, y: n.y } }
                void postLayerExtractionRefine(sessionId, refiningCandidateId, body)
                    .then((out) => {
                        applySessionCandidates(out.candidates, out.provider_capabilities ?? null)
                        if (out.warning) {
                            setRefineWarning(out.warning)
                        } else {
                            setRefineWarning(null)
                        }
                        setImgLayoutVersion((v) => v + 1)
                        creditsCallbackRef.current?.()
                    })
                    .catch((err) => {
                        setRefineWarning(err instanceof Error ? err.message : 'Refine failed')
                    })
                    .finally(() => setRefineLoading(false))
                return
            }

            if (!pickMode || !providerCapabilities?.supports_point_pick) {
                return
            }
            setLastPickPos(imageNormToContainerPercent(e.currentTarget, n))
            setPickWarning(null)
            setPickBusy(true)
            void postLayerExtractionPick(sessionId, { x: n.x, y: n.y })
                .then((out) => {
                    applySessionCandidates(out.candidates, out.provider_capabilities ?? null)
                    if (out.new_candidate) {
                        setSelected((s) => ({ ...s, [out.new_candidate!.id]: true }))
                    } else {
                        setPickWarning(out.warning ?? 'No separable element at that point.')
                    }
                    creditsCallbackRef.current?.()
                })
                .catch((err) => {
                    setPickWarning(err instanceof Error ? err.message : 'Pick failed')
                })
                .finally(() => setPickBusy(false))
        },
        [
            boxToolActive,
            pickMode,
            pickBusy,
            refineLoading,
            refineMode,
            sessionId,
            providerCapabilities?.supports_point_pick,
            providerCapabilities?.supports_point_refine,
            refiningCandidateId,
            creditsCallbackRef,
            applySessionCandidates,
        ]
    )

    const removeManualCandidate = useCallback(
        async (candidateId: string) => {
            if (!sessionId || pickBusy) {
                return
            }
            if (!candidateId.startsWith('pick_') && !candidateId.startsWith('box_')) {
                return
            }
            if (refiningCandidateId === candidateId) {
                setRefiningCandidateId(null)
                setRefineWarning(null)
            }
            setPickBusy(true)
            setErr(null)
            try {
                const st = await deleteLayerExtractionCandidate(sessionId, candidateId)
                applySessionCandidates(st.candidates ?? [], st.provider_capabilities ?? null)
                setLastPickPos(null)
            } catch (e) {
                setErr(e instanceof Error ? e.message : 'Could not remove')
            } finally {
                setPickBusy(false)
            }
        },
        [sessionId, pickBusy, refiningCandidateId, applySessionCandidates]
    )

    const clearPicks = useCallback(async () => {
        if (!sessionId || pickBusy) {
            return
        }
        setPickBusy(true)
        setErr(null)
        setRefiningCandidateId(null)
        setRefineMode('remove')
        setRefineWarning(null)
        try {
            const st = await postClearLayerExtractionPicks(sessionId)
            applySessionCandidates(st.candidates ?? [], st.provider_capabilities ?? null)
            setLastPickPos(null)
            setPickWarning(null)
        } catch (e) {
            setErr(e instanceof Error ? e.message : 'Could not clear')
        } finally {
            setPickBusy(false)
        }
    }, [sessionId, pickBusy, applySessionCandidates])

    const clearManualCandidates = useCallback(async () => {
        if (!sessionId || pickBusy) {
            return
        }
        setPickBusy(true)
        setErr(null)
        setRefiningCandidateId(null)
        setRefineMode('remove')
        setRefineWarning(null)
        try {
            const st = await postClearLayerExtractionManualCandidates(sessionId)
            applySessionCandidates(st.candidates ?? [], st.provider_capabilities ?? null)
            setLastPickPos(null)
            setPickWarning(null)
            setBoxWarning(null)
        } catch (e) {
            setErr(e instanceof Error ? e.message : 'Could not clear')
        } finally {
            setPickBusy(false)
        }
    }, [sessionId, pickBusy, applySessionCandidates])

    const resetRefineForCandidate = useCallback(
        async (candidateId: string) => {
            if (!sessionId || pickBusy || refineLoading) {
                return
            }
            setRefineLoading(true)
            setRefineWarning(null)
            try {
                const out = await postLayerExtractionResetRefine(sessionId, candidateId)
                applySessionCandidates(out.candidates, out.provider_capabilities ?? null)
            } catch (e) {
                setRefineWarning(e instanceof Error ? e.message : 'Reset failed')
            } finally {
                setRefineLoading(false)
            }
        },
        [sessionId, pickBusy, refineLoading, applySessionCandidates]
    )

    const confirm = useCallback(async () => {
        if (!sessionId || selectedIds.length === 0) {
            return
        }
        setErr(null)
        setBusy(true)
        try {
            const canBackgroundFill = providerCapabilities?.supports_background_fill === true
            const out = await postConfirmExtractLayers(compositionId, layerId, {
                extraction_session_id: sessionId,
                candidate_ids: selectedIds,
                keep_original_visible: keepOriginalVisible,
                create_filled_background: canBackgroundFill && createFilledBackground,
                hide_original_after_extraction: canBackgroundFill && createFilledBackground && hideOriginalAfterExtraction,
            })
            const doc = migrateDocumentIfNeeded(out.document as unknown as DocumentModel)
            onDocumentApplied(doc, { newLayerIds: out.new_layer_ids ?? [] })
            onClose()
        } catch (e: unknown) {
            setErr(e instanceof Error ? e.message : 'Could not create layers')
        } finally {
            setBusy(false)
        }
    }, [
        compositionId,
        createFilledBackground,
        hideOriginalAfterExtraction,
        keepOriginalVisible,
        layerId,
        onClose,
        onDocumentApplied,
        selectedIds,
        providerCapabilities,
        sessionId,
    ])

    if (!open) {
        return null
    }

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 p-4">
            <div
                role="dialog"
                aria-labelledby="studio-extract-title"
                className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl border border-gray-700 bg-gray-900 p-5 shadow-2xl"
            >
                <h2 id="studio-extract-title" className="text-lg font-semibold text-white">
                    Extract layers
                </h2>
                <p className="mt-2 text-xs leading-snug text-gray-400">
                    Splits a flattened layer into new image layers from detected regions. It does not recover
                    the original Photoshop layers, hidden text, or true separate labels in every case — you get
                    editable cutout layers. Choose <span className="text-gray-300">local</span> for free,
                    on-device detection or <span className="text-gray-300">AI</span> when you need better object
                    masks (separate from background fill, which is optional and uses inpainting when configured).
                </p>
                <p className="mt-2 text-[10px] leading-relaxed text-gray-500">
                    Tip: run this again on a created cutout to refine further.
                </p>

                {optionsError && (
                    <p className="mt-3 text-xs text-red-400" role="alert">
                        {optionsError}
                    </p>
                )}

                {!optionsError && methodOptions === null && (
                    <p className="mt-3 text-xs text-gray-500" role="status">
                        Loading extract options…
                    </p>
                )}

                {methodOptions && (
                    <div className="mt-4 space-y-2">
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                            Extraction method
                        </p>
                        <div className="flex w-full max-w-md rounded-lg border border-gray-600/90 bg-gray-800/90 p-0.5">
                            <button
                                type="button"
                                onClick={() => setExtractionMethod('local')}
                                className={`min-h-[2.5rem] flex-1 rounded-md px-2.5 py-1.5 text-left text-[11px] font-medium leading-snug transition ${
                                    extractionMethod === 'local'
                                        ? 'bg-gray-600 text-white shadow'
                                        : 'text-gray-400 hover:bg-gray-800/60 hover:text-gray-200'
                                }`}
                            >
                                Local mask detection — Free
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    if (aiMethodOption?.available) {
                                        setExtractionMethod('ai')
                                    }
                                }}
                                disabled={!aiMethodOption?.available}
                                title={
                                    !aiMethodOption?.available
                                        ? aiMethodOption?.unavailable_reason ?? 'AI segmentation is not available'
                                        : undefined
                                }
                                className={`min-h-[2.5rem] flex-1 rounded-md px-2.5 py-1.5 text-left text-[11px] font-medium leading-snug transition ${
                                    !aiMethodOption?.available
                                        ? 'cursor-not-allowed text-gray-500 opacity-50'
                                        : extractionMethod === 'ai'
                                          ? 'bg-violet-700/90 text-white shadow'
                                          : 'border border-violet-800/50 text-violet-100/95 hover:border-violet-600/60 hover:bg-violet-950/50 hover:text-violet-50'
                                }`}
                            >
                                AI segmentation — Uses credits
                            </button>
                        </div>
                        <p className="text-[11px] leading-relaxed text-gray-400">
                            {extractionMethod === 'ai' && aiMethodOption?.available
                                ? 'AI segmentation. Better object masks, uses credits.'
                                : 'Local mask detection. Free, fast, best for simple cutouts.'}
                        </p>
                        {extractionMethod === 'ai' &&
                            aiMethodOption?.available &&
                            aiMethodOption.estimated_credits != null && (
                                <p className="text-[10px] text-violet-200/85">
                                    Estimated: {aiMethodOption.estimated_credits} app credit
                                    {aiMethodOption.estimated_credits === 1 ? '' : 's'} this run
                                    {aiMethodOption.billable ? '' : ''}
                                </p>
                            )}
                        {extractionMethod === 'ai' && !aiMethodOption?.available && aiMethodOption?.unavailable_reason && (
                            <p className="text-[10px] text-amber-200/90">{aiMethodOption.unavailable_reason}</p>
                        )}
                    </div>
                )}

                {methodOptions && (
                    <div className="mt-4 border-t border-gray-700/80 pt-4">
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-gray-500">Tools</p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={() => void runExtraction()}
                                disabled={busy || (extractionMethod === 'ai' && !aiMethodOption?.available)}
                                className="rounded border border-violet-600/80 bg-violet-800/50 px-3 py-1.5 text-[11px] font-semibold text-violet-50 hover:bg-violet-800/70 disabled:cursor-not-allowed disabled:opacity-40"
                            >
                                Auto detect
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setPickMode((m) => {
                                        const next = !m
                                        if (next) {
                                            setBoxToolActive(false)
                                            setRefiningCandidateId(null)
                                            setRefineWarning(null)
                                        }
                                        return next
                                    })
                                    setPickWarning(null)
                                }}
                                disabled={!canUseImageTools || providerCapabilities?.supports_point_pick !== true}
                                className={`rounded border px-3 py-1.5 text-[11px] font-medium ${
                                    pickMode
                                        ? 'border-amber-500/90 bg-amber-950/50 text-amber-100'
                                        : 'border-amber-700/50 bg-amber-950/20 text-amber-200/90 hover:bg-amber-900/30'
                                } disabled:cursor-not-allowed disabled:opacity-40`}
                            >
                                Pick point
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setBoxToolActive((m) => {
                                        const next = !m
                                        if (next) {
                                            setPickMode(false)
                                            setRefiningCandidateId(null)
                                            setRefineWarning(null)
                                        }
                                        return next
                                    })
                                    setBoxWarning(null)
                                }}
                                disabled={!canUseImageTools || providerCapabilities?.supports_box_pick !== true}
                                className={`rounded border px-3 py-1.5 text-[11px] font-medium ${
                                    boxToolActive
                                        ? 'border-sky-500/90 bg-sky-950/50 text-sky-100'
                                        : 'border-sky-700/50 bg-sky-950/20 text-sky-200/90 hover:bg-sky-900/30'
                                } disabled:cursor-not-allowed disabled:opacity-40`}
                            >
                                Draw box
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    if (
                                        !editingTargetId?.startsWith('pick_') &&
                                        !editingTargetId?.startsWith('box_')
                                    ) {
                                        return
                                    }
                                    if (!providerCapabilities?.supports_point_refine) {
                                        return
                                    }
                                    setRefiningCandidateId(editingTargetId)
                                    setRefineMode('remove')
                                    setRefineWarning(null)
                                    setPickMode(false)
                                    setBoxToolActive(false)
                                }}
                                disabled={
                                    !canUseImageTools ||
                                    providerCapabilities?.supports_point_refine !== true ||
                                    !canRefineTarget
                                }
                                className={`rounded border px-3 py-1.5 text-[11px] font-medium ${
                                    refiningCandidateId
                                        ? 'border-fuchsia-500/90 bg-fuchsia-950/50 text-fuchsia-100'
                                        : 'border-fuchsia-800/50 bg-fuchsia-950/20 text-fuchsia-200/90 hover:bg-fuchsia-900/30'
                                } disabled:cursor-not-allowed disabled:opacity-40`}
                            >
                                Refine selected
                            </button>
                        </div>
                        <p className="mt-2 text-[10px] leading-relaxed text-gray-500">
                            <span className="font-medium text-gray-400">Auto detect</span> runs the current
                            method (floodfill auto or AI/SAM). <span className="font-medium text-gray-400">Pick</span>{' '}
                            and <span className="font-medium text-gray-400">Draw box</span> add targeted masks.{' '}
                            <span className="font-medium text-gray-400">Refine selected</span>: select a
                            candidate (violet ring), then add or remove areas.
                        </p>
                    </div>
                )}

                {sessionId && !sourceImageUrl && methodOptions && (
                    <p className="mt-3 rounded border border-amber-800/50 bg-amber-950/25 px-2.5 py-2 text-[11px] text-amber-100/90" role="status">
                        A preview of the source image is required to use Pick point, Draw box, and Refine. Open
                        Extract layers from a layer that has a visible image, or make sure the editor provides a
                        source URL.
                    </p>
                )}

                {pollNote && (
                    <div
                        className="mt-3 flex items-center gap-2 rounded border border-violet-900/60 bg-violet-950/40 px-2 py-2 text-xs text-violet-100"
                        role="status"
                    >
                        <span className="inline-block h-3 w-3 animate-spin rounded-full border-2 border-violet-300 border-t-transparent" />
                        {pollNote}
                    </div>
                )}

                {err && (
                    <div className="mt-3 space-y-2" role="alert">
                        <p className="text-xs font-medium text-red-400">{err}</p>
                        {localTooLargeMeta?.code === 'local_source_too_large' &&
                            localTooLargeMeta.can_try_ai === true &&
                            aiMethodOption?.available === true && (
                                <button
                                    type="button"
                                    onClick={() => void runExtraction('ai')}
                                    disabled={busy}
                                    className="rounded border border-violet-500/80 bg-violet-900/40 px-2.5 py-1.5 text-[11px] font-semibold text-violet-100 hover:bg-violet-800/50 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    Switch to AI segmentation
                                </button>
                            )}
                        {localTooLargeMeta?.code === 'local_source_too_large' &&
                            localTooLargeMeta.ai_unavailable_reason === 'insufficient_ai_credits' && (
                                <p className="text-[10px] text-amber-200/90">
                                    <a
                                        href="/billing"
                                        className="font-medium text-amber-100 underline hover:text-amber-50"
                                    >
                                        Manage credits
                                    </a>
                                </p>
                            )}
                        <button
                            type="button"
                            onClick={() => {
                                setErr(null)
                                setLocalTooLargeMeta(null)
                                setSessionId(null)
                                setCandidates([])
                                setSelected({})
                                setPollNote(null)
                                setPickMode(false)
                                setPickBusy(false)
                                setPickWarning(null)
                                setLastPickPos(null)
                                setRefiningCandidateId(null)
                                setRefineMode('remove')
                                setRefineLoading(false)
                                setRefineWarning(null)
                                setBoxToolActive(false)
                                setBoxDrag(null)
                                setBoxWarning(null)
                                setEditingTargetId(null)
                                void runExtraction()
                            }}
                            className="rounded border border-red-500/50 bg-red-950/30 px-2.5 py-1 text-[11px] font-medium text-red-100 hover:bg-red-900/40"
                        >
                            Try again
                        </button>
                    </div>
                )}

                <div className="mt-4 space-y-3">
                    {candidates.length === 0 && !err && sessionId && (
                        <p className="text-xs text-gray-500">{busy ? 'Loading candidates…' : 'No candidates yet.'}</p>
                    )}
                    {candidates.length > 0 && (
                        <p className="text-[11px] font-medium text-gray-300" role="status">
                            {candidates.length === 1 ? 'Detected element' : 'Detected elements'}
                        </p>
                    )}
                    {candidates.length > 0 && sessionId && (
                        <p className="text-[10px] text-gray-500" role="note">
                            <span className="text-violet-300/90">Ring</span> = target for tools / Refine.{' '}
                            <span className="text-gray-300">Checkbox</span> = create that region as a new layer.
                        </p>
                    )}

                    {extractionMethod === 'local' &&
                        sessionId &&
                        candidates.length === 1 &&
                        !candidates[0].id.startsWith('pick_') &&
                        !candidates[0].id.startsWith('box_') && (
                            <p className="rounded border border-amber-900/40 bg-amber-950/20 px-2.5 py-2 text-[11px] leading-relaxed text-amber-100/90">
                                Only one local region was detected. Use <span className="font-medium">Pick point</span>{' '}
                                or <span className="font-medium">Draw box</span> to target a specific element, or switch
                                to <span className="font-medium">AI segmentation</span> for better object masks.
                            </p>
                        )}

                    {canUseImageTools && (
                            <div className="space-y-2">
                                <p className="text-[10px] font-semibold uppercase tracking-wider text-gray-500">
                                    Source preview
                                </p>
                                <div className="flex flex-wrap items-center gap-2">
                                    {providerCapabilities?.supports_point_pick && (
                                        <button
                                            type="button"
                                            onClick={() => void clearPicks()}
                                            disabled={
                                                busy ||
                                                pickBusy ||
                                                refineLoading ||
                                                !candidates.some((c) => c.id.startsWith('pick_')) ||
                                                refiningCandidateId !== null
                                            }
                                            className="rounded border border-gray-600 px-2.5 py-1 text-[11px] text-gray-300 hover:bg-gray-800 disabled:opacity-40"
                                        >
                                            Clear picks
                                        </button>
                                    )}
                                    {(providerCapabilities?.supports_point_pick ||
                                        providerCapabilities?.supports_box_pick) && (
                                        <button
                                            type="button"
                                            onClick={() => void clearManualCandidates()}
                                            disabled={
                                                busy ||
                                                pickBusy ||
                                                refineLoading ||
                                                !candidates.some(
                                                    (c) => c.id.startsWith('pick_') || c.id.startsWith('box_')
                                                ) ||
                                                refiningCandidateId !== null
                                            }
                                            className="rounded border border-gray-600 px-2.5 py-1 text-[11px] text-gray-300 hover:bg-gray-800 disabled:opacity-40"
                                        >
                                            Clear manual
                                        </button>
                                    )}
                                </div>
                                {boxToolActive && !refiningCandidateId && (
                                    <div className="space-y-2.5">
                                        <p className="text-[11px] text-sky-100/90">
                                            Drag a box. Choose the mode that matches the subject.
                                        </p>
                                        <div className="space-y-2 rounded border border-sky-900/40 bg-slate-900/30 p-2.5">
                                            <p className="text-[10px] font-medium uppercase tracking-wide text-sky-200/80">
                                                Draw box mode
                                            </p>
                                            <div className="space-y-2 text-[10px]">
                                                <label className="flex cursor-pointer items-start gap-2">
                                                    <input
                                                        type="radio"
                                                        name="box-target"
                                                        className="mt-0.5"
                                                        checked={boxTargetMode === 'object'}
                                                        onChange={() => setBoxTargetMode('object')}
                                                        disabled={pickBusy}
                                                    />
                                                    <span>
                                                        <span className="font-medium text-sky-100/95">Object</span>
                                                        <span className="text-gray-400"> — </span>
                                                        <span className="text-gray-500">
                                                            Best for products, people, and shapes.
                                                        </span>
                                                    </span>
                                                </label>
                                                <label className="flex cursor-pointer items-start gap-2">
                                                    <input
                                                        type="radio"
                                                        name="box-target"
                                                        className="mt-0.5"
                                                        checked={boxTargetMode === 'text_graphic'}
                                                        onChange={() => setBoxTargetMode('text_graphic')}
                                                        disabled={pickBusy}
                                                    />
                                                    <span>
                                                        <span className="font-medium text-sky-100/95">Text / graphic</span>
                                                        <span className="text-gray-400"> — </span>
                                                        <span className="text-gray-500">
                                                            Best for flattened type, logos, and flat artwork. Creates an
                                                            image cutout, not editable text.
                                                        </span>
                                                    </span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                )}
                                {pickMode && !refiningCandidateId && (
                                    <p className="text-[11px] text-amber-100/90">
                                        Click the object or region you want to separate.
                                    </p>
                                )}
                                {refiningCandidateId && refiningCandidate && (
                                    <div className="space-y-2 rounded-lg border border-violet-800/50 bg-violet-950/20 p-2.5">
                                        <p className="text-[12px] font-medium text-white/95">
                                            Refining: {refiningCandidate.label ?? refiningCandidate.id}
                                        </p>
                                        <p className="text-[10px] leading-relaxed text-gray-400">
                                            Select a candidate, then use Refine to add or remove areas.{' '}
                                            <span className="text-emerald-200/95">Add area</span> includes more of the
                                            subject. <span className="text-rose-200/95">Remove area</span> excludes
                                            background or mistakes.
                                        </p>
                                        <div className="flex flex-wrap gap-1.5">
                                            <button
                                                type="button"
                                                onClick={() => setRefineMode('add')}
                                                disabled={refineLoading}
                                                className={`rounded border px-2 py-1 text-[10px] font-medium ${
                                                    refineMode === 'add'
                                                        ? 'border-emerald-500/80 bg-emerald-950/50 text-emerald-50'
                                                        : 'border-emerald-800/50 bg-gray-900/50 text-emerald-100/90 hover:bg-gray-800/60'
                                                } disabled:opacity-40`}
                                            >
                                                Add area
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setRefineMode('remove')}
                                                disabled={refineLoading}
                                                className={`rounded border px-2 py-1 text-[10px] font-medium ${
                                                    refineMode === 'remove'
                                                        ? 'border-rose-500/80 bg-rose-950/50 text-rose-50'
                                                        : 'border-rose-900/50 bg-gray-900/50 text-rose-100/90 hover:bg-gray-800/60'
                                                } disabled:opacity-40`}
                                            >
                                                Remove area
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setRefiningCandidateId(null)
                                                    setRefineWarning(null)
                                                    setRefineMode('remove')
                                                }}
                                                disabled={refineLoading}
                                                className="rounded border border-gray-600 bg-gray-800/60 px-2 py-1 text-[10px] text-gray-200 hover:bg-gray-700/60 disabled:opacity-40"
                                            >
                                                Done
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    refiningCandidateId && void resetRefineForCandidate(refiningCandidateId)
                                                }
                                                disabled={
                                                    refineLoading ||
                                                    !refiningCandidate ||
                                                    !hasRefineHistory(refiningCandidate)
                                                }
                                                className="rounded border border-slate-600 bg-slate-800/50 px-2 py-1 text-[10px] text-slate-200 hover:bg-slate-700/50 disabled:opacity-40"
                                            >
                                                Reset refine
                                            </button>
                                        </div>
                                        {refineMode === 'add' && (
                                            <p className="text-[11px] text-emerald-100/90">
                                                Click missing areas that should be included.
                                            </p>
                                        )}
                                        {refineMode === 'remove' && (
                                            <p className="text-[11px] text-rose-100/90">
                                                Click areas that should not be part of this cutout.
                                            </p>
                                        )}
                                    </div>
                                )}
                                <div
                                    className={`relative inline-block max-w-full overflow-hidden rounded border border-gray-700 bg-black/30 ${
                                        (pickMode || refiningCandidateId || boxToolActive) &&
                                        !(refiningCandidateId ? refineLoading : pickBusy)
                                            ? 'cursor-crosshair'
                                            : 'cursor-default'
                                    }`}
                                >
                                    <img
                                        ref={imgRef}
                                        src={sourceImageUrl}
                                        alt=""
                                        onLoad={() => setImgLayoutVersion((v) => v + 1)}
                                        onClick={handlePreviewClick}
                                        onMouseDown={handleBoxImageMouseDown}
                                        className="max-h-80 w-full object-contain"
                                    />
                                    {boxToolActive && boxDrag && imgRef.current ? (
                                        <div
                                            className="pointer-events-none absolute border-2 border-sky-400/90 bg-sky-400/15"
                                            style={normRectToOverlayStyle(imgRef.current, {
                                                x: Math.min(boxDrag.ax, boxDrag.bx),
                                                y: Math.min(boxDrag.ay, boxDrag.by),
                                                width: Math.abs(boxDrag.bx - boxDrag.ax),
                                                height: Math.abs(boxDrag.by - boxDrag.ay),
                                            })}
                                        />
                                    ) : null}
                                    {pickMode && !refiningCandidateId && lastPickPos && (
                                        <span
                                            className="pointer-events-none absolute h-3 w-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-amber-400 bg-amber-500/80 shadow"
                                            style={{
                                                left: `${lastPickPos.leftPct}%`,
                                                top: `${lastPickPos.topPct}%`,
                                            }}
                                            title="Last pick"
                                        />
                                    )}
                                    {refiningCandidateId &&
                                        positiveMarkers.map((pt, i) => {
                                            const el = imgRef.current
                                            if (!el) {
                                                return null
                                            }
                                            const p = imageNormToContainerPercent(el, pt)
                                            return (
                                                <span
                                                    key={`pos-${i}-${imgLayoutVersion}`}
                                                    className="pointer-events-none absolute h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-emerald-300 bg-emerald-500/90 shadow"
                                                    style={{ left: `${p.leftPct}%`, top: `${p.topPct}%` }}
                                                    title="Include"
                                                />
                                            )
                                        })}
                                    {refiningCandidateId &&
                                        negativeMarkers.map((pt, i) => {
                                            const el = imgRef.current
                                            if (!el) {
                                                return null
                                            }
                                            const p = imageNormToContainerPercent(el, pt)
                                            return (
                                                <span
                                                    key={`neg-${i}-${imgLayoutVersion}`}
                                                    className="pointer-events-none absolute h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-red-500 bg-red-600/90 shadow"
                                                    style={{ left: `${p.leftPct}%`, top: `${p.topPct}%` }}
                                                    title="Exclude"
                                                />
                                            )
                                        })}
                                    {(pickBusy || refineLoading) && (
                                        <div className="absolute inset-0 flex items-center justify-center bg-black/40">
                                            <span className="h-5 w-5 animate-spin rounded-full border-2 border-amber-200 border-t-transparent" />
                                        </div>
                                    )}
                                </div>
                                {pickWarning && <p className="text-[11px] text-amber-200/90">{pickWarning}</p>}
                                {boxWarning && <p className="text-[11px] text-sky-200/90">{boxWarning}</p>}
                                {refineWarning && <p className="text-[11px] text-rose-200/90">{refineWarning}</p>}
                            </div>
                        )}
                    {candidates.map((c) => {
                        const nNeg =
                            c.metadata && typeof c.metadata === 'object' && 'negative_points' in c.metadata
                                ? readNormPointList(
                                      c.metadata as Record<string, unknown>,
                                      'negative_points'
                                  ).length
                                : 0
                        const nPosExtra =
                            c.metadata && typeof c.metadata === 'object'
                                ? Math.max(
                                      0,
                                      readNormPointList(c.metadata as Record<string, unknown>, 'positive_points')
                                          .length - 1
                                  )
                                : 0
                        const meta0 =
                            c.metadata && typeof c.metadata === 'object'
                                ? (c.metadata as Record<string, unknown>)
                                : null
                        const methodStr = typeof meta0?.method === 'string' ? meta0.method : ''
                        const cardIsAi =
                            c.notes === 'AI segmentation' ||
                            meta0?.segmentation_engine === 'fal_sam2' ||
                            methodStr.startsWith('fal_sam2')
                        const maskSourceLabel = cardIsAi ? 'AI segmentation' : 'Local mask detection'
                        return (
                        <div
                            key={c.id}
                            role="button"
                            tabIndex={0}
                            onClick={() => setEditingTargetId(c.id)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' || e.key === ' ') {
                                    e.preventDefault()
                                    setEditingTargetId(c.id)
                                }
                            }}
                            className={`flex gap-3 rounded-lg border p-2.5 transition ${
                                editingTargetId === c.id
                                    ? 'border-violet-500/90 bg-violet-950/15 ring-1 ring-violet-500/50'
                                    : 'border-gray-700 bg-gray-800/50 hover:border-gray-600'
                            }`}
                        >
                            <div
                                className="shrink-0 pt-0.5"
                                onClick={(e) => e.stopPropagation()}
                                onKeyDown={(e) => e.stopPropagation()}
                            >
                                <input
                                    type="checkbox"
                                    title="Create this region as a new layer"
                                    className="mt-0.5 h-4 w-4 rounded border-gray-600"
                                    checked={selected[c.id] !== false}
                                    onChange={(e) => setSelected((s) => ({ ...s, [c.id]: e.target.checked }))}
                                    disabled={busy}
                                />
                            </div>
                                <div className="min-w-0 flex-1 text-left">
                                <p className="text-[9px] font-medium uppercase tracking-wide text-violet-300/90">
                                    {maskSourceLabel}
                                </p>
                                <div className="mt-0.5 flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-medium text-gray-100">{c.label ?? c.id}</span>
                                    {typeof c.confidence === 'number' && (
                                        <span className="text-[10px] text-gray-500">
                                            {Math.round(c.confidence * 100)}% confidence
                                        </span>
                                    )}
                                </div>
                                {c.preview_url && (
                                    <img
                                        src={c.preview_url}
                                        alt=""
                                        className="mt-2 h-20 w-auto max-w-full rounded border border-gray-700 bg-black/30 object-contain"
                                    />
                                )}
                                <p className="mt-1 text-[10px] text-gray-500">
                                    Box {Math.round(c.bbox.x)},{Math.round(c.bbox.y)} — {Math.round(c.bbox.width)}×
                                    {Math.round(c.bbox.height)} px
                                </p>
                                {c.notes && <p className="mt-0.5 text-[10px] text-gray-500">{c.notes}</p>}
                                {c.id.startsWith('box_') &&
                                    c.metadata &&
                                    typeof c.metadata === 'object' &&
                                    typeof (c.metadata as { note?: unknown }).note === 'string' && (
                                        <p className="mt-1 text-[10px] text-gray-400">
                                            {(c.metadata as { note: string }).note}
                                        </p>
                                    )}
                                {(c.id.startsWith('pick_') || c.id.startsWith('box_')) &&
                                    (nNeg > 0 || nPosExtra > 0) && (
                                    <p className="mt-1 text-[10px] text-amber-100/90">
                                        {c.id.startsWith('box_') ? 'Box cutout' : 'Picked element'}
                                        {nPosExtra > 0
                                            ? ` — ${nPosExtra} include tap${nPosExtra === 1 ? '' : 's'}`
                                            : ''}
                                        {nNeg > 0 && nPosExtra > 0 ? ' · ' : nNeg > 0 ? ' — ' : ''}
                                        {nNeg > 0
                                            ? `${nNeg} exclude point${nNeg === 1 ? '' : 's'}`
                                            : ''}
                                    </p>
                                )}
                            </div>
                            {(c.id.startsWith('pick_') || c.id.startsWith('box_')) && (
                                <div
                                    className="flex max-w-[8.5rem] shrink-0 flex-col items-end gap-1.5 self-start text-right"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    <button
                                        type="button"
                                        onClick={() => void removeManualCandidate(c.id)}
                                        disabled={busy || pickBusy || refineLoading}
                                        className="shrink-0 self-end text-[10px] text-gray-400 underline hover:text-gray-200"
                                    >
                                        Remove
                                    </button>
                                </div>
                            )}
                        </div>
                    )
                    })}

                    {providerCapabilities?.supports_background_fill && (
                        <>
                            <label className="flex cursor-pointer items-start gap-2 text-xs text-gray-300">
                                <input
                                    type="checkbox"
                                    className="mt-0.5 h-4 w-4 rounded border-gray-600"
                                    checked={createFilledBackground}
                                    onChange={(e) => {
                                        setCreateFilledBackground(e.target.checked)
                                        if (!e.target.checked) {
                                            setHideOriginalAfterExtraction(false)
                                        }
                                    }}
                                    disabled={busy || selectedIds.length === 0}
                                />
                                <span>
                                    Create filled background layer — uses background fill credits.
                                </span>
                            </label>
                            {createFilledBackground && (
                                <label className="ml-6 flex cursor-pointer items-center gap-2 text-xs text-gray-300">
                                    <input
                                        type="checkbox"
                                        className="h-4 w-4 rounded border-gray-600"
                                        checked={hideOriginalAfterExtraction}
                                        onChange={(e) => setHideOriginalAfterExtraction(e.target.checked)}
                                        disabled={busy}
                                    />
                                    Hide original layer after extraction
                                </label>
                            )}
                        </>
                    )}
                    <label className="flex cursor-pointer items-center gap-2 text-xs text-gray-300">
                        <input
                            type="checkbox"
                            className="h-4 w-4 rounded border-gray-600"
                            checked={keepOriginalVisible}
                            onChange={(e) => setKeepOriginalVisible(e.target.checked)}
                            disabled={busy}
                        />
                        Keep original layer visible
                    </label>
                </div>

                <div className="mt-5 flex flex-wrap items-center justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md border border-gray-600 px-3 py-1.5 text-xs font-medium text-gray-200 hover:bg-gray-800"
                        disabled={busy}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={() => void confirm()}
                        disabled={busy || candidates.length === 0 || selectedIds.length === 0}
                        className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        Create layers
                    </button>
                </div>
            </div>
        </div>
    )
}
