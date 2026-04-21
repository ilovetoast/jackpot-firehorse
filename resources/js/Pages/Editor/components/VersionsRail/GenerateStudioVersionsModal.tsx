import { useCallback, useEffect, useMemo, useState } from 'react'
import { fetchGenerationPresets, postCreativeSetGenerate } from '../../studioCreativeSetBridge'
import type {
    StudioGenerationPresetColor,
    StudioGenerationPresetScene,
    StudioGenerationPresetsDto,
} from '../../studioCreativeSetTypes'

type Props = {
    open: boolean
    creativeSetId: string
    sourceCompositionId: string
    onClose: () => void
    /** Called after POST /generate returns (202). Parent starts polling `jobId`. */
    onGenerationQueued: (jobId: string) => void
}

function combinationKeys(colorIds: string[], sceneIds: string[]): string[] {
    if (colorIds.length > 0 && sceneIds.length > 0) {
        const keys: string[] = []
        for (const c of colorIds) {
            for (const s of sceneIds) {
                keys.push(`c:${c}|s:${s}`)
            }
        }
        return keys
    }
    if (colorIds.length > 0) {
        return colorIds.map((c) => `c:${c}`)
    }
    if (sceneIds.length > 0) {
        return sceneIds.map((s) => `s:${s}`)
    }
    return []
}

function labelForCombinationKey(key: string, presets: StudioGenerationPresetsDto): string {
    const colorById = Object.fromEntries(presets.preset_colors.map((c) => [c.id, c.label]))
    const sceneById = Object.fromEntries(presets.preset_scenes.map((s) => [s.id, s.label]))
    const parts: string[] = []
    for (const part of key.split('|')) {
        const p = part.trim()
        if (p.startsWith('c:')) {
            const id = p.slice(2)
            parts.push(colorById[id] ?? id)
        }
        if (p.startsWith('s:')) {
            const id = p.slice(2)
            parts.push(sceneById[id] ?? id)
        }
    }
    return parts.length ? parts.join(' · ') : key
}

export function GenerateStudioVersionsModal(props: Props) {
    const { open, creativeSetId, sourceCompositionId, onClose, onGenerationQueued } = props
    const [presets, setPresets] = useState<StudioGenerationPresetsDto | null>(null)
    const [loadError, setLoadError] = useState<string | null>(null)
    const [submitting, setSubmitting] = useState(false)
    const [submitError, setSubmitError] = useState<string | null>(null)

    const [colorIds, setColorIds] = useState<string[]>([])
    const [sceneIds, setSceneIds] = useState<string[]>([])
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
                    setPresets(p)
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

    const allKeys = useMemo(() => combinationKeys(colorIds, sceneIds), [colorIds, sceneIds])

    useEffect(() => {
        setEnabledCombinationKeys(new Set(allKeys))
    }, [allKeys])

    const maxOutputs = presets?.limits.max_outputs_per_request ?? 24

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
        if (colorIds.length === 0 && sceneIds.length === 0) {
            setSubmitError('Select at least one color and/or scene.')
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
        setSubmitting(true)
        setSubmitError(null)
        try {
            const selectedKeys =
                selectedCount === allKeys.length ? undefined : allKeys.filter((k) => enabledCombinationKeys.has(k))
            const { generation_job } = await postCreativeSetGenerate(creativeSetId, {
                source_composition_id: sourceCompositionId,
                color_ids: colorIds,
                scene_ids: sceneIds,
                selected_combination_keys: selectedKeys,
            })
            onGenerationQueued(generation_job.id)
            onClose()
        } catch (e: unknown) {
            setSubmitError(e instanceof Error ? e.message : 'Generation could not start')
        } finally {
            setSubmitting(false)
        }
    }, [
        allKeys,
        colorIds,
        creativeSetId,
        enabledCombinationKeys,
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
                className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-gray-700 bg-gray-900 p-5 shadow-2xl"
            >
                <h2 id="gen-versions-title" className="text-lg font-semibold text-white">
                    Generate versions
                </h2>
                <p className="mt-1 text-sm text-gray-400">
                    Choose colorways and/or scenes. We duplicate your layout and run a controlled image edit on the main
                    product photo for each combination.
                </p>

                {loadError && <p className="mt-3 text-sm text-red-400">{loadError}</p>}

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

                        <div className="mt-4 rounded-lg border border-gray-800 bg-gray-950/80 px-3 py-2 text-sm text-gray-300">
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
                        disabled={submitting || !!loadError || !presets || selectedCount < 1}
                        onClick={() => void onSubmit()}
                        className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        {submitting ? 'Starting…' : `Generate ${selectedCount} version${selectedCount === 1 ? '' : 's'}`}
                    </button>
                </div>
            </div>
        </div>
    )
}
