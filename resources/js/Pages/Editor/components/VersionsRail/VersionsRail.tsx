import type { RefObject } from 'react'
import { InformationCircleIcon, TrashIcon, XMarkIcon } from '@heroicons/react/24/outline'
import { CheckIcon, PlusIcon, StarIcon } from '@heroicons/react/24/solid'
import { StudioVersionsHandoffBar } from './StudioVersionsHandoffBar'
import { StudioAnimationRailChips } from './StudioAnimationRailChips'
import type { StudioAnimationJobDto } from '../../editorStudioAnimationBridge'
import type { StudioCreativeSetDto, StudioCreativeSetVariantDto } from '../../studioCreativeSetTypes'
import type { StudioApplyScope } from './ApplyScopeBar'
import {
    buildSameColorSelection,
    buildSameFormatSelection,
    buildSameSceneSelection,
} from '../../../../utils/studioCreativeSetAxisQuickTarget.mjs'
import {
    getBaseCompositionId,
    getVariantAxisChipTexts,
    shouldShowVersionHints,
    variantHasAxisMetadata,
} from '../../../../utils/studioVersionRailHelpers.mjs'

function variantGroupTypeLabel(type: string): { title: string; short: string } {
    if (type === 'color') {
        return { title: 'Color family', short: 'Color' }
    }
    if (type === 'layout_size') {
        return { title: 'Size & layout family', short: 'Size' }
    }
    if (type === 'generic') {
        return { title: 'Variant set', short: 'Set' }
    }
    if (type === 'motion') {
        return { title: 'Motion family', short: 'Motion' }
    }
    return { title: type, short: type }
}

function statusRingClass(status: string, isActive: boolean): string {
    if (isActive) {
        return 'ring-2 ring-indigo-500 ring-offset-2 ring-offset-gray-950'
    }
    if (status === 'generating') {
        return 'ring-2 ring-amber-500/70 ring-offset-2 ring-offset-gray-950'
    }
    if (status === 'failed') {
        return 'ring-2 ring-red-500/70 ring-offset-2 ring-offset-gray-950'
    }
    if (status === 'draft') {
        return 'ring-1 ring-gray-600 ring-offset-2 ring-offset-gray-950'
    }
    return 'ring-1 ring-gray-700 ring-offset-2 ring-offset-gray-950'
}

export type StudioVersionsHandoffChrome = {
    selectionMode: boolean
    selectedCompositionIds: string[]
    exportBusy: boolean
    exportPhase: 'idle' | 'capturing' | 'zipping' | 'downloading'
    exportDetail: string
    onModeChange: (next: boolean) => void
    onToggleComposition: (compositionId: string) => void
    onClearSelection: () => void
    onSelectAll: () => void
    onSelectHero: () => void
    onSelectNew: () => void
    onSetSelectedCompositionIds: (compositionIds: string[]) => void
    onExportSelectedPng: () => void
    onExportSelectedJpeg: () => void
    onExportHeroPng: () => void
    onExportHeroJpeg: () => void
    onExportHeroAndAlternatesPng: () => void
    onExportHeroAndAlternatesJpeg: () => void
}

export function VersionsRail(props: {
    creativeSet: StudioCreativeSetDto
    activeCompositionId: string | null
    onSelectComposition: (compositionId: string) => void
    /** Primary path: outcome-first version creation. */
    onCreateVersions: () => void
    onDuplicateFromCurrent: () => void
    duplicateBusy: boolean
    onRetryVersion?: (generationJobItemId: string) => void
    retryBusyItemId?: string | null
    /** When “Selected versions” is active, show target-picking affordances. */
    applyScope?: StudioApplyScope
    pickMode?: boolean
    selectedCompositionIds?: string[]
    onTogglePickComposition?: (compositionId: string) => void
    onSelectAllSiblingCompositions?: () => void
    onClearPickedCompositions?: () => void
    /** Replaces the explicit picked list (used by axis quick-target presets). */
    onReplacePickedCompositions?: (compositionIds: string[]) => void
    onPickModeChange?: (next: boolean) => void
    /** Composition ids from the latest pack / duplicate batch (for “New” / review). */
    packNewcomerCompositionIds?: string[]
    /** Subset still unopened (shows “New” + emerald ring). */
    unviewedNewcomerCompositionIds?: string[]
    heroCompositionId?: string | null
    onReviewNextNew?: () => void
    onToggleHero?: (compositionId: string) => void
    heroBusyCompositionId?: string | null
    postCreateBanner?: {
        newCount: number
        unviewedCount: number
        onDismiss: () => void
        onReviewNext: () => void
        onCreateAnotherPack: () => void
    } | null
    railScrollRef?: RefObject<HTMLDivElement | null>
    /** Multi-select for export / handoff (orthogonal to semantic-apply pick list). */
    studioHandoff?: StudioVersionsHandoffChrome | null
    /** When set, shows a control to hide the whole versions strip (parent may persist + show a reopen bar). */
    onCollapsePanel?: () => void
    /** AI video jobs for the active composition — shown as tiles before static version tiles. */
    compositionAnimations?: StudioAnimationJobDto[]
    compositionAnimationsLoading?: boolean
    /** Editor composition title for animation tile labels. */
    compositionAnimationTitle?: string
    selectedStudioAnimationJobId?: string | null
    onSelectStudioAnimationJob?: (jobId: string) => void
    /** Remove a failed/canceled animation job from the rail (parent runs confirm + API). */
    onRequestDiscardStudioAnimationJob?: (jobId: string) => void | Promise<unknown>
    /** Remove a non-base variant from the set (parent runs confirm + API). */
    onRequestRemoveVariant?: (variant: StudioCreativeSetVariantDto) => void
    /** Delete the entire Versions set (compositions remain; parent runs confirm + API). */
    onDissolveSet?: () => void
    dissolveSetBusy?: boolean
    /** When false, hide AI image-to-video job chips in the rail (use layer Properties instead). */
    showCompositionAnimationChips?: boolean
}) {
    const {
        creativeSet,
        activeCompositionId,
        onSelectComposition,
        onCreateVersions,
        onDuplicateFromCurrent,
        duplicateBusy,
        onRetryVersion,
        retryBusyItemId,
        applyScope = 'this_version',
        pickMode = false,
        selectedCompositionIds = [],
        onTogglePickComposition,
        onSelectAllSiblingCompositions,
        onClearPickedCompositions,
        onReplacePickedCompositions,
        onPickModeChange,
        packNewcomerCompositionIds = [],
        unviewedNewcomerCompositionIds = [],
        heroCompositionId = null,
        onReviewNextNew,
        onToggleHero,
        heroBusyCompositionId = null,
        postCreateBanner = null,
        railScrollRef,
        studioHandoff = null,
        onCollapsePanel,
        compositionAnimations = [],
        compositionAnimationsLoading = false,
        compositionAnimationTitle = '',
        selectedStudioAnimationJobId = null,
        onSelectStudioAnimationJob,
        onRequestDiscardStudioAnimationJob,
        onRequestRemoveVariant,
        onDissolveSet,
        dissolveSetBusy = false,
        showCompositionAnimationChips = true,
    } = props

    const showPickChrome = applyScope === 'selected_versions'
    const selectedSet = new Set(selectedCompositionIds)
    const unviewedNewSet = new Set(unviewedNewcomerCompositionIds)
    const handoffSelectedSet = new Set(studioHandoff?.selectedCompositionIds ?? [])

    const variants = creativeSet.variants
    const activeId = activeCompositionId ?? ''
    const baseCompositionId = getBaseCompositionId(variants)
    const showHints = shouldShowVersionHints(variants.length)

    const sameScenePreset = buildSameSceneSelection(variants, activeId)
    const sameColorPreset = buildSameColorSelection(variants, activeId)
    const sameFormatPreset = buildSameFormatSelection(variants, activeId)
    const sceneChipDisabled = sameScenePreset.disabled !== 'none'
    const colorChipDisabled = sameColorPreset.disabled !== 'none'
    const formatChipDisabled = sameFormatPreset.disabled !== 'none'

    const sceneChipTitle =
        sameScenePreset.disabled === 'missing_axis'
            ? 'This version has no scene metadata. Generated versions include a scene you can match on.'
            : sameScenePreset.disabled === 'no_matches'
              ? `No other versions share this scene${
                    sameScenePreset.ref?.label ? ` (${sameScenePreset.ref.label})` : ''
                }.`
              : sameScenePreset.disabled === 'no_active_variant'
                ? 'Open a version in this set to use scene matching.'
                : `Same scene${sameScenePreset.ref?.label ? `: ${sameScenePreset.ref.label}` : sameScenePreset.ref?.id ? `: ${sameScenePreset.ref.id}` : ''}`

    const colorChipTitle =
        sameColorPreset.disabled === 'missing_axis'
            ? 'This version has no color metadata. Generated versions include a color you can match on.'
            : sameColorPreset.disabled === 'no_matches'
              ? `No other versions share this color${
                    sameColorPreset.ref?.label ? ` (${sameColorPreset.ref.label})` : ''
                }.`
              : sameColorPreset.disabled === 'no_active_variant'
                ? 'Open a version in this set to use color matching.'
                : `Same color${sameColorPreset.ref?.label ? `: ${sameColorPreset.ref.label}` : sameColorPreset.ref?.id ? `: ${sameColorPreset.ref.id}` : ''}`

    const formatChipTitle =
        sameFormatPreset.disabled === 'missing_axis'
            ? 'This version has no format metadata. Add formats when generating versions to match on output size.'
            : sameFormatPreset.disabled === 'no_matches'
              ? `No other versions share this format${
                    sameFormatPreset.ref?.label ? ` (${sameFormatPreset.ref.label})` : ''
                }.`
              : sameFormatPreset.disabled === 'no_active_variant'
                ? 'Open a version in this set to use format matching.'
                : `Same format${sameFormatPreset.ref?.label ? `: ${sameFormatPreset.ref.label}` : sameFormatPreset.ref?.id ? `: ${sameFormatPreset.ref.id}` : ''}`

    return (
        <div
            className="flex shrink-0 flex-col bg-gray-950 px-3 py-2"
            data-testid="studio-versions-rail-root"
        >
            {showHints && (
                <div className="mb-1.5 rounded-md border border-indigo-900/30 bg-indigo-950/15 px-2 py-1.5 text-[10px] leading-snug text-gray-400">
                    <span className="font-semibold text-indigo-100/90">Grow this set.</span> Try{' '}
                    <button
                        type="button"
                        onClick={() => onCreateVersions()}
                        className="font-semibold text-indigo-300 underline decoration-indigo-500/50 underline-offset-2 hover:text-indigo-200"
                    >
                        Create versions
                    </button>{' '}
                    for social sizes, colorways, or scenes — one short step, then you’re done.
                </div>
            )}
            {postCreateBanner && postCreateBanner.newCount > 0 && (
                <div className="mb-1.5 flex flex-col gap-1 rounded-md border border-emerald-900/40 bg-emerald-950/20 px-2 py-1.5">
                    <div className="flex items-start justify-between gap-2">
                        <p className="min-w-0 text-[10px] leading-snug text-emerald-100/90">
                            <span className="font-semibold">Nice — {postCreateBanner.newCount} new version(s).</span>{' '}
                            {postCreateBanner.unviewedCount > 0
                                ? `${postCreateBanner.unviewedCount} still to peek at.`
                                : 'You’ve opened them all.'}
                        </p>
                        <button
                            type="button"
                            onClick={() => postCreateBanner.onDismiss()}
                            className="shrink-0 rounded px-1 text-[10px] font-medium text-emerald-300/80 hover:bg-emerald-900/40"
                            title="Dismiss"
                        >
                            ✕
                        </button>
                    </div>
                    <div className="flex flex-wrap gap-1">
                        <button
                            type="button"
                            onClick={() => postCreateBanner.onReviewNext()}
                            className="rounded border border-emerald-700/60 bg-emerald-900/30 px-1.5 py-0.5 text-[9px] font-semibold text-emerald-50 hover:bg-emerald-900/50"
                        >
                            Review next
                        </button>
                        <button
                            type="button"
                            onClick={() => postCreateBanner.onCreateAnotherPack()}
                            className="rounded border border-gray-600 bg-gray-900/60 px-1.5 py-0.5 text-[9px] font-semibold text-gray-200 hover:bg-gray-800"
                        >
                            Another pack
                        </button>
                    </div>
                </div>
            )}
            <div className="mb-1.5 flex flex-col gap-1.5">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-[10px] font-semibold uppercase tracking-wider text-gray-500">Versions</p>
                        <p className="truncate text-[11px] font-medium text-gray-300" title={creativeSet.name}>
                            {creativeSet.name}
                        </p>
                    </div>
                    <div className="flex shrink-0 items-start gap-1.5">
                        {onCollapsePanel && (
                            <button
                                type="button"
                                data-testid="studio-versions-panel-collapse"
                                onClick={() => onCollapsePanel()}
                                className="inline-flex items-center gap-1 rounded-md border border-gray-700/80 px-1.5 py-1 text-[10px] font-medium text-gray-400 hover:border-gray-600 hover:bg-gray-800 hover:text-gray-200"
                                title="Hide the Versions bar — you can show it again from the strip at the bottom"
                            >
                                <XMarkIcon className="h-3.5 w-3.5" aria-hidden />
                                <span>Close</span>
                            </button>
                        )}
                        {onDissolveSet && (
                            <button
                                type="button"
                                data-testid="studio-versions-exit"
                                onClick={() => onDissolveSet()}
                                disabled={dissolveSetBusy}
                                className="inline-flex items-center gap-1 rounded-md border border-red-500/45 bg-red-950/35 px-2 py-1 text-[10px] font-semibold text-red-100 hover:border-red-400/60 hover:bg-red-950/50 disabled:cursor-not-allowed disabled:opacity-50"
                                title="Leave the Versions workspace for this composition. Compositions stay in your library; use File → Create versions to attach again."
                            >
                                {dissolveSetBusy ? 'Exiting…' : 'Exit versions'}
                            </button>
                        )}
                    <div className="flex shrink-0 flex-col items-end gap-1">
                        <button
                            type="button"
                            onClick={() => onCreateVersions()}
                            disabled={!activeCompositionId}
                            className="rounded-md bg-indigo-600 px-2.5 py-1 text-[10px] font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-40"
                            title="Add color, scene, or format versions from this creative"
                        >
                            Create versions
                        </button>
                        <button
                            type="button"
                            onClick={() => onDuplicateFromCurrent()}
                            disabled={duplicateBusy || !activeCompositionId}
                            className="text-[9px] font-medium text-gray-500 underline decoration-gray-600 decoration-dotted underline-offset-2 hover:text-gray-300 disabled:cursor-not-allowed disabled:opacity-40"
                            title="Copy this composition into the set without running generation"
                        >
                            {duplicateBusy ? 'Duplicating…' : 'Duplicate current (manual)'}
                        </button>
                        {packNewcomerCompositionIds.length > 0 && onReviewNextNew && (
                            <button
                                type="button"
                                onClick={() => onReviewNextNew()}
                                className="rounded border border-emerald-700/50 bg-emerald-950/30 px-2 py-0.5 text-[9px] font-semibold text-emerald-100 hover:bg-emerald-900/40"
                                title="Jump to the next new version in this batch"
                            >
                                Next new
                            </button>
                        )}
                        {studioHandoff && (
                            <button
                                type="button"
                                onClick={() => studioHandoff.onModeChange(!studioHandoff.selectionMode)}
                                disabled={!activeCompositionId || showPickChrome || studioHandoff.exportBusy}
                                className={`rounded border px-2 py-0.5 text-[9px] font-semibold shadow-sm disabled:cursor-not-allowed disabled:opacity-40 ${
                                    studioHandoff.selectionMode
                                        ? 'border-sky-500/70 bg-sky-900/50 text-sky-50'
                                        : 'border-gray-600 bg-gray-900/80 text-gray-200 hover:border-sky-600/50 hover:text-sky-100'
                                }`}
                                title={
                                    showPickChrome
                                        ? 'Finish choosing semantic apply targets first'
                                        : 'Select design versions to export as PNG or JPG (not AI video).'
                                }
                            >
                                {studioHandoff.selectionMode ? 'Handoff on' : 'Handoff'}
                            </button>
                        )}
                    </div>
                    </div>
                </div>
                {onDissolveSet && (
                    <p className="border-t border-gray-800/50 pt-1.5 text-[9px] leading-snug text-gray-500">
                        Need this workspace again? Use <span className="font-semibold text-gray-400">File → Create versions</span>{' '}
                        to reattach a set.
                    </p>
                )}
            </div>
            {studioHandoff?.selectionMode && (
                <StudioVersionsHandoffBar
                    selectedCount={studioHandoff.selectedCompositionIds.length}
                    variantCount={variants.length}
                    heroCompositionId={heroCompositionId ?? null}
                    packNewcomerCompositionIds={packNewcomerCompositionIds}
                    exportBusy={studioHandoff.exportBusy}
                    exportPhase={studioHandoff.exportPhase}
                    exportDetail={studioHandoff.exportDetail}
                    sameFormatDisabled={formatChipDisabled}
                    sameFormatTitle={formatChipTitle}
                    onDone={() => studioHandoff.onModeChange(false)}
                    onClearSelection={() => studioHandoff.onClearSelection()}
                    onSelectAll={() => studioHandoff.onSelectAll()}
                    onSelectHero={() => studioHandoff.onSelectHero()}
                    onSelectNew={() => studioHandoff.onSelectNew()}
                    onSelectSameFormat={() => studioHandoff.onSetSelectedCompositionIds(sameFormatPreset.ids)}
                    onExportSelectedPng={() => studioHandoff.onExportSelectedPng()}
                    onExportSelectedJpeg={() => studioHandoff.onExportSelectedJpeg()}
                    onExportHeroPng={() => studioHandoff.onExportHeroPng()}
                    onExportHeroJpeg={() => studioHandoff.onExportHeroJpeg()}
                    onExportHeroAndAlternatesPng={() => studioHandoff.onExportHeroAndAlternatesPng()}
                    onExportHeroAndAlternatesJpeg={() => studioHandoff.onExportHeroAndAlternatesJpeg()}
                    canExportHeroPlus={Boolean(
                        heroCompositionId &&
                            studioHandoff.selectedCompositionIds.some((id) => id !== heroCompositionId),
                    )}
                />
            )}
            {showPickChrome && (
                <div className="mb-1.5 flex flex-wrap items-center gap-1.5 rounded-md border border-indigo-900/40 bg-indigo-950/20 px-2 py-1">
                    <span className="text-[10px] font-semibold text-indigo-200/90">
                        {pickMode ? 'Tap versions to select' : 'Pick targets'}
                    </span>
                    <button
                        type="button"
                        onClick={() => onSelectAllSiblingCompositions?.()}
                        className="rounded border border-gray-700 bg-gray-900/80 px-1.5 py-0.5 text-[9px] font-semibold text-gray-300 hover:bg-gray-800"
                    >
                        All
                    </button>
                    <button
                        type="button"
                        onClick={() => onClearPickedCompositions?.()}
                        className="rounded border border-gray-700 bg-gray-900/80 px-1.5 py-0.5 text-[9px] font-semibold text-gray-300 hover:bg-gray-800"
                    >
                        Clear
                    </button>
                    <button
                        type="button"
                        disabled={sceneChipDisabled}
                        title={sceneChipTitle}
                        onClick={() => onReplacePickedCompositions?.(sameScenePreset.ids)}
                        className="rounded border border-gray-700 bg-gray-900/80 px-1.5 py-0.5 text-[9px] font-semibold text-gray-300 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        Same scene
                    </button>
                    <button
                        type="button"
                        disabled={colorChipDisabled}
                        title={colorChipTitle}
                        onClick={() => onReplacePickedCompositions?.(sameColorPreset.ids)}
                        className="rounded border border-gray-700 bg-gray-900/80 px-1.5 py-0.5 text-[9px] font-semibold text-gray-300 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        Same color
                    </button>
                    <button
                        type="button"
                        disabled={formatChipDisabled}
                        title={formatChipTitle}
                        onClick={() => onReplacePickedCompositions?.(sameFormatPreset.ids)}
                        className="rounded border border-gray-700 bg-gray-900/80 px-1.5 py-0.5 text-[9px] font-semibold text-gray-300 hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        Same format
                    </button>
                    {pickMode ? (
                        <button
                            type="button"
                            onClick={() => onPickModeChange?.(false)}
                            className="ml-auto rounded border border-indigo-600/60 bg-indigo-900/40 px-1.5 py-0.5 text-[9px] font-semibold text-indigo-100 hover:bg-indigo-900/60"
                        >
                            Done
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={() => onPickModeChange?.(true)}
                            className="ml-auto rounded border border-indigo-600/60 bg-indigo-900/40 px-1.5 py-0.5 text-[9px] font-semibold text-indigo-100 hover:bg-indigo-900/60"
                            title="Choose which versions receive sync"
                        >
                            Select
                        </button>
                    )}
                </div>
            )}
            {creativeSet.variant_groups && creativeSet.variant_groups.length > 0 && (
                <div className="mb-1.5 space-y-1 rounded-md border border-gray-800 bg-gray-900/50 px-2 py-1.5">
                    <p className="text-[9px] font-semibold uppercase tracking-wide text-gray-500">Variant sets</p>
                    <p className="text-[9px] leading-snug text-gray-500">
                        Color and size groups link sibling compositions from generation (read-only in the rail for now).
                    </p>
                    {creativeSet.variant_groups.map((g) => {
                        const t = variantGroupTypeLabel(String(g.type))
                        return (
                            <div
                                key={g.id}
                                className="flex flex-wrap items-center gap-1.5 text-[10px] text-gray-300"
                                title={g.label ? `${t.title}: ${g.label}` : t.title}
                            >
                                <span
                                    className="rounded bg-gray-800 px-1.5 py-0.5 text-[9px] font-semibold text-indigo-200"
                                >
                                    {t.short}
                                </span>
                                <span className="truncate text-gray-400">{g.label || t.title}</span>
                                <span className="text-gray-500">
                                    {g.member_count} member{g.member_count === 1 ? '' : 's'}
                                </span>
                            </div>
                        )
                    })}
                </div>
            )}
            {showCompositionAnimationChips ? (
            <div
                className="mb-1.5 flex gap-1.5 rounded-md border border-violet-900/35 bg-violet-950/20 px-2 py-1.5 text-[9px] leading-snug text-violet-100/90"
                title="In Animate composition: use a background-only layer (no type in frame) for cleaner motion, or full canvas for the exact layout."
            >
                <InformationCircleIcon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-violet-300/90" aria-hidden />
                <p>
                    <span className="font-semibold text-violet-200">Video versions</span> — best results when the start
                    frame is <strong className="text-violet-50">just the background</strong> (no text). You can still run{' '}
                    <strong className="text-violet-50">full composition</strong> to match your ad exactly.
                </p>
            </div>
            ) : null}
            <div
                ref={railScrollRef}
                data-testid="studio-versions-rail-scroll"
                className="flex gap-2 overflow-x-auto pb-1 pt-0.5"
                style={{ scrollbarWidth: 'thin' }}
            >
                {showCompositionAnimationChips ? (
                <StudioAnimationRailChips
                    jobs={compositionAnimations}
                    loading={compositionAnimationsLoading}
                    selectedJobId={selectedStudioAnimationJobId}
                    onSelectJob={(id) => onSelectStudioAnimationJob?.(id)}
                    compositionTitle={compositionAnimationTitle}
                    onRequestDiscardJob={onRequestDiscardStudioAnimationJob}
                />
                ) : null}
                {creativeSet.variants.map((v: StudioCreativeSetVariantDto) => {
                    const active = activeCompositionId === v.composition_id
                    const retryId = v.retryable_generation_job_item_id ?? null
                    const canRetry = Boolean(retryId && onRetryVersion)
                    const picked = selectedSet.has(v.composition_id)
                    const tilePickable = showPickChrome && pickMode && !active
                    const handoffMode = Boolean(studioHandoff?.selectionMode)
                    const handoffSelected = handoffSelectedSet.has(v.composition_id)
                    const isBase = baseCompositionId !== null && v.composition_id === baseCompositionId
                    const axisChips = getVariantAxisChipTexts(v.axis)
                    const tagged = variantHasAxisMetadata(v.axis)
                    const isUnviewedNewcomer = unviewedNewSet.has(v.composition_id)
                    const isHero = heroCompositionId !== null && v.composition_id === heroCompositionId
                    let thumbRing = statusRingClass(v.status, active)
                    if (!active) {
                        if (handoffMode && handoffSelected) {
                            thumbRing =
                                'ring-2 ring-sky-500/55 ring-offset-2 ring-offset-gray-950'
                        } else if (isUnviewedNewcomer) {
                            thumbRing =
                                'ring-2 ring-emerald-500/40 ring-offset-2 ring-offset-gray-950'
                        }
                    }

                    return (
                        <div
                            key={v.id}
                            data-studio-version-cid={v.composition_id}
                            className={`flex w-[76px] shrink-0 flex-col items-center gap-0.5 rounded-lg p-1 ${
                                active ? 'bg-gray-800' : 'bg-gray-900/80'
                            }`}
                        >
                            <div className="relative w-full">
                                <button
                                    type="button"
                                    onClick={() => {
                                        if (handoffMode) {
                                            studioHandoff?.onToggleComposition(v.composition_id)
                                            return
                                        }
                                        if (tilePickable) {
                                            onTogglePickComposition?.(v.composition_id)
                                        } else {
                                            onSelectComposition(v.composition_id)
                                        }
                                    }}
                                    title={
                                        handoffMode
                                            ? handoffSelected
                                                ? 'Deselect for export'
                                                : 'Select for export'
                                            : tilePickable
                                              ? picked
                                                  ? 'Deselect for sync'
                                                  : 'Select for sync'
                                              : v.label || `Composition ${v.composition_id}`
                                    }
                                    className="flex w-full flex-col items-center gap-1 rounded-md p-0.5 text-left transition-colors hover:bg-gray-800/90"
                                >
                                    <div className={`relative h-14 w-14 overflow-hidden rounded-md bg-gray-800 ${thumbRing}`}>
                                        {onToggleHero && (
                                            <span
                                                role="button"
                                                tabIndex={heroBusyCompositionId ? -1 : 0}
                                                title={
                                                    isHero
                                                        ? 'Clear hero'
                                                        : 'Mark as hero (best version for this set)'
                                                }
                                                aria-label={
                                                    isHero
                                                        ? 'Clear hero version'
                                                        : 'Mark as hero (best version for this set)'
                                                }
                                                aria-disabled={Boolean(heroBusyCompositionId)}
                                                aria-pressed={isHero}
                                                className={`absolute right-0.5 top-0.5 z-[2] flex h-5 w-5 items-center justify-center rounded-full border shadow outline-none focus-visible:ring-2 focus-visible:ring-amber-400/80 focus-visible:ring-offset-1 focus-visible:ring-offset-gray-900 ${
                                                    isHero
                                                        ? 'border-amber-400/80 bg-amber-500/90 text-gray-950'
                                                        : 'border-gray-600 bg-gray-950/80 text-gray-400 hover:border-amber-500/60 hover:text-amber-200'
                                                } ${
                                                    heroBusyCompositionId
                                                        ? 'cursor-not-allowed opacity-40'
                                                        : 'cursor-pointer'
                                                }`}
                                                onClick={(e) => {
                                                    e.stopPropagation()
                                                    e.preventDefault()
                                                    if (heroBusyCompositionId) {
                                                        return
                                                    }
                                                    onToggleHero(v.composition_id)
                                                }}
                                                onKeyDown={(e) => {
                                                    if (heroBusyCompositionId) {
                                                        return
                                                    }
                                                    if (e.key === 'Enter' || e.key === ' ') {
                                                        e.preventDefault()
                                                        e.stopPropagation()
                                                        onToggleHero(v.composition_id)
                                                    }
                                                }}
                                            >
                                                <StarIcon className="h-3 w-3" aria-hidden />
                                            </span>
                                        )}
                                        {isUnviewedNewcomer && (
                                            <span className="absolute left-1/2 top-0.5 z-[1] -translate-x-1/2 rounded bg-emerald-700/95 px-1 py-px text-[7px] font-bold uppercase tracking-wide text-white">
                                                New
                                            </span>
                                        )}
                                        {isHero && (
                                            <span className="absolute bottom-0.5 right-0.5 z-[1] rounded bg-amber-600/95 px-1 py-px text-[7px] font-bold uppercase text-gray-950">
                                                Hero
                                            </span>
                                        )}
                                        {isBase && (
                                            <span className="absolute bottom-0.5 left-0.5 z-[1] rounded bg-gray-950/90 px-1 py-px text-[7px] font-bold uppercase tracking-wide text-amber-200/95">
                                                Base
                                            </span>
                                        )}
                                        {handoffMode && handoffSelected && (
                                            <span className="absolute left-0.5 top-0.5 z-[1] flex h-4 w-4 items-center justify-center rounded bg-sky-600 text-white shadow">
                                                <CheckIcon className="h-3 w-3" aria-hidden />
                                            </span>
                                        )}
                                        {showPickChrome && !active && picked && !handoffMode && (
                                            <span className="absolute left-0.5 top-0.5 z-[1] flex h-4 w-4 items-center justify-center rounded bg-indigo-600 text-white shadow">
                                                <CheckIcon className="h-3 w-3" aria-hidden />
                                            </span>
                                        )}
                                        {v.thumbnail_url ? (
                                            <img src={v.thumbnail_url} alt="" className="h-full w-full object-cover" />
                                        ) : (
                                            <div className="flex h-full w-full items-center justify-center text-[9px] text-gray-600">
                                                No preview
                                            </div>
                                        )}
                                        {v.status === 'generating' && (
                                            <span className="absolute inset-0 flex items-center justify-center bg-black/40 text-[9px] font-semibold text-amber-200">
                                                …
                                            </span>
                                        )}
                                        {v.status === 'failed' && (
                                            <span className="absolute inset-0 flex items-center justify-center bg-black/50 text-[9px] font-semibold text-red-300">
                                                !
                                            </span>
                                        )}
                                    </div>
                                    <span className="line-clamp-2 w-full text-center text-[9px] font-medium leading-tight text-gray-400">
                                        {v.label || `Version ${v.sort_order + 1}`}
                                    </span>
                                    <div className="flex min-h-[14px] w-full flex-wrap justify-center gap-0.5 px-0.5">
                                        {axisChips.map((t) => (
                                            <span
                                                key={t}
                                                className="max-w-[72px] truncate rounded bg-gray-800/90 px-1 py-px text-[7px] font-medium text-gray-300"
                                                title={t}
                                            >
                                                {t}
                                            </span>
                                        ))}
                                        {!tagged && !isBase && (
                                            <span
                                                className="rounded bg-gray-800/60 px-1 py-px text-[7px] font-medium text-gray-500"
                                                title="No generation tags on this version"
                                            >
                                                Manual
                                            </span>
                                        )}
                                    </div>
                                </button>
                            </div>
                            {((handoffMode && !active) || (showPickChrome && pickMode && !active)) && (
                                <button
                                    type="button"
                                    onClick={(e) => {
                                        e.stopPropagation()
                                        onSelectComposition(v.composition_id)
                                    }}
                                    className="w-full rounded border border-gray-700/80 bg-gray-900/90 px-0.5 py-0.5 text-[8px] font-semibold text-gray-400 hover:border-gray-500 hover:text-gray-200"
                                >
                                    Open
                                </button>
                            )}
                            {canRetry && (
                                <button
                                    type="button"
                                    disabled={retryBusyItemId === retryId}
                                    onClick={(e) => {
                                        e.stopPropagation()
                                        onRetryVersion?.(retryId!)
                                    }}
                                    className="w-full rounded border border-red-900/50 bg-red-950/40 px-1 py-0.5 text-[9px] font-semibold text-red-200 hover:bg-red-950/70 disabled:cursor-not-allowed disabled:opacity-40"
                                >
                                    {retryBusyItemId === retryId ? 'Retrying…' : 'Retry version'}
                                </button>
                            )}
                            {!isBase && onRequestRemoveVariant && !handoffMode && (
                                <button
                                    type="button"
                                    data-testid={`studio-remove-variant-${v.composition_id}`}
                                    title="Remove this version from the set…"
                                    onClick={(e) => {
                                        e.stopPropagation()
                                        onRequestRemoveVariant(v)
                                    }}
                                    className="flex w-full items-center justify-center gap-0.5 rounded border border-gray-700/80 bg-gray-900/90 py-0.5 text-[8px] font-semibold text-gray-500 hover:border-red-900/50 hover:bg-red-950/30 hover:text-red-200"
                                >
                                    <TrashIcon className="h-3 w-3 shrink-0" aria-hidden />
                                    Remove
                                </button>
                            )}
                        </div>
                    )
                })}
                <div className="flex w-[76px] shrink-0 flex-col items-center gap-0.5 rounded-lg border border-dashed border-gray-700 bg-gray-900/40 p-1">
                    <button
                        type="button"
                        data-testid="studio-create-versions-tile"
                        onClick={() => onCreateVersions()}
                        disabled={!activeCompositionId}
                        title="Create versions — formats, colors, scenes, or duplicate"
                        className="flex w-full flex-col items-center gap-1 rounded-md p-0.5 text-left transition-colors hover:bg-gray-800/90 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        <div className="flex h-14 w-14 items-center justify-center rounded-md border border-gray-700 bg-gray-800/80 text-indigo-300">
                            <PlusIcon className="h-7 w-7" aria-hidden />
                        </div>
                        <span className="w-full text-center text-[9px] font-semibold leading-tight text-indigo-200/90">
                            New versions
                        </span>
                    </button>
                </div>
            </div>
        </div>
    )
}
