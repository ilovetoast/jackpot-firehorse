import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { formatSectionsForGenerateModal } from '../../../../utils/studioVersionFormatPresetGroups.mjs'
import { combinationKeys, labelForCombinationKey } from '../../../../utils/studioVersionsGenerationCartesian.mjs'
import { fetchGenerationPresets, postCreativeSetGenerate } from '../../studioCreativeSetBridge'
import type {
    StudioGenerationPresetColor,
    StudioGenerationPresetFormat,
    StudioGenerationPresetScene,
    StudioGenerationPresetsDto,
} from '../../studioCreativeSetTypes'

/** When opening from quick packs, parent supplies preset axis ids (empty arrays = axis omitted). */
export type GenerateStudioVersionsInitialAxes = {
    colorIds: string[]
    sceneIds: string[]
    formatIds: string[]
}

type Props = {
    open: boolean
    creativeSetId: string
    sourceCompositionId: string
    onClose: () => void
    /** Called after POST /generate returns (202). Parent starts polling `jobId`. */
    onGenerationQueued: (jobId: string) => void
    /** Applied whenever the modal opens (e.g. quick pack from Version Builder). */
    initialAxes?: GenerateStudioVersionsInitialAxes | null
}

function formatChipSubtitle(f: StudioGenerationPresetFormat): string {
    return `${f.width}×${f.height}`
}

export function GenerateStudioVersionsModal(props: Props) {
    const { open, creativeSetId, sourceCompositionId, onClose, onGenerationQueued, initialAxes = null } = props
    const [presets, setPresets] = useState<StudioGenerationPresetsDto | null>(null)
    const [loadError, setLoadError] = useState<string | null>(null)
    const [submitting, setSubmitting] = useState(false)
    const [submitError, setSubmitError] = useState<string | null>(null)
    const submitLockRef = useRef(false)

    const [colorIds, setColorIds] = useState<string[]>([])
    const [sceneIds, setSceneIds] = useState<string[]>([])
    const [formatIds, setFormatIds] = useState<string[]>([])
    /** Keys the user wants to generate (subset of allKeys). */
    const [enabledCombinationKeys, setEnabledCombinationKeys] = useState<Set<string>>(new Set())

    useEffect(() => {
        if (!open) {
            return
        }
        let cancelled = false
        setLoadError(null)
        void fetchGenerationPresets()
            .then((p) => {
                if (!cancelled) {
                    setPresets({
                        ...p,
                        preset_formats: p.preset_formats ?? [],
                        limits: {
                            ...p.limits,
                            max_formats: p.limits.max_formats ?? 3,
                        },
                    })
                }
            })
            .catch((e: unknown) => {
                if (!cancelled) {
                    setLoadError(e instanceof Error ? e.message : 'Could not load presets')
                }
            })
        return () => {
            cancelled = true
        }
    }, [open])

    const initialAxesKey = useMemo(() => JSON.stringify(initialAxes ?? null), [initialAxes])

    useEffect(() => {
        if (!open) {
            return
        }
        if (initialAxes) {
            setColorIds([...initialAxes.colorIds])
            setSceneIds([...initialAxes.sceneIds])
            setFormatIds([...initialAxes.formatIds])
        } else {
            setColorIds([])
            setSceneIds([])
            setFormatIds([])
        }
    }, [open, initialAxesKey])

    const allKeys = useMemo(() => combinationKeys(colorIds, sceneIds, formatIds), [colorIds, sceneIds, formatIds])

    useEffect(() => {
        setEnabledCombinationKeys(new Set(allKeys))
    }, [allKeys])

    const maxOutputs = presets?.limits.max_outputs_per_request ?? 24
    const maxFormats = presets?.limits.max_formats ?? 3
    const presetFormats = presets?.preset_formats ?? []

    const { recommendedFormatPresets, formatPresetGroups } = useMemo(() => {
        if (!presets) {
            return { recommendedFormatPresets: [], formatPresetGroups: [] }
        }
        const { recommended, groups } = formatSectionsForGenerateModal(presets)
        return { recommendedFormatPresets: recommended, formatPresetGroups: groups }
    }, [presets])

    const selectedCount = useMemo(() => {
        let n = 0
        for (const k of allKeys) {
            if (enabledCombinationKeys.has(k)) {
                n += 1
            }
        }
        return n
    }, [allKeys, enabledCombinationKeys])

    const toggle = useCallback((id: string, list: string[], setList: (v: string[]) => void) => {
        setList(list.includes(id) ? list.filter((x) => x !== id) : [...list, id])
    }, [])

    const toggleCombinationKey = useCallback((key: string) => {
        setEnabledCombinationKeys((prev) => {
            const next = new Set(prev)
            if (next.has(key)) {
                next.delete(key)
            } else {
                next.add(key)
            }
            return next
        })
    }, [])

    const onSubmit = useCallback(async () => {
        if (submitLockRef.current) {
            return
        }
        if (colorIds.length === 0 && sceneIds.length === 0 && formatIds.length === 0) {
            setSubmitError('Select at least one color, scene, and/or format.')
            return
        }
        if (selectedCount < 1) {
            setSubmitError('Select at least one output.')
            return
        }
        if (selectedCount > maxOutputs) {
            setSubmitError(`At most ${maxOutputs} selected outputs per request.`)
            return
        }
        submitLockRef.current = true
        setSubmitting(true)
        setSubmitError(null)
        try {
            const selectedKeys =
                selectedCount === allKeys.length ? undefined : allKeys.filter((k) => enabledCombinationKeys.has(k))
            const { generation_job } = await postCreativeSetGenerate(creativeSetId, {
                source_composition_id: sourceCompositionId,
                color_ids: colorIds,
                scene_ids: sceneIds,
                format_ids: formatIds,
                selected_combination_keys: selectedKeys,
            })
            onGenerationQueued(generation_job.id)
            onClose()
        } catch (e: unknown) {
            setSubmitError(
                e instanceof Error && e.message
                    ? e.message
                    : "Couldn't start version generation. Check your connection and try again."
            )
        } finally {
            setSubmitting(false)
            submitLockRef.current = false
        }
    }, [
        allKeys,
        colorIds,
        creativeSetId,
        enabledCombinationKeys,
        formatIds,
        maxOutputs,
        onClose,
        onGenerationQueued,
        sceneIds,
        selectedCount,
        sourceCompositionId,
    ])

    if (!open) {
        return null
    }

    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/60 p-4">
            <div
                role="dialog"
                aria-labelledby="gen-versions-title"
                data-testid="generate-versions-dialog"
                className="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-xl border border-gray-700 bg-gray-900 p-5 shadow-2xl"
            >
                <h2 id="gen-versions-title" className="text-lg font-semibold text-white">
                    Generate versions
                </h2>
                <p className="mt-1 text-sm text-gray-400">
                    Choose colorways, scenes, and/or output formats. We duplicate your layout, reflow roles into the
                    target canvas when the size changes, then run a controlled image edit on the main product photo for
                    each combination.
                </p>

                {loadError && <p className="mt-3 text-sm text-red-400">{loadError}</p>}

                {!presets && !loadError && (
                    <div className="mt-6 flex flex-col items-center gap-2 rounded-lg border border-gray-800 bg-gray-950/60 px-4 py-6 text-center">
                        <span className="h-6 w-6 animate-spin rounded-full border-2 border-indigo-400 border-t-transparent" aria-hidden />
                        <p className="text-sm text-gray-300">Loading generation presets…</p>
                        <p className="text-[11px] text-gray-500">This usually takes a moment.</p>
                    </div>
                )}

                {presets && !loadError && (
                    <>
                        <div className="mt-4">
                            <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Colors</p>
                            <div className="mt-2 flex flex-wrap gap-2">
                                {presets.preset_colors.map((c: StudioGenerationPresetColor) => (
                                    <button
                                        key={c.id}
                                        type="button"
                                        onClick={() => toggle(c.id, colorIds, setColorIds)}
                                        className={`flex items-center gap-2 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors ${
                                            colorIds.includes(c.id)
                                                ? 'border-indigo-500 bg-indigo-950/50 text-indigo-100'
                                                : 'border-gray-700 bg-gray-800 text-gray-300 hover:border-gray-500'
                                        }`}
                                    >
                                        <span
                                            className="h-4 w-4 rounded-full border border-white/20"
                                            style={{ backgroundColor: c.hex ?? '#666' }}
                                            aria-hidden
                                        />
                                        {c.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <div className="mt-4">
                            <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Scenes</p>
                            <div className="mt-2 flex flex-wrap gap-2">
                                {presets.preset_scenes.map((s: StudioGenerationPresetScene) => (
                                    <button
                                        key={s.id}
                                        type="button"
                                        onClick={() => toggle(s.id, sceneIds, setSceneIds)}
                                        className={`rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors ${
                                            sceneIds.includes(s.id)
                                                ? 'border-indigo-500 bg-indigo-950/50 text-indigo-100'
                                                : 'border-gray-700 bg-gray-800 text-gray-300 hover:border-gray-500'
                                        }`}
                                    >
                                        {s.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                        {presetFormats.length > 0 && (
                            <div className="mt-4" data-testid="generate-versions-formats-section">
                                <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Formats</p>
                                <p className="mt-1 text-[11px] text-gray-500">
                                    Optional — omit formats to keep the current canvas size. Select up to {maxFormats}{' '}
                                    curated presets (role-aware reflow, not simple scaling). Grouped by use case.
                                </p>
                                {recommendedFormatPresets.length > 0 && (
                                    <div className="mt-3" data-testid="generate-versions-formats-recommended">
                                        <p className="text-[10px] font-semibold uppercase tracking-wide text-gray-500">
                                            Recommended
                                        </p>
                                        <div className="mt-1.5 flex flex-wrap gap-2">
                                            {recommendedFormatPresets.map((f: StudioGenerationPresetFormat) => {
                                                const on = formatIds.includes(f.id)
                                                const atCap = !on && formatIds.length >= maxFormats
                                                const tip =
                                                    f.description != null && f.description !== ''
                                                        ? `${f.description} ${formatChipSubtitle(f)}`
                                                        : `${f.label} (${formatChipSubtitle(f)})`
                                                return (
                                                    <button
                                                        key={f.id}
                                                        type="button"
                                                        data-testid={`generate-versions-format-chip-${f.id}`}
                                                        disabled={atCap}
                                                        title={
                                                            atCap
                                                                ? `At most ${maxFormats} formats per generation`
                                                                : tip
                                                        }
                                                        onClick={() => toggle(f.id, formatIds, setFormatIds)}
                                                        className={`rounded-lg border px-2.5 py-1.5 text-left text-xs font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40 ${
                                                            on
                                                                ? 'border-indigo-500 bg-indigo-950/50 text-indigo-100'
                                                                : 'border-gray-700 bg-gray-800 text-gray-300 hover:border-gray-500'
                                                        }`}
                                                    >
                                                        <span className="block font-semibold">{f.label}</span>
                                                        <span className="block text-[10px] font-normal text-gray-500">
                                                            {formatChipSubtitle(f)}
                                                        </span>
                                                    </button>
                                                )
                                            })}
                                        </div>
                                    </div>
                                )}
                                <div className="mt-3 space-y-4">
                                    {formatPresetGroups.map((section) => (
                                        <div
                                            key={section.group}
                                            data-testid={`generate-versions-format-group-${section.group}`}
                                        >
                                            <p className="text-[10px] font-semibold uppercase tracking-wide text-gray-500">
                                                {section.heading}
                                            </p>
                                            <div className="mt-1.5 flex flex-wrap gap-2">
                                                {section.formats.map((f: StudioGenerationPresetFormat) => {
                                                    const on = formatIds.includes(f.id)
                                                    const atCap = !on && formatIds.length >= maxFormats
                                                    const tip =
                                                        f.description != null && f.description !== ''
                                                            ? `${f.description} ${formatChipSubtitle(f)}`
                                                            : `${f.label} (${formatChipSubtitle(f)})`
                                                    return (
                                                        <button
                                                            key={f.id}
                                                            type="button"
                                                            data-testid={`generate-versions-format-chip-${f.id}`}
                                                            disabled={atCap}
                                                            title={
                                                                atCap
                                                                    ? `At most ${maxFormats} formats per generation`
                                                                    : tip
                                                            }
                                                            onClick={() => toggle(f.id, formatIds, setFormatIds)}
                                                            className={`rounded-lg border px-2.5 py-1.5 text-left text-xs font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40 ${
                                                                on
                                                                    ? 'border-indigo-500 bg-indigo-950/50 text-indigo-100'
                                                                    : 'border-gray-700 bg-gray-800 text-gray-300 hover:border-gray-500'
                                                            }`}
                                                        >
                                                            <span className="block font-semibold">{f.label}</span>
                                                            <span className="block text-[10px] font-normal text-gray-500">
                                                                {formatChipSubtitle(f)}
                                                            </span>
                                                        </button>
                                                    )
                                                })}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {allKeys.length > 0 && (
                            <div className="mt-4">
                                <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    Selected outputs
                                </p>
                                <p className="mt-1 text-[11px] text-gray-500">
                                    Tap to include or exclude each combination (max {maxOutputs} per request).
                                </p>
                                <div className="mt-2 max-h-40 space-y-1.5 overflow-y-auto rounded-lg border border-gray-800 bg-gray-950/80 p-2">
                                    {allKeys.map((key) => {
                                        const on = enabledCombinationKeys.has(key)
                                        return (
                                            <button
                                                key={key}
                                                type="button"
                                                onClick={() => toggleCombinationKey(key)}
                                                className={`flex w-full items-center justify-between rounded-md border px-2 py-1.5 text-left text-[11px] font-medium transition-colors ${
                                                    on
                                                        ? 'border-indigo-500/60 bg-indigo-950/30 text-indigo-100'
                                                        : 'border-transparent bg-gray-900/60 text-gray-500 line-through'
                                                }`}
                                            >
                                                <span className="min-w-0 flex-1 truncate">
                                                    {labelForCombinationKey(key, presets)}
                                                </span>
                                                <span className="ml-2 shrink-0 text-[10px] text-gray-500">
                                                    {on ? 'On' : 'Off'}
                                                </span>
                                            </button>
                                        )
                                    })}
                                </div>
                            </div>
                        )}

                        <div
                            className="mt-4 rounded-lg border border-gray-800 bg-gray-950/80 px-3 py-2 text-sm text-gray-300"
                            data-testid="generate-versions-selected-summary"
                        >
                            <span className="font-semibold text-white">{selectedCount}</span> selected outputs
                            {allKeys.length > 0 && (
                                <span className="text-gray-500">
                                    {' '}
                                    of {allKeys.length} possible for this selection
                                </span>
                            )}
                            {presets.limits.max_versions_per_set != null && (
                                <span className="block pt-1 text-[11px] text-gray-500">
                                    Max {presets.limits.max_versions_per_set} versions per set including existing.
                                </span>
                            )}
                        </div>
                    </>
                )}

                {submitError && <p className="mt-3 text-sm text-red-400">{submitError}</p>}

                <div className="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md border border-gray-600 px-3 py-1.5 text-sm font-medium text-gray-300 hover:bg-gray-800"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        data-testid="generate-versions-submit"
                        disabled={submitting || !!loadError || !presets || selectedCount < 1}
                        onClick={() => void onSubmit()}
                        className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        {submitting ? 'Starting generation…' : `Generate ${selectedCount} version${selectedCount === 1 ? '' : 's'}`}
                    </button>
                </div>
            </div>
        </div>
    )
}
