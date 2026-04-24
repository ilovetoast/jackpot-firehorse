import { ExclamationTriangleIcon, LockClosedIcon, SparklesIcon } from '@heroicons/react/24/outline'
import { useCallback, useState, type ReactNode } from 'react'
import type { BrandContext, Layer, TextLayer } from '../../../Pages/Editor/documentModel'
import {
    fillLayerBackgroundCss,
    isFillLayer,
    isGenerativeImageLayer,
    isMaskLayer,
    isTextLayer,
    isVideoLayer,
    PLACEHOLDER_IMAGE_SRC,
} from '../../../Pages/Editor/documentModel'
import EditorSlotReelLoader from '../../../Components/Editor/EditorSlotReelLoader'
import { canvasImageObjectFit } from './canvasLayout'
import { CompositionTextReadonly } from './CompositionTextReadonly'
import type { CompositionSceneMode, CompositionSceneEditorHandlers } from './types'

const VARIATION_MAX = 4

/** Indigo scrim over layer pixels so slot-reel + status copy stay legible on light or busy imagery. */
const LAYER_AI_BUSY_OVERLAY =
    'flex flex-col items-center justify-center gap-2 bg-indigo-600/50 backdrop-blur-sm ring-1 ring-inset ring-indigo-950/15 dark:bg-indigo-900/55 dark:ring-white/10'
const LAYER_AI_BUSY_LABEL = 'text-white drop-shadow-[0_1px_2px_rgba(0,0,0,0.45)]'

export function CompositionLayerContent(props: {
    layer: Layer
    allLayers: Layer[]
    mode: CompositionSceneMode
    brandContext: BrandContext | null
    brandFontsEpoch?: number
    imageLoadFailedByLayerId: Record<string, boolean>
    setImageLoadFailedByLayerId?: CompositionSceneEditorHandlers['setImageLoadFailedByLayerId']
    editorHandlers?: CompositionSceneEditorHandlers
    renderTextLayer?: (layer: TextLayer) => ReactNode
    showVideoControls?: boolean
}) {
    const {
        layer,
        mode,
        brandContext,
        brandFontsEpoch,
        imageLoadFailedByLayerId,
        setImageLoadFailedByLayerId,
        editorHandlers,
        renderTextLayer,
        showVideoControls = false,
    } = props

    const [localImageFailed, setLocalImageFailed] = useState(false)
    const markImageFailed = useCallback(() => {
        if (setImageLoadFailedByLayerId) {
            setImageLoadFailedByLayerId((p) => ({ ...p, [layer.id]: true }))
        } else {
            setLocalImageFailed(true)
        }
    }, [layer.id, setImageLoadFailedByLayerId])

    const imageFailed = Boolean(imageLoadFailedByLayerId[layer.id] || localImageFailed)

    if (isMaskLayer(layer)) {
        return null
    }

    if (isGenerativeImageLayer(layer)) {
        return (
            <div className="relative h-full min-h-0 w-full min-w-0 overflow-hidden">
                {layer.resultSrc ? (
                    (() => {
                        const o = canvasImageObjectFit(layer.fit)
                        return (
                            <img
                                key={`${layer.resultSrc}-${o.value}`}
                                src={layer.resultSrc}
                                alt=""
                                draggable={false}
                                className={`editor-gen-fade-in pointer-events-none absolute inset-0 !h-full !w-full min-h-0 min-w-0 max-w-none max-h-full select-none ${o.className}`}
                                style={{ objectPosition: 'center' }}
                                onError={() =>
                                    editorHandlers?.updateLayer(layer.id, (l) =>
                                        isGenerativeImageLayer(l)
                                            ? {
                                                  ...l,
                                                  status: 'error',
                                                  lastError: 'The generated image could not be loaded.',
                                              }
                                            : l,
                                    )
                                }
                            />
                        )
                    })()
                ) : (
                    <div className="flex h-full w-full flex-col items-center justify-center gap-1 border-2 border-dashed border-violet-300 bg-violet-50/80 px-2 text-center text-[10px] text-violet-800 dark:border-violet-600 dark:bg-violet-950/40 dark:text-violet-200">
                        <SparklesIcon className="h-5 w-5 opacity-70" aria-hidden />
                        <span>Add a prompt in the panel, then Generate.</span>
                    </div>
                )}
                {layer.status === 'generating' && mode === 'editor' && (
                    <div
                        className={`absolute inset-0 z-10 ${LAYER_AI_BUSY_OVERLAY}`}
                        role="status"
                        aria-busy="true"
                    >
                        {layer.variationPending ? (
                            <EditorSlotReelLoader label="Variations…" labelClassName={LAYER_AI_BUSY_LABEL}>
                                <div className="grid w-[88px] grid-cols-2 gap-1">
                                    {Array.from({ length: layer.variationBatchSize ?? VARIATION_MAX }).map((_, i) => (
                                        <div
                                            key={i}
                                            className="aspect-square animate-pulse rounded bg-gradient-to-br from-violet-200 to-indigo-200 dark:from-violet-800 dark:to-indigo-900"
                                        />
                                    ))}
                                </div>
                            </EditorSlotReelLoader>
                        ) : (
                            <EditorSlotReelLoader label="Generating…" labelClassName={LAYER_AI_BUSY_LABEL} />
                        )}
                    </div>
                )}
                {layer.status === 'error' && (
                    <div className="pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-red-950/25 px-2 text-center dark:bg-red-950/50">
                        <ExclamationTriangleIcon className="h-6 w-6 text-red-400" aria-hidden />
                        <span className="max-h-[4.5rem] overflow-y-auto text-[10px] font-medium leading-snug text-red-900 dark:text-red-100">
                            {isGenerativeImageLayer(layer) && layer.lastError ? layer.lastError : 'Generation failed'}
                        </span>
                        {mode === 'editor' && editorHandlers ? (
                            <button
                                type="button"
                                className="pointer-events-auto rounded-md border border-red-300 bg-white px-2.5 py-1 text-[10px] font-semibold text-red-900 shadow-sm hover:bg-red-50 dark:border-red-700 dark:bg-gray-900 dark:text-red-100 dark:hover:bg-red-950/50"
                                onClick={(e) => {
                                    e.stopPropagation()
                                    void editorHandlers.runGenerativeGeneration(layer.id)
                                }}
                            >
                                Retry
                            </button>
                        ) : null}
                    </div>
                )}
                {mode === 'editor' &&
                    editorHandlers &&
                    editorHandlers.activeLayerAnimationBySourceLayerId.has(layer.id) && (
                        <div
                            className={`absolute inset-0 z-[22] ${LAYER_AI_BUSY_OVERLAY}`}
                            role="status"
                            aria-busy="true"
                        >
                            <EditorSlotReelLoader label="Animating…" labelClassName={LAYER_AI_BUSY_LABEL} />
                        </div>
                    )}
            </div>
        )
    }

    if (layer.type === 'image') {
        return (
            <div className="relative h-full min-h-0 w-full min-w-0 overflow-hidden">
                {layer.src ? (
                    (() => {
                        const o = canvasImageObjectFit(layer.fit)
                        return (
                            <img
                                key={`${layer.id}-${o.value}`}
                                src={layer.src}
                                alt=""
                                draggable={false}
                                className={`pointer-events-none absolute inset-0 !h-full !w-full min-h-0 min-w-0 max-w-none max-h-full select-none ${o.className}`}
                                style={{ objectPosition: 'center' }}
                                onError={() => {
                                    markImageFailed()
                                    editorHandlers?.updateLayer(layer.id, (l) =>
                                        l.type === 'image' && l.src !== PLACEHOLDER_IMAGE_SRC
                                            ? { ...l, src: PLACEHOLDER_IMAGE_SRC }
                                            : l,
                                    )
                                }}
                            />
                        )
                    })()
                ) : (
                    <button
                        type="button"
                        disabled={layer.locked || mode === 'export'}
                        onClick={(e) => {
                            e.stopPropagation()
                            editorHandlers?.openPickerForReplaceImage(layer.id)
                        }}
                        className="flex h-full w-full cursor-pointer flex-col items-center justify-center gap-1 border-2 border-dashed border-gray-600/70 bg-gray-900/40 px-2 text-center text-[10px] text-gray-300 backdrop-blur-sm transition-colors hover:border-indigo-500 hover:bg-indigo-950/40 hover:text-indigo-200 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span className="font-medium">Empty image slot</span>
                    </button>
                )}
                {imageFailed && (
                    <div
                        className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-1 bg-black/40 px-1 text-center"
                        aria-live="polite"
                    >
                        <ExclamationTriangleIcon className="h-6 w-6 shrink-0 text-amber-200" aria-hidden />
                        <span className="text-[10px] font-medium leading-tight text-white">Image failed to load</span>
                    </div>
                )}
                {layer.aiEdit?.status === 'editing' && mode === 'editor' && (
                    <div
                        className={`absolute inset-0 z-10 ${LAYER_AI_BUSY_OVERLAY}`}
                        role="status"
                        aria-busy="true"
                    >
                        <EditorSlotReelLoader label="Editing…" labelClassName={LAYER_AI_BUSY_LABEL} />
                    </div>
                )}
                {layer.aiEdit?.status === 'error' && mode === 'editor' && editorHandlers && (
                    <div className="pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-red-950/25 px-2 text-center dark:bg-red-950/50">
                        <ExclamationTriangleIcon className="h-6 w-6 text-red-400" aria-hidden />
                        <span className="max-h-[4.5rem] overflow-y-auto text-[10px] font-medium leading-snug text-red-900 dark:text-red-100">
                            {layer.aiEdit?.lastError || 'Edit failed'}
                        </span>
                        <button
                            type="button"
                            className="pointer-events-auto rounded-md border border-red-300 bg-white px-2.5 py-1 text-[10px] font-semibold text-red-900 shadow-sm hover:bg-red-50 dark:border-red-700 dark:bg-gray-900 dark:text-red-100 dark:hover:bg-red-950/50"
                            onClick={(e) => {
                                e.stopPropagation()
                                void editorHandlers.runImageLayerEdit(layer.id)
                            }}
                        >
                            Retry
                        </button>
                    </div>
                )}
                {mode === 'editor' &&
                    editorHandlers &&
                    editorHandlers.activeLayerAnimationBySourceLayerId.has(layer.id) && (
                        <div
                            className={`absolute inset-0 z-[22] ${LAYER_AI_BUSY_OVERLAY}`}
                            role="status"
                            aria-busy="true"
                        >
                            <EditorSlotReelLoader label="Animating…" labelClassName={LAYER_AI_BUSY_LABEL} />
                        </div>
                    )}
            </div>
        )
    }

    if (isVideoLayer(layer)) {
        return (
            <div className="relative h-full min-h-0 w-full min-w-0 overflow-hidden">
                {layer.src ? (
                    (() => {
                        const o = canvasImageObjectFit(layer.fit)
                        return (
                            <video
                                key={`${layer.id}-${o.value}`}
                                data-jp-editor-layer={layer.id}
                                data-jp-composition-scene-video="1"
                                src={layer.src}
                                className={`absolute inset-0 !h-full !w-full min-h-0 min-w-0 max-w-none max-h-full select-none ${showVideoControls ? 'pointer-events-auto' : 'pointer-events-none'} ${o.className}`}
                                style={{ objectPosition: 'center' }}
                                controls={showVideoControls}
                                muted
                                loop
                                playsInline
                                preload="metadata"
                                draggable={false}
                            />
                        )
                    })()
                ) : (
                    <div className="flex h-full w-full items-center justify-center border-2 border-dashed border-gray-600 bg-gray-900/30 px-2 text-center text-[10px] text-gray-500">
                        Missing video source
                    </div>
                )}
            </div>
        )
    }

    if (isFillLayer(layer)) {
        return (
            <div
                className="relative h-full min-h-0 w-full min-w-0"
                style={{
                    background: fillLayerBackgroundCss(layer),
                    borderRadius: layer.borderRadius != null ? `${layer.borderRadius}px` : undefined,
                    border:
                        layer.borderStrokeWidth && layer.borderStrokeWidth > 0
                            ? `${layer.borderStrokeWidth}px solid ${layer.borderStrokeColor ?? layer.color}`
                            : undefined,
                    boxSizing: 'border-box',
                }}
            />
        )
    }

    if (isTextLayer(layer)) {
        if (renderTextLayer) {
            return <>{renderTextLayer(layer)}</>
        }
        return (
            <CompositionTextReadonly
                layer={layer}
                brandContext={brandContext}
                brandFontsEpoch={brandFontsEpoch}
                onAutoFitFontSize={
                    mode === 'editor' && editorHandlers
                        ? (size) =>
                              editorHandlers.updateLayer(layer.id, (l) =>
                                  isTextLayer(l) ? { ...l, style: { ...l.style, fontSize: size } } : l,
                              )
                        : undefined
                }
            />
        )
    }

    return (
        <div className="flex h-full w-full items-center justify-center bg-amber-500/10 text-[10px] text-amber-900">
            Unsupported layer type: {(layer as { type?: string }).type ?? '?'}
        </div>
    )
}
