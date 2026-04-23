import type { BrandContext, DocumentModel, Layer, TextLayer } from '../../../Pages/Editor/documentModel'
import type { CompositionRenderPayloadV1 } from '../../../Pages/StudioExport/compositionRenderContract'
import type { Dispatch, MouseEvent, ReactNode, SetStateAction } from 'react'

export type CompositionSceneMode = 'editor' | 'export'

export type CompositionSceneEditorHandlers = {
    onLayerMouseDown: (layer: Layer, e: MouseEvent, opts: { isText: boolean; isEditingText: boolean }) => void
    onLayerDoubleClick: (layer: Layer, e: MouseEvent) => void
    updateLayer: (layerId: string, fn: (l: Layer) => Layer) => void
    setEditingTextLayerId: (id: string | null | ((prev: string | null) => string | null)) => void
    setSelectedLayerId: (id: string | null) => void
    beginMove: (layerId: string, e: MouseEvent) => void
    beginResize: (layerId: string, corner: 'nw' | 'ne' | 'sw' | 'se', e: MouseEvent) => void
    openPickerForReplaceImage: (layerId: string) => void
    runGenerativeGeneration: (layerId: string) => void | Promise<void>
    runGenerativeVariations: (layerId: string) => void | Promise<void>
    runImageLayerEdit: (layerId: string) => void | Promise<void>
    variationRequestCount: (usage: unknown) => number
    genUsage: unknown
    setImageLoadFailedByLayerId: Dispatch<SetStateAction<Record<string, boolean>>>
    /** Editor tracks in-flight studio animation jobs per source layer — {@link Map} or {@link Set} (both support `.has`). */
    activeLayerAnimationBySourceLayerId: ReadonlySet<string> | ReadonlyMap<string, unknown>
}

export type CompositionSceneProps = {
    mode: CompositionSceneMode
    /** Editor: live document. Export: omit when using {@link payload}. */
    document?: DocumentModel
    /** Export surface: canonical payload (converted with {@link documentFromRenderPayloadV1}). */
    payload?: CompositionRenderPayloadV1
    currentTimeMs: number
    brandContext: BrandContext | null
    brandFontsEpoch?: number
    /** Stage CSS scale (editor zoom). Export uses 1. */
    stageScale: number
    /** Editor-only: selection + interaction. */
    selectedLayerId?: string | null
    selectedGroupId?: string | null
    editingTextLayerId?: string | null
    editorHandlers?: CompositionSceneEditorHandlers
    /** Custom text rendering (editor uses {@link TextLayerEditable}). Export omits → read-only text. */
    renderTextLayer?: (layer: TextLayer) => ReactNode
    imageLoadFailedByLayerId?: Record<string, boolean>
    /** When set, bumps internal epoch so font preload effects re-run (editor). */
    exportReadinessEpoch?: number
    /** Editor only: mask gizmo vs preview raster mode. */
    compositionUiMode?: 'edit' | 'preview'
    /** Editor only: dashed union rect when a group is selected. */
    selectedGroupRect?: { x: number; y: number; width: number; height: number } | null
}
