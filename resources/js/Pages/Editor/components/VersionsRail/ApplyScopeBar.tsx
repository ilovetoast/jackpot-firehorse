import { postCreativeSetApply, postCreativeSetApplyPreview } from '../../studioCreativeSetBridge'
import type { Layer } from '../../documentModel'
import type { CreativeSetSemanticApplyScopeApi } from '../../studioCreativeSetTypes'
import {
    buildSemanticApplyCommandsFromLayer,
    describeApplyCommandBundle,
    formatApplyConfirmMessage,
    formatApplyResultNotice,
} from '../../studioSemanticApplyCommands'

export type StudioApplyScope = 'this_version' | 'all_versions' | 'selected_versions'

type Props = {
    creativeSetId: string
    sourceCompositionId: string | null
    applyScope: StudioApplyScope
    onApplyScopeChange: (scope: StudioApplyScope) => void
    /** Composition ids the user chose for “Selected versions” (never includes the open/source composition). */
    selectedTargetCompositionIds: string[]
    selectedLayer: Layer | null
    /** Number of other compositions in the set (excluding the open one). */
    siblingCompositionCount: number
    onNotice: (message: string) => void
    onCreativeSetUpdated: (creativeSet: import('../../studioCreativeSetTypes').StudioCreativeSetDto) => void
}

/**
 * Edit scope for Versions: local-only vs allowlisted semantic sync across sibling compositions.
 */
export function ApplyScopeBar(props: Props) {
    const {
        creativeSetId,
        sourceCompositionId,
        applyScope,
        onApplyScopeChange,
        selectedTargetCompositionIds,
        selectedLayer,
        siblingCompositionCount,
        onNotice,
        onCreativeSetUpdated,
    } = props

    const commands = selectedLayer ? buildSemanticApplyCommandsFromLayer(selectedLayer) : []
    const bundle = describeApplyCommandBundle(commands)

    const selectedCount = selectedTargetCompositionIds.length

    const canPushAll =
        applyScope === 'all_versions' &&
        sourceCompositionId != null &&
        siblingCompositionCount > 0 &&
        commands.length > 0

    const canPushSelected =
        applyScope === 'selected_versions' &&
        sourceCompositionId != null &&
        selectedCount > 0 &&
        commands.length > 0

    const runPreviewApply = async (mode: CreativeSetSemanticApplyScopeApi) => {
        if (!sourceCompositionId || !selectedLayer || commands.length === 0) {
            return
        }
        const targetIds =
            mode === 'selected_versions'
                ? selectedTargetCompositionIds.map((id) => Number(id)).filter((n) => Number.isFinite(n) && n > 0)
                : undefined
        const preview = await postCreativeSetApplyPreview(creativeSetId, {
            source_composition_id: sourceCompositionId,
            commands,
            scope: mode,
            target_composition_ids: targetIds,
        })
        const ok = window.confirm(
            formatApplyConfirmMessage({
                mode,
                primaryRoleLabel: bundle.primaryRoleLabel,
                aspects: bundle.aspects,
                siblingTargetCount: preview.sibling_compositions_targeted,
                eligibleCount: preview.sibling_compositions_eligible,
                wouldSkipCount: preview.sibling_compositions_would_skip,
            })
        )
        if (!ok) {
            return
        }
        const res = await postCreativeSetApply(creativeSetId, {
            source_composition_id: sourceCompositionId,
            commands,
            scope: mode,
            target_composition_ids: targetIds,
        })
        onCreativeSetUpdated(res.creative_set)
        const n = res.sibling_compositions_updated ?? res.updated_composition_ids.length
        const sk = res.skipped.length
        onNotice(
            formatApplyResultNotice({
                mode,
                primaryRoleLabel: bundle.primaryRoleLabel,
                aspects: bundle.aspects,
                updated: n,
                skipped: sk,
            })
        )
    }

    return (
        <div
            className="w-full border-b border-gray-800/90 bg-gray-900/95 px-2 py-1.5"
            data-testid="studio-apply-scope-bar"
        >
            <div className="mx-auto flex max-w-5xl flex-col gap-1.5 sm:flex-row sm:flex-wrap sm:items-end sm:gap-x-2 sm:gap-y-1">
                <div className="min-w-0 flex flex-1 flex-col gap-1 sm:flex-row sm:items-center sm:gap-2">
                    <div className="flex flex-wrap items-center gap-1">
                        <span className="shrink-0 pl-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500 sm:pl-0">
                            Edits
                        </span>
                        <button
                            type="button"
                            onClick={() => onApplyScopeChange('this_version')}
                            className={`rounded-md px-2.5 py-1 text-[11px] font-semibold transition-colors ${
                                applyScope === 'this_version'
                                    ? 'bg-gray-700 text-white'
                                    : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200'
                            }`}
                        >
                            This version
                        </button>
                        <button
                            type="button"
                            disabled={siblingCompositionCount < 1}
                            title={
                                siblingCompositionCount < 1
                                    ? 'Add another version to sync across the set'
                                    : 'Apply to every other version in this set'
                            }
                            onClick={() => onApplyScopeChange('all_versions')}
                            className={`rounded-md px-2.5 py-1 text-[11px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-40 ${
                                applyScope === 'all_versions'
                                    ? 'bg-indigo-900/50 text-indigo-100 ring-1 ring-indigo-500/40'
                                    : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200'
                            }`}
                        >
                            All versions
                        </button>
                        <button
                            type="button"
                            disabled={siblingCompositionCount < 1}
                            title={
                                siblingCompositionCount < 1
                                    ? 'Add another version to pick targets'
                                    : 'Pick specific versions in the Versions strip below'
                            }
                            onClick={() => onApplyScopeChange('selected_versions')}
                            className={`rounded-md px-2.5 py-1 text-[11px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-40 ${
                                applyScope === 'selected_versions'
                                    ? 'bg-indigo-900/50 text-indigo-100 ring-1 ring-indigo-500/40'
                                    : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200'
                            }`}
                        >
                            Selected versions
                        </button>
                    </div>
                    <p className="min-w-0 max-w-prose text-[9px] leading-snug text-gray-500 sm:text-[8px]">
                        Syncs text/logo-type layers across <span className="text-gray-400">compositions</span> — not the
                        AI video tiles in the strip.
                    </p>
                </div>
                {applyScope === 'selected_versions' && siblingCompositionCount > 0 && (
                    <span className="shrink-0 text-[10px] font-medium text-gray-400 sm:ml-auto">
                        {selectedCount === 0 ? 'Pick targets in Versions' : `${selectedCount} selected`}
                    </span>
                )}
                {applyScope === 'all_versions' && (
                    <div className="flex w-full min-w-0 flex-col items-stretch gap-1 sm:ml-auto sm:w-auto sm:flex-row sm:items-center">
                        <button
                            type="button"
                            disabled={!canPushAll}
                            title={
                                siblingCompositionCount < 1
                                    ? 'Add another version to sync across versions'
                                    : !selectedLayer
                                      ? 'Select a layer on the canvas'
                                      : commands.length === 0
                                        ? 'Sync is limited to Headline, Subheadline, CTA, Disclaimer, Logo, and Badge — product photos and AI backgrounds stay per version.'
                                        : `Push ${bundle.primaryRoleLabel} (${bundle.aspects}) to other versions`
                            }
                            onClick={() => {
                                if (!canPushAll) {
                                    return
                                }
                                void (async () => {
                                    try {
                                        await runPreviewApply('all_versions')
                                    } catch (e) {
                                        onNotice(e instanceof Error ? e.message : 'Sync failed')
                                    }
                                })()
                            }}
                            className="shrink-0 rounded-md bg-indigo-600 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            Push to all versions
                        </button>
                        {selectedLayer && commands.length === 0 && (
                            <p className="max-w-[240px] text-center text-[10px] leading-snug text-amber-200/90 sm:text-left">
                                This layer is not on the sync list (Headline, Subheadline, CTA, Disclaimer, Logo, Badge) —
                                rename or use a template role, or keep edits on this version only. Product shots and
                                generative backgrounds never sync.
                            </p>
                        )}
                    </div>
                )}
                {applyScope === 'selected_versions' && (
                    <div className="flex w-full min-w-0 flex-col items-stretch gap-1 sm:ml-auto sm:w-auto sm:flex-row sm:items-center">
                        <button
                            type="button"
                            disabled={!canPushSelected}
                            title={
                                selectedCount < 1
                                    ? 'Select one or more other versions in the Versions strip (checkboxes)'
                                    : !selectedLayer
                                      ? 'Select a layer on the canvas'
                                      : commands.length === 0
                                        ? 'Sync is limited to Headline, Subheadline, CTA, Disclaimer, Logo, and Badge — product photos and AI backgrounds stay per version.'
                                        : `Push ${bundle.primaryRoleLabel} (${bundle.aspects}) to ${selectedCount} selected version(s)`
                            }
                            onClick={() => {
                                if (!canPushSelected) {
                                    return
                                }
                                void (async () => {
                                    try {
                                        await runPreviewApply('selected_versions')
                                    } catch (e) {
                                        onNotice(e instanceof Error ? e.message : 'Sync failed')
                                    }
                                })()
                            }}
                            className="shrink-0 rounded-md bg-indigo-600 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            {selectedCount > 0 ? `Push to selected (${selectedCount})` : 'Push to selected'}
                        </button>
                        {selectedLayer && commands.length === 0 && (
                            <p className="max-w-[240px] text-center text-[10px] leading-snug text-amber-200/90 sm:text-left">
                                This layer is not on the sync list (Headline, Subheadline, CTA, Disclaimer, Logo, Badge) —
                                rename or use a template role, or keep edits on this version only. Product shots and
                                generative backgrounds never sync.
                            </p>
                        )}
                    </div>
                )}
            </div>
        </div>
    )
}
