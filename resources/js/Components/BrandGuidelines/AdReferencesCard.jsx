/**
 * AdReferencesCard
 *
 * Brand-owner-curated gallery of "ads we want our own output to feel like".
 * Users pick (or upload) DAM assets and attach optional notes; the list is
 * orderable via up/down buttons and persists to `brand_ad_references` via
 * {@link \App\Http\Controllers\BrandAdReferenceController}.
 *
 * Each reference row also carries server-extracted visual **signals**
 * (palette, avg brightness/saturation, palette kind, dominant hue bucket).
 * We surface those inline as small chips so the user can see *why* the
 * engine is about to nudge their ad style — no black-box inference.
 *
 * We also aggregate those signals into `hints` via a secondary endpoint
 * and bubble them up through `onHintsChange`, so the parent Builder can
 * refresh sibling previews (AdStyleCard, FormatPackPanel) the moment the
 * gallery mutates. No router.reload required.
 *
 * Reorder UX note:
 *   We deliberately use up/down chevrons instead of HTML5 drag-and-drop. DnD
 *   is surprisingly fiddly inside a modal-tabbed flow like the Builder, and
 *   chevrons are keyboard-accessible out of the box. A future polish pass can
 *   layer on drag-and-drop on top of the same `/reorder` endpoint.
 */

import { useCallback, useEffect, useMemo, useState } from 'react'
import BuilderAssetSelectorModal from './BuilderAssetSelectorModal'

const csrfToken = () =>
    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''

async function apiJson(url, { method = 'GET', body = null } = {}) {
    const init = {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
    }
    if (body !== null) {
        init.headers['Content-Type'] = 'application/json'
        init.body = JSON.stringify(body)
    }
    const res = await fetch(url, init)
    const text = await res.text()
    let data
    try {
        data = JSON.parse(text)
    } catch {
        throw new Error(text || `${method} ${url} failed`)
    }
    if (!res.ok) {
        throw new Error(data?.error || data?.message || `${method} ${url} failed`)
    }
    return data
}

export default function AdReferencesCard({
    brandId,
    initialHints = null,
    onHintsChange = null,
}) {
    const [references, setReferences] = useState([])
    const [loading, setLoading] = useState(true)
    const [error, setError] = useState(null)
    const [pickerOpen, setPickerOpen] = useState(false)
    const [saving, setSaving] = useState(false)
    const [editingNotesFor, setEditingNotesFor] = useState(null)
    const [notesDraft, setNotesDraft] = useState('')
    const [hints, setHints] = useState(initialHints)
    const [reextractingId, setReextractingId] = useState(null)

    const listUrl = useMemo(
        () => `/app/brands/${brandId}/ad-references`,
        [brandId],
    )
    const hintsUrl = useMemo(
        () => `/app/brands/${brandId}/ad-references/hints`,
        [brandId],
    )

    // Pull fresh aggregated hints from the server and bubble them up. We
    // keep the inner + outer state in sync via `onHintsChange` so the
    // parent Builder can re-render preview siblings without a full Inertia
    // navigation. Silent on failure — stale hints are strictly better than
    // an error state blocking the whole card.
    const refreshHints = useCallback(async () => {
        try {
            const data = await apiJson(hintsUrl)
            setHints(data.hints ?? null)
            if (typeof onHintsChange === 'function') {
                onHintsChange(data.hints ?? null)
            }
        } catch {
            // Swallow — the list still renders fine without the aggregate ribbon.
        }
    }, [hintsUrl, onHintsChange])

    const load = useCallback(async () => {
        setLoading(true)
        setError(null)
        try {
            const data = await apiJson(listUrl)
            setReferences(data.references ?? [])
        } catch (e) {
            setError(e.message)
        } finally {
            setLoading(false)
        }
    }, [listUrl])

    useEffect(() => {
        if (brandId) {
            load()
            // Only refetch hints on mount if we weren't given them via props.
            // Parent-provided `initialHints` already rode along with the
            // Inertia payload so there's no reason to double-fetch.
            if (initialHints === null) refreshHints()
        }
    }, [brandId, load, refreshHints, initialHints])

    const handleSelect = async (asset) => {
        if (!asset?.id) return
        setPickerOpen(false)
        setSaving(true)
        try {
            await apiJson(listUrl, {
                method: 'POST',
                body: { asset_id: asset.id },
            })
            await load()
            await refreshHints()
        } catch (e) {
            setError(e.message)
        } finally {
            setSaving(false)
        }
    }

    const removeReference = async (ref) => {
        if (!confirm('Remove this reference? (The underlying DAM asset is kept.)')) {
            return
        }
        setSaving(true)
        try {
            await apiJson(`${listUrl}/${ref.id}`, { method: 'DELETE' })
            await load()
            await refreshHints()
        } catch (e) {
            setError(e.message)
        } finally {
            setSaving(false)
        }
    }

    const reextractReference = async (ref) => {
        setReextractingId(ref.id)
        try {
            await apiJson(`${listUrl}/${ref.id}/reextract`, { method: 'POST' })
            await load()
            await refreshHints()
        } catch (e) {
            setError(e.message)
        } finally {
            setReextractingId(null)
        }
    }

    const moveReference = async (ref, direction) => {
        const idx = references.findIndex((r) => r.id === ref.id)
        const targetIdx = idx + direction
        if (idx < 0 || targetIdx < 0 || targetIdx >= references.length) return
        const next = references.slice()
        const [moved] = next.splice(idx, 1)
        next.splice(targetIdx, 0, moved)
        // Optimistic: client-side reorder first, then PATCH. If the PATCH
        // fails we reload to restore server truth.
        setReferences(next)
        try {
            await apiJson(`${listUrl}/reorder`, {
                method: 'POST',
                body: { order: next.map((r) => Number(r.id)) },
            })
        } catch (e) {
            setError(e.message)
            load()
        }
    }

    const saveNotes = async (ref) => {
        try {
            await apiJson(`${listUrl}/${ref.id}`, {
                method: 'PATCH',
                body: { notes: notesDraft },
            })
            setEditingNotesFor(null)
            setNotesDraft('')
            load()
            // Notes don't affect signals, so no hints refetch here.
        } catch (e) {
            setError(e.message)
        }
    }

    return (
        <div className="rounded-2xl border border-white/20 bg-white/5 p-6">
            <div className="flex items-start justify-between gap-4 mb-4">
                <div>
                    <h3 className="text-lg font-semibold text-white mb-1">Reference ads</h3>
                    <p className="text-sm text-white/60">
                        Curate ads you want your brand's output to feel like. Upload from your
                        library or pick existing DAM assets — these help teammates align on taste
                        and will feed the recipe engine in a future pass.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => setPickerOpen(true)}
                    disabled={saving}
                    className="whitespace-nowrap rounded-xl bg-indigo-500 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-400 disabled:opacity-60"
                >
                    + Add reference
                </button>
            </div>

            {error && (
                <div className="mb-3 rounded-lg border border-rose-400/40 bg-rose-900/20 px-3 py-2 text-sm text-rose-200">
                    {error}
                </div>
            )}

            <HintsSummary hints={hints} />


            {loading ? (
                <div className="py-8 text-center text-sm text-white/40">Loading references…</div>
            ) : references.length === 0 ? (
                <div className="rounded-xl border border-dashed border-white/20 bg-black/20 p-8 text-center">
                    <p className="text-sm text-white/50">
                        No references yet. Add a few example ads to set a visual north star.
                    </p>
                </div>
            ) : (
                <ul className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    {references.map((ref, idx) => (
                        <li
                            key={ref.id}
                            className="flex flex-col overflow-hidden rounded-xl border border-white/10 bg-black/30"
                        >
                            <div className="relative aspect-square w-full bg-black/40">
                                {ref.asset?.preview_url ? (
                                    // eslint-disable-next-line @next/next/no-img-element
                                    <img
                                        src={ref.asset.preview_url}
                                        alt={ref.asset?.name ?? 'Reference'}
                                        className="h-full w-full object-contain"
                                    />
                                ) : (
                                    <div className="flex h-full items-center justify-center text-xs text-white/30">
                                        No preview
                                    </div>
                                )}
                                <div className="absolute top-2 left-2 rounded-md bg-black/60 px-2 py-0.5 text-[10px] font-semibold text-white/80">
                                    #{idx + 1}
                                </div>
                            </div>

                            <div className="flex flex-1 flex-col gap-2 p-3">
                                <div className="truncate text-xs text-white/60">
                                    {ref.asset?.name ?? 'Untitled'}
                                </div>

                                <SignalChips
                                    signals={ref.signals}
                                    extractionError={ref.signals_extraction_error}
                                    isReextracting={reextractingId === ref.id}
                                    onReextract={() => reextractReference(ref)}
                                />

                                {editingNotesFor === ref.id ? (
                                    <div className="flex flex-col gap-2">
                                        <textarea
                                            value={notesDraft}
                                            onChange={(e) => setNotesDraft(e.target.value)}
                                            rows={3}
                                            placeholder="What makes this ad great?"
                                            className="w-full rounded-md border border-white/20 bg-white/5 px-2 py-1.5 text-xs text-white placeholder-white/30 focus:ring-2 focus:ring-white/30"
                                        />
                                        <div className="flex gap-2">
                                            <button
                                                type="button"
                                                onClick={() => saveNotes(ref)}
                                                className="flex-1 rounded-md bg-indigo-500 px-2 py-1 text-xs font-semibold text-white hover:bg-indigo-400"
                                            >
                                                Save
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setEditingNotesFor(null)
                                                    setNotesDraft('')
                                                }}
                                                className="rounded-md border border-white/20 px-2 py-1 text-xs text-white/70 hover:bg-white/5"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setEditingNotesFor(ref.id)
                                            setNotesDraft(ref.notes ?? '')
                                        }}
                                        className="text-left text-xs text-white/50 hover:text-white/70"
                                    >
                                        {ref.notes?.trim()
                                            ? ref.notes
                                            : <span className="italic text-white/30">+ Add notes</span>}
                                    </button>
                                )}

                                <div className="mt-auto flex items-center gap-1 pt-1">
                                    <button
                                        type="button"
                                        onClick={() => moveReference(ref, -1)}
                                        disabled={idx === 0 || saving}
                                        title="Move up"
                                        aria-label="Move up"
                                        className="rounded-md border border-white/10 px-2 py-1 text-xs text-white/70 hover:bg-white/5 disabled:opacity-30"
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => moveReference(ref, 1)}
                                        disabled={idx === references.length - 1 || saving}
                                        title="Move down"
                                        aria-label="Move down"
                                        className="rounded-md border border-white/10 px-2 py-1 text-xs text-white/70 hover:bg-white/5 disabled:opacity-30"
                                    >
                                        ↓
                                    </button>
                                    <div className="flex-1" />
                                    <button
                                        type="button"
                                        onClick={() => removeReference(ref)}
                                        disabled={saving}
                                        className="rounded-md border border-rose-400/30 px-2 py-1 text-xs text-rose-200 hover:bg-rose-500/10"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            <BuilderAssetSelectorModal
                open={pickerOpen}
                onClose={() => setPickerOpen(false)}
                brandId={brandId}
                builderContext="visual_reference"
                onSelect={handleSelect}
                title="Pick a reference ad"
                showUpload={true}
                multiSelect={false}
            />
        </div>
    )
}

/**
 * Aggregate-signals ribbon. Renders a one-liner summary of what the
 * gallery is "saying" to the recipe engine, or a coaching hint when the
 * sample count is below the aggregator's threshold (currently 2).
 *
 * The goal here is *transparency*, not analytics. Users should be able
 * to look at this ribbon and predict why their Format Pack preview just
 * shifted toward dark backgrounds after adding three dark references.
 */
function HintsSummary({ hints }) {
    if (!hints) return null
    const n = hints.sample_count ?? 0

    if (n === 0) {
        return (
            <div className="mb-4 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs text-white/50">
                Reference signals appear here once you add at least one analyzed reference.
            </div>
        )
    }

    // Below the aggregator threshold — show count but no suggestions.
    if (!hints.suggestions) {
        return (
            <div className="mb-4 rounded-lg border border-amber-400/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-200">
                {n} reference{n === 1 ? '' : 's'} analyzed. Add at least {Math.max(1, 2 - n)} more
                to start nudging the recipe engine — a single example isn't enough signal to
                bias inference.
            </div>
        )
    }

    const s = hints.suggestions
    const signals = []
    if (s.prefers_dark_backgrounds) signals.push('dark backgrounds')
    if (s.prefers_light_backgrounds) signals.push('light backgrounds')
    if (s.prefers_vibrant) signals.push('vibrant saturation')
    if (s.prefers_muted) signals.push('muted saturation')
    if (s.prefers_minimal_palette) signals.push('minimal palette')
    if (s.prefers_rich_palette) signals.push('rich palette')
    if (hints.dominant_hue_bucket && hints.dominant_hue_bucket !== 'neutral') {
        signals.push(`${hints.dominant_hue_bucket} tones`)
    }

    return (
        <div className="mb-4 rounded-lg border border-indigo-400/30 bg-indigo-500/10 px-3 py-2 text-xs text-indigo-100">
            <span className="font-semibold">
                Your references suggest {signals.length > 0 ? signals.join(', ') : 'balanced defaults'}
            </span>
            <span className="text-indigo-200/70">
                {' '}— applied as soft nudges to your Studio recipes. Your explicit ad-style
                overrides still take priority.
            </span>
        </div>
    )
}

/**
 * Per-row signal chips — palette swatches + a couple of terse labels.
 * Intentionally dense; the whole point is to let a user eyeball the
 * gallery at a glance and see which reference is driving which signal.
 */
function SignalChips({ signals, extractionError, isReextracting, onReextract }) {
    if (extractionError) {
        return (
            <div className="flex items-center justify-between rounded-md border border-rose-400/30 bg-rose-900/10 px-2 py-1 text-[10px] text-rose-200">
                <span className="truncate">Signals unavailable</span>
                <button
                    type="button"
                    onClick={onReextract}
                    disabled={isReextracting}
                    className="ml-2 shrink-0 rounded bg-rose-500/20 px-1.5 py-0.5 text-[10px] font-semibold text-rose-100 hover:bg-rose-500/30 disabled:opacity-50"
                >
                    {isReextracting ? '…' : 'Retry'}
                </button>
            </div>
        )
    }

    if (!signals) {
        // Either extraction hasn't run yet (brand-new row) or the feature
        // is disabled (missing Imagick). Show a subtle extracting state
        // with the manual trigger available.
        return (
            <div className="flex items-center justify-between rounded-md border border-white/10 bg-white/5 px-2 py-1 text-[10px] text-white/40">
                <span>Analyzing visuals…</span>
                <button
                    type="button"
                    onClick={onReextract}
                    disabled={isReextracting}
                    className="ml-2 shrink-0 rounded bg-white/10 px-1.5 py-0.5 text-[10px] font-semibold text-white/60 hover:bg-white/20 disabled:opacity-50"
                >
                    {isReextracting ? '…' : 'Extract'}
                </button>
            </div>
        )
    }

    const topColors = Array.isArray(signals.top_colors) ? signals.top_colors.slice(0, 5) : []
    const paletteKind = signals.palette_kind ?? 'polychrome'
    const hueBucket = signals.dominant_hue_bucket ?? 'neutral'
    const brightness = typeof signals.avg_brightness === 'number' ? signals.avg_brightness : null
    const saturation = typeof signals.avg_saturation === 'number' ? signals.avg_saturation : null

    const brightnessLabel =
        brightness === null ? null : brightness <= 0.35 ? 'dark' : brightness >= 0.65 ? 'light' : 'mid'
    const saturationLabel =
        saturation === null ? null : saturation >= 0.55 ? 'vibrant' : saturation <= 0.25 ? 'muted' : 'balanced'

    return (
        <div className="flex flex-col gap-1.5">
            {topColors.length > 0 && (
                <div className="flex items-center gap-1">
                    {topColors.map((c, i) => (
                        <span
                            key={`${c.hex}-${i}`}
                            title={`${c.hex} (${Math.round((c.weight ?? 0) * 100)}%)`}
                            className="inline-block h-4 w-4 rounded-sm border border-white/20"
                            style={{ backgroundColor: c.hex }}
                        />
                    ))}
                </div>
            )}
            <div className="flex flex-wrap items-center gap-1 text-[10px] text-white/50">
                {brightnessLabel && <SignalPill>{brightnessLabel}</SignalPill>}
                {saturationLabel && <SignalPill>{saturationLabel}</SignalPill>}
                <SignalPill>{paletteKind}</SignalPill>
                <SignalPill>{hueBucket}</SignalPill>
            </div>
        </div>
    )
}

function SignalPill({ children }) {
    return (
        <span className="rounded-full border border-white/10 bg-white/5 px-1.5 py-0.5 text-[9px] uppercase tracking-wide text-white/60">
            {children}
        </span>
    )
}
