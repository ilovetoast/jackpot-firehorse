import { forwardRef, useCallback, useEffect, useMemo, useRef, useState, type CSSProperties } from 'react'
import type { Layer } from '../../../Pages/Editor/documentModel'
import {
    buildMaskStyleForLayer,
    isFillLayer,
    isGenerativeImageLayer,
    isImageLayer,
    isMaskLayer,
    isTextLayer,
} from '../../../Pages/Editor/documentModel'
import type { CompositionRenderPayloadV1 } from '../../../Pages/StudioExport/compositionRenderContract'
import { LockClosedIcon } from '@heroicons/react/24/outline'
import { CompositionLayerContent } from './CompositionLayerContent'
import { documentFromRenderPayloadV1 } from './payloadAdapter'
import { sortLayersForCanvas } from './canvasLayout'
import { applyVideosCurrentTimeInContainer } from './timing'
import type { CompositionSceneEditorHandlers, CompositionSceneProps } from './types'
import type { DocumentModel } from '../../../Pages/Editor/documentModel'

/**
 * Shared DOM composition renderer — editor canvas + export render surface.
 * No selection chrome here; parent may wrap with resize handles when {@link mode} is `editor`.
 */
export const CompositionScene = forwardRef<HTMLDivElement, CompositionSceneProps>(function CompositionSceneInner(
    props,
    ref,
) {
        const {
            mode,
            document: documentProp,
            payload,
            currentTimeMs,
            brandContext,
            brandFontsEpoch = 0,
            stageScale,
            selectedLayerId = null,
            selectedGroupId = null,
            editingTextLayerId = null,
            editorHandlers,
            renderTextLayer,
            imageLoadFailedByLayerId: imageFailedProp,
            compositionUiMode = 'edit',
            selectedGroupRect = null,
        } = props

        const innerRef = useRef<HTMLDivElement | null>(null)
        const setRootRef = useCallback(
            (el: HTMLDivElement | null) => {
                innerRef.current = el
                if (typeof ref === 'function') {
                    ref(el)
                } else if (ref) {
                    ref.current = el
                }
            },
            [ref],
        )

        const documentModel = useMemo((): DocumentModel => {
            if (documentProp) {
                return documentProp
            }
            if (payload) {
                return documentFromRenderPayloadV1(payload as CompositionRenderPayloadV1)
            }
            throw new Error('CompositionScene requires `document` or `payload`')
        }, [documentProp, payload])

        const durationMs = Math.max(1, documentModel.studio_timeline?.duration_ms ?? 30_000)

        const [exportImageFailed, setExportImageFailed] = useState<Record<string, boolean>>({})
        const imageLoadFailedByLayerId = imageFailedProp ?? exportImageFailed
        const setImageLoadFailedByLayerId = editorHandlers?.setImageLoadFailedByLayerId ?? setExportImageFailed

        const layersSorted = useMemo(() => sortLayersForCanvas(documentModel.layers), [documentModel.layers])

        useEffect(() => {
            applyVideosCurrentTimeInContainer(innerRef.current, currentTimeMs, durationMs)
        }, [currentTimeMs, durationMs])

        return (
            <div
                ref={setRootRef}
                role="presentation"
                className={`isolate origin-top-left relative ${mode === 'export' ? 'overflow-hidden bg-white' : ''}`}
                style={{
                    width: documentModel.width,
                    height: documentModel.height,
                    ...(Math.abs(stageScale - 1) > 1e-6
                        ? { transform: `scale(${stageScale})`, transformOrigin: 'top left' as const }
                        : {}),
                    ...(mode === 'editor' ? { overflow: 'visible' as const } : {}),
                }}
                data-jp-composition-scene-root
                data-composition-scene-mode={mode}
            >
                {layersSorted.map((layer) => {
                    if (!layer.visible) {
                        return null
                    }
                    if (isMaskLayer(layer) && compositionUiMode === 'preview') {
                        return null
                    }
                    const isSelected = layer.id === selectedLayerId
                    const showMemberRing = mode === 'editor' && Boolean(isSelected && !selectedGroupId)
                    const isGroupMate =
                        mode === 'editor' &&
                        Boolean(selectedGroupId && !isSelected && layer.groupId === selectedGroupId)
                    const t = layer.transform
                    const rot = t.rotation ?? 0

                    return (
                        <div
                            key={layer.id}
                            data-studio-layer-id={layer.id}
                            role="presentation"
                            className={`group relative box-border ${
                                showMemberRing
                                    ? isTextLayer(layer)
                                        ? 'ring-2 ring-indigo-500 ring-offset-0 outline outline-1 outline-dashed outline-indigo-400/90 dark:ring-indigo-400 dark:outline-indigo-500/80'
                                        : 'ring-2 ring-indigo-500 ring-offset-0 dark:ring-indigo-400'
                                    : isGroupMate
                                      ? 'outline outline-1 outline-dashed outline-teal-400/70'
                                      : ''
                            }`}
                            style={{
                                position: 'absolute',
                                left: t.x,
                                top: t.y,
                                width: t.width,
                                height: t.height,
                                zIndex: Number.isFinite(Number(layer.z)) ? Number(layer.z) : 0,
                                overflow: isTextLayer(layer)
                                    ? layer.style?.autoFit
                                        ? 'hidden'
                                        : 'visible'
                                    : 'hidden',
                                transform: rot !== 0 ? `rotate(${rot}deg)` : undefined,
                                ...(layer.blendMode && layer.blendMode !== 'normal'
                                    ? {
                                          mixBlendMode: layer.blendMode as CSSProperties['mixBlendMode'],
                                      }
                                    : {}),
                                ...(buildMaskStyleForLayer(layer, documentModel.layers) as CSSProperties),
                            }}
                            onMouseDown={
                                mode === 'editor' && editorHandlers
                                    ? (e) => {
                                          e.stopPropagation()
                                          if (selectedLayerId !== layer.id) {
                                              editorHandlers.setEditingTextLayerId(null)
                                          }
                                          editorHandlers.onLayerMouseDown(layer, e, {
                                              isText: isTextLayer(layer),
                                              isEditingText: editingTextLayerId === layer.id,
                                          })
                                      }
                                    : undefined
                            }
                            onDoubleClick={
                                mode === 'editor' && editorHandlers
                                    ? (e) => editorHandlers.onLayerDoubleClick(layer, e)
                                    : undefined
                            }
                        >
                            {layer.locked && (
                                <div
                                    data-jp-export-capture-exclude
                                    className="pointer-events-none absolute right-1 top-1 z-20 rounded bg-black/55 p-0.5 text-white"
                                    title="Layer locked"
                                >
                                    <LockClosedIcon className="h-3.5 w-3.5" aria-hidden />
                                </div>
                            )}
                            {isMaskLayer(layer) && compositionUiMode === 'edit' && (
                                <div
                                    data-jp-export-capture-exclude
                                    className="pointer-events-none absolute inset-0 rounded border-2 border-dashed border-amber-400/80 bg-amber-400/5"
                                    aria-hidden
                                >
                                    <span className="absolute left-1 top-1 rounded bg-amber-400 px-1 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-black">
                                        Mask · {layer.shape}
                                    </span>
                                </div>
                            )}
                            {mode === 'editor' && editorHandlers && isGenerativeImageLayer(layer) && (
                                <GenerativeImageToolbarPlaceholder layer={layer} editorHandlers={editorHandlers} />
                            )}
                            {mode === 'editor' && editorHandlers && isImageLayer(layer) && (
                                <ImageToolbarPlaceholder layer={layer} editorHandlers={editorHandlers} />
                            )}
                            {mode === 'editor' && editorHandlers && isFillLayer(layer) && (
                                <FillToolbarPlaceholder layer={layer} editorHandlers={editorHandlers} />
                            )}
                            <CompositionLayerContent
                                layer={layer}
                                allLayers={documentModel.layers}
                                mode={mode}
                                brandContext={brandContext}
                                brandFontsEpoch={brandFontsEpoch}
                                imageLoadFailedByLayerId={imageLoadFailedByLayerId}
                                setImageLoadFailedByLayerId={setImageLoadFailedByLayerId}
                                editorHandlers={editorHandlers}
                                renderTextLayer={renderTextLayer}
                                /* Native controls stay off — use {@link EditorCompositionVideoPlaybackBar} only. */
                                showVideoControls={false}
                            />
                            {mode === 'editor' &&
                                editorHandlers &&
                                isSelected &&
                                !layer.locked &&
                                (['nw', 'ne', 'sw', 'se'] as const).map((corner) => (
                                    <button
                                        key={corner}
                                        type="button"
                                        data-jp-export-capture-exclude
                                        aria-label={`Resize ${corner}`}
                                        className="absolute z-10 h-2.5 w-2.5 rounded-sm border border-white bg-indigo-500 shadow dark:bg-indigo-400"
                                        style={{
                                            cursor: `${corner}-resize`,
                                            ...(corner === 'nw' ? { top: -4, left: -4 } : {}),
                                            ...(corner === 'ne' ? { top: -4, right: -4 } : {}),
                                            ...(corner === 'sw' ? { bottom: -4, left: -4 } : {}),
                                            ...(corner === 'se' ? { bottom: -4, right: -4 } : {}),
                                        }}
                                        onMouseDown={(e) => {
                                            e.stopPropagation()
                                            editorHandlers.beginResize(layer.id, corner, e)
                                        }}
                                    />
                                ))}
                        </div>
                    )
                })}
                {mode === 'editor' && compositionUiMode === 'edit' && selectedGroupRect && (
                    <div
                        data-jp-export-capture-exclude
                        className="pointer-events-none absolute outline outline-2 outline-dashed outline-indigo-400"
                        style={{
                            left: selectedGroupRect.x - 2,
                            top: selectedGroupRect.y - 2,
                            width: selectedGroupRect.width + 4,
                            height: selectedGroupRect.height + 4,
                            zIndex: 9999,
                        }}
                        aria-hidden
                    />
                )}
            </div>
        )
})

/** Kept as separate nodes in AssetEditor previously — still editor-only; extracted minimally to avoid huge JSX here. */
function GenerativeImageToolbarPlaceholder(props: {
    layer: import('../../../Pages/Editor/documentModel').GenerativeImageLayer
    editorHandlers: CompositionSceneEditorHandlers
}) {
    const { layer, editorHandlers } = props
    return (
        <div
            data-jp-export-capture-exclude
            className="pointer-events-none absolute bottom-full left-1/2 z-30 mb-1 flex -translate-x-1/2 opacity-0 transition-opacity duration-200 group-hover:pointer-events-auto group-hover:opacity-100"
        >
            <div className="pointer-events-auto flex flex-nowrap gap-0.5 rounded-md border border-gray-200 bg-white/95 px-1 py-0.5 text-[10px] font-medium text-gray-800 shadow-md dark:border-gray-700 dark:bg-gray-900/95 dark:text-gray-100">
                <button
                    type="button"
                    title="Regenerate this layer"
                    disabled={
                        layer.locked ||
                        layer.status === 'generating' ||
                        !layer.prompt?.scene?.trim()
                    }
                    className="rounded px-1.5 py-0.5 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-gray-800"
                    onClick={(e) => {
                        e.stopPropagation()
                        void editorHandlers.runGenerativeGeneration(layer.id)
                    }}
                >
                    Regenerate
                </button>
                <button
                    type="button"
                    title="Variations"
                    disabled={
                        layer.locked ||
                        layer.status === 'generating' ||
                        layer.variationPending ||
                        !layer.prompt?.scene?.trim() ||
                        editorHandlers.variationRequestCount(editorHandlers.genUsage) < 1
                    }
                    className="rounded px-1.5 py-0.5 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-gray-800"
                    onClick={(e) => {
                        e.stopPropagation()
                        void editorHandlers.runGenerativeVariations(layer.id)
                    }}
                >
                    Variations
                </button>
                <button
                    type="button"
                    className="rounded px-1.5 py-0.5 hover:bg-gray-100 dark:hover:bg-gray-800"
                    onClick={(e) => {
                        e.stopPropagation()
                        editorHandlers.updateLayer(layer.id, (l) => ({
                            ...l,
                            locked: !l.locked,
                        }))
                    }}
                >
                    {layer.locked ? 'Unlock' : 'Lock'}
                </button>
            </div>
        </div>
    )
}

function ImageToolbarPlaceholder(props: {
    layer: import('../../../Pages/Editor/documentModel').ImageLayer
    editorHandlers: CompositionSceneEditorHandlers
}) {
    const { layer, editorHandlers } = props
    return (
        <div
            data-jp-export-capture-exclude
            className="pointer-events-none absolute bottom-full left-1/2 z-30 mb-1 flex -translate-x-1/2 opacity-0 transition-opacity duration-200 group-hover:pointer-events-auto group-hover:opacity-100"
        >
            <div className="pointer-events-auto flex gap-0.5 rounded-md border border-gray-200 bg-white/95 px-1 py-0.5 text-[10px] font-medium text-gray-800 shadow-md dark:border-gray-700 dark:bg-gray-900/95 dark:text-gray-100">
                <button
                    type="button"
                    title="Replace image"
                    disabled={layer.locked}
                    className="rounded px-1.5 py-0.5 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-gray-800"
                    onClick={(e) => {
                        e.stopPropagation()
                        editorHandlers.openPickerForReplaceImage(layer.id)
                    }}
                >
                    Replace
                </button>
                <button
                    type="button"
                    className="rounded px-1.5 py-0.5 hover:bg-gray-100 dark:hover:bg-gray-800"
                    onClick={(e) => {
                        e.stopPropagation()
                        editorHandlers.updateLayer(layer.id, (l) => ({
                            ...l,
                            locked: !l.locked,
                        }))
                    }}
                >
                    {layer.locked ? 'Unlock' : 'Lock'}
                </button>
            </div>
        </div>
    )
}

function FillToolbarPlaceholder(props: {
    layer: import('../../../Pages/Editor/documentModel').FillLayer
    editorHandlers: CompositionSceneEditorHandlers
}) {
    const { layer, editorHandlers } = props
    return (
        <div
            data-jp-export-capture-exclude
            className="pointer-events-none absolute bottom-full left-1/2 z-30 mb-1 flex -translate-x-1/2 opacity-0 transition-opacity duration-200 group-hover:pointer-events-auto group-hover:opacity-100"
        >
            <div className="pointer-events-auto flex gap-0.5 rounded-md border border-gray-200 bg-white/95 px-1 py-0.5 text-[10px] font-medium text-gray-800 shadow-md dark:border-gray-700 dark:bg-gray-900/95 dark:text-gray-100">
                <button
                    type="button"
                    title="Lock this fill layer"
                    className="rounded px-1.5 py-0.5 hover:bg-gray-100 dark:hover:bg-gray-800"
                    onClick={(e) => {
                        e.stopPropagation()
                        editorHandlers.updateLayer(layer.id, (l) => ({
                            ...l,
                            locked: !l.locked,
                        }))
                    }}
                >
                    {layer.locked ? 'Unlock' : 'Lock'}
                </button>
            </div>
        </div>
    )
}
