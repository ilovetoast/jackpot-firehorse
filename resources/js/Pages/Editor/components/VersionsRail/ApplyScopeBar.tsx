import { postCreativeSetApply } from '../../studioCreativeSetBridge'
import type { Layer } from '../../documentModel'
import {
    buildSemanticApplyCommandsFromLayer,
    describeSyncForLayer,
} from '../../studioSemanticApplyCommands'

export type StudioApplyScope = 'this_version' | 'all_versions'

type Props = {
    creativeSetId: string
    sourceCompositionId: string | null
    applyScope: StudioApplyScope
    onApplyScopeChange: (scope: StudioApplyScope) => void
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
        selectedLayer,
        siblingCompositionCount,
        onNotice,
        onCreativeSetUpdated,
    } = props

    const syncLabel = selectedLayer ? describeSyncForLayer(selectedLayer) : null
    const commands = selectedLayer ? buildSemanticApplyCommandsFromLayer(selectedLayer) : []

    const canPushToAll =
        applyScope === 'all_versions' &&
        sourceCompositionId != null &&
        siblingCompositionCount > 0 &&
        commands.length > 0

    return (
        <div className="pointer-events-none absolute inset-x-0 bottom-[120px] z-30 flex justify-center px-4">
            <div className="pointer-events-auto flex max-w-lg flex-col items-center gap-2 rounded-lg border border-gray-700/90 bg-gray-900/95 px-2 py-2 shadow-lg backdrop-blur-sm sm:flex-row sm:px-1 sm:py-1">
                <div className="flex items-center gap-1">
                    <span className="hidden pl-2 text-[10px] font-semibold uppercase tracking-wide text-gray-500 sm:inline">
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
                        onClick={() => onApplyScopeChange('all_versions')}
                        className={`rounded-md px-2.5 py-1 text-[11px] font-semibold transition-colors ${
                            applyScope === 'all_versions'
                                ? 'bg-indigo-900/50 text-indigo-100 ring-1 ring-indigo-500/40'
                                : 'text-gray-400 hover:bg-gray-800 hover:text-gray-200'
                        }`}
                    >
                        All versions
                    </button>
                </div>
                {applyScope === 'all_versions' && (
                    <div className="flex flex-col items-stretch gap-1 sm:flex-row sm:items-center">
                        <button
                            type="button"
                            disabled={!canPushToAll}
                            title={
                                siblingCompositionCount < 1
                                    ? 'Add another version to sync across versions'
                                    : !selectedLayer
                                      ? 'Select a layer on the canvas'
                                      : commands.length === 0
                                        ? 'This layer type is not supported for sync yet (e.g. product photos stay per version)'
                                        : `Apply the current ${syncLabel ?? 'layer'} state to other versions`
                            }
                            onClick={() => {
                                if (!sourceCompositionId || !selectedLayer || commands.length === 0) {
                                    return
                                }
                                const noun = syncLabel ? `${syncLabel}` : 'this layer'
                                const ok = window.confirm(
                                    `Sync ${noun} across ${siblingCompositionCount} other version(s)?\n\n` +
                                        `Only safe fields (text, alignment, placement, visibility for recognized roles) are updated.`
                                )
                                if (!ok) {
                                    return
                                }
                                void (async () => {
                                    try {
                                        const res = await postCreativeSetApply(creativeSetId, {
                                            source_composition_id: sourceCompositionId,
                                            commands,
                                        })
                                        onCreativeSetUpdated(res.creative_set)
                                        const n = res.sibling_compositions_updated ?? res.updated_composition_ids.length
                                        const sk = res.skipped.length
                                        onNotice(
                                            sk
                                                ? `Updated ${n} version(s). ${sk} skipped — some layouts may differ.`
                                                : `Updated ${n} other version(s).`
                                        )
                                    } catch (e) {
                                        onNotice(e instanceof Error ? e.message : 'Sync failed')
                                    }
                                })()
                            }}
                            className="rounded-md bg-indigo-600 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            Push to all versions
                        </button>
                        {selectedLayer && commands.length === 0 && (
                            <p className="max-w-[220px] text-center text-[10px] leading-snug text-amber-200/90 sm:text-left">
                                This edit stays on this version only — sync is limited to headline, subheadline, CTA,
                                disclaimer, logo, and badge roles for now.
                            </p>
                        )}
                    </div>
                )}
            </div>
        </div>
    )
}
