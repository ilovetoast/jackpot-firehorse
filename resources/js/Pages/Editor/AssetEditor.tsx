import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
    type CSSProperties,
} from 'react'
import { router, usePage } from '@inertiajs/react'
import { flushSync } from 'react-dom'
import { AnimatePresence, motion } from 'framer-motion'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import MetadataGroups from '../../Components/Upload/MetadataGroups'
import { toJpeg, toPng } from 'html-to-image'
import {
    ArrowDownIcon,
    ArrowPathIcon,
    ArrowUpIcon,
    ArrowsRightLeftIcon,
    ChevronDoubleDownIcon,
    ChevronDoubleUpIcon,
    ClockIcon,
    DocumentDuplicateIcon,
    ExclamationTriangleIcon,
    EyeIcon,
    EyeSlashIcon,
    FolderOpenIcon,
    LockClosedIcon,
    LockOpenIcon,
    PhotoIcon,
    SparklesIcon,
    SwatchIcon,
    Bars3BottomLeftIcon,
    Bars3Icon,
    Square2StackIcon,
    Squares2X2Icon,
    TrashIcon,
    ViewfinderCircleIcon,
    XMarkIcon,
} from '@heroicons/react/24/outline'
import type {
    BrandContext,
    CopyScore,
    DamPickerAsset,
    DocumentModel,
    GenerativeImageLayer,
    ImageLayer,
    Layer,
    LayerBlendMode,
    TextLayer,
} from './documentModel'
import {
    buildBrandAugmentedPrompt,
    buildPromptPreviewSummary,
    buildPromotionAssetName,
    centerLayerInDocument,
    cloneLayer,
    computeAutoFitTextFontSize,
    convertGenerativeLayerToImage,
    createDefaultGenerativeImageLayer,
    buildEditorVisualContext,
    createDefaultTextLayer,
    createFillLayer,
    createGuidedLayoutLayers,
    createImageLayerFromDamAsset,
    createInitialDocument,
    fillLayerBackgroundCss,
    isBlankUnsavedCanvas,
    LAYER_BLEND_MODE_OPTIONS,
    labeledBrandPalette,
    DEFAULT_TEXT_FONT_FAMILY,
    effectivePrimaryFontFamily,
    defaultCompositionName,
    detectTextIntent,
    estimateBrandScore,
    generativeLayerToGenerationSize,
    GENERATIVE_PREVIOUS_RESULTS_MAX,
    nextZIndex,
    normalizeZ,
    isFillLayer,
    parseDocumentFromApi,
    PLACEHOLDER_IMAGE_SRC,
    resolvedFillGradientStops,
} from './documentModel'
import FillGradientStopField from './FillGradientStopField'
import {
    confirmDamAssetDimensions,
    fetchEditorAssetById,
    fetchEditorAssets,
    fetchEditorCollectionsForPublish,
    fetchEditorPublishCategories,
    fetchEditorPublishMetadataSchema,
    promoteCompositionToAsset,
    type EditorPublishCategory,
    type EditorPublishMetadataSchema,
} from './editorAssetBridge'
import {
    assetVersionStripLabel,
    fetchAssetVersions,
    isAssetVersionThumbnailActive,
    orderAssetVersionsForStrip,
    type EditorAssetVersionRow,
} from './editorAssetVersionBridge'
import { captureCompositionThumbnailBase64 } from './editorCompositionThumbnail'
import {
    ensureCanvasFontLoaded,
    formatCssFontFamilyStack,
    resolveCanvasFontFamily,
} from './editorBrandFonts'
import {
    deleteCompositionApi,
    duplicateCompositionApi,
    fetchCompositionSummaries,
    fetchCompositionVersions,
    getComposition,
    getCompositionVersion,
    postComposition,
    postCompositionFromDocument,
    postCompositionVersion,
    putComposition,
} from './editorCompositionBridge'
import type { CompositionSummaryDto } from './editorCompositionBridge'
import { areAllRequiredFieldsSatisfied } from '../../utils/metadataValidation'
import { loadEditorBrandTypography } from './editorBrandFonts'
import { fetchEditorBrandContext } from './editorBrandContextBridge'
import {
    postGenerateCopy,
    serializeBrandForCopy,
    type CopySuggestionVariant,
    type GenerateCopyOperation,
} from './editorGenerateCopyBridge'
import {
    canGenerateFromUsage,
    fetchGenerateImageUsage,
    generateEditorImage,
    GENERATIVE_ADVANCED_MODEL_OPTIONS,
    GENERATIVE_EDIT_MODEL_OPTIONS,
    GENERATIVE_MODEL_OPTIONS,
    MODEL_MAP,
    normalizeEditModelKey,
    resolveModelConfig,
    type GenerateImageUsage,
    type GenerativeUiModelKey,
} from './editorGenerateImageBridge'
import { editImage } from './editorEditImageBridge'
import {
    handleAIError,
    MAX_CONCURRENT_AI_REQUESTS,
    trackEvent,
    editorHtmlToImageFetchRequestInit,
    waitForImagesToLoad,
    withAIConcurrency,
} from './editorHardening'

/*
 * QA checklist (manual)
 * [ ] Generate image works
 * [ ] Generate copy works
 * [ ] Save + version works
 * [ ] Compare works
 * [ ] Export works
 * [ ] Preview works
 */

/** Log when old vs new image aspect ratios differ enough to look wrong in the same frame box. */
const REPLACE_ASPECT_WARN_RATIO = 1.75

const ASSET_EDITOR_PROPERTIES_WIDTH_KEY = 'asset-editor:properties-panel-width'

const DOCUMENT_DIMENSION_MIN = 64
const DOCUMENT_DIMENSION_MAX = 8192

/** Quick canvas sizes for social / display ads (px). */
const DOCUMENT_SIZE_PRESETS: ReadonlyArray<{ label: string; w: number; h: number }> = [
    { label: '1080 × 1080', w: 1080, h: 1080 },
    { label: '1080 × 1920', w: 1080, h: 1920 },
    { label: '1920 × 1080', w: 1920, h: 1080 },
    { label: '1200 × 628', w: 1200, h: 628 },
]

function readStoredPropertiesPanelWidth(): number {
    if (typeof window === 'undefined') {
        return 288
    }
    const n = Number(window.localStorage.getItem(ASSET_EDITOR_PROPERTIES_WIDTH_KEY))
    return Number.isFinite(n) && n >= 240 && n <= 720 ? Math.round(n) : 288
}

const GENERATE_DEBOUNCE_MS = 400
const COPY_ASSIST_DEBOUNCE_MS = 400
const TEXT_INPUT_DEBOUNCE_MS = 140
/** Debounced autosave when a composition id exists */
const AUTOSAVE_MS = 2500

const TEXT_PRESETS = {
    heading: {
        fontSize: 36,
        fontWeight: 700,
        lineHeight: 1.15,
        letterSpacing: -0.5,
    },
    subheading: {
        fontSize: 22,
        fontWeight: 600,
        lineHeight: 1.25,
        letterSpacing: 0,
    },
    body: {
        fontSize: 16,
        fontWeight: 400,
        lineHeight: 1.5,
        letterSpacing: 0,
    },
    caption: {
        fontSize: 12,
        fontWeight: 400,
        lineHeight: 1.4,
        letterSpacing: 0.4,
    },
} as const

type TextPresetKey = keyof typeof TEXT_PRESETS

/** Merges API `typography.presets` (Phase 7) over built-in defaults. */
function resolveTextPreset(brand: BrandContext | null | undefined, key: TextPresetKey) {
    const base = TEXT_PRESETS[key]
    const o = brand?.typography?.presets?.[key]
    if (!o) {
        return base
    }
    return {
        fontSize: o.fontSize ?? base.fontSize,
        fontWeight: o.fontWeight ?? base.fontWeight,
        lineHeight: o.lineHeight ?? base.lineHeight,
        letterSpacing: o.letterSpacing ?? base.letterSpacing,
    }
}

function normalizeHexColor(hex: string): string {
    const s = hex.trim()
    if (s.startsWith('#') && s.length === 4 && /^#[0-9a-fA-F]{4}$/.test(s)) {
        return `#${s[1]}${s[1]}${s[2]}${s[2]}${s[3]}${s[3]}`.toLowerCase()
    }
    return s.toLowerCase()
}

function colorsMatch(a: string, b: string): boolean {
    return normalizeHexColor(a) === normalizeHexColor(b)
}

/** When toggling fill to solid, prefer an opaque hex from gradient end, then start. */
function opaqueHexForSolidFromGradientStops(start: string, end: string, prev: string): string {
    const e = end.trim()
    const s = start.trim()
    if (/^#[0-9a-fA-F]{6}$/i.test(e)) {
        return e
    }
    if (/^#[0-9a-fA-F]{6}$/i.test(s)) {
        return s
    }
    return prev
}

function firstFontFamilyToken(fontFamily: string): string {
    return fontFamily.split(',')[0].trim().replace(/^["']|["']$/g, '')
}

function fontFamilyMatches(a: string, b: string): boolean {
    return firstFontFamilyToken(a).toLowerCase() === firstFontFamilyToken(b).toLowerCase()
}

/** Unique CSS family names from Brand DNA uploaded fonts (EditorFontFaceSource list). */
function uniqueFontFamiliesFromFaceSources(
    sources: { family?: string }[] | undefined
): string[] {
    if (!sources?.length) {
        return []
    }
    const seen = new Set<string>()
    const out: string[] = []
    for (const s of sources) {
        const fam = s.family?.trim()
        if (!fam) {
            continue
        }
        const k = firstFontFamilyToken(fam).toLowerCase()
        if (seen.has(k)) {
            continue
        }
        seen.add(k)
        out.push(fam)
    }
    return out
}
const MAX_REFERENCE_ASSETS = 5
/** Max parallel images per variation run (actual count may be lower if credits are limited). */
const VARIATION_MAX = 4

const PROMPT_ASSIST_CHIPS = ['studio lighting', 'premium fashion', 'minimal background'] as const

type SmartSuggestionAction = 'premium' | 'contrast' | 'minimal' | 'tone' | 'colors'

const SMART_SUGGESTIONS: ReadonlyArray<{ label: string; action: SmartSuggestionAction }> = [
    { label: 'Make more premium', action: 'premium' },
    { label: 'Add contrast', action: 'contrast' },
    { label: 'Simplify composition', action: 'minimal' },
]

const SUGGESTION_TOAST: Record<SmartSuggestionAction, string> = {
    premium: 'Applied: Premium style',
    contrast: 'Applied: Contrast lighting',
    minimal: 'Applied: Minimal composition',
    tone: 'Applied: Brand tone',
    colors: 'Applied: Brand colors',
}

function variationRequestCount(usage: GenerateImageUsage | null): number {
    if (!usage || !canGenerateFromUsage(usage)) {
        return 0
    }
    if (usage.limit < 0) {
        return VARIATION_MAX
    }
    return Math.min(VARIATION_MAX, Math.max(1, usage.remaining))
}

async function runWithConcurrency<T>(items: T[], limit: number, worker: (item: T) => Promise<void>): Promise<void> {
    let cursor = 0
    const n = Math.min(limit, Math.max(1, items.length))
    const workers = Array.from({ length: n }, async () => {
        while (true) {
            const idx = cursor++
            if (idx >= items.length) {
                break
            }
            await worker(items[idx])
        }
    })
    await Promise.all(workers)
}

type ResizeCorner = 'nw' | 'ne' | 'sw' | 'se'

type DragState =
    | {
          kind: 'move'
          layerId: string
          startDocX: number
          startDocY: number
          startLayerX: number
          startLayerY: number
      }
    | {
          kind: 'resize'
          layerId: string
          corner: ResizeCorner
          startDocX: number
          startDocY: number
          start: { x: number; y: number; width: number; height: number }
          aspectRatio: number
          lockAspectResize: boolean
      }

const UNTITLED_DRAFT_NAME = 'Untitled draft'

function isUntitledDraftName(name: string): boolean {
    return name.trim().toLowerCase() === UNTITLED_DRAFT_NAME.toLowerCase()
}

function isImageLayer(l: Layer): l is ImageLayer {
    return l.type === 'image'
}

function isGenerativeImageLayer(l: Layer): l is GenerativeImageLayer {
    return l.type === 'generative_image'
}

function isTextLayer(l: Layer): l is TextLayer {
    return l.type === 'text'
}

/** Image-like layers: lock aspect ratio on resize by default (Shift inverts). */
function locksAspectOnResize(l: Layer): boolean {
    return l.type === 'image' || l.type === 'generative_image'
}

function TextLayerEditable({
    layer,
    editing,
    assistLoading,
    brandContext,
    brandFontsEpoch = 0,
    onChange,
    onStopEdit,
    onTextHeightChange,
    onAutoFitFontSize,
}: {
    layer: TextLayer
    editing: boolean
    assistLoading?: boolean
    brandContext: BrandContext | null
    /** Increments after brand @font-face / FontFace registration completes. */
    brandFontsEpoch?: number
    onChange: (text: string) => void
    onStopEdit: () => void
    onTextHeightChange: (height: number) => void
    onAutoFitFontSize: (size: number) => void
}) {
    const readRef = useRef<HTMLDivElement>(null)
    const editRef = useRef<HTMLDivElement>(null)
    const draftRef = useRef(layer.content)
    const debounceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)
    const onTextHeightChangeRef = useRef(onTextHeightChange)
    onTextHeightChangeRef.current = onTextHeightChange
    const onAutoFitFontSizeRef = useRef(onAutoFitFontSize)
    onAutoFitFontSizeRef.current = onAutoFitFontSize
    const layerHeightRef = useRef(layer.transform.height)
    layerHeightRef.current = layer.transform.height
    const autoFit = layer.style.autoFit === true
    const resolvedFontFamily = resolveCanvasFontFamily(brandContext, layer.style.fontFamily)
    const cssFontFamilyStack = formatCssFontFamilyStack(resolvedFontFamily)

    const flushTextDebounced = useCallback(() => {
        if (debounceTimerRef.current) {
            clearTimeout(debounceTimerRef.current)
            debounceTimerRef.current = null
        }
        onChange(draftRef.current)
    }, [onChange])

    const scheduleTextDebounced = useCallback(() => {
        if (debounceTimerRef.current) {
            clearTimeout(debounceTimerRef.current)
        }
        debounceTimerRef.current = setTimeout(() => {
            debounceTimerRef.current = null
            onChange(draftRef.current)
        }, TEXT_INPUT_DEBOUNCE_MS)
    }, [onChange])

    useEffect(() => {
        if (!editing) {
            draftRef.current = layer.content
        }
    }, [layer.content, editing])

    const measureEl = useCallback((el: HTMLDivElement | null) => {
        if (!el || autoFit) {
            return
        }
        el.style.height = 'auto'
        const h = el.scrollHeight
        el.style.height = `${h}px`
        const next = Math.max(20, h)
        if (Math.round(next) !== Math.round(layerHeightRef.current)) {
            onTextHeightChangeRef.current(next)
        }
    }, [autoFit])

    useLayoutEffect(() => {
        if (editing && editRef.current) {
            editRef.current.textContent = layer.content
            draftRef.current = layer.content
            editRef.current.focus()
            requestAnimationFrame(() => measureEl(editRef.current))
        }
    }, [editing, layer.id, measureEl])

    useLayoutEffect(() => {
        if (!editing && readRef.current) {
            measureEl(readRef.current)
        }
    }, [
        editing,
        layer.content,
        layer.style.fontSize,
        layer.style.fontWeight,
        layer.style.fontFamily,
        resolvedFontFamily,
        cssFontFamilyStack,
        layer.style.lineHeight,
        layer.style.letterSpacing,
        measureEl,
    ])

    useLayoutEffect(() => {
        if (!autoFit) {
            return
        }
        const fitFamily = resolvedFontFamily
        const next = computeAutoFitTextFontSize(
            layer.content,
            layer.transform.width,
            layer.transform.height,
            layer.style.fontSize,
            {
                fontFamily: formatCssFontFamilyStack(fitFamily),
                fontWeight: layer.style.fontWeight,
                lineHeight: layer.style.lineHeight,
                letterSpacing: layer.style.letterSpacing,
                textAlign: layer.style.textAlign,
            }
        )
        if (Math.round(next) !== Math.round(layer.style.fontSize)) {
            onAutoFitFontSizeRef.current(next)
        }
    }, [
        autoFit,
        brandContext,
        resolvedFontFamily,
        cssFontFamilyStack,
        layer.id,
        layer.content,
        layer.transform.width,
        layer.transform.height,
        layer.style.fontSize,
        layer.style.fontFamily,
        layer.style.fontWeight,
        layer.style.lineHeight,
        layer.style.letterSpacing,
        layer.style.textAlign,
    ])

    const lh = layer.style.lineHeight ?? 1.25
    const ls = layer.style.letterSpacing ?? 0
    const vAlign = layer.style.verticalAlign ?? 'top'
    const alignItems: CSSProperties['alignItems'] =
        vAlign === 'middle' ? 'center' : vAlign === 'bottom' ? 'flex-end' : 'flex-start'

    useLayoutEffect(() => {
        let cancelled = false
        const run = async () => {
            await ensureCanvasFontLoaded(
                resolvedFontFamily,
                layer.style.fontSize,
                layer.style.fontWeight ?? 400
            )
            if (cancelled) {
                return
            }
            if (editing && editRef.current) {
                measureEl(editRef.current)
            } else if (!editing && readRef.current) {
                measureEl(readRef.current)
            }
        }
        void run()
        return () => {
            cancelled = true
        }
    }, [
        resolvedFontFamily,
        cssFontFamilyStack,
        layer.style.fontSize,
        layer.style.fontWeight,
        editing,
        layer.content,
        measureEl,
        brandFontsEpoch,
    ])

    const textStyle: CSSProperties = {
        fontFamily: cssFontFamilyStack,
        fontSize: layer.style.fontSize,
        fontWeight: layer.style.fontWeight ?? 400,
        lineHeight: `${lh}`,
        letterSpacing: `${ls}px`,
        color: layer.style.color,
        textAlign: layer.style.textAlign ?? 'left',
        whiteSpace: 'pre-wrap',
        wordBreak: 'break-word',
        width: '100%',
        minHeight: autoFit ? '100%' : undefined,
    }

    const innerRead = (
        <div
            className={`flex h-full w-full min-h-0 min-w-0 flex-row ${autoFit ? 'overflow-hidden' : ''}`}
            style={{ alignItems }}
        >
            <div
                ref={readRef}
                className="pointer-events-none min-h-0 min-w-0 w-full max-w-full flex-1 select-none"
                style={{
                    ...textStyle,
                    overflow: autoFit ? 'hidden' : 'visible',
                }}
            >
                {layer.content}
            </div>
        </div>
    )

    if (!editing) {
        return (
            <div className="relative h-full min-h-0 w-full">
                {innerRead}
                {assistLoading && (
                    <div
                        className="absolute inset-0 z-20 flex flex-col items-center justify-center gap-2 bg-white/75 dark:bg-gray-950/75"
                        aria-busy
                        aria-label="Generating copy"
                    >
                        <ArrowPathIcon className="h-7 w-7 animate-spin text-indigo-600 dark:text-indigo-400" />
                        <span className="text-[10px] font-medium text-gray-700 dark:text-gray-200">
                            Generating copy…
                        </span>
                    </div>
                )}
            </div>
        )
    }

    return (
        <div className="relative h-full min-h-0 w-full">
            <div
                className={`flex h-full w-full min-h-0 min-w-0 flex-row ${autoFit ? 'overflow-hidden' : ''}`}
                style={{ alignItems }}
            >
                <div
                    ref={editRef}
                    contentEditable
                    suppressContentEditableWarning
                    className="min-h-0 min-w-0 w-full flex-1 cursor-text outline-none"
                    style={{
                        ...textStyle,
                        overflow: autoFit ? 'hidden' : 'visible',
                    }}
                    onMouseDown={(e) => e.stopPropagation()}
                    onInput={(e) => {
                        const el = e.currentTarget as HTMLDivElement
                        draftRef.current = el.innerText ?? ''
                        scheduleTextDebounced()
                        measureEl(el)
                    }}
                    onBlur={() => {
                        flushTextDebounced()
                        onStopEdit()
                    }}
                />
            </div>
            {assistLoading && (
                <div
                    className="absolute inset-0 z-20 flex flex-col items-center justify-center gap-2 bg-white/75 dark:bg-gray-950/75"
                    aria-busy
                    aria-label="Generating copy"
                >
                    <ArrowPathIcon className="h-7 w-7 animate-spin text-indigo-600 dark:text-indigo-400" />
                    <span className="text-[10px] font-medium text-gray-700 dark:text-gray-200">
                        Generating copy…
                    </span>
                </div>
            )}
        </div>
    )
}

function computeResizeRect(
    corner: ResizeCorner,
    start: { x: number; y: number; width: number; height: number },
    dx: number,
    dy: number,
    min: number,
    lockAspect: boolean,
    aspectRatio: number
): { x: number; y: number; width: number; height: number } {
    if (!lockAspect) {
        let x = start.x
        let y = start.y
        let w = start.width
        let h = start.height
        if (corner === 'se') {
            w = Math.max(min, start.width + dx)
            h = Math.max(min, start.height + dy)
        } else if (corner === 'sw') {
            const newW = Math.max(min, start.width - dx)
            x = start.x + (start.width - newW)
            w = newW
            h = Math.max(min, start.height + dy)
        } else if (corner === 'ne') {
            const newH = Math.max(min, start.height - dy)
            y = start.y + (start.height - newH)
            h = newH
            w = Math.max(min, start.width + dx)
        } else if (corner === 'nw') {
            const newW = Math.max(min, start.width - dx)
            const newH = Math.max(min, start.height - dy)
            x = start.x + (start.width - newW)
            y = start.y + (start.height - newH)
            w = newW
            h = newH
        }
        return { x, y, width: w, height: h }
    }

    const ar = aspectRatio
    let x = start.x
    let y = start.y
    let w = start.width
    let h = start.height
    if (corner === 'se') {
        w = Math.max(min, start.width + dx)
        h = Math.max(min, w / ar)
    } else if (corner === 'sw') {
        w = Math.max(min, start.width - dx)
        h = Math.max(min, w / ar)
        x = start.x + start.width - w
    } else if (corner === 'ne') {
        w = Math.max(min, start.width + dx)
        h = Math.max(min, w / ar)
        y = start.y + start.height - h
    } else if (corner === 'nw') {
        w = Math.max(min, start.width - dx)
        h = Math.max(min, w / ar)
        x = start.x + start.width - w
        y = start.y + start.height - h
    }
    return { x, y, width: w, height: h }
}

/**
 * Back → front = ascending z. "up" = one step toward front (higher z).
 * Swap stored `z` values — {@link normalizeZ} sorts by existing z, so array-only reorder is ignored.
 */
function moveLayerZOrder(doc: DocumentModel, layerId: string, dir: 'up' | 'down'): DocumentModel {
    const asc = [...doc.layers].sort((a, b) => a.z - b.z || a.id.localeCompare(b.id))
    const i = asc.findIndex((l) => l.id === layerId)
    if (i < 0) {
        return doc
    }
    const j = dir === 'up' ? i + 1 : i - 1
    if (j < 0 || j >= asc.length) {
        return doc
    }
    const a = asc[i]
    const b = asc[j]
    const zA = a.z
    const zB = b.z
    return {
        ...doc,
        layers: doc.layers.map((l) => {
            if (l.id === a.id) {
                return { ...l, z: zB }
            }
            if (l.id === b.id) {
                return { ...l, z: zA }
            }
            return l
        }),
        updated_at: new Date().toISOString(),
    }
}

function bringLayerToFront(doc: DocumentModel, layerId: string): DocumentModel {
    const asc = [...doc.layers].sort((a, b) => a.z - b.z || a.id.localeCompare(b.id))
    const i = asc.findIndex((l) => l.id === layerId)
    if (i < 0) {
        return doc
    }
    const [layer] = asc.splice(i, 1)
    asc.push(layer)
    return {
        ...doc,
        layers: asc.map((l, index) => ({ ...l, z: index })),
        updated_at: new Date().toISOString(),
    }
}

function sendLayerToBack(doc: DocumentModel, layerId: string): DocumentModel {
    const asc = [...doc.layers].sort((a, b) => a.z - b.z || a.id.localeCompare(b.id))
    const i = asc.findIndex((l) => l.id === layerId)
    if (i < 0) {
        return doc
    }
    const [layer] = asc.splice(i, 1)
    asc.unshift(layer)
    return {
        ...doc,
        layers: asc.map((l, index) => ({ ...l, z: index })),
        updated_at: new Date().toISOString(),
    }
}

/**
 * Layers panel order (top → bottom): front → back, like Photoshop / Figma.
 * Guided layout keeps background at z = 0 so it appears at the bottom of this list.
 */
function sortLayersPanelFrontAtTop(layers: Layer[]): Layer[] {
    return [...layers].sort((a, b) => {
        const za = Number(a.z)
        const zb = Number(b.z)
        const d = (Number.isFinite(zb) ? zb : 0) - (Number.isFinite(za) ? za : 0)
        return d !== 0 ? d : a.id.localeCompare(b.id)
    })
}

/** Panel row 0 = front (top of stack); last row = back. Maps to z: bottom row → 0. */
function applyPanelOrderToZ(orderedTopToBottom: Layer[]): Layer[] {
    const n = orderedTopToBottom.length
    return orderedTopToBottom.map((layer, i) => ({
        ...layer,
        z: n - 1 - i,
    }))
}

function duplicateLayerInDoc(
    doc: DocumentModel,
    layerId: string
): { doc: DocumentModel; newId: string } | null {
    const layer = doc.layers.find((l) => l.id === layerId)
    if (!layer) {
        return null
    }
    const dup = cloneLayer(layer)
    const asc = [...doc.layers].sort((a, b) => a.z - b.z)
    asc.push(dup)
    return {
        doc: {
            ...doc,
            layers: normalizeZ(asc),
            updated_at: new Date().toISOString(),
        },
        newId: dup.id,
    }
}

function deleteLayerFromDoc(doc: DocumentModel, layerId: string): DocumentModel {
    return {
        ...doc,
        layers: normalizeZ(doc.layers.filter((l) => l.id !== layerId)),
        updated_at: new Date().toISOString(),
    }
}

function replaceUrlCompositionParam(id: string | null) {
    const url = new URL(window.location.href)
    if (id) {
        url.searchParams.set('composition', id)
    } else {
        url.searchParams.delete('composition')
    }
    window.history.replaceState({}, '', url)
}

export default function AssetEditor() {
    const page = usePage()
    const { auth } = page.props as {
        auth: { activeBrand?: { id?: number; name?: string; primary_color?: string | null } }
    }
    const activeBrandId = auth?.activeBrand?.id
    const compositionIdFromUrl = useMemo(() => {
        try {
            const u = new URL(page.url, window.location.origin)
            return u.searchParams.get('composition')
        } catch {
            return null
        }
    }, [page.url])

    const initialDocumentRef = useRef<DocumentModel | null>(null)
    const [document, setDocument] = useState<DocumentModel>(() => {
        if (!initialDocumentRef.current) {
            initialDocumentRef.current = createInitialDocument()
        }
        return initialDocumentRef.current
    })
    const documentRef = useRef(document)
    documentRef.current = document
    const [selectedLayerId, setSelectedLayerId] = useState<string | null>(null)
    const [editingTextLayerId, setEditingTextLayerId] = useState<string | null>(null)
    const [pickerOpen, setPickerOpen] = useState(false)
    const [pickerMode, setPickerMode] = useState<'add' | 'replace' | 'references' | null>(null)
    const [replaceLayerId, setReplaceLayerId] = useState<string | null>(null)
    const [referencePickerLayerId, setReferencePickerLayerId] = useState<string | null>(null)
    const [referenceSelectionIds, setReferenceSelectionIds] = useState<string[]>([])
    const [brandContext, setBrandContext] = useState<BrandContext | null>(null)
    const [brandFontsLoading, setBrandFontsLoading] = useState(false)
    /** Bumps when licensed brand fonts finish registering so text layers re-run font loading/measure. */
    const [brandFontsEpoch, setBrandFontsEpoch] = useState(0)
    const [copyAssistLoadingId, setCopyAssistLoadingId] = useState<string | null>(null)
    const [copyAssistSuggestions, setCopyAssistSuggestions] = useState<CopySuggestionVariant[]>([])
    const [copyAssistScore, setCopyAssistScore] = useState<CopyScore | null>(null)
    const [copyAssistError, setCopyAssistError] = useState<string | null>(null)
    const [copyAssistHoverIdx, setCopyAssistHoverIdx] = useState<number | null>(null)
    const copyAbortRef = useRef<AbortController | null>(null)
    const copyAssistLastAtRef = useRef(0)
    const copyAssistLastOpRef = useRef<GenerateCopyOperation>('generate')
    const [damAssets, setDamAssets] = useState<DamPickerAsset[]>([])
    /** Assets fetched individually when a reference id is missing from the picker list. */
    const [extraDamAssets, setExtraDamAssets] = useState<DamPickerAsset[]>([])
    const [damLoading, setDamLoading] = useState(false)
    const [damError, setDamError] = useState<string | null>(null)
    const [pickerCategories, setPickerCategories] = useState<EditorPublishCategory[]>([])
    const [pickerCategoriesLoading, setPickerCategoriesLoading] = useState(false)
    /** Library (ASSET) vs executions (DELIVERABLE) in the image picker */
    const [pickerScope, setPickerScope] = useState<'library' | 'executions'>('library')
    const [pickerCategoryFilterId, setPickerCategoryFilterId] = useState<number | ''>('')
    const [promoteSaving, setPromoteSaving] = useState(false)
    const [promoteError, setPromoteError] = useState<string | null>(null)
    const [promoteOk, setPromoteOk] = useState(false)
    const [publishModalOpen, setPublishModalOpen] = useState(false)
    const [publishCategories, setPublishCategories] = useState<
        { id: number; name: string; slug: string; asset_type: 'asset' | 'deliverable' }[]
    >([])
    const [publishCategoriesLoading, setPublishCategoriesLoading] = useState(false)
    const [publishCategoriesError, setPublishCategoriesError] = useState<string | null>(null)
    const [publishTitle, setPublishTitle] = useState('')
    const [publishCategoryId, setPublishCategoryId] = useState<number | ''>('')
    const [publishDescription, setPublishDescription] = useState('')
    const [publishMetadataSchema, setPublishMetadataSchema] = useState<EditorPublishMetadataSchema | null>(null)
    const [publishMetadataLoading, setPublishMetadataLoading] = useState(false)
    const [publishMetadataError, setPublishMetadataError] = useState<string | null>(null)
    const [publishMetadataValues, setPublishMetadataValues] = useState<Record<string, unknown>>({})
    const [publishMetadataShowErrors, setPublishMetadataShowErrors] = useState(false)
    const [publishCollectionsList, setPublishCollectionsList] = useState<Array<{ id: number; name: string; is_public?: boolean }>>([])
    const [publishCollectionsLoading, setPublishCollectionsLoading] = useState(false)
    const [publishCollectionIds, setPublishCollectionIds] = useState<number[]>([])
    const [compositionId, setCompositionId] = useState<string | null>(null)
    const [compositionName, setCompositionName] = useState(() => defaultCompositionName(createInitialDocument()))
    const [lastSavedSerialized, setLastSavedSerialized] = useState(() =>
        JSON.stringify(initialDocumentRef.current!)
    )
    const [saveState, setSaveState] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle')
    const [saveError, setSaveError] = useState<string | null>(null)
    const [compositionBootstrapping, setCompositionBootstrapping] = useState(Boolean(compositionIdFromUrl))
    const [propertiesPanelWidth, setPropertiesPanelWidth] = useState(readStoredPropertiesPanelWidth)
    const propertiesPanelWidthRef = useRef(propertiesPanelWidth)
    propertiesPanelWidthRef.current = propertiesPanelWidth

    const onPropertiesResizePointerDown = useCallback((e: React.PointerEvent<HTMLDivElement>) => {
        e.preventDefault()
        const target = e.currentTarget
        target.setPointerCapture(e.pointerId)
        const startX = e.clientX
        const startW = propertiesPanelWidthRef.current
        const onMove = (ev: PointerEvent) => {
            setPropertiesPanelWidth(
                Math.min(720, Math.max(240, startW + (startX - ev.clientX)))
            )
        }
        const onUp = () => {
            try {
                target.releasePointerCapture(e.pointerId)
            } catch {
                /* already released */
            }
            window.removeEventListener('pointermove', onMove)
            window.removeEventListener('pointerup', onUp)
            window.removeEventListener('pointercancel', onUp)
            try {
                window.localStorage.setItem(
                    ASSET_EDITOR_PROPERTIES_WIDTH_KEY,
                    String(propertiesPanelWidthRef.current)
                )
            } catch {
                /* private mode / quota */
            }
        }
        window.addEventListener('pointermove', onMove)
        window.addEventListener('pointerup', onUp)
        window.addEventListener('pointercancel', onUp)
    }, [])
    const [compositionLoadError, setCompositionLoadError] = useState<string | null>(null)
    const [historyOpen, setHistoryOpen] = useState(false)
    const [openCompositionPicker, setOpenCompositionPicker] = useState(false)
    const [compositionSummaries, setCompositionSummaries] = useState<CompositionSummaryDto[]>([])
    const [compositionListLoading, setCompositionListLoading] = useState(false)
    const [compositionListError, setCompositionListError] = useState<string | null>(null)
    const [compositionDeleteBusy, setCompositionDeleteBusy] = useState(false)
    const [versions, setVersions] = useState<
        import('./editorCompositionBridge').CompositionVersionMeta[]
    >([])
    const [versionsLoading, setVersionsLoading] = useState(false)
    /** Per image-layer id: DAM asset version rows for the version strip (no new versions on switch). */
    const [layerVersions, setLayerVersions] = useState<Record<string, EditorAssetVersionRow[]>>({})
    const [compareOpen, setCompareOpen] = useState(false)
    const [compareLeftId, setCompareLeftId] = useState<string | null>(null)
    const [compareRightId, setCompareRightId] = useState<string | null>(null)
    const [compareUrls, setCompareUrls] = useState<[string, string] | null>(null)
    const [compareBusy, setCompareBusy] = useState(false)
    const [compareSlider, setCompareSlider] = useState(50)
    const documentBeforeCompareRef = useRef<DocumentModel | null>(null)
    const [uiMode, setUiMode] = useState<'edit' | 'preview'>('edit')
    const [previewFrame, setPreviewFrame] = useState<'social' | 'banner'>('social')
    const compositionIdRef = useRef<string | null>(null)
    compositionIdRef.current = compositionId
    /** Shown when an image URL failed; cleared when the layer’s src changes away from placeholder. */
    const [imageLoadFailedByLayerId, setImageLoadFailedByLayerId] = useState<Record<string, true>>({})
    const [genUsage, setGenUsage] = useState<{
        remaining: number
        limit: number
        plan: string
        plan_name?: string
    } | null>(null)
    const [genUsageError, setGenUsageError] = useState<string | null>(null)
    const [genActionError, setGenActionError] = useState<string | null>(null)
    const [suggestionToast, setSuggestionToast] = useState<string | null>(null)
    /** Short-lived confirmations (save, export, generation). */
    const [activityToast, setActivityToast] = useState<string | null>(null)
    const [variationHoverIdx, setVariationHoverIdx] = useState<number | null>(null)
    const [variationPressedIdx, setVariationPressedIdx] = useState<number | null>(null)
    const [layerDragId, setLayerDragId] = useState<string | null>(null)
    const lastGenerateAtByLayerRef = useRef<Record<string, number>>({})
    const genSeqRef = useRef<Record<string, number>>({})
    const genAbortByLayerRef = useRef<Record<string, AbortController>>({})
    const imageEditSeqRef = useRef<Record<string, number>>({})
    const imageEditAbortByLayerRef = useRef<Record<string, AbortController>>({})
    const [imageEditActionError, setImageEditActionError] = useState<string | null>(null)
    const [viewportScale, setViewportScale] = useState(1)
    const canvasContainerRef = useRef<HTMLDivElement>(null)
    const stageRef = useRef<HTMLDivElement>(null)
    const dragRef = useRef<DragState | null>(null)

    const layersForCanvas = useMemo(
        () =>
            [...document.layers].sort((a, b) => {
                const za = Number(a.z)
                const zb = Number(b.z)
                const d = (Number.isFinite(za) ? za : 0) - (Number.isFinite(zb) ? zb : 0)
                return d !== 0 ? d : a.id.localeCompare(b.id)
            }),
        [document.layers]
    )
    const layersForPanel = useMemo(() => sortLayersPanelFrontAtTop(document.layers), [document.layers])

    const selectedLayer = useMemo(
        () => document.layers.find((l) => l.id === selectedLayerId) ?? null,
        [document.layers, selectedLayerId]
    )

    const selectedImageLayerWithAsset = useMemo(() => {
        if (!selectedLayerId) {
            return null
        }
        const l = document.layers.find((x) => x.id === selectedLayerId)
        return l && isImageLayer(l) && l.assetId ? l : null
    }, [document.layers, selectedLayerId])

    useEffect(() => {
        if (!selectedImageLayerWithAsset?.assetId) {
            return
        }
        const layerId = selectedImageLayerWithAsset.id
        const assetId = selectedImageLayerWithAsset.assetId
        let cancelled = false
        fetchAssetVersions(assetId)
            .then((res) => {
                if (!cancelled) {
                    setLayerVersions((prev) => ({ ...prev, [layerId]: res.versions }))
                }
            })
            .catch((err) => {
                console.error(err)
            })
        return () => {
            cancelled = true
        }
    }, [selectedImageLayerWithAsset?.id, selectedImageLayerWithAsset?.assetId])

    const handleSwitchAssetVersion = useCallback((layerId: string, version: EditorAssetVersionRow) => {
        setDocument((prev) => ({
            ...prev,
            layers: prev.layers.map((layer) => {
                if (layer.id !== layerId || !isImageLayer(layer)) {
                    return layer
                }
                return {
                    ...layer,
                    src: version.url,
                    assetVersionId: version.id,
                }
            }),
        }))
    }, [])

    const selectedImageLayerVersionStrip = useMemo(() => {
        if (!selectedImageLayerWithAsset) {
            return []
        }
        const raw = layerVersions[selectedImageLayerWithAsset.id] ?? []
        return orderAssetVersionsForStrip(raw)
    }, [selectedImageLayerWithAsset, layerVersions])

    const selectedImageLayerVersionStripMin = useMemo(() => {
        const nums = selectedImageLayerVersionStrip
            .map((v) => v.version_number)
            .filter((n): n is number => typeof n === 'number' && n > 0)
        return nums.length > 0 ? Math.min(...nums) : 1
    }, [selectedImageLayerVersionStrip])

    const generativeBrandScore = useMemo(() => {
        if (!selectedLayer || !isGenerativeImageLayer(selectedLayer)) {
            return null
        }
        return estimateBrandScore(selectedLayer.prompt, brandContext)
    }, [selectedLayer, brandContext])

    const generativePromptPreview = useMemo(() => {
        if (!selectedLayer || !isGenerativeImageLayer(selectedLayer)) {
            return null
        }
        return buildPromptPreviewSummary(selectedLayer.prompt)
    }, [selectedLayer])

    const variationResultsKey =
        selectedLayer && isGenerativeImageLayer(selectedLayer) && selectedLayer.variationResults
            ? selectedLayer.variationResults.join('|')
            : ''

    /** Block AI actions only when usage is known and exhausted, or usage fetch failed — not while still loading (null). */
    const imageEditUsageBlocked = useMemo(
        () =>
            genUsageError !== null ||
            (genUsage !== null && !canGenerateFromUsage(genUsage)),
        [genUsage, genUsageError]
    )

    const damAssetById = useMemo(() => {
        const m = new Map<string, DamPickerAsset>()
        for (const a of damAssets) {
            m.set(a.id, a)
        }
        for (const a of extraDamAssets) {
            m.set(a.id, a)
        }
        return m
    }, [damAssets, extraDamAssets])

    const pickerCategoriesForScope = useMemo(() => {
        return pickerCategories.filter((c) =>
            pickerScope === 'library' ? c.asset_type === 'asset' : c.asset_type === 'deliverable'
        )
    }, [pickerCategories, pickerScope])

    const dirty = useMemo(
        () => JSON.stringify(document) !== lastSavedSerialized,
        [document, lastSavedSerialized]
    )

    /** True when there is something worth warning about before navigate / reset (not a fresh empty canvas). */
    const discardRequiresConfirmation = useMemo(
        () => dirty && !isBlankUnsavedCanvas(document, compositionId !== null),
        [dirty, document, compositionId]
    )

    const refreshVersions = useCallback(async () => {
        const id = compositionIdRef.current
        if (!id) {
            setVersions([])
            return
        }
        setVersionsLoading(true)
        try {
            const v = await fetchCompositionVersions(id)
            setVersions(v)
        } catch {
            setVersions([])
        } finally {
            setVersionsLoading(false)
        }
    }, [])

    const snapshotCheckpoint = useCallback(
        async (label: string, doc: DocumentModel) => {
            const id = compositionIdRef.current
            if (!id) {
                return
            }
            let thumb: string | null = null
            if (stageRef.current) {
                thumb = await captureCompositionThumbnailBase64(stageRef.current, doc)
            }
            try {
                await postCompositionVersion(id, doc, label, thumb)
                await refreshVersions()
            } catch {
                /* optional checkpoints */
            }
        },
        [refreshVersions]
    )

    const pickerHighlightAssetId = useMemo(() => {
        if (!pickerOpen || pickerMode !== 'replace' || !replaceLayerId) {
            return undefined
        }
        const layer = document.layers.find((l) => l.id === replaceLayerId)
        if (!layer || !isImageLayer(layer)) {
            return undefined
        }
        return layer.assetId
    }, [pickerOpen, pickerMode, replaceLayerId, document.layers])

    const compareLeftMeta = useMemo(
        () => versions.find((v) => v.id === compareLeftId),
        [versions, compareLeftId]
    )
    const compareRightMeta = useMemo(
        () => versions.find((v) => v.id === compareRightId),
        [versions, compareRightId]
    )

    useEffect(() => {
        if (!suggestionToast) {
            return
        }
        const t = window.setTimeout(() => setSuggestionToast(null), 4200)
        return () => window.clearTimeout(t)
    }, [suggestionToast])

    useEffect(() => {
        if (!activityToast) {
            return
        }
        const t = window.setTimeout(() => setActivityToast(null), 3200)
        return () => window.clearTimeout(t)
    }, [activityToast])

    useEffect(() => {
        const id = compositionIdFromUrl
        if (!id) {
            setCompositionBootstrapping(false)
            return
        }
        let cancelled = false
        setCompositionBootstrapping(true)
        setCompositionLoadError(null)
        getComposition(id)
            .then((c) => {
                if (cancelled) {
                    return
                }
                const doc = parseDocumentFromApi(c.document)
                flushSync(() => {
                    setCompositionId(c.id)
                    setCompositionName(c.name)
                    setDocument(doc)
                    setLastSavedSerialized(JSON.stringify(doc))
                    setSelectedLayerId(null)
                    setEditingTextLayerId(null)
                })
                void refreshVersions()
            })
            .catch(() => {
                if (!cancelled) {
                    setCompositionLoadError('Could not load composition.')
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setCompositionBootstrapping(false)
                }
            })
        return () => {
            cancelled = true
        }
    }, [compositionIdFromUrl, refreshVersions])

    useEffect(() => {
        if (!compositionId || !dirty) {
            return
        }
        const t = window.setTimeout(() => {
            void (async () => {
                try {
                    setSaveState('saving')
                    setSaveError(null)
                    const doc = documentRef.current
                    const name = compositionName.trim() || defaultCompositionName(doc)
                    let thumb: string | null = null
                    if (stageRef.current) {
                        thumb = await captureCompositionThumbnailBase64(stageRef.current, doc)
                    }
                    await putComposition(compositionId, doc, {
                        name,
                        versionLabel: null,
                        createVersion: false,
                        thumbnailPngBase64: thumb,
                    })
                    setLastSavedSerialized(JSON.stringify(documentRef.current))
                    setSaveState('saved')
                } catch (e) {
                    setSaveState('error')
                    setSaveError(handleAIError(e))
                }
            })()
        }, AUTOSAVE_MS)
        return () => window.clearTimeout(t)
    }, [document, compositionId, dirty, compositionName])

    useEffect(() => {
        setVariationHoverIdx(null)
        setVariationPressedIdx(null)
    }, [selectedLayerId, variationResultsKey])

    useEffect(() => {
        setCopyAssistSuggestions([])
        setCopyAssistScore(null)
        setCopyAssistError(null)
        setCopyAssistHoverIdx(null)
    }, [selectedLayerId])

    useEffect(
        () => () => {
            copyAbortRef.current?.abort()
        },
        []
    )

    useEffect(() => {
        fetchGenerateImageUsage()
            .then((u) => {
                setGenUsage(u)
                setGenUsageError(null)
            })
            .catch((e) =>
                setGenUsageError(e instanceof Error ? e.message : 'Could not load usage')
            )
    }, [])

    useEffect(() => {
        fetchEditorBrandContext()
            .then((ctx) => setBrandContext(ctx))
            .catch(() => setBrandContext(null))
    }, [activeBrandId])

    useEffect(() => {
        const typo = brandContext?.typography
        const hasSheets = (typo?.stylesheet_urls?.length ?? 0) > 0 || (typo?.font_urls?.length ?? 0) > 0
        const hasFaces = (typo?.font_face_sources?.length ?? 0) > 0
        if (!typo || (!hasSheets && !hasFaces)) {
            setBrandFontsLoading(false)
            return
        }
        let cancelled = false
        setBrandFontsLoading(true)
        void loadEditorBrandTypography(typo).then(() => {
            if (!cancelled) {
                setBrandFontsLoading(false)
                setBrandFontsEpoch((n) => n + 1)
            }
        })
        return () => {
            cancelled = true
        }
    }, [brandContext])

    useEffect(() => {
        setDamAssets([])
        setExtraDamAssets([])
    }, [activeBrandId])

    useEffect(() => {
        const needsThumb = document.layers.some(
            (l) => l.type === 'generative_image' && (l.referenceAssetIds?.length ?? 0) > 0
        )
        if (!needsThumb || damAssets.length > 0) {
            return
        }
        fetchEditorAssets(50)
            .then((r) => setDamAssets(r.assets))
            .catch(() => {})
    }, [document.layers, damAssets.length])

    useEffect(() => {
        const needed = new Set<string>()
        for (const l of document.layers) {
            if (l.type === 'generative_image' && l.referenceAssetIds?.length) {
                for (const id of l.referenceAssetIds) {
                    needed.add(id)
                }
            }
        }
        const missing: string[] = []
        for (const id of needed) {
            if (!damAssetById.has(id)) {
                missing.push(id)
            }
        }
        if (missing.length === 0) {
            return
        }
        let cancelled = false
        Promise.all(
            missing.map((id) =>
                fetchEditorAssetById(id).then((a) => (a ? a : null)).catch(() => null)
            )
        ).then((results) => {
            if (cancelled) {
                return
            }
            const got = results.filter((x): x is DamPickerAsset => x != null)
            if (got.length === 0) {
                return
            }
            setExtraDamAssets((prev) => {
                const have = new Set(prev.map((a) => a.id))
                const add = got.filter((a) => !have.has(a.id))
                return add.length ? [...prev, ...add] : prev
            })
        })
        return () => {
            cancelled = true
        }
    }, [document.layers, damAssetById])

    useEffect(() => {
        setGenActionError(null)
    }, [selectedLayerId])

    useEffect(() => {
        setImageLoadFailedByLayerId((prev) => {
            const next = { ...prev }
            let changed = false
            for (const id of Object.keys(next)) {
                const layer = document.layers.find((l) => l.id === id)
                if (!layer || !isImageLayer(layer)) {
                    delete next[id]
                    changed = true
                } else if (layer.src !== PLACEHOLDER_IMAGE_SRC) {
                    delete next[id]
                    changed = true
                }
            }
            return changed ? next : prev
        })
    }, [document.layers])

    useLayoutEffect(() => {
        const el = canvasContainerRef.current
        if (!el) {
            return
        }
        const measure = () => {
            const cr = el.getBoundingClientRect()
            const pad = 40
            const availW = Math.max(100, cr.width - pad * 2)
            const availH = Math.max(100, cr.height - pad * 2)
            const s = Math.min(availW / document.width, availH / document.height, 1)
            setViewportScale(s > 0 ? s : 1)
        }
        measure()
        const ro = new ResizeObserver(measure)
        ro.observe(el)
        return () => ro.disconnect()
    }, [document.width, document.height])

    /**
     * Map pointer coordinates to document space. Uses the stage’s actual rendered size from
     * getBoundingClientRect() vs {@link documentRef} dimensions so we stay correct even if
     * `viewportScale` state lags the real CSS transform or subpixels differ.
     */
    const clientToDoc = useCallback((clientX: number, clientY: number) => {
        const stage = stageRef.current
        if (!stage) {
            return { x: 0, y: 0 }
        }
        const rect = stage.getBoundingClientRect()
        const doc = documentRef.current
        const dw = Math.max(1, doc.width)
        const dh = Math.max(1, doc.height)
        const rw = rect.width
        const rh = rect.height
        if (rw <= 0 || rh <= 0) {
            return { x: 0, y: 0 }
        }
        return {
            x: ((clientX - rect.left) / rw) * dw,
            y: ((clientY - rect.top) / rh) * dh,
        }
    }, [])

    const updateLayer = useCallback((layerId: string, fn: (l: Layer) => Layer) => {
        setDocument((prev) => ({
            ...prev,
            layers: prev.layers.map((l) => (l.id === layerId ? fn(l) : l)),
            updated_at: new Date().toISOString(),
        }))
    }, [])

    const runCopyAssist = useCallback(
        async (operation: GenerateCopyOperation) => {
            const layerId = selectedLayerId
            if (!layerId) {
                return
            }
            const layer = documentRef.current.layers.find((l) => l.id === layerId)
            if (!layer || !isTextLayer(layer) || layer.locked) {
                return
            }

            const now = Date.now()
            if (now - copyAssistLastAtRef.current < COPY_ASSIST_DEBOUNCE_MS) {
                return
            }
            copyAssistLastAtRef.current = now

            copyAbortRef.current?.abort()
            const ac = new AbortController()
            copyAbortRef.current = ac
            copyAssistLastOpRef.current = operation

            setCopyAssistLoadingId(layerId)
            setCopyAssistError(null)
            try {
                const doc = documentRef.current
                const intent = detectTextIntent(layer)
                const visual = buildEditorVisualContext(doc, brandContext, layer)
                const rawInput = layer.content
                const input =
                    operation === 'generate' && !rawInput.trim() ? '' : rawInput.slice(0, 8000)

                const res = await withAIConcurrency(() =>
                    postGenerateCopy(
                        {
                            input,
                            intent,
                            operation,
                            brand_context: serializeBrandForCopy(brandContext),
                            visual_context: visual,
                            text_box_width: Math.round(layer.transform.width),
                        },
                        ac.signal
                    )
                )

                updateLayer(layerId, (l) => {
                    if (!isTextLayer(l)) {
                        return l
                    }
                    const prevStack = [...(l.previousText ?? []), l.content].slice(-10)
                    return {
                        ...l,
                        content: res.text,
                        previousText: prevStack,
                    }
                })
                setCopyAssistSuggestions(res.suggestions.filter((s) => s.text.trim() !== ''))
                setCopyAssistScore(res.copy_score)
                setActivityToast('Copy updated')
                trackEvent('generate_copy', { operation })
                window.setTimeout(() => {
                    void snapshotCheckpoint('Edited text', documentRef.current)
                }, 0)
            } catch (e) {
                if (e instanceof DOMException && e.name === 'AbortError') {
                    return
                }
                setCopyAssistError(handleAIError(e))
            } finally {
                if (copyAbortRef.current === ac) {
                    copyAbortRef.current = null
                }
                setCopyAssistLoadingId((id) => (id === layerId ? null : id))
            }
        },
        [selectedLayerId, brandContext, updateLayer, snapshotCheckpoint]
    )

    const revertLastCopy = useCallback(() => {
        if (!selectedLayerId) {
            return
        }
        updateLayer(selectedLayerId, (l) => {
            if (!isTextLayer(l) || !l.previousText?.length) {
                return l
            }
            const stack = [...l.previousText]
            const prev = stack.pop()
            if (prev === undefined) {
                return l
            }
            return { ...l, content: prev, previousText: stack }
        })
    }, [selectedLayerId, updateLayer])

    const replaceWithCopySuggestion = useCallback(
        (layerId: string, text: string) => {
            updateLayer(layerId, (l) => {
                if (!isTextLayer(l)) {
                    return l
                }
                const prevStack = [...(l.previousText ?? []), l.content].slice(-10)
                return {
                    ...l,
                    content: text,
                    previousText: prevStack,
                }
            })
        },
        [updateLayer]
    )

    const insertCopySuggestionBelow = useCallback(
        (layerId: string, text: string) => {
            updateLayer(layerId, (l) => {
                if (!isTextLayer(l)) {
                    return l
                }
                const prevStack = [...(l.previousText ?? []), l.content].slice(-10)
                const base = l.content.trim()
                const next = base ? `${base}\n\n${text}` : text
                return {
                    ...l,
                    content: next,
                    previousText: prevStack,
                }
            })
        },
        [updateLayer]
    )

    const performManualSave = useCallback(async () => {
        setSaveState('saving')
        setSaveError(null)
        try {
            const docSnapshot = documentRef.current
            let nameToSave = compositionName.trim() || defaultCompositionName(docSnapshot)

            if (!compositionId) {
                nameToSave = UNTITLED_DRAFT_NAME
            } else if (isUntitledDraftName(compositionName)) {
                const suggested = defaultCompositionName(docSnapshot)
                const entered = window.prompt('Name this composition', suggested)
                if (entered === null) {
                    setSaveState('idle')
                    return
                }
                nameToSave = entered.trim() || suggested
                setCompositionName(nameToSave)
            }

            let thumb: string | null = null
            if (stageRef.current) {
                thumb = await captureCompositionThumbnailBase64(stageRef.current, documentRef.current)
            }
            if (!compositionId) {
                const c = await postComposition(nameToSave, docSnapshot, { thumbnailPngBase64: thumb })
                const doc = parseDocumentFromApi(c.document)
                setCompositionId(c.id)
                setCompositionName(c.name?.trim() ? c.name : UNTITLED_DRAFT_NAME)
                setDocument(doc)
                setLastSavedSerialized(JSON.stringify(doc))
                replaceUrlCompositionParam(c.id)
            } else {
                await putComposition(compositionId, docSnapshot, {
                    name: nameToSave,
                    versionLabel: null,
                    createVersion: true,
                    thumbnailPngBase64: thumb,
                })
                setLastSavedSerialized(JSON.stringify(documentRef.current))
            }
            setSaveState('saved')
            setActivityToast('Version saved')
            trackEvent('save_composition', { composition_id: compositionId ?? 'new' })
            await refreshVersions()
        } catch (e) {
            setSaveState('error')
            setSaveError(handleAIError(e))
        }
    }, [compositionId, compositionName, refreshVersions])

    const handleSave = useCallback(() => {
        void performManualSave()
    }, [performManualSave])

    const loadVersionIntoEditor = useCallback(
        async (versionId: string) => {
            if (!compositionId) {
                return
            }
            if (discardRequiresConfirmation && !window.confirm('Discard unsaved changes and load this version?')) {
                return
            }
            const v = await getCompositionVersion(compositionId, versionId)
            if (!v) {
                window.alert('Version not found.')
                return
            }
            const doc = parseDocumentFromApi(v.document)
            flushSync(() => {
                setDocument(doc)
                setSelectedLayerId(null)
                setEditingTextLayerId(null)
            })
            setLastSavedSerialized(JSON.stringify(doc))
            setHistoryOpen(false)
        },
        [compositionId, discardRequiresConfirmation]
    )

    const duplicateVersionAsNewComposition = useCallback(
        async (versionId: string) => {
            if (!compositionId) {
                return
            }
            const v = await getCompositionVersion(compositionId, versionId)
            if (!v) {
                window.alert('Version not found.')
                return
            }
            const doc = parseDocumentFromApi(v.document)
            const name = `${compositionName.trim() || defaultCompositionName(doc)} (copy)`
            try {
                const c = await postCompositionFromDocument(name, doc)
                const d = parseDocumentFromApi(c.document)
                setCompositionId(c.id)
                setCompositionName(c.name)
                setDocument(d)
                setLastSavedSerialized(JSON.stringify(d))
                replaceUrlCompositionParam(c.id)
                await refreshVersions()
                setHistoryOpen(false)
            } catch (e) {
                window.alert(e instanceof Error ? e.message : 'Could not duplicate')
            }
        },
        [compositionId, compositionName, refreshVersions]
    )

    const duplicateWholeComposition = useCallback(async () => {
        if (!compositionId) {
            return
        }
        try {
            const c = await duplicateCompositionApi(compositionId)
            const d = parseDocumentFromApi(c.document)
            setCompositionId(c.id)
            setCompositionName(c.name)
            setDocument(d)
            setLastSavedSerialized(JSON.stringify(d))
            replaceUrlCompositionParam(c.id)
            setSelectedLayerId(null)
            setEditingTextLayerId(null)
            await refreshVersions()
        } catch (e) {
            setSaveError(e instanceof Error ? e.message : 'Duplicate failed')
        }
    }, [compositionId, refreshVersions])

    const runCompareCapture = useCallback(async () => {
        if (!compositionId || !compareLeftId || !compareRightId || compareLeftId === compareRightId) {
            return
        }
        setCompareBusy(true)
        setCompareUrls(null)
        documentBeforeCompareRef.current = documentRef.current
        try {
            const [va, vb] = await Promise.all([
                getCompositionVersion(compositionId, compareLeftId),
                getCompositionVersion(compositionId, compareRightId),
            ])
            if (!va || !vb) {
                window.alert('Could not load one or both versions.')
                return
            }
            const docA = parseDocumentFromApi(va.document)
            const docB = parseDocumentFromApi(vb.document)
            const capture = async (doc: DocumentModel) => {
                flushSync(() => setDocument(doc))
                await new Promise<void>((r) =>
                    requestAnimationFrame(() => requestAnimationFrame(() => r()))
                )
                const node = stageRef.current
                if (!node) {
                    throw new Error('Stage not ready')
                }
                return toPng(node, {
                    cacheBust: true,
                    skipFonts: true,
                    pixelRatio: 1,
                    backgroundColor: '#ffffff',
                    width: doc.width,
                    height: doc.height,
                    fetchRequestInit: editorHtmlToImageFetchRequestInit,
                    style: {
                        transform: 'none',
                        width: `${doc.width}px`,
                        height: `${doc.height}px`,
                    },
                })
            }
            const urlA = await capture(docA)
            const urlB = await capture(docB)
            setCompareUrls([urlA, urlB])
        } catch (e) {
            window.alert(e instanceof Error ? e.message : 'Compare failed')
        } finally {
            const prev = documentBeforeCompareRef.current
            if (prev) {
                flushSync(() => setDocument(prev))
            }
            documentBeforeCompareRef.current = null
            setCompareBusy(false)
        }
    }, [compositionId, compareLeftId, compareRightId])

    const downloadExport = useCallback(
        async (kind: 'png' | 'jpeg' | 'json') => {
            const base = (compositionName.trim() || defaultCompositionName(document)).replace(
                /[^a-z0-9-_]+/gi,
                '_'
            )
            const stamp = new Date().toISOString().slice(0, 19).replace(/T/, '_').replace(/:/g, '-')
            const fileStem = `${base || 'composition'}-${stamp}`
            if (kind === 'json') {
                const raw = JSON.stringify(document, null, 2)
                const blob = new Blob([raw], { type: 'application/json' })
                const a = window.document.createElement('a')
                a.href = URL.createObjectURL(blob)
                a.download = `${fileStem}.json`
                a.click()
                URL.revokeObjectURL(a.href)
                return
            }
            const node = stageRef.current
            if (!node) {
                return
            }
            if (document.layers.length === 0) {
                setActivityToast('Exported empty canvas')
            }
            await waitForImagesToLoad(node)
            const opts = {
                cacheBust: true,
                skipFonts: true,
                pixelRatio: 1,
                backgroundColor: '#ffffff',
                width: document.width,
                height: document.height,
                fetchRequestInit: editorHtmlToImageFetchRequestInit,
                style: {
                    transform: 'none',
                    width: `${document.width}px`,
                    height: `${document.height}px`,
                },
            } as const
            const dataUrl =
                kind === 'png'
                    ? await toPng(node, opts)
                    : await toJpeg(node, { ...opts, quality: 0.92 })
            const a = window.document.createElement('a')
            a.href = dataUrl
            a.download = `${fileStem}.${kind === 'png' ? 'png' : 'jpg'}`
            a.click()
            setActivityToast(kind === 'json' ? 'Document exported' : 'Image exported')
        },
        [document, compositionName]
    )

    const startNewComposition = useCallback((opts?: { skipDiscardConfirm?: boolean }) => {
        if (
            !opts?.skipDiscardConfirm &&
            discardRequiresConfirmation &&
            !window.confirm('Discard unsaved changes and start a new composition?')
        ) {
            return
        }
        const fresh = createInitialDocument()
        flushSync(() => {
            setCompositionId(null)
            setCompositionName(defaultCompositionName(fresh))
            setDocument(fresh)
            setLastSavedSerialized(JSON.stringify(fresh))
            setSelectedLayerId(null)
            setEditingTextLayerId(null)
            setVersions([])
            setSaveState('idle')
            setSaveError(null)
            setPromoteOk(false)
            setPromoteError(null)
            setCompareUrls(null)
            setCompareOpen(false)
            setHistoryOpen(false)
            setOpenCompositionPicker(false)
            setCompositionLoadError(null)
            setCompareSlider(50)
            setUiMode('edit')
        })
        replaceUrlCompositionParam(null)
    }, [discardRequiresConfirmation])

    const deleteCompositionById = useCallback(
        async (targetId: string) => {
            if (
                !window.confirm(
                    'Delete this composition for everyone in this brand? The saved canvas and its version history will be removed. Images in your library are not deleted.'
                )
            ) {
                return
            }
            setCompositionDeleteBusy(true)
            try {
                await deleteCompositionApi(targetId)
                setActivityToast('Composition deleted')
                if (compositionId === targetId) {
                    startNewComposition({ skipDiscardConfirm: true })
                }
                void fetchCompositionSummaries()
                    .then((rows) => {
                        setCompositionSummaries(rows)
                    })
                    .catch(() => {
                        /* list refresh best-effort */
                    })
            } catch (e: unknown) {
                setActivityToast(handleAIError(e))
            } finally {
                setCompositionDeleteBusy(false)
            }
        },
        [compositionId, startNewComposition]
    )

    const openCompositionPickerAndLoad = useCallback(() => {
        setOpenCompositionPicker(true)
        setCompositionListLoading(true)
        setCompositionListError(null)
        void fetchCompositionSummaries()
            .then((rows) => {
                setCompositionSummaries(rows)
            })
            .catch((e: unknown) => {
                setCompositionSummaries([])
                setCompositionListError(handleAIError(e))
            })
            .finally(() => {
                setCompositionListLoading(false)
            })
    }, [])

    const navigateToComposition = useCallback(
        (id: string) => {
            if (discardRequiresConfirmation && !window.confirm('Discard unsaved changes and open this composition?')) {
                return
            }
            setOpenCompositionPicker(false)
            router.visit(`/app/generative?composition=${encodeURIComponent(id)}`)
        },
        [discardRequiresConfirmation]
    )

    useEffect(() => {
        if (historyOpen && compositionId) {
            void refreshVersions()
        }
    }, [historyOpen, compositionId, refreshVersions])

    useEffect(() => {
        if (compareOpen && versions.length >= 2 && !compareLeftId && !compareRightId) {
            setCompareLeftId(versions[0]?.id ?? null)
            setCompareRightId(versions[1]?.id ?? null)
        }
        if (compareOpen) {
            setCompareSlider(50)
        }
    }, [compareOpen, versions, compareLeftId, compareRightId])

    const duplicateSelectedLayer = useCallback(() => {
        if (!selectedLayerId) {
            return
        }
        setDocument((prev) => {
            const result = duplicateLayerInDoc(prev, selectedLayerId)
            if (!result) {
                return prev
            }
            queueMicrotask(() => {
                setSelectedLayerId(result.newId)
                setEditingTextLayerId(null)
            })
            return result.doc
        })
    }, [selectedLayerId])

    const deleteSelectedLayer = useCallback(() => {
        if (!selectedLayerId) {
            return
        }
        setDocument((d) => deleteLayerFromDoc(d, selectedLayerId))
        setSelectedLayerId(null)
        setEditingTextLayerId(null)
    }, [selectedLayerId])

    const bringSelectedToFront = useCallback(() => {
        if (!selectedLayerId) {
            return
        }
        setDocument((d) => bringLayerToFront(d, selectedLayerId))
    }, [selectedLayerId])

    const sendSelectedToBack = useCallback(() => {
        if (!selectedLayerId) {
            return
        }
        setDocument((d) => sendLayerToBack(d, selectedLayerId))
    }, [selectedLayerId])

    const onLayerPanelDragStart = useCallback((e: React.DragEvent, layerId: string) => {
        e.dataTransfer.setData('text/plain', layerId)
        e.dataTransfer.effectAllowed = 'move'
        setLayerDragId(layerId)
    }, [])

    const onLayerPanelDragOver = useCallback((e: React.DragEvent) => {
        e.preventDefault()
        e.dataTransfer.dropEffect = 'move'
    }, [])

    const onLayerPanelDrop = useCallback((e: React.DragEvent, targetLayerId: string) => {
        e.preventDefault()
        const sourceId = e.dataTransfer.getData('text/plain')
        if (!sourceId) {
            return
        }
        setDocument((prev) => {
            const panel = sortLayersPanelFrontAtTop(prev.layers)
            const fromIdx = panel.findIndex((l) => l.id === sourceId)
            const targetIdx = panel.findIndex((l) => l.id === targetLayerId)
            if (fromIdx < 0 || targetIdx < 0) {
                return prev
            }
            const el = e.currentTarget as HTMLElement
            const rect = el.getBoundingClientRect()
            const insertBefore = e.clientY < rect.top + rect.height / 2
            let insertPos = insertBefore ? targetIdx : targetIdx + 1
            const next = [...panel]
            const [moved] = next.splice(fromIdx, 1)
            if (fromIdx < insertPos) {
                insertPos -= 1
            }
            next.splice(insertPos, 0, moved)
            return {
                ...prev,
                layers: applyPanelOrderToZ(next),
                updated_at: new Date().toISOString(),
            }
        })
    }, [])

    const centerSelectedLayerInDocument = useCallback(() => {
        if (!selectedLayerId) {
            return
        }
        const doc = documentRef.current
        updateLayer(selectedLayerId, (l) => {
            if (l.locked) {
                return l
            }
            const w = Math.max(1, l.transform.width)
            const h = Math.max(1, l.transform.height)
            const { x, y } = centerLayerInDocument(doc, w, h)
            return {
                ...l,
                transform: { ...l.transform, x, y },
            }
        })
    }, [selectedLayerId, updateLayer])

    const setDocumentDimensions = useCallback((width: number, height: number) => {
        const w = Math.round(
            Math.min(DOCUMENT_DIMENSION_MAX, Math.max(DOCUMENT_DIMENSION_MIN, width))
        )
        const h = Math.round(
            Math.min(DOCUMENT_DIMENSION_MAX, Math.max(DOCUMENT_DIMENSION_MIN, height))
        )
        setDocument((prev) => ({
            ...prev,
            width: w,
            height: h,
            updated_at: new Date().toISOString(),
        }))
    }, [])

    useEffect(() => {
        if (!pickerOpen) {
            return
        }
        let cancelled = false
        setPickerCategoriesLoading(true)
        fetchEditorPublishCategories()
            .then((r) => {
                if (!cancelled) {
                    setPickerCategories(r.categories ?? [])
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setPickerCategories([])
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setPickerCategoriesLoading(false)
                }
            })
        return () => {
            cancelled = true
        }
    }, [pickerOpen])

    useEffect(() => {
        if (!pickerOpen) {
            return
        }
        setDamLoading(true)
        setDamError(null)
        const assetType = pickerScope === 'executions' ? 'deliverable' : 'asset'
        const categoryId =
            pickerCategoryFilterId === '' ? undefined : Math.floor(Number(pickerCategoryFilterId))
        fetchEditorAssets(80, {
            assetType,
            categoryId: categoryId !== undefined && categoryId > 0 ? categoryId : undefined,
        })
            .then((r) => setDamAssets(r.assets))
            .catch((e) => setDamError(e instanceof Error ? e.message : 'Failed to load assets'))
            .finally(() => setDamLoading(false))
    }, [pickerOpen, pickerScope, pickerCategoryFilterId])

    const openPickerForAddImage = useCallback(() => {
        setPickerMode('add')
        setReplaceLayerId(null)
        setReferencePickerLayerId(null)
        setReferenceSelectionIds([])
        setPickerOpen(true)
    }, [])

    const openPickerForReplaceImage = useCallback((layerId: string) => {
        setPickerMode('replace')
        setReplaceLayerId(layerId)
        setReferencePickerLayerId(null)
        setPickerOpen(true)
    }, [])

    const openReferencePicker = useCallback((layerId: string) => {
        const layer = document.layers.find((l) => l.id === layerId)
        if (!layer || !isGenerativeImageLayer(layer)) {
            return
        }
        setReferencePickerLayerId(layerId)
        setReferenceSelectionIds([...(layer.referenceAssetIds ?? [])].slice(0, MAX_REFERENCE_ASSETS))
        setPickerMode('references')
        setReplaceLayerId(null)
        setPickerOpen(true)
    }, [document.layers])

    const toggleReferenceAssetInPicker = useCallback((assetId: string) => {
        setReferenceSelectionIds((prev) => {
            if (prev.includes(assetId)) {
                return prev.filter((x) => x !== assetId)
            }
            if (prev.length >= MAX_REFERENCE_ASSETS) {
                return prev
            }
            return [...prev, assetId]
        })
    }, [])

    const applyReferencePicker = useCallback(() => {
        if (!referencePickerLayerId) {
            return
        }
        updateLayer(referencePickerLayerId, (l) => {
            if (!isGenerativeImageLayer(l)) {
                return l
            }
            return { ...l, referenceAssetIds: [...referenceSelectionIds] }
        })
        setPickerOpen(false)
        setPickerMode(null)
        setReferencePickerLayerId(null)
        setReferenceSelectionIds([])
    }, [referencePickerLayerId, referenceSelectionIds, updateLayer])

    const closeAssetPicker = useCallback(() => {
        setPickerOpen(false)
        setPickerMode(null)
        setReplaceLayerId(null)
        setReferencePickerLayerId(null)
        setReferenceSelectionIds([])
        setPickerScope('library')
        setPickerCategoryFilterId('')
    }, [])

    const removeReferenceAsset = useCallback(
        (layerId: string, assetId: string) => {
            updateLayer(layerId, (l) => {
                if (!isGenerativeImageLayer(l)) {
                    return l
                }
                const next = (l.referenceAssetIds ?? []).filter((id) => id !== assetId)
                return {
                    ...l,
                    referenceAssetIds: next.length > 0 ? next : undefined,
                }
            })
        },
        [updateLayer]
    )

    const handlePickDamAsset = useCallback(
        async (asset: DamPickerAsset) => {
            if (pickerMode === 'references') {
                return
            }
            const dims = await confirmDamAssetDimensions(asset)
            const enriched: DamPickerAsset = { ...asset, width: dims.width, height: dims.height }

            if (pickerMode === 'replace' && replaceLayerId) {
                const oldLayer = document.layers.find((l) => l.id === replaceLayerId)
                if (oldLayer && isImageLayer(oldLayer)) {
                    const ow =
                        oldLayer.naturalWidth && oldLayer.naturalWidth > 0
                            ? oldLayer.naturalWidth
                            : oldLayer.transform.width
                    const oh =
                        oldLayer.naturalHeight && oldLayer.naturalHeight > 0
                            ? oldLayer.naturalHeight
                            : oldLayer.transform.height
                    const nw = enriched.width && enriched.width > 0 ? enriched.width : dims.width
                    const nh = enriched.height && enriched.height > 0 ? enriched.height : dims.height
                    if (ow > 0 && oh > 0 && nw > 0 && nh > 0) {
                        const rOld = ow / oh
                        const rNew = nw / nh
                        const spread = Math.max(rOld, rNew) / Math.min(rOld, rNew)
                        if (spread > REPLACE_ASPECT_WARN_RATIO) {
                            console.warn('[AssetEditor] Replace image aspect ratio differs significantly', {
                                layerId: replaceLayerId,
                                oldAspect: rOld,
                                newAspect: rNew,
                                spread,
                            })
                        }
                    }
                }
                updateLayer(replaceLayerId, (l) => {
                    if (!isImageLayer(l)) {
                        return l
                    }
                    return {
                        ...l,
                        assetId: enriched.id,
                        src: enriched.file_url,
                        naturalWidth: enriched.width,
                        naturalHeight: enriched.height,
                        name: enriched.name || l.name,
                    }
                })
                setSelectedLayerId(replaceLayerId)
            } else {
                setDocument((prev) => {
                    const layer = createImageLayerFromDamAsset(nextZIndex(prev.layers), prev, enriched)
                    setSelectedLayerId(layer.id)
                    return {
                        ...prev,
                        layers: normalizeZ([...prev.layers, layer]),
                        updated_at: new Date().toISOString(),
                    }
                })
            }
            setPickerOpen(false)
            setPickerMode(null)
            setReplaceLayerId(null)
        },
        [pickerMode, replaceLayerId, updateLayer, document.layers]
    )

    const openPublishModal = useCallback(async () => {
        setPromoteError(null)
        setPublishTitle(buildPromotionAssetName(documentRef.current))
        setPublishDescription('')
        setPublishMetadataValues({})
        setPublishCollectionIds([])
        setPublishMetadataShowErrors(false)
        setPublishMetadataSchema(null)
        setPublishMetadataError(null)
        setPublishModalOpen(true)
        setPublishCategoriesLoading(true)
        setPublishCategoriesError(null)
        setPublishCollectionsLoading(true)
        try {
            const [res, collections] = await Promise.all([
                fetchEditorPublishCategories(),
                fetchEditorCollectionsForPublish().catch(() => []),
            ])
            setPublishCategories(res.categories)
            setPublishCollectionsList(collections)
            const def = res.default_category_id
            setPublishCategoryId(
                def != null && res.categories.some((c) => c.id === def) ? def : (res.categories[0]?.id ?? '')
            )
        } catch (e) {
            setPublishCategoriesError(handleAIError(e))
            setPublishCategories([])
            setPublishCategoryId('')
            setPublishCollectionsList([])
        } finally {
            setPublishCategoriesLoading(false)
            setPublishCollectionsLoading(false)
        }
    }, [])

    useEffect(() => {
        if (!publishModalOpen || publishCategoryId === '') {
            return
        }
        const ac = new AbortController()
        setPublishMetadataLoading(true)
        setPublishMetadataError(null)
        fetchEditorPublishMetadataSchema(publishCategoryId)
            .then((schema) => {
                if (ac.signal.aborted) {
                    return
                }
                setPublishMetadataSchema(schema)
            })
            .catch((e: unknown) => {
                if (ac.signal.aborted) {
                    return
                }
                setPublishMetadataSchema(null)
                setPublishMetadataError(handleAIError(e))
            })
            .finally(() => {
                if (!ac.signal.aborted) {
                    setPublishMetadataLoading(false)
                }
            })
        return () => ac.abort()
    }, [publishModalOpen, publishCategoryId])

    const runPublishToLibrary = useCallback(
        async (opts: {
            title: string
            categoryId: number
            description: string
            fieldMetadata: Record<string, unknown>
            collectionIds: number[]
        }) => {
            if (!stageRef.current) {
                return
            }
            setPromoteSaving(true)
            setPromoteError(null)
            setPromoteOk(false)

            const exportStagePng = async () => {
                const node = stageRef.current
                if (!node) {
                    throw new Error('Stage not ready')
                }
                const doc = documentRef.current
                await waitForImagesToLoad(node)
                return toPng(node, {
                    cacheBust: true,
                    skipFonts: true,
                    pixelRatio: 1,
                    backgroundColor: '#ffffff',
                    width: doc.width,
                    height: doc.height,
                    fetchRequestInit: editorHtmlToImageFetchRequestInit,
                    style: {
                        transform: 'none',
                        width: `${doc.width}px`,
                        height: `${doc.height}px`,
                    },
                })
            }

            try {
                let dataUrl: string
                try {
                    dataUrl = await exportStagePng()
                } catch (e) {
                    console.warn('Retry export...', e)
                    dataUrl = await exportStagePng()
                }
                const blob = await (await fetch(dataUrl)).blob()
                const name = opts.title.trim() || buildPromotionAssetName(documentRef.current)
                await promoteCompositionToAsset(blob, name, documentRef.current, {
                    categoryId: opts.categoryId,
                    description: opts.description.trim() || undefined,
                    fieldMetadata: opts.fieldMetadata,
                    collectionIds: opts.collectionIds,
                })
                void snapshotCheckpoint('Published to library', documentRef.current)
                setPromoteOk(true)
                setPublishModalOpen(false)
                window.setTimeout(() => setPromoteOk(false), 5000)
            } catch (e) {
                setPromoteError(handleAIError(e))
            } finally {
                setPromoteSaving(false)
            }
        },
        [snapshotCheckpoint]
    )

    const submitPublishModal = useCallback(() => {
        if (publishCategoryId === '') {
            setPromoteError('Select a category.')
            return
        }
        const groups = publishMetadataSchema?.groups
        const valuesForValidation: Record<string, unknown> = {
            ...publishMetadataValues,
            collection: publishCollectionIds,
        }
        if (groups && groups.length > 0 && !areAllRequiredFieldsSatisfied(groups, valuesForValidation)) {
            setPublishMetadataShowErrors(true)
            setPromoteError('Complete the required metadata fields.')
            return
        }
        void runPublishToLibrary({
            title: publishTitle,
            categoryId: publishCategoryId,
            description: publishDescription,
            fieldMetadata: publishMetadataValues,
            collectionIds: publishCollectionIds,
        })
    }, [
        publishTitle,
        publishCategoryId,
        publishDescription,
        publishMetadataSchema,
        publishMetadataValues,
        publishCollectionIds,
        runPublishToLibrary,
    ])

    const addGenerativeImageLayer = useCallback(() => {
        setDocument((prev) => {
            const layer = createDefaultGenerativeImageLayer(nextZIndex(prev.layers), prev)
            setSelectedLayerId(layer.id)
            return {
                ...prev,
                layers: normalizeZ([...prev.layers, layer]),
                updated_at: new Date().toISOString(),
            }
        })
    }, [])

    const runGenerativeGeneration = useCallback(
        async (layerId: string) => {
            const layer = documentRef.current.layers.find((l) => l.id === layerId)
            if (!layer || !isGenerativeImageLayer(layer) || layer.locked) {
                return
            }
            const scene = layer.prompt?.scene?.trim()
            if (!scene) {
                setGenActionError('Add a prompt first.')
                return
            }
            if (!canGenerateFromUsage(genUsage)) {
                setGenActionError("You've reached your monthly limit.")
                return
            }
            const now = Date.now()
            if (now - (lastGenerateAtByLayerRef.current[layerId] ?? 0) < GENERATE_DEBOUNCE_MS) {
                return
            }
            lastGenerateAtByLayerRef.current[layerId] = now
            setGenActionError(null)

            genAbortByLayerRef.current[layerId]?.abort()
            const ac = new AbortController()
            genAbortByLayerRef.current[layerId] = ac

            const seq = (genSeqRef.current[layerId] = (genSeqRef.current[layerId] ?? 0) + 1)

            updateLayer(layerId, (l) =>
                isGenerativeImageLayer(l)
                    ? {
                          ...l,
                          status: 'generating',
                          variationPending: false,
                          variationBatchSize: undefined,
                          variationResults: undefined,
                      }
                    : l
            )

            let finishedOk = false
            try {
                const rawKey = layer.model ?? 'default'
                const modelKey: GenerativeUiModelKey =
                    rawKey in MODEL_MAP ? (rawKey as GenerativeUiModelKey) : 'default'
                const structuredPrompt = { ...layer.prompt, scene }
                const applyBrand = layer.applyBrandDna !== false
                const refs = (layer.referenceAssetIds ?? []).slice(0, MAX_REFERENCE_ASSETS)
                const promptString = buildBrandAugmentedPrompt(structuredPrompt, applyBrand && brandContext ? brandContext : null, {
                    referenceCount: refs.length,
                })

                const res = await withAIConcurrency(() =>
                    generateEditorImage(
                        {
                            prompt: structuredPrompt,
                            prompt_string: promptString,
                            negative_prompt: layer.negativePrompt ?? [],
                            model_key: modelKey,
                            model: resolveModelConfig(modelKey),
                            size: generativeLayerToGenerationSize(layer),
                            brand_context: brandContext ?? undefined,
                            references: refs.length ? refs : undefined,
                            ...(layer.advancedModel && layer.modelOverride?.trim()
                                ? { model_override: layer.modelOverride.trim() }
                                : {}),
                            ...(compositionId ? { composition_id: compositionId } : {}),
                            ...(activeBrandId != null ? { brand_id: activeBrandId } : {}),
                            generative_layer_uuid: layerId,
                        },
                        { signal: ac.signal }
                    )
                )

                if (genSeqRef.current[layerId] !== seq) {
                    return
                }

                const resolvedLabel = res.model_display_name ?? res.resolved_model_key
                setDocument((prev) => {
                    const current = prev.layers.find((l) => l.id === layerId)
                    if (!current || current.type !== 'generative_image') {
                        return prev
                    }
                    const prevSrc = current.resultSrc
                    const history = [...(current.previousResults ?? [])]
                    if (prevSrc) {
                        history.push(prevSrc)
                    }
                    const previousResults = history.slice(-GENERATIVE_PREVIOUS_RESULTS_MAX)
                    const next: DocumentModel = {
                        ...prev,
                        layers: prev.layers.map((l) =>
                            l.id === layerId && l.type === 'generative_image'
                                ? {
                                      ...l,
                                      status: 'done' as const,
                                      resultSrc: res.image_url,
                                      ...(res.asset_id ? { resultAssetId: res.asset_id } : {}),
                                      previousResults,
                                      variationResults: undefined,
                                      variationPending: false,
                                      variationBatchSize: undefined,
                                      ...(resolvedLabel
                                          ? { lastResolvedModelDisplay: resolvedLabel }
                                          : {}),
                                  }
                                : l
                        ),
                        updated_at: new Date().toISOString(),
                    }
                    queueMicrotask(() => {
                        void snapshotCheckpoint('Generated image', next)
                    })
                    return next
                })
                finishedOk = true
                setActivityToast('Image generated')
                trackEvent('generate_image', { layer_id: layerId })
                try {
                    const fresh = await fetchGenerateImageUsage()
                    setGenUsage(fresh)
                } catch {
                    /* ignore — server already counted; UI may be slightly stale until reload */
                }
            } catch (e) {
                if (e instanceof DOMException && e.name === 'AbortError') {
                    if (genSeqRef.current[layerId] === seq) {
                        setDocument((prev) => ({
                            ...prev,
                            layers: prev.layers.map((l) =>
                                l.id === layerId && l.type === 'generative_image'
                                    ? { ...l, status: 'idle' }
                                    : l
                            ),
                            updated_at: new Date().toISOString(),
                        }))
                    }
                    return
                }
                if (genSeqRef.current[layerId] !== seq) {
                    return
                }
                setDocument((prev) => ({
                    ...prev,
                    layers: prev.layers.map((l) =>
                        l.id === layerId && l.type === 'generative_image'
                            ? { ...l, status: 'error' as const }
                            : l
                    ),
                    updated_at: new Date().toISOString(),
                }))
                setGenActionError(handleAIError(e))
                try {
                    const u = await fetchGenerateImageUsage()
                    setGenUsage(u)
                } catch {
                    /* ignore */
                }
            } finally {
                delete genAbortByLayerRef.current[layerId]
                if (finishedOk || genSeqRef.current[layerId] !== seq) {
                    return
                }
                setDocument((prev) => ({
                    ...prev,
                    layers: prev.layers.map((l) => {
                        if (l.id !== layerId || l.type !== 'generative_image') {
                            return l
                        }
                        if (l.status !== 'generating') {
                            return l
                        }
                        return {
                            ...l,
                            status: 'idle' as const,
                            variationPending: false,
                            variationBatchSize: undefined,
                        }
                    }),
                    updated_at: new Date().toISOString(),
                }))
            }
        },
        [genUsage, updateLayer, brandContext, snapshotCheckpoint, compositionId, activeBrandId]
    )

    const runImageLayerEdit = useCallback(
        async (layerId: string) => {
            const layer = documentRef.current.layers.find((l) => l.id === layerId)
            if (!layer || !isImageLayer(layer) || layer.locked) {
                return
            }
            const instruction = (layer.aiEdit?.prompt ?? '').trim()
            if (!instruction) {
                setImageEditActionError('Describe what to change first.')
                return
            }
            let usage = genUsage
            if (usage === null) {
                try {
                    usage = await fetchGenerateImageUsage()
                    setGenUsage(usage)
                    setGenUsageError(null)
                } catch {
                    setImageEditActionError('Could not verify your plan. Check your connection and try again.')
                    return
                }
            }
            if (!canGenerateFromUsage(usage)) {
                setImageEditActionError("You've reached your monthly limit.")
                return
            }
            if (!layer.src || layer.src === PLACEHOLDER_IMAGE_SRC) {
                setImageEditActionError('No image to edit.')
                return
            }

            setImageEditActionError(null)
            imageEditAbortByLayerRef.current[layerId]?.abort()
            const ac = new AbortController()
            imageEditAbortByLayerRef.current[layerId] = ac
            const seq = (imageEditSeqRef.current[layerId] = (imageEditSeqRef.current[layerId] ?? 0) + 1)

            updateLayer(layerId, (l) => {
                if (!isImageLayer(l)) {
                    return l
                }
                return {
                    ...l,
                    aiEdit: {
                        ...l.aiEdit,
                        prompt: instruction,
                        status: 'editing',
                    },
                }
            })

            let finishedOk = false
            try {
                const res = await withAIConcurrency(() =>
                    editImage(
                        {
                            ...(layer.assetId
                                ? { assetId: layer.assetId }
                                : { imageUrl: layer.src }),
                            instruction,
                            modelKey: normalizeEditModelKey(layer.aiEdit?.editModelKey),
                            brandContext: brandContext ?? undefined,
                            ...(compositionId ? { compositionId } : {}),
                            ...(activeBrandId != null ? { brandId: activeBrandId } : {}),
                            generativeLayerUuid: layerId,
                        },
                        { signal: ac.signal }
                    )
                )
                if (imageEditSeqRef.current[layerId] !== seq) {
                    return
                }
                setDocument((prev) => {
                    const cur = prev.layers.find((l) => l.id === layerId)
                    if (!cur || cur.type !== 'image') {
                        return prev
                    }
                    const prevSrc = cur.src
                    const hist = [...(cur.aiEdit?.previousResults ?? [])]
                    if (prevSrc && prevSrc !== PLACEHOLDER_IMAGE_SRC) {
                        hist.push(prevSrc)
                    }
                    const previousResults = hist.slice(-GENERATIVE_PREVIOUS_RESULTS_MAX)
                    const next: DocumentModel = {
                        ...prev,
                        layers: prev.layers.map((l) =>
                            l.id === layerId && l.type === 'image'
                                ? {
                                      ...l,
                                      src: res.image_url,
                                      ...(res.asset_id ? { assetId: res.asset_id } : {}),
                                      aiEdit: {
                                          ...l.aiEdit,
                                          prompt: instruction,
                                          status: 'done',
                                          resultSrc: res.image_url,
                                          previousResults,
                                      },
                                  }
                                : l
                        ),
                        updated_at: new Date().toISOString(),
                    }
                    queueMicrotask(() => {
                        void snapshotCheckpoint('AI image edit', next)
                    })
                    return next
                })
                const persistedAssetId = res.asset_id ?? layer.assetId ?? null
                if (persistedAssetId && imageEditSeqRef.current[layerId] === seq) {
                    try {
                        const verRes = await fetchAssetVersions(persistedAssetId)
                        if (imageEditSeqRef.current[layerId] === seq && verRes.versions.length > 0) {
                            setLayerVersions((prev) => ({ ...prev, [layerId]: verRes.versions }))
                            const newest = verRes.versions.reduce((best, row) =>
                                (row.version_number ?? 0) > (best.version_number ?? 0) ? row : best
                            )
                            setDocument((prev) => ({
                                ...prev,
                                layers: prev.layers.map((l) => {
                                    if (l.id !== layerId || l.type !== 'image') {
                                        return l
                                    }
                                    return {
                                        ...l,
                                        assetId: persistedAssetId,
                                        assetVersionId: newest.id,
                                        src: newest.url,
                                        aiEdit: l.aiEdit
                                            ? {
                                                  ...l.aiEdit,
                                                  prompt: instruction,
                                                  status: 'done',
                                                  resultSrc: res.image_url,
                                              }
                                            : undefined,
                                    }
                                }),
                                updated_at: new Date().toISOString(),
                            }))
                        }
                    } catch {
                        /* version strip refresh is optional */
                    }
                }
                finishedOk = true
                setActivityToast('Image updated')
                try {
                    const fresh = await fetchGenerateImageUsage()
                    setGenUsage(fresh)
                } catch {
                    /* ignore */
                }
            } catch (e) {
                if (e instanceof DOMException && e.name === 'AbortError') {
                    if (imageEditSeqRef.current[layerId] === seq) {
                        updateLayer(layerId, (l) => {
                            if (!isImageLayer(l)) {
                                return l
                            }
                            return {
                                ...l,
                                aiEdit: { ...l.aiEdit, status: 'idle' },
                            }
                        })
                    }
                    return
                }
                if (imageEditSeqRef.current[layerId] !== seq) {
                    return
                }
                updateLayer(layerId, (l) => {
                    if (!isImageLayer(l)) {
                        return l
                    }
                    return {
                        ...l,
                        aiEdit: { ...l.aiEdit, status: 'error' },
                    }
                })
                setImageEditActionError(handleAIError(e))
                try {
                    const u = await fetchGenerateImageUsage()
                    setGenUsage(u)
                } catch {
                    /* ignore */
                }
            } finally {
                delete imageEditAbortByLayerRef.current[layerId]
                if (finishedOk || imageEditSeqRef.current[layerId] !== seq) {
                    return
                }
                setDocument((prev) => ({
                    ...prev,
                    layers: prev.layers.map((l) => {
                        if (l.id !== layerId || l.type !== 'image') {
                            return l
                        }
                        if (l.aiEdit?.status !== 'editing') {
                            return l
                        }
                        return {
                            ...l,
                            aiEdit: { ...l.aiEdit, status: 'idle' },
                        }
                    }),
                    updated_at: new Date().toISOString(),
                }))
            }
        },
        [genUsage, updateLayer, brandContext, snapshotCheckpoint, compositionId, activeBrandId]
    )

    const runGenerativeVariations = useCallback(
        async (layerId: string) => {
            const layer = documentRef.current.layers.find((l) => l.id === layerId)
            if (!layer || !isGenerativeImageLayer(layer) || layer.locked) {
                return
            }
            const scene = layer.prompt?.scene?.trim()
            if (!scene) {
                setGenActionError('Add a prompt first.')
                return
            }
            if (!canGenerateFromUsage(genUsage)) {
                setGenActionError("You've reached your monthly limit.")
                return
            }
            const batchCount = variationRequestCount(genUsage)
            if (batchCount < 1) {
                setGenActionError('No generations remaining for variations.')
                return
            }
            if (layer.status === 'generating' || layer.variationPending) {
                return
            }
            const now = Date.now()
            if (now - (lastGenerateAtByLayerRef.current[layerId] ?? 0) < GENERATE_DEBOUNCE_MS) {
                return
            }
            lastGenerateAtByLayerRef.current[layerId] = now
            setGenActionError(null)

            genAbortByLayerRef.current[layerId]?.abort()
            const ac = new AbortController()
            genAbortByLayerRef.current[layerId] = ac

            const seq = (genSeqRef.current[layerId] = (genSeqRef.current[layerId] ?? 0) + 1)

            updateLayer(layerId, (l) =>
                isGenerativeImageLayer(l)
                    ? {
                          ...l,
                          status: 'generating',
                          variationPending: true,
                          variationBatchSize: batchCount,
                          variationResults: undefined,
                      }
                    : l
            )

            let variationsFinishedOk = false
            try {
                const rawKey = layer.model ?? 'default'
                const modelKey: GenerativeUiModelKey =
                    rawKey in MODEL_MAP ? (rawKey as GenerativeUiModelKey) : 'default'
                const structuredPrompt = { ...layer.prompt, scene }
                const applyBrand = layer.applyBrandDna !== false
                const refs = (layer.referenceAssetIds ?? []).slice(0, MAX_REFERENCE_ASSETS)
                const promptString = buildBrandAugmentedPrompt(structuredPrompt, applyBrand && brandContext ? brandContext : null, {
                    referenceCount: refs.length,
                })
                // Omit generative_layer_uuid: parallel variation requests would contend for the same versioned asset.
                const payload = {
                    prompt: structuredPrompt,
                    prompt_string: promptString,
                    negative_prompt: layer.negativePrompt ?? [],
                    model_key: modelKey,
                    model: resolveModelConfig(modelKey),
                    size: generativeLayerToGenerationSize(layer),
                    brand_context: brandContext ?? undefined,
                    references: refs.length ? refs : undefined,
                    ...(layer.advancedModel && layer.modelOverride?.trim()
                        ? { model_override: layer.modelOverride.trim() }
                        : {}),
                    ...(compositionId ? { composition_id: compositionId } : {}),
                    ...(activeBrandId != null ? { brand_id: activeBrandId } : {}),
                }
                const results = await Promise.all(
                    Array.from({ length: batchCount }, () =>
                        withAIConcurrency(() => generateEditorImage(payload, { signal: ac.signal }))
                    )
                )
                if (genSeqRef.current[layerId] !== seq) {
                    return
                }
                const urls = results.map((r) => r.image_url)
                const varResolved =
                    results[0]?.model_display_name ?? results[0]?.resolved_model_key
                setDocument((prev) => ({
                    ...prev,
                    layers: prev.layers.map((l) => {
                        if (l.id !== layerId || l.type !== 'generative_image') {
                            return l
                        }
                        const history = [...(l.previousResults ?? [])]
                        const add = (u: string) => {
                            if (u && !history.includes(u)) {
                                history.push(u)
                            }
                        }
                        if (l.resultSrc) {
                            add(l.resultSrc)
                        }
                        for (const u of urls) {
                            add(u)
                        }
                        const previousResults = history.slice(-GENERATIVE_PREVIOUS_RESULTS_MAX)
                        return {
                            ...l,
                            status: 'idle',
                            variationPending: false,
                            variationBatchSize: undefined,
                            variationResults: urls,
                            previousResults,
                            ...(varResolved ? { lastResolvedModelDisplay: varResolved } : {}),
                        }
                    }),
                    updated_at: new Date().toISOString(),
                }))
                variationsFinishedOk = true
                setActivityToast('Variations ready')
                trackEvent('generate_image', { layer_id: layerId, kind: 'variations' })
                try {
                    const fresh = await fetchGenerateImageUsage()
                    setGenUsage(fresh)
                } catch {
                    /* ignore */
                }
            } catch (e) {
                if (e instanceof DOMException && e.name === 'AbortError') {
                    if (genSeqRef.current[layerId] === seq) {
                        setDocument((prev) => ({
                            ...prev,
                            layers: prev.layers.map((l) =>
                                l.id === layerId && l.type === 'generative_image'
                                    ? {
                                          ...l,
                                          status: 'idle',
                                          variationPending: false,
                                          variationBatchSize: undefined,
                                      }
                                    : l
                            ),
                            updated_at: new Date().toISOString(),
                        }))
                    }
                    return
                }
                if (genSeqRef.current[layerId] !== seq) {
                    return
                }
                setDocument((prev) => ({
                    ...prev,
                    layers: prev.layers.map((l) =>
                        l.id === layerId && l.type === 'generative_image'
                            ? {
                                  ...l,
                                  status: 'error' as const,
                                  variationPending: false,
                                  variationBatchSize: undefined,
                                  variationResults: undefined,
                              }
                            : l
                    ),
                    updated_at: new Date().toISOString(),
                }))
                setGenActionError(handleAIError(e))
                try {
                    const u = await fetchGenerateImageUsage()
                    setGenUsage(u)
                } catch {
                    /* ignore */
                }
            } finally {
                delete genAbortByLayerRef.current[layerId]
                if (variationsFinishedOk || genSeqRef.current[layerId] !== seq) {
                    return
                }
                setDocument((prev) => ({
                    ...prev,
                    layers: prev.layers.map((l) => {
                        if (l.id !== layerId || l.type !== 'generative_image') {
                            return l
                        }
                        if (l.status !== 'generating' && !l.variationPending) {
                            return l
                        }
                        return {
                            ...l,
                            status: 'idle' as const,
                            variationPending: false,
                            variationBatchSize: undefined,
                            variationResults: undefined,
                        }
                    }),
                    updated_at: new Date().toISOString(),
                }))
            }
        },
        [genUsage, updateLayer, brandContext, compositionId, activeBrandId]
    )

    const applyVariationChoice = useCallback(
        (layerId: string, imageUrl: string) => {
            setDocument((prev) => ({
                ...prev,
                layers: prev.layers.map((l) => {
                    if (l.id !== layerId || !isGenerativeImageLayer(l)) {
                        return l
                    }
                    const prevSrc = l.resultSrc
                    const history = [...(l.previousResults ?? [])]
                    if (prevSrc && !l.variationResults?.includes(prevSrc)) {
                        history.push(prevSrc)
                    }
                    const previousResults = history.slice(-GENERATIVE_PREVIOUS_RESULTS_MAX)
                    return {
                        ...l,
                        resultSrc: imageUrl,
                        previousResults,
                        variationResults: undefined,
                        variationPending: false,
                        variationBatchSize: undefined,
                        status: 'done' as const,
                    }
                }),
                updated_at: new Date().toISOString(),
            }))
        },
        []
    )

    const discardVariationResults = useCallback((layerId: string) => {
        updateLayer(layerId, (l) =>
            isGenerativeImageLayer(l)
                ? { ...l, variationResults: undefined, variationPending: false, variationBatchSize: undefined }
                : l
        )
    }, [updateLayer])

    const insertPromptChip = useCallback(
        (chip: string) => {
            if (!selectedLayerId) {
                return
            }
            updateLayer(selectedLayerId, (l) => {
                if (!isGenerativeImageLayer(l)) {
                    return l
                }
                const s = (l.prompt.scene ?? '').trim()
                const next = s ? `${s}, ${chip}` : chip
                return { ...l, prompt: { ...l.prompt, scene: next } }
            })
        },
        [selectedLayerId, updateLayer]
    )

    const generateGuidedLayout = useCallback(async () => {
        let product: DamPickerAsset | null = null
        try {
            const r = await fetchEditorAssets(8)
            const first = r.assets[0]
            if (first) {
                const dims = await confirmDamAssetDimensions(first)
                product = { ...first, width: dims.width, height: dims.height }
            }
        } catch {
            /* optional product */
        }
        setDocument((prev) => {
            if (prev.layers.length > 0) {
                const ok = window.confirm(
                    'Replace all layers with a new starter layout? Current layers will be removed.'
                )
                if (!ok) {
                    return prev
                }
            }
            const newLayers = createGuidedLayoutLayers(prev, 0, {
                productAsset: product,
                brandContext,
            })
            const bg = newLayers.find((l) => l.type === 'generative_image')
            if (bg) {
                queueMicrotask(() => setSelectedLayerId(bg.id))
            }
            const next: DocumentModel = {
                ...prev,
                layers: normalizeZ(newLayers),
                updated_at: new Date().toISOString(),
            }
            queueMicrotask(() => {
                void snapshotCheckpoint('Generated layout', next)
            })
            return next
        })
    }, [brandContext, snapshotCheckpoint])

    const runRegenerateAllUnlockedGenerative = useCallback(async () => {
        const ids = documentRef.current.layers
            .filter(
                (l): l is GenerativeImageLayer =>
                    isGenerativeImageLayer(l) &&
                    !l.locked &&
                    !!(l.prompt?.scene?.trim()) &&
                    l.status !== 'generating' &&
                    !l.variationPending
            )
            .map((l) => l.id)
        await runWithConcurrency(ids, MAX_CONCURRENT_AI_REQUESTS, (id) => runGenerativeGeneration(id))
    }, [runGenerativeGeneration])

    const applySuggestionAction = useCallback(
        (kind: SmartSuggestionAction) => {
            if (!selectedLayerId) {
                return
            }
            const layer = documentRef.current.layers.find((l) => l.id === selectedLayerId)
            if (!layer || layer.locked) {
                return
            }

            if (isTextLayer(layer)) {
                if (kind === 'premium') {
                    void runCopyAssist('premium')
                    setSuggestionToast('Applied: Premium copy')
                    return
                }
                if (kind === 'tone' && brandContext?.tone?.[0]) {
                    void runCopyAssist('align_tone')
                    setSuggestionToast(SUGGESTION_TOAST.tone)
                    return
                }
                if (kind === 'minimal') {
                    void runCopyAssist('improve')
                    setSuggestionToast('Applied: Refined copy')
                    return
                }
                if (kind === 'contrast') {
                    void runCopyAssist('improve')
                    setSuggestionToast('Applied: Sharper copy')
                    return
                }
                if (kind === 'colors' && brandContext?.colors?.length) {
                    void runCopyAssist('improve')
                    setSuggestionToast('Applied: Palette-aware copy')
                    return
                }
                return
            }

            if (!isGenerativeImageLayer(layer)) {
                return
            }

            setDocument((prev) => ({
                ...prev,
                layers: prev.layers.map((l) => {
                    if (l.id !== selectedLayerId || !isGenerativeImageLayer(l)) {
                        return l
                    }
                    let nextPrompt = { ...l.prompt }
                    if (kind === 'premium') {
                        nextPrompt = {
                            ...nextPrompt,
                            style: {
                                ...nextPrompt.style,
                                editorial: 'premium luxury brand campaign',
                            },
                        }
                    } else if (kind === 'contrast') {
                        nextPrompt = {
                            ...nextPrompt,
                            lighting: {
                                ...nextPrompt.lighting,
                                look: 'high contrast, cinematic lighting with strong light–shadow separation',
                            },
                        }
                    } else if (kind === 'minimal') {
                        nextPrompt = {
                            ...nextPrompt,
                            composition: {
                                ...nextPrompt.composition,
                                style: 'minimal clean composition, generous negative space, uncluttered',
                            },
                        }
                    } else if (kind === 'tone' && brandContext?.tone?.[0]) {
                        nextPrompt = {
                            ...nextPrompt,
                            brand_hints: {
                                ...nextPrompt.brand_hints,
                                tone: brandContext.tone[0],
                            },
                        }
                    } else if (kind === 'colors' && brandContext?.colors?.length) {
                        nextPrompt = {
                            ...nextPrompt,
                            brand_hints: {
                                ...nextPrompt.brand_hints,
                                palette: brandContext.colors.join(', '),
                            },
                        }
                    }
                    return { ...l, prompt: nextPrompt }
                }),
                updated_at: new Date().toISOString(),
            }))
            setSuggestionToast(SUGGESTION_TOAST[kind])
            window.setTimeout(() => runGenerativeGeneration(selectedLayerId), 0)
        },
        [selectedLayerId, brandContext, runGenerativeGeneration, runCopyAssist]
    )

    const convertGenerativeToImageLayer = useCallback(() => {
        if (!selectedLayerId) {
            return
        }
        setDocument((prev) => {
            const result = convertGenerativeLayerToImage(prev, selectedLayerId)
            if (!result) {
                return prev
            }
            queueMicrotask(() => setSelectedLayerId(result.newLayerId))
            return result.doc
        })
    }, [selectedLayerId])

    const addTextLayer = useCallback(() => {
        setDocument((prev) => {
            const layer = createDefaultTextLayer(nextZIndex(prev.layers), prev, brandContext)
            setSelectedLayerId(layer.id)
            return {
                ...prev,
                layers: normalizeZ([...prev.layers, layer]),
                updated_at: new Date().toISOString(),
            }
        })
    }, [brandContext])

    const addFillLayer = useCallback(() => {
        const raw = auth?.activeBrand?.primary_color
        const brandColor =
            typeof raw === 'string' && /^#?[0-9a-fA-F]{3,8}$/.test(raw.trim())
                ? raw.trim().startsWith('#')
                    ? raw.trim()
                    : `#${raw.trim()}`
                : '#6366f1'
        setDocument((prev) => {
            const layer = createFillLayer(nextZIndex(prev.layers), prev, {
                color: brandColor,
                fillKind: 'gradient',
                gradientStartColor: 'transparent',
                gradientEndColor: brandColor,
            })
            setSelectedLayerId(layer.id)
            return {
                ...prev,
                layers: normalizeZ([...prev.layers, layer]),
                updated_at: new Date().toISOString(),
            }
        })
    }, [auth?.activeBrand?.primary_color])

    const beginMove = useCallback(
        (layerId: string, e: React.MouseEvent) => {
            const layer = document.layers.find((l) => l.id === layerId)
            if (!layer || layer.locked || !layer.visible) {
                return
            }
            e.stopPropagation()
            e.preventDefault()
            const { x, y } = clientToDoc(e.clientX, e.clientY)
            dragRef.current = {
                kind: 'move',
                layerId,
                startDocX: x,
                startDocY: y,
                startLayerX: layer.transform.x,
                startLayerY: layer.transform.y,
            }
        },
        [clientToDoc, document.layers]
    )

    const beginResize = useCallback(
        (layerId: string, corner: ResizeCorner, e: React.MouseEvent) => {
            const layer = document.layers.find((l) => l.id === layerId)
            if (!layer || layer.locked || !layer.visible) {
                return
            }
            e.stopPropagation()
            e.preventDefault()
            setSelectedLayerId(layerId)
            setEditingTextLayerId(null)
            const { x, y } = clientToDoc(e.clientX, e.clientY)
            const ar = layer.transform.width / Math.max(layer.transform.height, 0.001)
            dragRef.current = {
                kind: 'resize',
                layerId,
                corner,
                startDocX: x,
                startDocY: y,
                start: {
                    x: layer.transform.x,
                    y: layer.transform.y,
                    width: layer.transform.width,
                    height: layer.transform.height,
                },
                aspectRatio: ar,
                lockAspectResize: locksAspectOnResize(layer),
            }
        },
        [clientToDoc, document.layers]
    )

    useEffect(() => {
        const onMove = (e: MouseEvent) => {
            const d = dragRef.current
            if (!d) {
                return
            }
            const { x: mx, y: my } = clientToDoc(e.clientX, e.clientY)
            if (d.kind === 'move') {
                const dx = mx - d.startDocX
                const dy = my - d.startDocY
                updateLayer(d.layerId, (l) => ({
                    ...l,
                    transform: {
                        ...l.transform,
                        x: d.startLayerX + dx,
                        y: d.startLayerY + dy,
                    },
                }))
                return
            }
            const dx = mx - d.startDocX
            const dy = my - d.startDocY
            const min = 20
            const lockAspect =
                (d.lockAspectResize && !e.shiftKey) || (!d.lockAspectResize && e.shiftKey)
            const { x, y, width: w, height: h } = computeResizeRect(
                d.corner,
                d.start,
                dx,
                dy,
                min,
                lockAspect,
                d.aspectRatio
            )
            updateLayer(d.layerId, (l) => ({
                ...l,
                transform: {
                    ...l.transform,
                    x,
                    y,
                    width: w,
                    height: h,
                },
            }))
        }
        const onUp = () => {
            dragRef.current = null
        }
        window.addEventListener('mousemove', onMove)
        window.addEventListener('mouseup', onUp)
        return () => {
            window.removeEventListener('mousemove', onMove)
            window.removeEventListener('mouseup', onUp)
        }
    }, [clientToDoc, updateLayer])

    const clearSelection = useCallback((e: React.MouseEvent) => {
        if (e.target === e.currentTarget) {
            setSelectedLayerId(null)
            setEditingTextLayerId(null)
        }
    }, [])

    return (
        <div className="flex h-screen flex-col overflow-hidden bg-gray-50 dark:bg-gray-950">
            {compositionBootstrapping && (
                <div
                    className="fixed inset-0 z-50 flex flex-col items-center justify-center gap-3 bg-gray-950/80 text-sm text-white"
                    role="status"
                    aria-busy="true"
                >
                    <ArrowPathIcon className="h-10 w-10 animate-spin text-indigo-300" aria-hidden />
                    <span>Loading composition…</span>
                </div>
            )}
            <AppHead title="Generative editor" />
            <div className={uiMode === 'preview' ? 'hidden' : ''}>
                <AppNav brand={auth?.activeBrand} tenant={null} />
            </div>

            {/* Top bar */}
            <header
                className={`flex min-h-14 shrink-0 flex-wrap items-center justify-between gap-2 border-b px-3 py-2 sm:px-4 ${
                    uiMode === 'preview'
                        ? 'border-neutral-800 bg-neutral-950'
                        : 'border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900'
                }`}
            >
                <div className="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
                    <Bars3BottomLeftIcon
                        className={`h-5 w-5 shrink-0 ${uiMode === 'preview' ? 'text-neutral-500' : 'text-gray-400'}`}
                        aria-hidden
                    />
                    {uiMode === 'edit' ? (
                        <>
                            <input
                                type="text"
                                value={compositionName}
                                onChange={(e) => setCompositionName(e.target.value)}
                                className="min-w-0 max-w-[min(100%,280px)] rounded border border-gray-300 bg-white px-2 py-1 text-sm font-medium text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                placeholder={defaultCompositionName(document)}
                                aria-label="Composition name"
                            />
                            <span className="hidden text-xs text-gray-500 sm:inline">
                                {document.width} × {document.height}px
                            </span>
                        </>
                    ) : (
                        <span className="text-sm font-medium text-neutral-100">Preview</span>
                    )}
                </div>
                <div className="flex flex-wrap items-center justify-end gap-1.5 sm:gap-2">
                    {uiMode === 'edit' && (
                        <>
                            {(promoteOk || promoteError) && (
                                <span
                                    className={`text-xs ${promoteOk ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'}`}
                                    role="status"
                                >
                                    {promoteOk ? 'Published to library.' : promoteError}
                                </span>
                            )}
                            {saveError && (
                                <span className="max-w-[160px] truncate text-xs font-medium text-red-600" title={saveError}>
                                    {saveError}
                                </span>
                            )}
                            {discardRequiresConfirmation && saveState !== 'saving' && (
                                <span className="text-xs font-semibold text-amber-700 dark:text-amber-400">
                                    Unsaved changes
                                </span>
                            )}
                            {saveState === 'saving' && (
                                <span className="inline-flex items-center gap-1.5 rounded-md border border-indigo-400 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-950 shadow-sm dark:border-indigo-500 dark:bg-indigo-950/60 dark:text-indigo-100">
                                    <ArrowPathIcon className="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden />
                                    Saving…
                                </span>
                            )}
                            <AnimatePresence mode="wait">
                                {saveState === 'saved' && (
                                    <motion.span
                                        key="saved-toast"
                                        initial={{ opacity: 1 }}
                                        animate={{ opacity: 0 }}
                                        transition={{ duration: 1.6, ease: 'easeOut' }}
                                        onAnimationComplete={() =>
                                            setSaveState((prev) => (prev === 'saved' ? 'idle' : prev))
                                        }
                                        className="text-xs font-semibold text-emerald-600 dark:text-emerald-400"
                                    >
                                        Saved
                                    </motion.span>
                                )}
                            </AnimatePresence>
                            {activityToast && (
                                <span
                                    className="max-w-[220px] truncate rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-[11px] font-medium text-slate-800 shadow-sm dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100"
                                    role="status"
                                    title={activityToast}
                                >
                                    {activityToast}
                                </span>
                            )}
                            <button
                                type="button"
                                onClick={generateGuidedLayout}
                                className="inline-flex items-center gap-1.5 rounded-md border border-violet-400 bg-violet-50 px-2.5 py-1.5 text-xs font-semibold text-violet-950 shadow-sm transition-colors duration-150 hover:bg-violet-100 dark:border-violet-500 dark:bg-violet-950/50 dark:text-violet-100 dark:hover:bg-violet-900/40"
                            >
                                <SparklesIcon className="h-4 w-4" aria-hidden />
                                <span className="hidden sm:inline">Generate layout</span>
                                <span className="sm:hidden">Layout</span>
                            </button>
                            <button
                                type="button"
                                disabled={
                                    !document.layers.some(
                                        (l) =>
                                            isGenerativeImageLayer(l) &&
                                            !l.locked &&
                                            !!(l.prompt?.scene?.trim()) &&
                                            l.status !== 'generating' &&
                                            !l.variationPending
                                    ) ||
                                    genUsage === null ||
                                    !canGenerateFromUsage(genUsage)
                                }
                                onClick={() => void runRegenerateAllUnlockedGenerative()}
                                className="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 shadow-sm transition-colors duration-150 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                title="Regenerate every unlocked AI image layer that has a prompt"
                            >
                                <ArrowPathIcon className="h-4 w-4" aria-hidden />
                                <span className="hidden lg:inline">Regenerate all</span>
                            </button>
                            <button
                                type="button"
                                onClick={addTextLayer}
                                className="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                            >
                                Add text
                            </button>
                            <button
                                type="button"
                                onClick={openPickerForAddImage}
                                className="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                            >
                                <PhotoIcon className="h-4 w-4" aria-hidden />
                                Image
                            </button>
                            <button
                                type="button"
                                onClick={addGenerativeImageLayer}
                                className="inline-flex items-center gap-1.5 rounded-md border border-violet-300 bg-violet-50 px-2.5 py-1.5 text-xs font-medium text-violet-900 shadow-sm hover:bg-violet-100 dark:border-violet-600 dark:bg-violet-950/50 dark:text-violet-100 dark:hover:bg-violet-900/40"
                            >
                                <SparklesIcon className="h-4 w-4" aria-hidden />
                                AI image
                            </button>
                            <button
                                type="button"
                                onClick={addFillLayer}
                                title="Solid color or two-stop gradient (full canvas by default; resize as needed)"
                                className="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                            >
                                <SwatchIcon className="h-4 w-4" aria-hidden />
                                <span className="hidden sm:inline">Fill</span>
                            </button>
                            <button
                                type="button"
                                disabled={promoteSaving}
                                onClick={() => void openPublishModal()}
                                title="Export the canvas as a PNG and publish it to your brand library. Choose a category and details first."
                                className="inline-flex items-center rounded-md border border-emerald-600 bg-emerald-50 px-2.5 py-1.5 text-xs font-semibold text-emerald-900 shadow-sm hover:bg-emerald-100 disabled:opacity-50 dark:border-emerald-500 dark:bg-emerald-950/40 dark:text-emerald-100 dark:hover:bg-emerald-900/50"
                            >
                                {promoteSaving ? 'Publishing…' : 'Publish'}
                            </button>
                            <button
                                type="button"
                                onClick={handleSave}
                                className="inline-flex items-center rounded-md bg-indigo-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors duration-150 hover:bg-indigo-500"
                            >
                                Save
                            </button>
                            <button
                                type="button"
                                onClick={() => setHistoryOpen(true)}
                                className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                            >
                                <ClockIcon className="h-4 w-4" aria-hidden />
                                History
                            </button>
                            <button
                                type="button"
                                disabled={!compositionId || versions.length < 2}
                                onClick={() => setCompareOpen(true)}
                                className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                            >
                                <ArrowsRightLeftIcon className="h-4 w-4" aria-hidden />
                                Compare
                            </button>
                            <button
                                type="button"
                                disabled={!compositionId}
                                onClick={() => void duplicateWholeComposition()}
                                className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                            >
                                <DocumentDuplicateIcon className="h-4 w-4" aria-hidden />
                                Duplicate
                            </button>
                            <button
                                type="button"
                                disabled={!compositionId || compositionDeleteBusy}
                                onClick={() => compositionId && void deleteCompositionById(compositionId)}
                                title="Delete this saved composition for everyone in this brand. Library assets are not removed."
                                className="inline-flex items-center gap-1 rounded-md border border-red-300 bg-white px-2.5 py-1.5 text-xs font-medium text-red-800 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200 dark:hover:bg-red-950/60"
                            >
                                <TrashIcon className="h-4 w-4" aria-hidden />
                                Delete
                            </button>
                            <button
                                type="button"
                                onClick={openCompositionPickerAndLoad}
                                className="inline-flex items-center gap-1 rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                title="Open a saved composition for this brand"
                            >
                                <FolderOpenIcon className="h-4 w-4" aria-hidden />
                                Open
                            </button>
                            <button
                                type="button"
                                onClick={startNewComposition}
                                className="inline-flex items-center rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                            >
                                New
                            </button>
                            <details className="relative">
                                <summary className="inline-flex cursor-pointer list-none items-center rounded-md border border-gray-300 bg-white px-2.5 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                                    Export
                                </summary>
                                <div className="absolute right-0 z-40 mt-1 w-40 rounded-md border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-600 dark:bg-gray-900">
                                    <button
                                        type="button"
                                        className="block w-full px-3 py-1.5 text-left text-xs hover:bg-gray-50 dark:hover:bg-gray-800"
                                        onClick={() => void downloadExport('png')}
                                    >
                                        PNG (default)
                                    </button>
                                    <button
                                        type="button"
                                        className="block w-full px-3 py-1.5 text-left text-xs hover:bg-gray-50 dark:hover:bg-gray-800"
                                        onClick={() => void downloadExport('jpeg')}
                                    >
                                        JPG
                                    </button>
                                    <button
                                        type="button"
                                        className="block w-full px-3 py-1.5 text-left text-xs hover:bg-gray-50 dark:hover:bg-gray-800"
                                        onClick={() => void downloadExport('json')}
                                    >
                                        JSON (document)
                                    </button>
                                </div>
                            </details>
                            <button
                                type="button"
                                onClick={() => setUiMode('preview')}
                                className="inline-flex items-center rounded-md border border-gray-400 bg-gray-100 px-2.5 py-1.5 text-xs font-semibold text-gray-900 hover:bg-gray-200 dark:border-gray-500 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                            >
                                Preview
                            </button>
                        </>
                    )}
                    {uiMode === 'preview' && (
                        <>
                            <label className="flex items-center gap-1 text-xs text-neutral-300">
                                <span className="hidden sm:inline">Frame</span>
                                <select
                                    value={previewFrame}
                                    onChange={(e) =>
                                        setPreviewFrame(e.target.value as 'social' | 'banner')
                                    }
                                    className="rounded border border-neutral-600 bg-neutral-900 px-2 py-1 text-xs text-neutral-100"
                                >
                                    <option value="social">Social post</option>
                                    <option value="banner">Web banner</option>
                                </select>
                            </label>
                            <button
                                type="button"
                                onClick={() => setUiMode('edit')}
                                className="inline-flex items-center rounded-md bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-900 hover:bg-gray-100"
                            >
                                Exit preview
                            </button>
                        </>
                    )}
                </div>
            </header>

            <div className="flex min-h-0 flex-1">
                {/* Layer panel */}
                <aside
                    className={`flex w-64 shrink-0 flex-col border-r border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900 ${
                        uiMode === 'preview' ? 'hidden' : ''
                    }`}
                >
                    <div className="border-b border-gray-200 px-3 py-2 dark:border-gray-800">
                        <h2 className="text-xs font-semibold uppercase tracking-wide text-gray-500">Layers</h2>
                        <p className="mt-1 text-[10px] leading-snug text-gray-400 dark:text-gray-500">
                            Top = front. Bottom = back (e.g. layout background). Drag the grip to reorder.
                        </p>
                    </div>
                    <div className="flex-1 overflow-y-auto p-3">
                        {layersForPanel.length === 0 ? (
                            <p className="px-2 py-4 text-center text-xs text-gray-500">No layers yet</p>
                        ) : (
                            <ul className="space-y-1">
                                {layersForPanel.map((layer) => {
                                    const selected = layer.id === selectedLayerId
                                    return (
                                        <li
                                            key={layer.id}
                                            onDragOver={onLayerPanelDragOver}
                                            onDrop={(e) => onLayerPanelDrop(e, layer.id)}
                                            className={`rounded-md ${
                                                layerDragId === layer.id ? 'opacity-60' : ''
                                            }`}
                                        >
                                            <div
                                                className={`flex items-center gap-1 rounded-md border px-2 py-1.5 text-left text-xs ${
                                                    selected
                                                        ? 'border-indigo-500 bg-indigo-50 dark:border-indigo-400 dark:bg-indigo-950/40'
                                                        : 'border-transparent bg-gray-50 hover:bg-gray-100 dark:bg-gray-800/80 dark:hover:bg-gray-800'
                                                }`}
                                            >
                                                <button
                                                    type="button"
                                                    draggable
                                                    onDragStart={(e) => {
                                                        e.stopPropagation()
                                                        onLayerPanelDragStart(e, layer.id)
                                                    }}
                                                    onDragEnd={() => setLayerDragId(null)}
                                                    className="shrink-0 cursor-grab touch-none text-gray-400 active:cursor-grabbing dark:text-gray-500"
                                                    title="Drag to reorder"
                                                    aria-label={`Reorder layer ${layer.name || layer.id}`}
                                                >
                                                    <Bars3Icon className="h-4 w-4" aria-hidden />
                                                </button>
                                                <button
                                                    type="button"
                                                    draggable={false}
                                                    className="min-w-0 flex-1 truncate text-left font-medium text-gray-900 dark:text-gray-100"
                                                    onClick={() => {
                                                        setSelectedLayerId(layer.id)
                                                        setEditingTextLayerId(null)
                                                    }}
                                                >
                                                    {layer.name ||
                                                        (layer.type === 'text'
                                                            ? 'Text'
                                                            : layer.type === 'generative_image'
                                                              ? 'AI image'
                                                              : layer.type === 'fill'
                                                                ? 'Fill'
                                                                : 'Image')}
                                                </button>
                                                <button
                                                    type="button"
                                                    draggable={false}
                                                    className="rounded p-0.5 text-gray-500 hover:bg-gray-200 dark:hover:bg-gray-700"
                                                    title={layer.visible ? 'Hide' : 'Show'}
                                                    onClick={(e) => {
                                                        e.stopPropagation()
                                                        updateLayer(layer.id, (l) => ({ ...l, visible: !l.visible }))
                                                    }}
                                                >
                                                    {layer.visible ? (
                                                        <EyeIcon className="h-4 w-4" />
                                                    ) : (
                                                        <EyeSlashIcon className="h-4 w-4 text-gray-400" />
                                                    )}
                                                </button>
                                            </div>
                                            {selected && (
                                                <div className="mt-1 flex justify-end gap-1 px-1 pb-1">
                                                    <button
                                                        type="button"
                                                        draggable={false}
                                                        className="rounded border border-gray-200 bg-white p-1 text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:hover:bg-gray-700"
                                                        title="Bring forward (one step)"
                                                        onClick={() => setDocument((d) => moveLayerZOrder(d, layer.id, 'up'))}
                                                    >
                                                        <ArrowUpIcon className="h-3.5 w-3.5" />
                                                    </button>
                                                    <button
                                                        type="button"
                                                        draggable={false}
                                                        className="rounded border border-gray-200 bg-white p-1 text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:hover:bg-gray-700"
                                                        title="Send backward (one step)"
                                                        onClick={() => setDocument((d) => moveLayerZOrder(d, layer.id, 'down'))}
                                                    >
                                                        <ArrowDownIcon className="h-3.5 w-3.5" />
                                                    </button>
                                                </div>
                                            )}
                                        </li>
                                    )
                                })}
                            </ul>
                        )}
                    </div>
                </aside>

                {/* Canvas */}
                <main
                    ref={canvasContainerRef}
                    className={`relative box-border flex min-w-0 flex-1 items-center justify-center overflow-hidden ${
                        uiMode === 'preview'
                            ? previewFrame === 'social'
                                ? 'bg-gradient-to-b from-neutral-950 via-neutral-900 to-black p-8 sm:p-8 md:p-12 before:pointer-events-none before:absolute before:inset-0 before:z-[1] before:bg-black/25 before:content-[\'\']'
                                : 'bg-gradient-to-br from-slate-900 via-slate-800 to-slate-950 p-8 sm:p-10 md:p-14 before:pointer-events-none before:absolute before:inset-0 before:z-[1] before:bg-black/25 before:content-[\'\']'
                            : 'bg-neutral-200 p-10 dark:bg-neutral-900'
                    }`}
                >
                    {document.layers.length === 0 && (
                        <div className="pointer-events-none absolute inset-0 z-10 flex items-center justify-center p-6">
                            <div className="pointer-events-auto max-w-sm rounded-xl border border-gray-200 bg-white/95 p-6 text-center shadow-lg dark:border-gray-600 dark:bg-gray-900/95">
                                <h2 className="text-base font-semibold text-gray-900 dark:text-gray-100">
                                    Create something
                                </h2>
                                <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Start from a ready-made layout, add layers manually, or open a saved composition for this
                                    brand.
                                </p>
                                <div className="mt-4 flex flex-col gap-2">
                                    <button
                                        type="button"
                                        onClick={() => void generateGuidedLayout()}
                                        className="inline-flex items-center justify-center gap-2 rounded-lg bg-violet-600 px-3 py-2 text-xs font-semibold text-white hover:bg-violet-500"
                                    >
                                        <SparklesIcon className="h-4 w-4" aria-hidden />
                                        Generate layout
                                    </button>
                                    <button
                                        type="button"
                                        onClick={openPickerForAddImage}
                                        className="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-800 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                                    >
                                        <PhotoIcon className="h-4 w-4" aria-hidden />
                                        Add image
                                    </button>
                                    <button
                                        type="button"
                                        onClick={addTextLayer}
                                        className="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-800 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                                    >
                                        Add text
                                    </button>
                                    <button
                                        type="button"
                                        onClick={addFillLayer}
                                        className="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-800 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                                    >
                                        <SwatchIcon className="h-4 w-4" aria-hidden />
                                        Solid / gradient fill
                                    </button>
                                    <button
                                        type="button"
                                        onClick={openCompositionPickerAndLoad}
                                        className="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-800 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                                    >
                                        <FolderOpenIcon className="h-4 w-4" aria-hidden />
                                        Open existing
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}
                    <div
                        className={
                            uiMode === 'preview' && previewFrame === 'social'
                                ? 'relative z-[2] rounded-[2.25rem] border-[14px] border-neutral-900 bg-gradient-to-b from-neutral-900 to-black p-3 shadow-[0_25px_80px_-12px_rgba(0,0,0,0.65)] ring-1 ring-white/10'
                                : uiMode === 'preview' && previewFrame === 'banner'
                                  ? 'relative z-[2] w-full max-w-5xl rounded-xl border border-neutral-500/80 bg-neutral-100 p-4 shadow-[0_18px_50px_-12px_rgba(0,0,0,0.45)] ring-1 ring-black/10 sm:p-5'
                                  : 'relative flex min-h-0 min-w-0 shrink items-center justify-center'
                        }
                    >
                        {uiMode === 'preview' && previewFrame === 'banner' && (
                            <div className="mb-4 flex h-9 items-center gap-2 rounded-lg border border-neutral-400/40 bg-gradient-to-r from-neutral-200 to-neutral-300 px-3 shadow-inner">
                                <span className="inline-block h-2.5 w-2.5 rounded-full bg-red-400 shadow-sm" />
                                <span className="inline-block h-2.5 w-2.5 rounded-full bg-amber-400 shadow-sm" />
                                <span className="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500 shadow-sm" />
                                <span className="ml-1 text-[11px] font-medium text-neutral-700">
                                    Web banner preview
                                </span>
                            </div>
                        )}
                        <motion.div
                            key={`${uiMode}-${previewFrame}`}
                            initial={
                                uiMode === 'preview'
                                    ? { opacity: 0.88, scale: 0.985 }
                                    : { opacity: 1, scale: 1 }
                            }
                            animate={{ opacity: 1, scale: 1 }}
                            transition={{ duration: 0.36, ease: [0.22, 1, 0.36, 1] }}
                            className={`relative z-[2] overflow-hidden shadow-2xl ring-1 ring-black/10 ${
                                uiMode === 'preview' && previewFrame === 'banner' ? 'mx-auto' : ''
                            }`}
                            style={{
                                width: document.width * viewportScale,
                                height: document.height * viewportScale,
                            }}
                        >
                            <div
                                ref={stageRef}
                                role="presentation"
                                onMouseDown={clearSelection}
                                className="absolute left-0 top-0 isolate origin-top-left overflow-hidden bg-white dark:bg-neutral-800"
                                style={{
                                    width: document.width,
                                    height: document.height,
                                    transform: `scale(${viewportScale})`,
                                    transformOrigin: 'top left',
                                }}
                            >
                            {layersForCanvas.map((layer) => {
                                if (!layer.visible) {
                                    return null
                                }
                                const isSelected = layer.id === selectedLayerId
                                const t = layer.transform
                                const rot = t.rotation ?? 0
                                return (
                                    <div
                                        key={layer.id}
                                        role="presentation"
                                        className={`group relative box-border ${
                                            isSelected
                                                ? isTextLayer(layer)
                                                    ? 'ring-2 ring-indigo-500 ring-offset-0 outline outline-1 outline-dashed outline-indigo-400/90 dark:ring-indigo-400 dark:outline-indigo-500/80'
                                                    : 'ring-2 ring-indigo-500 ring-offset-0 dark:ring-indigo-400'
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
                                                ? layer.style.autoFit
                                                    ? 'hidden'
                                                    : 'visible'
                                                : 'hidden',
                                            transform: rot !== 0 ? `rotate(${rot}deg)` : undefined,
                                            ...(layer.blendMode && layer.blendMode !== 'normal'
                                                ? {
                                                      mixBlendMode:
                                                          layer.blendMode as CSSProperties['mixBlendMode'],
                                                  }
                                                : {}),
                                        }}
                                        onMouseDown={(e) => {
                                            e.stopPropagation()
                                            if (selectedLayerId !== layer.id) {
                                                setEditingTextLayerId(null)
                                            }
                                            setSelectedLayerId(layer.id)
                                            if (!isTextLayer(layer) || editingTextLayerId !== layer.id) {
                                                beginMove(layer.id, e)
                                            }
                                        }}
                                        onDoubleClick={(e) => {
                                            if (isTextLayer(layer)) {
                                                if (layer.locked) {
                                                    return
                                                }
                                                e.stopPropagation()
                                                setSelectedLayerId(layer.id)
                                                setEditingTextLayerId(layer.id)
                                                return
                                            }
                                            if (layer.type !== 'image' || layer.locked) {
                                                return
                                            }
                                            e.stopPropagation()
                                            openPickerForReplaceImage(layer.id)
                                        }}
                                    >
                                        {layer.locked && (
                                            <div
                                                className="pointer-events-none absolute right-1 top-1 z-20 rounded bg-black/55 p-0.5 text-white"
                                                title="Layer locked — won&apos;t change when regenerating"
                                            >
                                                <LockClosedIcon className="h-3.5 w-3.5" aria-hidden />
                                            </div>
                                        )}
                                        {isGenerativeImageLayer(layer) && (
                                            <div className="pointer-events-none absolute bottom-full left-1/2 z-30 mb-1 flex -translate-x-1/2 opacity-0 transition-opacity duration-200 group-hover:pointer-events-auto group-hover:opacity-100">
                                                <div className="pointer-events-auto flex flex-nowrap gap-0.5 rounded-md border border-gray-200 bg-white/95 px-1 py-0.5 text-[10px] font-medium text-gray-800 shadow-md dark:border-gray-600 dark:bg-gray-900/95 dark:text-gray-100">
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
                                                            void runGenerativeGeneration(layer.id)
                                                        }}
                                                    >
                                                        Regenerate
                                                    </button>
                                                    <button
                                                        type="button"
                                                        title="Variations (up to four, or fewer if credits are low)"
                                                        disabled={
                                                            layer.locked ||
                                                            layer.status === 'generating' ||
                                                            layer.variationPending ||
                                                            !layer.prompt?.scene?.trim() ||
                                                            variationRequestCount(genUsage) < 1
                                                        }
                                                        className="rounded px-1.5 py-0.5 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-gray-800"
                                                        onClick={(e) => {
                                                            e.stopPropagation()
                                                            void runGenerativeVariations(layer.id)
                                                        }}
                                                    >
                                                        Variations
                                                    </button>
                                                    <button
                                                        type="button"
                                                        title="Prevent this layer from changing"
                                                        className="rounded px-1.5 py-0.5 hover:bg-gray-100 dark:hover:bg-gray-800"
                                                        onClick={(e) => {
                                                            e.stopPropagation()
                                                            updateLayer(layer.id, (l) => ({
                                                                ...l,
                                                                locked: !l.locked,
                                                            }))
                                                        }}
                                                    >
                                                        {layer.locked ? 'Unlock' : 'Lock'}
                                                    </button>
                                                </div>
                                            </div>
                                        )}
                                        {isFillLayer(layer) && (
                                            <div className="pointer-events-none absolute bottom-full left-1/2 z-30 mb-1 flex -translate-x-1/2 opacity-0 transition-opacity duration-200 group-hover:pointer-events-auto group-hover:opacity-100">
                                                <div className="pointer-events-auto flex gap-0.5 rounded-md border border-gray-200 bg-white/95 px-1 py-0.5 text-[10px] font-medium text-gray-800 shadow-md dark:border-gray-600 dark:bg-gray-900/95 dark:text-gray-100">
                                                    <button
                                                        type="button"
                                                        title="Lock this fill layer"
                                                        className="rounded px-1.5 py-0.5 hover:bg-gray-100 dark:hover:bg-gray-800"
                                                        onClick={(e) => {
                                                            e.stopPropagation()
                                                            updateLayer(layer.id, (l) => ({
                                                                ...l,
                                                                locked: !l.locked,
                                                            }))
                                                        }}
                                                    >
                                                        {layer.locked ? 'Unlock' : 'Lock'}
                                                    </button>
                                                </div>
                                            </div>
                                        )}
                                        {layer.type === 'image' && (
                                            <div className="pointer-events-none absolute bottom-full left-1/2 z-30 mb-1 flex -translate-x-1/2 opacity-0 transition-opacity duration-200 group-hover:pointer-events-auto group-hover:opacity-100">
                                                <div className="pointer-events-auto flex gap-0.5 rounded-md border border-gray-200 bg-white/95 px-1 py-0.5 text-[10px] font-medium text-gray-800 shadow-md dark:border-gray-600 dark:bg-gray-900/95 dark:text-gray-100">
                                                    <button
                                                        type="button"
                                                        title="Replace image"
                                                        disabled={layer.locked}
                                                        className="rounded px-1.5 py-0.5 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-gray-800"
                                                        onClick={(e) => {
                                                            e.stopPropagation()
                                                            openPickerForReplaceImage(layer.id)
                                                        }}
                                                    >
                                                        Replace
                                                    </button>
                                                    <button
                                                        type="button"
                                                        title="Prevent this layer from changing"
                                                        className="rounded px-1.5 py-0.5 hover:bg-gray-100 dark:hover:bg-gray-800"
                                                        onClick={(e) => {
                                                            e.stopPropagation()
                                                            updateLayer(layer.id, (l) => ({
                                                                ...l,
                                                                locked: !l.locked,
                                                            }))
                                                        }}
                                                    >
                                                        {layer.locked ? 'Unlock' : 'Lock'}
                                                    </button>
                                                </div>
                                            </div>
                                        )}
                                        {isGenerativeImageLayer(layer) && (
                                            <div className="relative h-full min-h-0 w-full min-w-0">
                                                {layer.resultSrc ? (
                                                    <img
                                                        key={layer.resultSrc}
                                                        src={layer.resultSrc}
                                                        alt=""
                                                        draggable={false}
                                                        className="editor-gen-fade-in block h-full w-full max-h-full max-w-none select-none"
                                                        style={{
                                                            width: '100%',
                                                            height: '100%',
                                                            objectFit: layer.fit ?? 'cover',
                                                        }}
                                                        onError={() =>
                                                            updateLayer(layer.id, (l) =>
                                                                isGenerativeImageLayer(l)
                                                                    ? { ...l, status: 'error' }
                                                                    : l
                                                            )
                                                        }
                                                    />
                                                ) : (
                                                    <div className="flex h-full w-full flex-col items-center justify-center gap-1 border-2 border-dashed border-violet-300 bg-violet-50/80 px-2 text-center text-[10px] text-violet-800 dark:border-violet-600 dark:bg-violet-950/40 dark:text-violet-200">
                                                        <SparklesIcon className="h-5 w-5 opacity-70" aria-hidden />
                                                        <span>Add a prompt in the panel, then Generate.</span>
                                                    </div>
                                                )}
                                                {layer.status === 'generating' && (
                                                    <div
                                                        className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-white/85 dark:bg-gray-950/85"
                                                        role="status"
                                                        aria-busy="true"
                                                        aria-label={
                                                            layer.variationPending
                                                                ? 'Creating image variations'
                                                                : 'Generating image'
                                                        }
                                                    >
                                                        <ArrowPathIcon
                                                            className="h-8 w-8 shrink-0 animate-spin text-indigo-600 dark:text-indigo-400"
                                                            aria-hidden
                                                        />
                                                        {layer.variationPending ? (
                                                            <>
                                                                <div className="grid w-[88px] grid-cols-2 gap-1">
                                                                    {Array.from({
                                                                        length: layer.variationBatchSize ?? VARIATION_MAX,
                                                                    }).map((_, i) => (
                                                                        <div
                                                                            key={i}
                                                                            className="aspect-square animate-pulse rounded bg-gradient-to-br from-violet-200 to-indigo-200 dark:from-violet-800 dark:to-indigo-900"
                                                                        />
                                                                    ))}
                                                                </div>
                                                                <span className="text-xs font-medium text-gray-800 dark:text-gray-200">
                                                                    Creating variations…
                                                                </span>
                                                            </>
                                                        ) : (
                                                            <span className="text-xs font-medium text-gray-800 dark:text-gray-200">
                                                                Generating…
                                                            </span>
                                                        )}
                                                    </div>
                                                )}
                                                {layer.status === 'error' && (
                                                    <div className="pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-red-950/25 px-2 text-center dark:bg-red-950/50">
                                                        <ExclamationTriangleIcon
                                                            className="h-6 w-6 text-red-600 dark:text-red-400"
                                                            aria-hidden
                                                        />
                                                        <span className="text-[10px] font-medium text-red-900 dark:text-red-100">
                                                            Generation failed
                                                        </span>
                                                        <button
                                                            type="button"
                                                            className="pointer-events-auto rounded-md border border-red-300 bg-white px-2.5 py-1 text-[10px] font-semibold text-red-900 shadow-sm hover:bg-red-50 dark:border-red-700 dark:bg-gray-900 dark:text-red-100 dark:hover:bg-red-950/50"
                                                            onClick={(e) => {
                                                                e.stopPropagation()
                                                                runGenerativeGeneration(layer.id)
                                                            }}
                                                        >
                                                            Retry
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                        {layer.type === 'image' && (
                                            <div className="relative h-full min-h-0 w-full min-w-0">
                                                <img
                                                    src={layer.src}
                                                    alt=""
                                                    draggable={false}
                                                    className="block h-full w-full max-h-full max-w-none select-none"
                                                    style={{
                                                        width: '100%',
                                                        height: '100%',
                                                        objectFit: layer.fit ?? 'cover',
                                                    }}
                                                    onError={() => {
                                                        setImageLoadFailedByLayerId((p) => ({
                                                            ...p,
                                                            [layer.id]: true,
                                                        }))
                                                        updateLayer(layer.id, (l) =>
                                                            l.type === 'image' && l.src !== PLACEHOLDER_IMAGE_SRC
                                                                ? { ...l, src: PLACEHOLDER_IMAGE_SRC }
                                                                : l
                                                        )
                                                    }}
                                                />
                                                {imageLoadFailedByLayerId[layer.id] && (
                                                    <div
                                                        className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-1 bg-black/40 px-1 text-center"
                                                        aria-live="polite"
                                                    >
                                                        <ExclamationTriangleIcon
                                                            className="h-6 w-6 shrink-0 text-amber-200"
                                                            aria-hidden
                                                        />
                                                        <span className="text-[10px] font-medium leading-tight text-white">
                                                            Image failed to load
                                                        </span>
                                                    </div>
                                                )}
                                                {layer.aiEdit?.status === 'editing' && (
                                                    <div
                                                        className="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-white/85 dark:bg-gray-950/85"
                                                        role="status"
                                                        aria-busy="true"
                                                        aria-label="Editing image"
                                                    >
                                                        <ArrowPathIcon
                                                            className="h-8 w-8 shrink-0 animate-spin text-indigo-600 dark:text-indigo-400"
                                                            aria-hidden
                                                        />
                                                        <span className="text-xs font-medium text-gray-800 dark:text-gray-200">
                                                            Editing…
                                                        </span>
                                                    </div>
                                                )}
                                                {layer.aiEdit?.status === 'error' && (
                                                    <div className="pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-red-950/25 px-2 text-center dark:bg-red-950/50">
                                                        <ExclamationTriangleIcon
                                                            className="h-6 w-6 text-red-600 dark:text-red-400"
                                                            aria-hidden
                                                        />
                                                        <span className="text-[10px] font-medium text-red-900 dark:text-red-100">
                                                            Edit failed
                                                        </span>
                                                        <button
                                                            type="button"
                                                            className="pointer-events-auto rounded-md border border-red-300 bg-white px-2.5 py-1 text-[10px] font-semibold text-red-900 shadow-sm hover:bg-red-50 dark:border-red-700 dark:bg-gray-900 dark:text-red-100 dark:hover:bg-red-950/50"
                                                            onClick={(e) => {
                                                                e.stopPropagation()
                                                                void runImageLayerEdit(layer.id)
                                                            }}
                                                        >
                                                            Retry
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                        {isFillLayer(layer) && (
                                            <div
                                                className="relative h-full min-h-0 w-full min-w-0"
                                                style={{
                                                    background: fillLayerBackgroundCss(layer),
                                                }}
                                            />
                                        )}
                                        {isTextLayer(layer) && (
                                            <TextLayerEditable
                                                layer={layer}
                                                editing={editingTextLayerId === layer.id}
                                                assistLoading={copyAssistLoadingId === layer.id}
                                                brandContext={brandContext}
                                                brandFontsEpoch={brandFontsEpoch}
                                                onChange={(text) =>
                                                    updateLayer(layer.id, (l) =>
                                                        isTextLayer(l) ? { ...l, content: text } : l
                                                    )
                                                }
                                                onStopEdit={() =>
                                                    setEditingTextLayerId((id) =>
                                                        id === layer.id ? null : id
                                                    )
                                                }
                                                onTextHeightChange={(h) =>
                                                    updateLayer(layer.id, (l) =>
                                                        isTextLayer(l)
                                                            ? {
                                                                  ...l,
                                                                  transform: {
                                                                      ...l.transform,
                                                                      height: Math.max(20, h),
                                                                  },
                                                              }
                                                            : l
                                                    )
                                                }
                                                onAutoFitFontSize={(size) =>
                                                    updateLayer(layer.id, (l) =>
                                                        isTextLayer(l)
                                                            ? {
                                                                  ...l,
                                                                  style: { ...l.style, fontSize: size },
                                                              }
                                                            : l
                                                    )
                                                }
                                            />
                                        )}

                                        {isSelected && !layer.locked && (
                                            <>
                                                {(['nw', 'ne', 'sw', 'se'] as const).map((corner) => (
                                                    <button
                                                        key={corner}
                                                        type="button"
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
                                                            beginResize(layer.id, corner, e)
                                                        }}
                                                    />
                                                ))}
                                            </>
                                        )}
                                    </div>
                                )
                            })}
                            </div>
                        </motion.div>
                    </div>
                </main>

                {/* Properties — width is user-resizable (stored in localStorage) */}
                <div
                    className={`relative flex shrink-0 flex-col ${uiMode === 'preview' ? 'hidden' : ''}`}
                    style={{ width: propertiesPanelWidth }}
                >
                    <div
                        role="separator"
                        aria-orientation="vertical"
                        aria-label="Resize properties panel"
                        tabIndex={0}
                        className="absolute left-0 top-0 z-10 h-full w-2 -translate-x-1/2 cursor-col-resize bg-transparent hover:bg-indigo-400/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                        onPointerDown={onPropertiesResizePointerDown}
                    />
                    <aside className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden border-l border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
                    <div className="border-b border-gray-200 px-3 py-2 dark:border-gray-800">
                        <h2 className="text-xs font-semibold uppercase tracking-wide text-gray-500">Properties</h2>
                        {brandFontsLoading && (
                            <p
                                className="mt-1.5 flex items-center gap-1.5 text-[10px] text-violet-600 dark:text-violet-400"
                                aria-live="polite"
                            >
                                <ArrowPathIcon className="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden />
                                Loading brand fonts…
                            </p>
                        )}
                    </div>
                    <div className="flex-1 overflow-y-auto p-3 text-xs">
                        <div className="mb-4 space-y-2 border-b border-gray-200 pb-4 dark:border-gray-700">
                            <h3 className="text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                Document size
                            </h3>
                            <div className="grid grid-cols-2 gap-2">
                                <div>
                                    <label className="mb-1 block text-gray-600 dark:text-gray-400">
                                        Width (px)
                                    </label>
                                    <input
                                        type="number"
                                        min={DOCUMENT_DIMENSION_MIN}
                                        max={DOCUMENT_DIMENSION_MAX}
                                        value={document.width}
                                        onChange={(e) => {
                                            const v = Number(e.target.value)
                                            if (Number.isNaN(v)) {
                                                return
                                            }
                                            setDocumentDimensions(v, document.height)
                                        }}
                                        className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                    />
                                </div>
                                <div>
                                    <label className="mb-1 block text-gray-600 dark:text-gray-400">
                                        Height (px)
                                    </label>
                                    <input
                                        type="number"
                                        min={DOCUMENT_DIMENSION_MIN}
                                        max={DOCUMENT_DIMENSION_MAX}
                                        value={document.height}
                                        onChange={(e) => {
                                            const v = Number(e.target.value)
                                            if (Number.isNaN(v)) {
                                                return
                                            }
                                            setDocumentDimensions(document.width, v)
                                        }}
                                        className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                    />
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-1">
                                {DOCUMENT_SIZE_PRESETS.map((p) => (
                                    <button
                                        key={`${p.w}x${p.h}`}
                                        type="button"
                                        onClick={() => setDocumentDimensions(p.w, p.h)}
                                        title={`${p.w} × ${p.h}px`}
                                        className="rounded border border-gray-300 bg-white px-1.5 py-0.5 text-[10px] font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                    >
                                        {p.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                        {!selectedLayer && (
                            <p className="text-gray-500">Select a layer to edit properties.</p>
                        )}
                        {selectedLayer && (
                            <div className="space-y-4">
                                <div className="flex flex-wrap gap-2 border-b border-gray-200 pb-3 dark:border-gray-700">
                                    <button
                                        type="button"
                                        onClick={duplicateSelectedLayer}
                                        className="inline-flex items-center gap-1 rounded border border-gray-300 bg-white px-2 py-1 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:hover:bg-gray-700"
                                        title="Duplicate layer"
                                    >
                                        <Square2StackIcon className="h-3.5 w-3.5" aria-hidden />
                                        Duplicate
                                    </button>
                                    <button
                                        type="button"
                                        onClick={deleteSelectedLayer}
                                        className="inline-flex items-center gap-1 rounded border border-red-200 bg-white px-2 py-1 text-red-700 hover:bg-red-50 dark:border-red-900 dark:bg-gray-800 dark:text-red-400 dark:hover:bg-red-950/40"
                                        title="Delete layer"
                                    >
                                        <TrashIcon className="h-3.5 w-3.5" aria-hidden />
                                        Delete
                                    </button>
                                    <button
                                        type="button"
                                        onClick={bringSelectedToFront}
                                        className="inline-flex items-center gap-1 rounded border border-gray-300 bg-white px-2 py-1 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:hover:bg-gray-700"
                                        title="Bring to front"
                                    >
                                        <ChevronDoubleUpIcon className="h-3.5 w-3.5" aria-hidden />
                                        Front
                                    </button>
                                    <button
                                        type="button"
                                        onClick={sendSelectedToBack}
                                        className="inline-flex items-center gap-1 rounded border border-gray-300 bg-white px-2 py-1 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:hover:bg-gray-700"
                                        title="Send to back"
                                    >
                                        <ChevronDoubleDownIcon className="h-3.5 w-3.5" aria-hidden />
                                        Back
                                    </button>
                                </div>
                                <div>
                                    <label className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Name</label>
                                    <input
                                        type="text"
                                        value={selectedLayer.name ?? ''}
                                        onChange={(e) =>
                                            updateLayer(selectedLayer.id, (l) => ({
                                                ...l,
                                                name: e.target.value || undefined,
                                            }))
                                        }
                                        className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                        placeholder={
                                            selectedLayer.type === 'text'
                                                ? 'Text'
                                                : selectedLayer.type === 'generative_image'
                                                  ? 'AI image'
                                                  : selectedLayer.type === 'fill'
                                                    ? 'Fill'
                                                    : 'Image'
                                        }
                                    />
                                </div>
                                <div className="grid grid-cols-2 gap-2">
                                    <div>
                                        <label className="mb-1 block text-gray-600 dark:text-gray-400">X</label>
                                        <input
                                            type="number"
                                            value={Math.round(selectedLayer.transform.x)}
                                            onChange={(e) => {
                                                const v = Number(e.target.value)
                                                if (Number.isNaN(v)) {
                                                    return
                                                }
                                                updateLayer(selectedLayer.id, (l) => ({
                                                    ...l,
                                                    transform: { ...l.transform, x: v },
                                                }))
                                            }}
                                            className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                        />
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-gray-600 dark:text-gray-400">Y</label>
                                        <input
                                            type="number"
                                            value={Math.round(selectedLayer.transform.y)}
                                            onChange={(e) => {
                                                const v = Number(e.target.value)
                                                if (Number.isNaN(v)) {
                                                    return
                                                }
                                                updateLayer(selectedLayer.id, (l) => ({
                                                    ...l,
                                                    transform: { ...l.transform, y: v },
                                                }))
                                            }}
                                            className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                        />
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-gray-600 dark:text-gray-400">Width</label>
                                        <input
                                            type="number"
                                            value={Math.round(selectedLayer.transform.width)}
                                            onChange={(e) => {
                                                const v = Math.max(20, Number(e.target.value))
                                                if (Number.isNaN(v)) {
                                                    return
                                                }
                                                updateLayer(selectedLayer.id, (l) => ({
                                                    ...l,
                                                    transform: { ...l.transform, width: v },
                                                }))
                                            }}
                                            className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                        />
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-gray-600 dark:text-gray-400">Height</label>
                                        <input
                                            type="number"
                                            value={Math.round(selectedLayer.transform.height)}
                                            onChange={(e) => {
                                                const v = Math.max(20, Number(e.target.value))
                                                if (Number.isNaN(v)) {
                                                    return
                                                }
                                                updateLayer(selectedLayer.id, (l) => ({
                                                    ...l,
                                                    transform: { ...l.transform, height: v },
                                                }))
                                            }}
                                            className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                        />
                                    </div>
                                </div>
                                <div>
                                    <button
                                        type="button"
                                        disabled={selectedLayer.locked}
                                        onClick={centerSelectedLayerInDocument}
                                        title={
                                            selectedLayer.locked
                                                ? 'Unlock the layer to move it'
                                                : 'Center this layer in the document frame (keeps size)'
                                        }
                                        className="inline-flex w-full items-center justify-center gap-1.5 rounded border border-gray-300 bg-white px-2 py-1.5 font-medium text-gray-800 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                                    >
                                        <ViewfinderCircleIcon className="h-3.5 w-3.5 shrink-0" aria-hidden />
                                        Center on canvas
                                    </button>
                                </div>
                                <div>
                                    <label className="mb-1 block text-gray-600 dark:text-gray-400">Rotation (°)</label>
                                    <input
                                        type="number"
                                        value={selectedLayer.transform.rotation ?? 0}
                                        onChange={(e) => {
                                            const v = Number(e.target.value)
                                            if (Number.isNaN(v)) {
                                                return
                                            }
                                            updateLayer(selectedLayer.id, (l) => ({
                                                ...l,
                                                transform: { ...l.transform, rotation: v },
                                            }))
                                        }}
                                        className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                    />
                                </div>
                                <div>
                                    <label className="mb-1 block text-gray-600 dark:text-gray-400">
                                        Blend mode
                                    </label>
                                    <p className="mb-1.5 text-[9px] leading-snug text-gray-500 dark:text-gray-400">
                                        How this layer composites over layers below (e.g. multiply, screen,
                                        overlay).
                                    </p>
                                    <select
                                        value={selectedLayer.blendMode ?? 'normal'}
                                        onChange={(e) =>
                                            updateLayer(selectedLayer.id, (l) => ({
                                                ...l,
                                                blendMode: e.target.value as LayerBlendMode,
                                            }))
                                        }
                                        className="w-full rounded border border-gray-300 px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-800"
                                    >
                                        {LAYER_BLEND_MODE_OPTIONS.map((opt) => (
                                            <option key={opt.value} value={opt.value}>
                                                {opt.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="flex gap-3">
                                    <button
                                        type="button"
                                        className="inline-flex items-center gap-1 text-gray-700 dark:text-gray-300"
                                        onClick={() =>
                                            updateLayer(selectedLayer.id, (l) => ({ ...l, visible: !l.visible }))
                                        }
                                    >
                                        {selectedLayer.visible ? (
                                            <EyeIcon className="h-4 w-4" />
                                        ) : (
                                            <EyeSlashIcon className="h-4 w-4" />
                                        )}
                                        Visible
                                    </button>
                                    <button
                                        type="button"
                                        title="Prevent this layer from changing when regenerating or editing"
                                        className="inline-flex items-center gap-1 text-gray-700 dark:text-gray-300"
                                        onClick={() =>
                                            updateLayer(selectedLayer.id, (l) => ({ ...l, locked: !l.locked }))
                                        }
                                    >
                                        {selectedLayer.locked ? (
                                            <LockClosedIcon className="h-4 w-4" />
                                        ) : (
                                            <LockOpenIcon className="h-4 w-4" />
                                        )}
                                        Lock
                                    </button>
                                </div>

                                {isFillLayer(selectedLayer) && (
                                    <div className="space-y-3 border-t border-gray-200 pt-3 dark:border-gray-700">
                                        <h3 className="text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                            Fill
                                        </h3>
                                        <div>
                                            <label className="mb-1 block text-gray-600 dark:text-gray-400">
                                                Style
                                            </label>
                                            <select
                                                value={selectedLayer.fillKind}
                                                disabled={selectedLayer.locked}
                                                onChange={(e) => {
                                                    const v = e.target.value as 'solid' | 'gradient'
                                                    updateLayer(selectedLayer.id, (l) => {
                                                        if (!isFillLayer(l)) {
                                                            return l
                                                        }
                                                        if (v === 'solid') {
                                                            const { start, end } = resolvedFillGradientStops(l)
                                                            return {
                                                                ...l,
                                                                fillKind: 'solid',
                                                                name: 'Solid fill',
                                                                color: opaqueHexForSolidFromGradientStops(
                                                                    start,
                                                                    end,
                                                                    l.color
                                                                ),
                                                                gradientStartColor: undefined,
                                                                gradientEndColor: undefined,
                                                            }
                                                        }
                                                        if (l.fillKind === 'solid') {
                                                            return {
                                                                ...l,
                                                                fillKind: 'gradient',
                                                                name: 'Gradient fill',
                                                                gradientStartColor: 'transparent',
                                                                gradientEndColor: l.color,
                                                                color: l.color,
                                                            }
                                                        }
                                                        return {
                                                            ...l,
                                                            fillKind: v,
                                                            name: 'Gradient fill',
                                                        }
                                                    })
                                                }}
                                                className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                            >
                                                <option value="solid">Solid color</option>
                                                <option value="gradient">Gradient (two colors)</option>
                                            </select>
                                        </div>
                                        {selectedLayer.fillKind === 'solid' && (
                                            <div>
                                                <label className="mb-1 block text-gray-600 dark:text-gray-400">
                                                    Color
                                                </label>
                                                <div className="flex gap-2">
                                                    <input
                                                        type="color"
                                                        value={
                                                            /^#[0-9a-fA-F]{6}$/.test(selectedLayer.color)
                                                                ? selectedLayer.color
                                                                : '#6366f1'
                                                        }
                                                        disabled={selectedLayer.locked}
                                                        onChange={(e) =>
                                                            updateLayer(selectedLayer.id, (l) =>
                                                                isFillLayer(l)
                                                                    ? { ...l, color: e.target.value }
                                                                    : l
                                                            )
                                                        }
                                                        className="h-9 w-12 cursor-pointer rounded border border-gray-300 bg-white dark:border-gray-600"
                                                    />
                                                    <input
                                                        type="text"
                                                        value={selectedLayer.color}
                                                        disabled={selectedLayer.locked}
                                                        onChange={(e) =>
                                                            updateLayer(selectedLayer.id, (l) =>
                                                                isFillLayer(l)
                                                                    ? { ...l, color: e.target.value }
                                                                    : l
                                                            )
                                                        }
                                                        className="min-w-0 flex-1 rounded border border-gray-300 px-2 py-1 font-mono text-[11px] dark:border-gray-600 dark:bg-gray-800"
                                                        placeholder="#RRGGBB"
                                                    />
                                                </div>
                                            </div>
                                        )}
                                        {selectedLayer.fillKind === 'gradient' && (
                                            <>
                                                <FillGradientStopField
                                                    label="Gradient start"
                                                    value={resolvedFillGradientStops(selectedLayer).start}
                                                    disabled={selectedLayer.locked}
                                                    allowTransparent
                                                    brandContext={brandContext}
                                                    onChange={(newStart) => {
                                                        const { end } = resolvedFillGradientStops(selectedLayer)
                                                        updateLayer(selectedLayer.id, (l) => {
                                                            if (!isFillLayer(l) || l.fillKind !== 'gradient') {
                                                                return l
                                                            }
                                                            return {
                                                                ...l,
                                                                gradientStartColor: newStart,
                                                                gradientEndColor: end,
                                                                color: opaqueHexForSolidFromGradientStops(
                                                                    newStart,
                                                                    end,
                                                                    l.color
                                                                ),
                                                            }
                                                        })
                                                    }}
                                                />
                                                <FillGradientStopField
                                                    label="Gradient end"
                                                    value={resolvedFillGradientStops(selectedLayer).end}
                                                    disabled={selectedLayer.locked}
                                                    allowTransparent
                                                    brandContext={brandContext}
                                                    onChange={(newEnd) => {
                                                        const { start } = resolvedFillGradientStops(selectedLayer)
                                                        updateLayer(selectedLayer.id, (l) => {
                                                            if (!isFillLayer(l) || l.fillKind !== 'gradient') {
                                                                return l
                                                            }
                                                            return {
                                                                ...l,
                                                                gradientStartColor: start,
                                                                gradientEndColor: newEnd,
                                                                color: opaqueHexForSolidFromGradientStops(
                                                                    start,
                                                                    newEnd,
                                                                    l.color
                                                                ),
                                                            }
                                                        })
                                                    }}
                                                />
                                                <p className="text-[9px] text-gray-500 dark:text-gray-400">
                                                    New gradients default to transparent → brand color. Use angle to
                                                    place each stop along the line.
                                                </p>
                                                <div>
                                                    <label className="mb-1 block text-gray-600 dark:text-gray-400">
                                                        Angle (°)
                                                    </label>
                                                    <input
                                                        type="range"
                                                        min={0}
                                                        max={360}
                                                        value={selectedLayer.gradientAngleDeg ?? 180}
                                                        disabled={selectedLayer.locked}
                                                        onChange={(e) => {
                                                            const v = Number(e.target.value)
                                                            updateLayer(selectedLayer.id, (l) =>
                                                                isFillLayer(l)
                                                                    ? { ...l, gradientAngleDeg: v }
                                                                    : l
                                                            )
                                                        }}
                                                        className="w-full accent-indigo-600"
                                                    />
                                                    <p className="text-[10px] text-gray-500 dark:text-gray-400">
                                                        {selectedLayer.gradientAngleDeg ?? 180}
                                                        ° — direction of the gradient line (e.g. 180° runs top to
                                                        bottom).
                                                    </p>
                                                </div>
                                            </>
                                        )}
                                    </div>
                                )}

                                {isGenerativeImageLayer(selectedLayer) && (
                                    <div className="space-y-3 border-t border-gray-200 pt-3 dark:border-gray-700">
                                        {genUsageError && (
                                            <p className="text-[10px] text-amber-700 dark:text-amber-300">{genUsageError}</p>
                                        )}
                                        {genUsage && (
                                            <p className="text-[10px] text-gray-500 dark:text-gray-400">
                                                {genUsage.limit < 0
                                                    ? `Plan: ${genUsage.plan_name ?? genUsage.plan} — unlimited`
                                                    : `${genUsage.remaining} / ${genUsage.limit} generations this month (${genUsage.plan_name ?? genUsage.plan})`}
                                            </p>
                                        )}
                                        {genActionError && (
                                            <div className="flex flex-wrap items-start gap-2">
                                                <p className="min-w-0 flex-1 text-[10px] text-red-600 dark:text-red-400">
                                                    {genActionError}
                                                </p>
                                                <button
                                                    type="button"
                                                    className="shrink-0 rounded border border-red-200 bg-white px-2 py-0.5 text-[10px] font-semibold text-red-700 transition-colors hover:bg-red-50 dark:border-red-900 dark:bg-gray-900 dark:text-red-300 dark:hover:bg-red-950/40"
                                                    onClick={() => void runGenerativeGeneration(selectedLayer.id)}
                                                >
                                                    Retry
                                                </button>
                                            </div>
                                        )}
                                        {suggestionToast && (
                                            <p
                                                className="rounded border border-emerald-200 bg-emerald-50 px-2 py-1.5 text-[10px] text-emerald-950 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100"
                                                role="status"
                                            >
                                                {suggestionToast}
                                            </p>
                                        )}
                                        {genUsage && genUsage.limit >= 0 && genUsage.remaining <= 0 && (
                                            <p className="text-[10px] font-medium text-amber-800 dark:text-amber-200">
                                                You&apos;ve reached your monthly limit.
                                            </p>
                                        )}
                                        {selectedLayer.status === 'generating' && (
                                            <div
                                                className="flex items-center gap-2 rounded-md border border-violet-300 bg-violet-50 px-2.5 py-2 text-[10px] font-medium text-violet-950 dark:border-violet-600 dark:bg-violet-950/40 dark:text-violet-100"
                                                role="status"
                                                aria-live="polite"
                                            >
                                                <ArrowPathIcon className="h-4 w-4 shrink-0 animate-spin" aria-hidden />
                                                {selectedLayer.variationPending
                                                    ? 'Creating variations…'
                                                    : 'Generating image…'}
                                            </div>
                                        )}
                                        <div>
                                            <label className="mb-1 block font-medium text-gray-700 dark:text-gray-300">
                                                Prompt
                                            </label>
                                            <textarea
                                                rows={4}
                                                disabled={
                                                    selectedLayer.locked ||
                                                    selectedLayer.status === 'generating' ||
                                                    selectedLayer.variationPending
                                                }
                                                value={selectedLayer.prompt.scene ?? ''}
                                                onChange={(e) => {
                                                    const v = e.target.value
                                                    updateLayer(selectedLayer.id, (l) => {
                                                        if (!isGenerativeImageLayer(l)) {
                                                            return l
                                                        }
                                                        return {
                                                            ...l,
                                                            prompt: { ...l.prompt, scene: v },
                                                        }
                                                    })
                                                }}
                                                placeholder="Describe the scene…"
                                                className="w-full rounded border border-gray-300 px-2 py-1.5 text-[11px] leading-snug dark:border-gray-600 dark:bg-gray-800"
                                            />
                                            <p className="mb-1 mt-1.5 text-[9px] text-gray-500 dark:text-gray-400">
                                                Quick insert
                                            </p>
                                            <div className="flex flex-wrap gap-1">
                                                {PROMPT_ASSIST_CHIPS.map((chip) => (
                                                    <button
                                                        key={chip}
                                                        type="button"
                                                        disabled={
                                                            selectedLayer.locked ||
                                                            selectedLayer.status === 'generating' ||
                                                            selectedLayer.variationPending
                                                        }
                                                        onClick={() => insertPromptChip(chip)}
                                                        className="rounded-full border border-gray-200 bg-white px-2 py-0.5 text-[9px] text-gray-700 hover:bg-gray-50 disabled:opacity-40 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                                    >
                                                        {chip}
                                                    </button>
                                                ))}
                                            </div>
                                            {generativePromptPreview && (
                                                <p className="mt-2 rounded border border-gray-100 bg-gray-50/90 px-2 py-1.5 text-[9px] leading-snug text-gray-600 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-300">
                                                    <span className="font-medium text-gray-500 dark:text-gray-400">
                                                        Current style:{' '}
                                                    </span>
                                                    {generativePromptPreview}
                                                </p>
                                            )}
                                        </div>
                                        <div className="rounded-md border border-gray-200 bg-gray-50/80 p-3 dark:border-gray-600 dark:bg-gray-800/50">
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="flex flex-wrap items-center gap-1.5 text-[11px] font-medium text-gray-800 dark:text-gray-200">
                                                    Brand influence
                                                    {brandContext && selectedLayer.applyBrandDna !== false && (
                                                        <span className="rounded-full bg-emerald-100 px-1.5 py-0.5 text-[8px] font-bold uppercase tracking-wide text-emerald-900 dark:bg-emerald-900/50 dark:text-emerald-100">
                                                            Brand alignment active
                                                        </span>
                                                    )}
                                                </span>
                                                <label className="flex cursor-pointer items-center gap-2 text-[10px] text-gray-600 dark:text-gray-400">
                                                    <span>Apply Brand DNA</span>
                                                    <input
                                                        type="checkbox"
                                                        className="h-3.5 w-3.5 rounded border-gray-300 text-violet-600 focus:ring-violet-500"
                                                        checked={selectedLayer.applyBrandDna !== false}
                                                        disabled={
                                                            selectedLayer.locked ||
                                                            selectedLayer.status === 'generating' ||
                                                            selectedLayer.variationPending
                                                        }
                                                        onChange={(e) =>
                                                            updateLayer(selectedLayer.id, (l) =>
                                                                isGenerativeImageLayer(l)
                                                                    ? { ...l, applyBrandDna: e.target.checked }
                                                                    : l
                                                            )
                                                        }
                                                    />
                                                </label>
                                            </div>
                                            {brandContext && selectedLayer.applyBrandDna === false && (
                                                <p className="mt-2 rounded border border-amber-200 bg-amber-50/90 px-2 py-1 text-[9px] leading-snug text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">
                                                    You are generating without brand alignment.
                                                </p>
                                            )}
                                            {brandContext && (
                                                <p className="mt-1 text-[9px] leading-snug text-gray-500 dark:text-gray-400">
                                                    {brandContext.visual_style && (
                                                        <span className="mr-1">Style: {brandContext.visual_style}.</span>
                                                    )}
                                                    {brandContext.archetype && (
                                                        <span className="mr-1">Archetype: {brandContext.archetype}.</span>
                                                    )}
                                                    {!brandContext.visual_style && !brandContext.archetype && (
                                                        <span>Brand context loaded.</span>
                                                    )}
                                                </p>
                                            )}
                                            {!brandContext && (
                                                <p className="mt-1 text-[9px] text-amber-700 dark:text-amber-300">
                                                    No brand context — generation uses your prompt only.
                                                </p>
                                            )}
                                        </div>
                                        <div>
                                            <div className="mb-1 flex items-center justify-between">
                                                <label className="block font-medium text-gray-700 dark:text-gray-300">
                                                    References
                                                </label>
                                                <span className="text-[9px] text-gray-500 dark:text-gray-400">
                                                    Max {MAX_REFERENCE_ASSETS}
                                                </span>
                                            </div>
                                            <button
                                                type="button"
                                                disabled={
                                                    selectedLayer.locked ||
                                                    selectedLayer.status === 'generating' ||
                                                    selectedLayer.variationPending
                                                }
                                                onClick={() => openReferencePicker(selectedLayer.id)}
                                                className="mb-2 w-full rounded-md border border-dashed border-violet-400/70 bg-violet-50/50 px-2 py-1.5 text-[11px] font-medium text-violet-900 hover:bg-violet-100/80 disabled:cursor-not-allowed disabled:opacity-50 dark:border-violet-500/50 dark:bg-violet-950/30 dark:text-violet-100 dark:hover:bg-violet-950/50"
                                            >
                                                Add references from library…
                                            </button>
                                            {(selectedLayer.referenceAssetIds?.length ?? 0) > 0 && (
                                                <div className="flex flex-wrap gap-1.5">
                                                    {(selectedLayer.referenceAssetIds ?? []).map((rid) => {
                                                        const ref = damAssetById.get(rid)
                                                        return (
                                                            <div
                                                                key={rid}
                                                                className="relative h-12 w-12 overflow-hidden rounded border border-gray-200 bg-gray-100 dark:border-gray-600 dark:bg-gray-700"
                                                            >
                                                                {ref ? (
                                                                    <img
                                                                        src={ref.thumbnail_url || ref.file_url}
                                                                        alt=""
                                                                        className="h-full w-full object-cover"
                                                                    />
                                                                ) : (
                                                                    <div className="flex h-full w-full items-center justify-center text-[8px] text-gray-500">
                                                                        …
                                                                    </div>
                                                                )}
                                                                <button
                                                                    type="button"
                                                                    aria-label="Remove reference"
                                                                    disabled={
                                                                        selectedLayer.locked ||
                                                                        selectedLayer.status === 'generating' ||
                                                                        selectedLayer.variationPending
                                                                    }
                                                                    onClick={() =>
                                                                        removeReferenceAsset(selectedLayer.id, rid)
                                                                    }
                                                                    className="absolute right-0 top-0 rounded-bl bg-black/60 p-0.5 text-white hover:bg-black/80 disabled:opacity-50"
                                                                >
                                                                    <XMarkIcon className="h-3 w-3" />
                                                                </button>
                                                            </div>
                                                        )
                                                    })}
                                                </div>
                                            )}
                                        </div>
                                        {generativeBrandScore && (
                                            <div className="rounded-md border border-gray-200 bg-white p-2 dark:border-gray-600 dark:bg-gray-900">
                                                <p className="text-[11px] font-semibold text-gray-900 dark:text-gray-100">
                                                    Estimated brand alignment: {generativeBrandScore.score}%
                                                </p>
                                                <p className="mt-0.5 text-[9px] text-gray-500 dark:text-gray-400">
                                                    Heuristic preview — not a measured guarantee.
                                                </p>
                                                {generativeBrandScore.feedback.length > 0 && (
                                                    <ul className="mt-1 list-inside list-disc text-[10px] text-gray-600 dark:text-gray-400">
                                                        {generativeBrandScore.feedback.map((f, i) => (
                                                            <li key={i}>{f}</li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </div>
                                        )}
                                        <div>
                                            <p className="mb-1 font-medium text-gray-700 dark:text-gray-300">
                                                Suggestions
                                            </p>
                                            <div className="flex flex-wrap gap-1">
                                                {SMART_SUGGESTIONS.map((s) => (
                                                    <button
                                                        key={s.action}
                                                        type="button"
                                                        disabled={
                                                            selectedLayer.locked ||
                                                            selectedLayer.status === 'generating' ||
                                                            selectedLayer.variationPending
                                                        }
                                                        onClick={() => applySuggestionAction(s.action)}
                                                        className="rounded border border-gray-200 bg-gray-50 px-2 py-0.5 text-[10px] text-gray-800 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                                    >
                                                        {s.label}
                                                    </button>
                                                ))}
                                                {brandContext?.tone?.[0] && (
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            selectedLayer.locked ||
                                                            selectedLayer.status === 'generating' ||
                                                            selectedLayer.variationPending
                                                        }
                                                        onClick={() => applySuggestionAction('tone')}
                                                        className="rounded border border-gray-200 bg-gray-50 px-2 py-0.5 text-[10px] text-gray-800 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                                    >
                                                        Align with brand tone
                                                    </button>
                                                )}
                                                {!!brandContext?.colors?.length && (
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            selectedLayer.locked ||
                                                            selectedLayer.status === 'generating' ||
                                                            selectedLayer.variationPending
                                                        }
                                                        onClick={() => applySuggestionAction('colors')}
                                                        className="rounded border border-gray-200 bg-gray-50 px-2 py-0.5 text-[10px] text-gray-800 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                                    >
                                                        Use brand colors
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                        {selectedLayer.variationResults &&
                                            selectedLayer.variationResults.length > 0 && (
                                                <div className="rounded-md border border-violet-200 bg-violet-50/50 p-2 dark:border-violet-800 dark:bg-violet-950/30">
                                                    <p className="mb-1.5 text-[10px] font-medium text-violet-950 dark:text-violet-100">
                                                        Pick a variation ({selectedLayer.variationResults.length}{' '}
                                                        {selectedLayer.variationResults.length === 1 ? 'option' : 'options'})
                                                    </p>
                                                    <div className="grid grid-cols-2 gap-1.5">
                                                        {selectedLayer.variationResults.map((url, idx) => (
                                                            <button
                                                                key={`${url}-${idx}`}
                                                                type="button"
                                                                className={`group/v relative overflow-hidden rounded bg-white transition-shadow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-600 dark:bg-gray-900 ${
                                                                    variationPressedIdx === idx
                                                                        ? 'ring-2 ring-violet-700 dark:ring-violet-400'
                                                                        : variationHoverIdx === idx
                                                                          ? 'ring-2 ring-violet-400 dark:ring-violet-500'
                                                                          : 'ring-1 ring-violet-200/80 dark:ring-violet-700'
                                                                }`}
                                                                onMouseEnter={() => setVariationHoverIdx(idx)}
                                                                onMouseLeave={() =>
                                                                    setVariationHoverIdx((h) =>
                                                                        h === idx ? null : h
                                                                    )
                                                                }
                                                                onMouseDown={() => setVariationPressedIdx(idx)}
                                                                onClick={() =>
                                                                    applyVariationChoice(selectedLayer.id, url)
                                                                }
                                                            >
                                                                <img
                                                                    src={url}
                                                                    alt=""
                                                                    className="editor-gen-fade-in aspect-square w-full object-cover"
                                                                />
                                                                <span className="absolute inset-x-0 bottom-0 bg-black/50 py-0.5 text-[9px] text-white opacity-0 transition-opacity group-hover/v:opacity-100">
                                                                    Use this
                                                                </span>
                                                            </button>
                                                        ))}
                                                    </div>
                                                    <button
                                                        type="button"
                                                        className="mt-2 w-full text-[10px] text-gray-600 underline hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100"
                                                        onClick={() => discardVariationResults(selectedLayer.id)}
                                                    >
                                                        Discard variations
                                                    </button>
                                                </div>
                                            )}
                                        <div>
                                            <label className="mb-1 block font-medium text-gray-700 dark:text-gray-300">
                                                Model
                                            </label>
                                            <select
                                                disabled={
                                                    selectedLayer.locked ||
                                                    selectedLayer.status === 'generating' ||
                                                    selectedLayer.variationPending
                                                }
                                                value={selectedLayer.model ?? 'default'}
                                                onChange={(e) =>
                                                    updateLayer(selectedLayer.id, (l) =>
                                                        isGenerativeImageLayer(l)
                                                            ? { ...l, model: e.target.value }
                                                            : l
                                                    )
                                                }
                                                className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                            >
                                                {GENERATIVE_MODEL_OPTIONS.map((opt) => (
                                                    <option key={opt.value} value={opt.value}>
                                                        {opt.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="space-y-1">
                                            <label className="flex cursor-pointer items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                                                <input
                                                    type="checkbox"
                                                    className="rounded border-gray-300"
                                                    disabled={
                                                        selectedLayer.locked ||
                                                        selectedLayer.status === 'generating' ||
                                                        selectedLayer.variationPending
                                                    }
                                                    checked={Boolean(selectedLayer.advancedModel)}
                                                    onChange={(e) =>
                                                        updateLayer(selectedLayer.id, (l) =>
                                                            isGenerativeImageLayer(l)
                                                                ? {
                                                                      ...l,
                                                                      advancedModel: e.target.checked,
                                                                      modelOverride:
                                                                          l.modelOverride ??
                                                                          GENERATIVE_ADVANCED_MODEL_OPTIONS[0]
                                                                              ?.value,
                                                                  }
                                                                : l
                                                        )
                                                    }
                                                />
                                                Advanced model (override)
                                            </label>
                                            {selectedLayer.advancedModel && (
                                                <select
                                                    disabled={
                                                        selectedLayer.locked ||
                                                        selectedLayer.status === 'generating' ||
                                                        selectedLayer.variationPending
                                                    }
                                                    value={
                                                        selectedLayer.modelOverride ??
                                                        GENERATIVE_ADVANCED_MODEL_OPTIONS[0]?.value ??
                                                        'gpt-image-1'
                                                    }
                                                    onChange={(e) =>
                                                        updateLayer(selectedLayer.id, (l) =>
                                                            isGenerativeImageLayer(l)
                                                                ? { ...l, modelOverride: e.target.value }
                                                                : l
                                                        )
                                                    }
                                                    className="w-full rounded border border-gray-300 px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-800"
                                                >
                                                    {GENERATIVE_ADVANCED_MODEL_OPTIONS.map((opt) => (
                                                        <option key={opt.value} value={opt.value}>
                                                            {opt.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}
                                            {selectedLayer.lastResolvedModelDisplay && (
                                                <p className="text-[10px] text-gray-500 dark:text-gray-400">
                                                    Model: {selectedLayer.lastResolvedModelDisplay}
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex flex-col gap-2">
                                            <button
                                                type="button"
                                                disabled={
                                                    selectedLayer.locked ||
                                                    selectedLayer.status === 'generating' ||
                                                    selectedLayer.variationPending ||
                                                    genUsage === null ||
                                                    !canGenerateFromUsage(genUsage) ||
                                                    !(selectedLayer.prompt.scene?.trim())
                                                }
                                                onClick={() => void runGenerativeGeneration(selectedLayer.id)}
                                                className="inline-flex items-center justify-center gap-1.5 rounded-md bg-violet-600 px-3 py-2 text-xs font-semibold text-white shadow-sm transition-colors duration-150 hover:bg-violet-500 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                <SparklesIcon className="h-4 w-4" aria-hidden />
                                                Generate
                                            </button>
                                            <button
                                                type="button"
                                                disabled={
                                                    selectedLayer.locked ||
                                                    selectedLayer.status === 'generating' ||
                                                    selectedLayer.variationPending ||
                                                    genUsage === null ||
                                                    !canGenerateFromUsage(genUsage) ||
                                                    !(selectedLayer.prompt.scene?.trim()) ||
                                                    variationRequestCount(genUsage) < 1
                                                }
                                                onClick={() => void runGenerativeVariations(selectedLayer.id)}
                                                className="inline-flex items-center justify-center gap-1.5 rounded-md border border-violet-400 bg-violet-50 px-3 py-2 text-xs font-semibold text-violet-950 shadow-sm transition-colors duration-150 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-violet-500 dark:bg-violet-950/50 dark:text-violet-100 dark:hover:bg-violet-900/40"
                                            >
                                                <Squares2X2Icon className="h-4 w-4" aria-hidden />
                                                Generate variations
                                            </button>
                                            {selectedLayer.resultSrc && (
                                                <>
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            selectedLayer.locked ||
                                                            selectedLayer.status === 'generating' ||
                                                            selectedLayer.variationPending ||
                                                            genUsage === null ||
                                                            !canGenerateFromUsage(genUsage) ||
                                                            !(selectedLayer.prompt.scene?.trim())
                                                        }
                                                        onClick={() => void runGenerativeGeneration(selectedLayer.id)}
                                                        className="rounded-md border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-800 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                                                    >
                                                        Regenerate
                                                    </button>
                                                    <button
                                                        type="button"
                                                        disabled={selectedLayer.locked}
                                                        onClick={convertGenerativeToImageLayer}
                                                        className="rounded-md border border-emerald-600 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-900 hover:bg-emerald-100 disabled:opacity-50 dark:border-emerald-500 dark:bg-emerald-950/40 dark:text-emerald-100"
                                                    >
                                                        Convert to image layer
                                                    </button>
                                                </>
                                            )}
                                        </div>
                                        {selectedLayer.resultSrc && (
                                            <div>
                                                <label className="mb-1 block font-medium text-gray-700 dark:text-gray-300">
                                                    Object fit
                                                </label>
                                                <select
                                                    value={selectedLayer.fit ?? 'cover'}
                                                    onChange={(e) =>
                                                        updateLayer(selectedLayer.id, (l) => {
                                                            if (!isGenerativeImageLayer(l)) {
                                                                return l
                                                            }
                                                            return {
                                                                ...l,
                                                                fit: e.target.value as GenerativeImageLayer['fit'],
                                                            }
                                                        })
                                                    }
                                                    className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                                >
                                                    <option value="cover">cover</option>
                                                    <option value="contain">contain</option>
                                                    <option value="fill">fill</option>
                                                </select>
                                            </div>
                                        )}
                                    </div>
                                )}

                                {isImageLayer(selectedLayer) && (
                                    <div className="space-y-3">
                                        <button
                                            type="button"
                                            disabled={selectedLayer.locked}
                                            onClick={() => openPickerForReplaceImage(selectedLayer.id)}
                                            className="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-left text-xs font-medium text-gray-800 shadow-sm hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                                        >
                                            Replace image
                                        </button>
                                        <div>
                                        <label className="mb-1 block font-medium text-gray-700 dark:text-gray-300">
                                            Object fit
                                        </label>
                                        <select
                                            value={selectedLayer.fit ?? 'cover'}
                                            onChange={(e) =>
                                                updateLayer(selectedLayer.id, (l) => {
                                                    if (!isImageLayer(l)) {
                                                        return l
                                                    }
                                                    return {
                                                        ...l,
                                                        fit: e.target.value as ImageLayer['fit'],
                                                    }
                                                })
                                            }
                                            className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                        >
                                            <option value="cover">cover</option>
                                            <option value="contain">contain</option>
                                            <option value="fill">fill</option>
                                        </select>
                                        </div>

                                        {selectedLayer.assetId && (
                                            <div className="mt-4">
                                                <div className="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-2">
                                                    Versions
                                                </div>
                                                <p className="mb-2 text-[10px] leading-snug text-gray-500 dark:text-gray-400">
                                                    Original first, newest edits next. Click a thumbnail to swap the
                                                    canvas.
                                                </p>
                                                <div className="flex gap-3 overflow-x-auto pb-1 pt-0.5">
                                                    {selectedImageLayerVersionStrip.map((v) => {
                                                        const active = isAssetVersionThumbnailActive(
                                                            selectedLayer,
                                                            v,
                                                            selectedImageLayerVersionStrip
                                                        )
                                                        const label = assetVersionStripLabel(
                                                            v,
                                                            selectedImageLayerVersionStripMin
                                                        )
                                                        return (
                                                            <button
                                                                key={v.id}
                                                                type="button"
                                                                disabled={selectedLayer.locked}
                                                                onClick={() =>
                                                                    handleSwitchAssetVersion(selectedLayer.id, v)
                                                                }
                                                                title={v.created_at ?? label}
                                                                className={`flex flex-col items-center gap-1 flex-shrink-0 rounded-lg p-0.5 transition-shadow disabled:cursor-not-allowed disabled:opacity-50 ${
                                                                    active
                                                                        ? 'ring-2 ring-indigo-500 ring-offset-2 ring-offset-white dark:ring-offset-gray-900'
                                                                        : 'hover:ring-2 hover:ring-indigo-400/60 hover:ring-offset-1 hover:ring-offset-white dark:hover:ring-offset-gray-900'
                                                                }`}
                                                            >
                                                                <div className="h-14 w-14 overflow-hidden rounded-md border border-gray-200 dark:border-gray-600 bg-gray-100 dark:bg-gray-800">
                                                                    <img
                                                                        src={v.url}
                                                                        alt=""
                                                                        className="h-full w-full object-cover"
                                                                    />
                                                                </div>
                                                                <span className="max-w-[4.5rem] truncate text-center text-[10px] font-semibold tabular-nums text-gray-600 dark:text-gray-300">
                                                                    {label}
                                                                </span>
                                                            </button>
                                                        )
                                                    })}
                                                </div>
                                            </div>
                                        )}

                                        <div className="rounded-lg border border-violet-200 bg-violet-50/50 p-2 text-gray-900 dark:border-violet-800 dark:bg-violet-950/30 dark:text-gray-100">
                                            <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-violet-900 dark:text-violet-200">
                                                Modify image (AI)
                                            </p>
                                            <p className="mb-2 text-[10px] leading-snug text-gray-600 dark:text-gray-400">
                                                Uses the same monthly AI image budget as Generate. Apply runs on the
                                                current layer. After a successful edit,{' '}
                                                <span className="font-medium text-gray-800 dark:text-gray-300">
                                                    Regenerate
                                                </span>{' '}
                                                re-runs your prompt on the latest result. Choose{' '}
                                                <span className="font-medium text-gray-800 dark:text-gray-300">
                                                    Nano Banana (2.5)
                                                </span>{' '}
                                                or{' '}
                                                <span className="font-medium text-gray-800 dark:text-gray-300">
                                                    Nano Banana Pro (3)
                                                </span>{' '}
                                                if OpenAI cannot decode your file (e.g. AVIF/HEIC); Gemini models
                                                require{' '}
                                                <span className="font-mono text-[10px]">GEMINI_API_KEY</span>.
                                            </p>
                                            <div className="mb-2">
                                                <label className="mb-0.5 block text-[10px] font-medium text-gray-700 dark:text-gray-300">
                                                    Edit model
                                                </label>
                                                <select
                                                    value={normalizeEditModelKey(
                                                        selectedLayer.aiEdit?.editModelKey
                                                    )}
                                                    disabled={
                                                        selectedLayer.locked ||
                                                        selectedLayer.aiEdit?.status === 'editing'
                                                    }
                                                    onChange={(e) => {
                                                        const v = e.target.value
                                                        updateLayer(selectedLayer.id, (l) => {
                                                            if (!isImageLayer(l)) {
                                                                return l
                                                            }
                                                            return {
                                                                ...l,
                                                                aiEdit: {
                                                                    ...l.aiEdit,
                                                                    editModelKey: v,
                                                                },
                                                            }
                                                        })
                                                    }}
                                                    className="w-full rounded border border-violet-200 bg-white px-2 py-1.5 text-[11px] text-gray-900 dark:border-violet-700 dark:bg-gray-900 dark:text-gray-100"
                                                >
                                                    {GENERATIVE_EDIT_MODEL_OPTIONS.map((opt) => (
                                                        <option key={opt.value} value={opt.value}>
                                                            {opt.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            {genUsageError && (
                                                <p className="mb-2 text-[10px] text-amber-800 dark:text-amber-200">
                                                    {genUsageError} — AI actions stay off until usage loads.
                                                </p>
                                            )}
                                            <textarea
                                                value={selectedLayer.aiEdit?.prompt ?? ''}
                                                onChange={(e) => {
                                                    const v = e.target.value
                                                    updateLayer(selectedLayer.id, (l) => {
                                                        if (!isImageLayer(l)) {
                                                            return l
                                                        }
                                                        return {
                                                            ...l,
                                                            aiEdit: { ...l.aiEdit, prompt: v },
                                                        }
                                                    })
                                                }}
                                                rows={3}
                                                placeholder="Describe what to change (e.g. change background to desert, make pants blue)"
                                                disabled={selectedLayer.locked || selectedLayer.aiEdit?.status === 'editing'}
                                                className="w-full rounded border border-violet-200 bg-white px-2 py-1.5 text-xs text-gray-900 placeholder:text-gray-400 dark:border-violet-700 dark:bg-gray-900 dark:text-gray-100"
                                            />
                                            {imageEditActionError && (
                                                <p className="mt-1 text-[10px] text-red-600 dark:text-red-400">{imageEditActionError}</p>
                                            )}
                                            {selectedLayer.aiEdit?.status === 'error' && !imageEditActionError && (
                                                <p className="mt-1 text-[10px] text-red-600 dark:text-red-400">
                                                    Edit failed. Try again or adjust the instruction.
                                                </p>
                                            )}
                                            <div className="mt-2 flex flex-col gap-2">
                                                <div className="flex flex-wrap gap-2">
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            selectedLayer.locked ||
                                                            selectedLayer.aiEdit?.status === 'editing' ||
                                                            imageEditUsageBlocked
                                                        }
                                                        title={
                                                            genUsageError
                                                                ? genUsageError
                                                                : imageEditUsageBlocked
                                                                  ? "You've used all AI image generations for this month"
                                                                  : 'Send this layer and prompt to the AI editor'
                                                        }
                                                        onClick={() => void runImageLayerEdit(selectedLayer.id)}
                                                        className="group inline-flex min-h-[2.25rem] min-w-[8.5rem] flex-1 items-center justify-center rounded-md border-2 border-violet-800 bg-violet-600 px-3 py-1.5 text-xs font-semibold shadow-sm hover:bg-violet-700 disabled:cursor-not-allowed disabled:border-violet-400 disabled:bg-violet-300 dark:border-violet-400 dark:bg-violet-700 dark:hover:bg-violet-600 dark:disabled:border-violet-800 dark:disabled:bg-violet-900"
                                                    >
                                                        <span className="text-black group-disabled:text-violet-950 dark:group-disabled:text-violet-100">
                                                            Apply changes
                                                        </span>
                                                    </button>
                                                    {selectedLayer.aiEdit?.resultSrc ? (
                                                        <button
                                                            type="button"
                                                            disabled={
                                                                selectedLayer.locked ||
                                                                selectedLayer.aiEdit?.status === 'editing' ||
                                                                imageEditUsageBlocked ||
                                                                !(selectedLayer.aiEdit?.prompt ?? '').trim()
                                                            }
                                                            title="Run the same prompt again on the last AI result"
                                                            onClick={() => void runImageLayerEdit(selectedLayer.id)}
                                                            className="inline-flex min-h-[2.25rem] min-w-[8rem] flex-1 items-center justify-center rounded-md border-2 border-gray-800 bg-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-950 shadow-sm hover:bg-gray-400 disabled:cursor-not-allowed disabled:border-gray-500 disabled:bg-gray-200 disabled:text-gray-600 dark:border-gray-400 dark:bg-neutral-600 dark:text-white dark:hover:bg-neutral-500 dark:disabled:border-neutral-700 dark:disabled:bg-neutral-800 dark:disabled:text-neutral-300"
                                                        >
                                                            Regenerate
                                                        </button>
                                                    ) : null}
                                                </div>
                                                {(selectedLayer.aiEdit?.previousResults?.length ?? 0) > 0 && (
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            selectedLayer.locked ||
                                                            selectedLayer.aiEdit?.status === 'editing'
                                                        }
                                                        onClick={() =>
                                                            updateLayer(selectedLayer.id, (l) => {
                                                                if (!isImageLayer(l)) {
                                                                    return l
                                                                }
                                                                const stack = [...(l.aiEdit?.previousResults ?? [])]
                                                                if (stack.length === 0) {
                                                                    return l
                                                                }
                                                                const nextSrc = stack[stack.length - 1]
                                                                return {
                                                                    ...l,
                                                                    src: nextSrc,
                                                                    aiEdit: {
                                                                        ...l.aiEdit,
                                                                        previousResults: stack.slice(0, -1),
                                                                        resultSrc: nextSrc,
                                                                        status: 'idle',
                                                                    },
                                                                }
                                                            })
                                                        }
                                                        className="inline-flex w-full min-h-[2.25rem] items-center justify-center rounded-md border-2 border-gray-800 bg-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-950 shadow-sm hover:bg-gray-400 disabled:cursor-not-allowed disabled:border-gray-500 disabled:bg-gray-200 disabled:text-gray-600 dark:border-gray-400 dark:bg-neutral-600 dark:text-white dark:hover:bg-neutral-500 dark:disabled:border-neutral-700 dark:disabled:bg-neutral-800 dark:disabled:text-neutral-300 sm:w-auto"
                                                    >
                                                        Revert to previous image
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {isTextLayer(selectedLayer) && (
                                    <>
                                        {effectivePrimaryFontFamily(brandContext) && (
                                            <div>
                                                <button
                                                    type="button"
                                                    className="w-full rounded border border-violet-300 bg-violet-50 px-2 py-1.5 text-left text-[11px] font-medium text-violet-900 hover:bg-violet-100 dark:border-violet-700 dark:bg-violet-950/50 dark:text-violet-100 dark:hover:bg-violet-900/60"
                                                    onClick={() =>
                                                        updateLayer(selectedLayer.id, (l) => {
                                                            if (!isTextLayer(l)) {
                                                                return l
                                                            }
                                                            const next = effectivePrimaryFontFamily(brandContext)
                                                            if (!next) {
                                                                return l
                                                            }
                                                            return {
                                                                ...l,
                                                                style: { ...l.style, fontFamily: next },
                                                            }
                                                        })
                                                    }
                                                >
                                                    Use brand font ({effectivePrimaryFontFamily(brandContext)})
                                                </button>
                                            </div>
                                        )}

                                        <div>
                                            <label className="mb-1 block font-medium text-gray-700 dark:text-gray-300">
                                                Font family
                                            </label>
                                            <select
                                                value={selectedLayer.style.fontFamily}
                                                onChange={(e) => {
                                                    const v = e.target.value
                                                    updateLayer(selectedLayer.id, (l) => {
                                                        if (!isTextLayer(l)) {
                                                            return l
                                                        }
                                                        return {
                                                            ...l,
                                                            style: { ...l.style, fontFamily: v },
                                                        }
                                                    })
                                                }}
                                                className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                            >
                                                {(() => {
                                                    const primary = brandContext?.typography?.primary_font?.trim()
                                                    const primaryCanvas = effectivePrimaryFontFamily(brandContext)
                                                    const secondary = brandContext?.typography?.secondary_font?.trim()
                                                    const faceFamilies = uniqueFontFamiliesFromFaceSources(
                                                        brandContext?.typography?.font_face_sources
                                                    )
                                                    const extraFamilies = faceFamilies.filter((f) => {
                                                        if (primaryCanvas && fontFamilyMatches(f, primaryCanvas)) {
                                                            return false
                                                        }
                                                        if (primary && fontFamilyMatches(f, primary)) {
                                                            return false
                                                        }
                                                        if (secondary && fontFamilyMatches(f, secondary)) {
                                                            return false
                                                        }
                                                        return true
                                                    })
                                                    const listed = [
                                                        primaryCanvas,
                                                        secondary,
                                                        ...extraFamilies,
                                                        DEFAULT_TEXT_FONT_FAMILY,
                                                        'system-ui, -apple-system, sans-serif',
                                                        'Georgia, serif',
                                                    ].filter((x): x is string => Boolean(x))
                                                    const uniq: string[] = []
                                                    const seenKeys = new Set<string>()
                                                    for (const x of listed) {
                                                        const k = firstFontFamilyToken(x).toLowerCase()
                                                        if (seenKeys.has(k)) {
                                                            continue
                                                        }
                                                        seenKeys.add(k)
                                                        uniq.push(x)
                                                    }
                                                    const cur = selectedLayer.style.fontFamily
                                                    const has = uniq.some((u) => fontFamilyMatches(cur, u))
                                                    return (
                                                        <>
                                                            {!has && (
                                                                <option value={cur}>
                                                                    {firstFontFamilyToken(cur)} (current)
                                                                </option>
                                                            )}
                                                            {primaryCanvas && (
                                                                <option value={primaryCanvas}>Primary brand font</option>
                                                            )}
                                                            {secondary &&
                                                                !fontFamilyMatches(
                                                                    secondary,
                                                                    primaryCanvas ?? primary ?? ''
                                                                ) && (
                                                                    <option value={secondary}>Brand secondary</option>
                                                                )}
                                                            {extraFamilies.map((fam) => (
                                                                <option key={fam} value={fam}>
                                                                    {firstFontFamilyToken(fam)}
                                                                </option>
                                                            ))}
                                                            <option value={DEFAULT_TEXT_FONT_FAMILY}>
                                                                Inter (default)
                                                            </option>
                                                            <option value="system-ui, -apple-system, sans-serif">
                                                                System UI
                                                            </option>
                                                            <option value="Georgia, serif">Georgia</option>
                                                        </>
                                                    )
                                                })()}
                                            </select>
                                        </div>

                                        <div className="grid grid-cols-2 gap-2">
                                            <div>
                                                <label className="mb-1 block font-medium text-gray-700 dark:text-gray-300">
                                                    Font size
                                                </label>
                                                <input
                                                    type="number"
                                                    min={8}
                                                    value={selectedLayer.style.fontSize}
                                                    onChange={(e) => {
                                                        const v = Math.max(8, Number(e.target.value))
                                                        if (Number.isNaN(v)) {
                                                            return
                                                        }
                                                        updateLayer(selectedLayer.id, (l) => {
                                                            if (!isTextLayer(l)) {
                                                                return l
                                                            }
                                                            return {
                                                                ...l,
                                                                style: { ...l.style, fontSize: v },
                                                            }
                                                        })
                                                    }}
                                                    className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                                />
                                            </div>
                                            <div>
                                                <label className="mb-1 block font-medium text-gray-700 dark:text-gray-300">
                                                    Weight
                                                </label>
                                                <select
                                                    value={selectedLayer.style.fontWeight ?? 400}
                                                    onChange={(e) => {
                                                        const v = Number(e.target.value)
                                                        updateLayer(selectedLayer.id, (l) => {
                                                            if (!isTextLayer(l)) {
                                                                return l
                                                            }
                                                            return {
                                                                ...l,
                                                                style: { ...l.style, fontWeight: v },
                                                            }
                                                        })
                                                    }}
                                                    className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                                >
                                                    {[100, 200, 300, 400, 500, 600, 700, 800, 900].map((w) => (
                                                        <option key={w} value={w}>
                                                            {w}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                        </div>

                                        <div className="grid grid-cols-2 gap-2">
                                            <div>
                                                <label className="mb-1 block font-medium text-gray-700 dark:text-gray-300">
                                                    Line height
                                                </label>
                                                <input
                                                    type="number"
                                                    step={0.05}
                                                    min={0.8}
                                                    max={3}
                                                    value={selectedLayer.style.lineHeight ?? 1.25}
                                                    onChange={(e) => {
                                                        const v = Number(e.target.value)
                                                        if (Number.isNaN(v)) {
                                                            return
                                                        }
                                                        updateLayer(selectedLayer.id, (l) => {
                                                            if (!isTextLayer(l)) {
                                                                return l
                                                            }
                                                            return {
                                                                ...l,
                                                                style: { ...l.style, lineHeight: v },
                                                            }
                                                        })
                                                    }}
                                                    className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                                />
                                            </div>
                                            <div>
                                                <label className="mb-1 block font-medium text-gray-700 dark:text-gray-300">
                                                    Letter spacing (px)
                                                </label>
                                                <input
                                                    type="number"
                                                    step={0.1}
                                                    value={selectedLayer.style.letterSpacing ?? 0}
                                                    onChange={(e) => {
                                                        const v = Number(e.target.value)
                                                        if (Number.isNaN(v)) {
                                                            return
                                                        }
                                                        updateLayer(selectedLayer.id, (l) => {
                                                            if (!isTextLayer(l)) {
                                                                return l
                                                            }
                                                            return {
                                                                ...l,
                                                                style: { ...l.style, letterSpacing: v },
                                                            }
                                                        })
                                                    }}
                                                    className="w-full rounded border border-gray-300 px-2 py-1 dark:border-gray-600 dark:bg-gray-800"
                                                />
                                            </div>
                                        </div>

                                        <div>
                                            <label className="mb-1 block font-medium text-gray-700 dark:text-gray-300">
                                                Presets
                                            </label>
                                            <div className="flex flex-wrap gap-1">
                                                {(
                                                    [
                                                        ['Heading', 'heading'],
                                                        ['Subheading', 'subheading'],
                                                        ['Body', 'body'],
                                                        ['Caption', 'caption'],
                                                    ] as const
                                                ).map(([label, key]) => (
                                                    <button
                                                        key={key}
                                                        type="button"
                                                        className="rounded border border-gray-300 px-2 py-1 text-[10px] font-medium hover:bg-gray-50 dark:border-gray-600 dark:hover:bg-gray-800"
                                                        onClick={() => {
                                                            const p = resolveTextPreset(brandContext, key)
                                                            updateLayer(selectedLayer.id, (l) => {
                                                                if (!isTextLayer(l)) {
                                                                    return l
                                                                }
                                                                return {
                                                                    ...l,
                                                                    style: {
                                                                        ...l.style,
                                                                        fontSize: p.fontSize,
                                                                        fontWeight: p.fontWeight,
                                                                        lineHeight: p.lineHeight,
                                                                        letterSpacing: p.letterSpacing,
                                                                    },
                                                                }
                                                            })
                                                        }}
                                                    >
                                                        {label}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>

                                        <div className="rounded-md border border-violet-200 bg-violet-50/40 p-2 dark:border-violet-800 dark:bg-violet-950/20">
                                            <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-violet-900 dark:text-violet-200">
                                                Copy Assist
                                            </p>
                                            {copyAssistLoadingId === selectedLayer.id && (
                                                <div
                                                    className="mb-2 flex items-center gap-2 rounded border border-violet-300/80 bg-white/80 px-2 py-1.5 text-[10px] font-medium text-violet-900 dark:border-violet-700 dark:bg-violet-950/50 dark:text-violet-100"
                                                    role="status"
                                                    aria-live="polite"
                                                >
                                                    <ArrowPathIcon className="h-4 w-4 shrink-0 animate-spin" aria-hidden />
                                                    Working on copy…
                                                </div>
                                            )}
                                            <div className="flex flex-wrap gap-1">
                                                {(
                                                    [
                                                        ['Generate copy', 'generate' as const],
                                                        ['Improve copy', 'improve' as const],
                                                        ['Shorten', 'shorten' as const],
                                                        ['Make more premium', 'premium' as const],
                                                        ['Align with brand tone', 'align_tone' as const],
                                                    ] as const
                                                ).map(([label, op]) => (
                                                    <button
                                                        key={op}
                                                        type="button"
                                                        disabled={
                                                            selectedLayer.locked ||
                                                            copyAssistLoadingId === selectedLayer.id
                                                        }
                                                        onClick={() => void runCopyAssist(op)}
                                                        className="rounded border border-violet-300 bg-white px-2 py-1 text-[10px] font-medium text-violet-900 hover:bg-violet-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-violet-700 dark:bg-violet-950/40 dark:text-violet-100 dark:hover:bg-violet-900/50"
                                                    >
                                                        {label}
                                                    </button>
                                                ))}
                                            </div>
                                            {copyAssistError && (
                                                <div className="mt-2 flex flex-wrap items-start gap-2">
                                                    <p
                                                        className="min-w-0 flex-1 text-[10px] font-medium text-red-600 dark:text-red-400"
                                                        role="alert"
                                                    >
                                                        {copyAssistError}
                                                    </p>
                                                    <button
                                                        type="button"
                                                        className="shrink-0 rounded border border-red-200 bg-white px-2 py-0.5 text-[10px] font-semibold text-red-700 transition-colors hover:bg-red-50 dark:border-red-900 dark:bg-gray-900 dark:text-red-300 dark:hover:bg-red-950/40"
                                                        onClick={() => void runCopyAssist(copyAssistLastOpRef.current)}
                                                    >
                                                        Retry
                                                    </button>
                                                </div>
                                            )}
                                            {copyAssistScore && (
                                                <div className="mt-2 rounded border border-violet-200/80 bg-white/80 p-1.5 dark:border-violet-800 dark:bg-violet-950/40">
                                                    <p className="text-[10px] font-semibold text-gray-900 dark:text-gray-100">
                                                        Estimated brand voice alignment: {copyAssistScore.score}%
                                                    </p>
                                                    <p className="mt-0.5 text-[9px] text-gray-500 dark:text-gray-400">
                                                        Heuristic preview — not a measured guarantee.
                                                    </p>
                                                    {copyAssistScore.feedback.length > 0 && (
                                                        <ul className="mt-0.5 list-inside list-disc text-[9px] text-gray-600 dark:text-gray-400">
                                                            {copyAssistScore.feedback.map((f, i) => (
                                                                <li key={i}>{f}</li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                </div>
                                            )}
                                            {copyAssistSuggestions.length > 0 && (
                                                <div className="mt-2">
                                                    <p className="mb-0.5 text-[9px] font-medium text-gray-600 dark:text-gray-400">
                                                        Alternates (hover for full text)
                                                    </p>
                                                    <div className="flex flex-col gap-1.5">
                                                        {copyAssistSuggestions.map((sug, idx) => (
                                                            <div
                                                                key={`${idx}-${sug.label}`}
                                                                className={`rounded border border-gray-200 bg-white p-1.5 dark:border-gray-600 dark:bg-gray-900 ${
                                                                    copyAssistHoverIdx === idx
                                                                        ? 'ring-1 ring-indigo-400 dark:ring-indigo-500'
                                                                        : ''
                                                                }`}
                                                                title={sug.text}
                                                                onMouseEnter={() => setCopyAssistHoverIdx(idx)}
                                                                onMouseLeave={() =>
                                                                    setCopyAssistHoverIdx((h) =>
                                                                        h === idx ? null : h
                                                                    )
                                                                }
                                                            >
                                                                <p className="text-[9px] font-semibold text-indigo-800 dark:text-indigo-200">
                                                                    {sug.label}
                                                                </p>
                                                                <p className="mt-0.5 line-clamp-2 text-[10px] leading-snug text-gray-800 dark:text-gray-100">
                                                                    {sug.text}
                                                                </p>
                                                                <div className="mt-1 flex flex-wrap gap-1">
                                                                    <button
                                                                        type="button"
                                                                        disabled={selectedLayer.locked}
                                                                        onClick={() =>
                                                                            replaceWithCopySuggestion(
                                                                                selectedLayer.id,
                                                                                sug.text
                                                                            )
                                                                        }
                                                                        className="rounded bg-indigo-600 px-2 py-0.5 text-[9px] font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                                                                    >
                                                                        Replace
                                                                    </button>
                                                                    <button
                                                                        type="button"
                                                                        disabled={selectedLayer.locked}
                                                                        onClick={() =>
                                                                            insertCopySuggestionBelow(
                                                                                selectedLayer.id,
                                                                                sug.text
                                                                            )
                                                                        }
                                                                        className="rounded border border-gray-300 bg-white px-2 py-0.5 text-[9px] font-medium text-gray-800 hover:bg-gray-50 disabled:opacity-50 dark:border-gray-500 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                                                                    >
                                                                        Insert below
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                            {(selectedLayer.previousText?.length ?? 0) > 0 && (
                                                <button
                                                    type="button"
                                                    disabled={selectedLayer.locked}
                                                    onClick={revertLastCopy}
                                                    className="mt-2 text-[10px] font-medium text-violet-700 underline hover:text-violet-900 disabled:opacity-50 dark:text-violet-300"
                                                >
                                                    Revert last change
                                                </button>
                                            )}
                                            <div className="mt-2 border-t border-violet-200/80 pt-2 dark:border-violet-800">
                                                <p className="mb-1 text-[9px] font-medium text-gray-600 dark:text-gray-400">
                                                    Quick suggestions (same as image panel — updates copy here)
                                                </p>
                                                <div className="flex flex-wrap gap-1">
                                                    {SMART_SUGGESTIONS.map((s) => (
                                                        <button
                                                            key={s.action}
                                                            type="button"
                                                            disabled={
                                                                selectedLayer.locked ||
                                                                copyAssistLoadingId === selectedLayer.id
                                                            }
                                                            onClick={() => applySuggestionAction(s.action)}
                                                            className="rounded border border-gray-200 bg-gray-50 px-2 py-0.5 text-[10px] text-gray-800 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                                        >
                                                            {s.label}
                                                        </button>
                                                    ))}
                                                    {brandContext?.tone?.[0] && (
                                                        <button
                                                            type="button"
                                                            disabled={
                                                                selectedLayer.locked ||
                                                                copyAssistLoadingId === selectedLayer.id
                                                            }
                                                            onClick={() => applySuggestionAction('tone')}
                                                            className="rounded border border-gray-200 bg-gray-50 px-2 py-0.5 text-[10px] text-gray-800 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                                        >
                                                            Align with brand tone
                                                        </button>
                                                    )}
                                                    {!!brandContext?.colors?.length && (
                                                        <button
                                                            type="button"
                                                            disabled={
                                                                selectedLayer.locked ||
                                                                copyAssistLoadingId === selectedLayer.id
                                                            }
                                                            onClick={() => applySuggestionAction('colors')}
                                                            className="rounded border border-gray-200 bg-gray-50 px-2 py-0.5 text-[10px] text-gray-800 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                                        >
                                                            Use brand colors
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        </div>

                                        <div>
                                            <div className="flex items-center gap-2">
                                                <input
                                                    id={`autofit-${selectedLayer.id}`}
                                                    type="checkbox"
                                                    checked={selectedLayer.style.autoFit === true}
                                                    title="Auto-fit adjusts font size to fit the box"
                                                    onChange={(e) =>
                                                        updateLayer(selectedLayer.id, (l) => {
                                                            if (!isTextLayer(l)) {
                                                                return l
                                                            }
                                                            return {
                                                                ...l,
                                                                style: { ...l.style, autoFit: e.target.checked },
                                                            }
                                                        })
                                                    }
                                                    className="rounded border-gray-300"
                                                />
                                                <label
                                                    htmlFor={`autofit-${selectedLayer.id}`}
                                                    className="font-medium text-gray-700 dark:text-gray-300"
                                                    title="Auto-fit adjusts font size to fit the box"
                                                >
                                                    Auto-fit text
                                                </label>
                                            </div>
                                            <p className="mt-1 text-[10px] leading-snug text-gray-500 dark:text-gray-400">
                                                Auto-fit adjusts font size to fit the box. Resize the layer to change
                                                the box; font size follows when this is on.
                                            </p>
                                        </div>

                                        <div>
                                            <p className="mb-1 font-medium text-gray-700 dark:text-gray-300">Color</p>
                                            {(() => {
                                                const labeled = labeledBrandPalette(brandContext)
                                                if (labeled.length === 0) {
                                                    return null
                                                }
                                                return (
                                                    <div className="mb-2">
                                                        <p className="mb-1 text-[10px] font-medium uppercase tracking-wide text-gray-500">
                                                            Brand colors
                                                        </p>
                                                        <div className="flex flex-wrap items-end gap-2">
                                                            {labeled.map(({ label, color: c }) => {
                                                                const active = colorsMatch(c, selectedLayer.style.color)
                                                                return (
                                                                    <div
                                                                        key={`${label}-${c}`}
                                                                        className="flex flex-col items-center gap-0.5"
                                                                    >
                                                                        <button
                                                                            type="button"
                                                                            title={`${label} brand color`}
                                                                            className={`h-7 w-7 rounded border-2 shadow-sm ${
                                                                                active
                                                                                    ? 'border-indigo-600 ring-2 ring-indigo-300 dark:border-indigo-400 dark:ring-indigo-700'
                                                                                    : 'border-gray-300 dark:border-gray-600'
                                                                            }`}
                                                                            style={{ backgroundColor: c }}
                                                                            onClick={() =>
                                                                                updateLayer(selectedLayer.id, (l) => {
                                                                                    if (!isTextLayer(l)) {
                                                                                        return l
                                                                                    }
                                                                                    return {
                                                                                        ...l,
                                                                                        style: { ...l.style, color: c },
                                                                                    }
                                                                                })
                                                                            }
                                                                        />
                                                                        <span className="max-w-[4.5rem] truncate text-center text-[9px] font-medium text-gray-600 dark:text-gray-400">
                                                                            {label}
                                                                        </span>
                                                                    </div>
                                                                )
                                                            })}
                                                        </div>
                                                        {labeled.some(({ color: c }) =>
                                                            colorsMatch(c, selectedLayer.style.color)
                                                        ) && (
                                                            <p className="mt-1 text-[10px] text-indigo-600 dark:text-indigo-400">
                                                                Brand color
                                                            </p>
                                                        )}
                                                    </div>
                                                )
                                            })()}

                                            <p className="mb-1 text-[10px] font-medium uppercase tracking-wide text-gray-500">
                                                Quick colors
                                            </p>
                                            <div className="mb-2 flex flex-wrap gap-1.5">
                                                {(['#ffffff', '#000000'] as const).map((c) => {
                                                    const active = colorsMatch(c, selectedLayer.style.color)
                                                    return (
                                                        <button
                                                            key={c}
                                                            type="button"
                                                            className={`h-7 w-7 rounded border-2 shadow-sm ${
                                                                active
                                                                    ? 'border-indigo-600 ring-2 ring-indigo-300 dark:border-indigo-400'
                                                                    : 'border-gray-300 dark:border-gray-600'
                                                            }`}
                                                            style={{ backgroundColor: c }}
                                                            aria-label={c === '#ffffff' ? 'White' : 'Black'}
                                                            onClick={() =>
                                                                updateLayer(selectedLayer.id, (l) => {
                                                                    if (!isTextLayer(l)) {
                                                                        return l
                                                                    }
                                                                    return {
                                                                        ...l,
                                                                        style: { ...l.style, color: c },
                                                                    }
                                                                })
                                                            }
                                                        />
                                                    )
                                                })}
                                            </div>

                                            <div className="my-2 border-t border-gray-200 dark:border-gray-700" />

                                            <label className="mb-1 block text-[10px] font-medium uppercase tracking-wide text-gray-500">
                                                Custom
                                            </label>
                                            <input
                                                type="color"
                                                value={
                                                    /^#[0-9a-fA-F]{6}$/i.test(selectedLayer.style.color)
                                                        ? selectedLayer.style.color
                                                        : '#111827'
                                                }
                                                onChange={(e) =>
                                                    updateLayer(selectedLayer.id, (l) => {
                                                        if (!isTextLayer(l)) {
                                                            return l
                                                        }
                                                        return {
                                                            ...l,
                                                            style: { ...l.style, color: e.target.value },
                                                        }
                                                    })
                                                }
                                                className="h-9 w-full cursor-pointer rounded border border-gray-300 dark:border-gray-600"
                                            />
                                        </div>

                                        <div>
                                            <label className="mb-1 block font-medium text-gray-700 dark:text-gray-300">
                                                Horizontal align
                                            </label>
                                            <div className="flex gap-1">
                                                {(['left', 'center', 'right'] as const).map((al) => (
                                                    <button
                                                        key={al}
                                                        type="button"
                                                        className={`flex-1 rounded border px-1 py-1 text-[10px] font-medium capitalize ${
                                                            (selectedLayer.style.textAlign ?? 'left') === al
                                                                ? 'border-indigo-500 bg-indigo-50 text-indigo-900 dark:border-indigo-400 dark:bg-indigo-950/50 dark:text-indigo-100'
                                                                : 'border-gray-300 dark:border-gray-600'
                                                        }`}
                                                        onClick={() =>
                                                            updateLayer(selectedLayer.id, (l) => {
                                                                if (!isTextLayer(l)) {
                                                                    return l
                                                                }
                                                                return {
                                                                    ...l,
                                                                    style: { ...l.style, textAlign: al },
                                                                }
                                                            })
                                                        }
                                                    >
                                                        {al}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>

                                        <div>
                                            <label className="mb-1 block font-medium text-gray-700 dark:text-gray-300">
                                                Vertical align
                                            </label>
                                            <div className="flex gap-1">
                                                {(['top', 'middle', 'bottom'] as const).map((al) => (
                                                    <button
                                                        key={al}
                                                        type="button"
                                                        className={`flex-1 rounded border px-1 py-1 text-[10px] font-medium capitalize ${
                                                            (selectedLayer.style.verticalAlign ?? 'top') === al
                                                                ? 'border-indigo-500 bg-indigo-50 text-indigo-900 dark:border-indigo-400 dark:bg-indigo-950/50 dark:text-indigo-100'
                                                                : 'border-gray-300 dark:border-gray-600'
                                                        }`}
                                                        onClick={() =>
                                                            updateLayer(selectedLayer.id, (l) => {
                                                                if (!isTextLayer(l)) {
                                                                    return l
                                                                }
                                                                return {
                                                                    ...l,
                                                                    style: { ...l.style, verticalAlign: al },
                                                                }
                                                            })
                                                        }
                                                    >
                                                        {al}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    </>
                                )}
                            </div>
                        )}
                    </div>
                    </aside>
                </div>
            </div>

            {openCompositionPicker && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="open-composition-dialog-title"
                >
                    <div className="flex max-h-[85vh] w-full max-w-md flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900">
                        <div className="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                            <h3
                                id="open-composition-dialog-title"
                                className="text-sm font-semibold text-gray-900 dark:text-gray-100"
                            >
                                Open composition
                            </h3>
                            <button
                                type="button"
                                className="rounded p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800"
                                onClick={() => setOpenCompositionPicker(false)}
                                aria-label="Close"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>
                        <div className="max-h-[60vh] overflow-y-auto p-3 text-xs">
                            {compositionListLoading && (
                                <div
                                    className="flex items-center gap-2 py-6 text-gray-600 dark:text-gray-300"
                                    role="status"
                                    aria-busy="true"
                                >
                                    <ArrowPathIcon className="h-5 w-5 shrink-0 animate-spin text-indigo-600 dark:text-indigo-400" />
                                    Loading compositions…
                                </div>
                            )}
                            {compositionListError && (
                                <p className="text-red-600 dark:text-red-400">{compositionListError}</p>
                            )}
                            {!compositionListLoading &&
                                !compositionListError &&
                                compositionSummaries.length === 0 && (
                                    <p className="text-gray-500 dark:text-gray-400">
                                        No saved compositions yet. Use <strong>Save</strong> to create one; the
                                        address bar will include <code className="rounded bg-gray-100 px-1 dark:bg-gray-800">?composition=…</code> for bookmarks.
                                    </p>
                                )}
                            {compositionSummaries.map((c) => (
                                <div
                                    key={c.id}
                                    className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 py-2.5 dark:border-gray-800"
                                >
                                    <div className="flex min-w-0 flex-1 items-start gap-3">
                                        {c.thumbnail_url ? (
                                            <img
                                                key={`open-comp-${c.id}-${c.updated_at}`}
                                                src={`${c.thumbnail_url}${c.thumbnail_url.includes('?') ? '&' : '?'}rid=${encodeURIComponent(c.id)}`}
                                                alt=""
                                                className="h-14 w-14 shrink-0 rounded-lg object-cover shadow-sm ring-1 ring-gray-200 dark:ring-gray-600"
                                            />
                                        ) : (
                                            <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-[9px] font-medium text-gray-400 dark:bg-gray-800 dark:text-gray-500">
                                                No
                                                <br />
                                                preview
                                            </div>
                                        )}
                                        <div className="min-w-0">
                                            <div className="font-medium text-gray-900 dark:text-gray-100">
                                                {c.name || 'Untitled'}
                                            </div>
                                            <div className="text-gray-500 dark:text-gray-400">
                                                {c.updated_at
                                                    ? new Date(c.updated_at).toLocaleString()
                                                    : ''}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-1.5">
                                        <button
                                            type="button"
                                            disabled={compositionDeleteBusy}
                                            className="rounded border border-gray-300 bg-white p-1.5 text-gray-600 hover:bg-red-50 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-red-950/50 dark:hover:text-red-300"
                                            title="Delete composition"
                                            aria-label={`Delete ${c.name || 'composition'}`}
                                            onClick={() => void deleteCompositionById(c.id)}
                                        >
                                            <TrashIcon className="h-4 w-4" aria-hidden />
                                        </button>
                                        <button
                                            type="button"
                                            className="shrink-0 rounded border border-indigo-600 bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-900 hover:bg-indigo-100 dark:border-indigo-500 dark:bg-indigo-950/50 dark:text-indigo-100 dark:hover:bg-indigo-900/40"
                                            onClick={() => navigateToComposition(c.id)}
                                        >
                                            Open
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            )}

            {historyOpen && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="history-dialog-title"
                >
                    <div className="flex max-h-[85vh] w-full max-w-md flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900">
                        <div className="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                            <h3
                                id="history-dialog-title"
                                className="text-sm font-semibold text-gray-900 dark:text-gray-100"
                            >
                                History
                            </h3>
                            <button
                                type="button"
                                className="rounded p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800"
                                onClick={() => setHistoryOpen(false)}
                                aria-label="Close"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>
                        <div className="max-h-[60vh] overflow-y-auto p-3 text-xs">
                            {versionsLoading && (
                                <div
                                    className="flex items-center gap-2 py-6 text-gray-600 dark:text-gray-300"
                                    role="status"
                                    aria-busy="true"
                                >
                                    <ArrowPathIcon className="h-5 w-5 shrink-0 animate-spin text-indigo-600 dark:text-indigo-400" />
                                    Loading versions…
                                </div>
                            )}
                            {!compositionId && (
                                <p className="text-gray-500 dark:text-gray-400">
                                    Save the composition first to track versions.
                                </p>
                            )}
                            {compositionId &&
                                !versionsLoading &&
                                versions.length === 0 &&
                                !compositionLoadError && (
                                    <p className="text-gray-500 dark:text-gray-400">No versions yet.</p>
                                )}
                            {compositionLoadError && (
                                <p className="text-red-600 dark:text-red-400">{compositionLoadError}</p>
                            )}
                            {compositionId &&
                                versions.map((v) => (
                                    <div
                                        key={v.id}
                                        className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 py-2.5 dark:border-gray-800"
                                    >
                                        <div className="flex min-w-0 flex-1 items-start gap-3">
                                            {v.thumbnail_url ? (
                                                <img
                                                    src={v.thumbnail_url}
                                                    alt=""
                                                    className="h-14 w-14 shrink-0 rounded-lg object-cover shadow-sm ring-1 ring-gray-200 dark:ring-gray-600"
                                                />
                                            ) : (
                                                <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-[9px] font-medium text-gray-400 dark:bg-gray-800 dark:text-gray-500">
                                                    No
                                                    <br />
                                                    preview
                                                </div>
                                            )}
                                            <div className="min-w-0">
                                                <div className="font-medium text-gray-900 dark:text-gray-100">
                                                    {new Date(v.created_at).toLocaleString()}
                                                </div>
                                                {v.label && (
                                                    <div className="text-gray-500 dark:text-gray-400">{v.label}</div>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 gap-1">
                                            <button
                                                type="button"
                                                className="rounded border border-gray-300 bg-white px-2 py-1 text-gray-800 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                                                onClick={() => void loadVersionIntoEditor(v.id)}
                                            >
                                                Load
                                            </button>
                                            <button
                                                type="button"
                                                className="rounded border border-gray-300 bg-white px-2 py-1 text-gray-800 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                                                onClick={() => void duplicateVersionAsNewComposition(v.id)}
                                            >
                                                Duplicate
                                            </button>
                                        </div>
                                    </div>
                                ))}
                        </div>
                    </div>
                </div>
            )}

            {compareOpen && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="compare-dialog-title"
                >
                    <div className="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900">
                        <div className="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                            <h3
                                id="compare-dialog-title"
                                className="text-sm font-semibold text-gray-900 dark:text-gray-100"
                            >
                                Compare versions
                            </h3>
                            <button
                                type="button"
                                className="rounded p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800"
                                onClick={() => {
                                    setCompareOpen(false)
                                    setCompareUrls(null)
                                }}
                                aria-label="Close"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>
                        <div className="space-y-4 overflow-y-auto p-4 text-xs">
                            <p className="text-gray-500 dark:text-gray-400">
                                Image preview only (no layer diff). Drag the slider to compare A vs B.
                            </p>
                            <div className="flex flex-wrap items-end gap-3">
                                <div>
                                    <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500">Version A (left)</label>
                                    <select
                                        value={compareLeftId ?? ''}
                                        onChange={(e) => setCompareLeftId(e.target.value || null)}
                                        className="max-w-[220px] rounded border border-gray-300 bg-white px-2 py-1.5 text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    >
                                        <option value="">Select…</option>
                                        {versions.map((v) => (
                                            <option key={v.id} value={v.id}>
                                                {v.label ? `${v.label} — ` : ''}
                                                {new Date(v.created_at).toLocaleString()}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500">Version B (right)</label>
                                    <select
                                        value={compareRightId ?? ''}
                                        onChange={(e) => setCompareRightId(e.target.value || null)}
                                        className="max-w-[220px] rounded border border-gray-300 bg-white px-2 py-1.5 text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    >
                                        <option value="">Select…</option>
                                        {versions.map((v) => (
                                            <option key={`r-${v.id}`} value={v.id}>
                                                {v.label ? `${v.label} — ` : ''}
                                                {new Date(v.created_at).toLocaleString()}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <button
                                    type="button"
                                    disabled={
                                        compareBusy ||
                                        !compareLeftId ||
                                        !compareRightId ||
                                        compareLeftId === compareRightId
                                    }
                                    onClick={() => void runCompareCapture()}
                                    className="rounded-md bg-indigo-600 px-3 py-1.5 font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {compareBusy ? 'Rendering…' : 'Render preview'}
                                </button>
                            </div>
                            {compareLeftId &&
                                compareRightId &&
                                compareLeftId === compareRightId && (
                                    <p className="text-amber-700 dark:text-amber-400" role="status">
                                        Select two different versions to compare.
                                    </p>
                                )}
                            {compareUrls && (
                                <div className="space-y-3">
                                    <div className="flex flex-col gap-1 sm:flex-row sm:justify-between sm:gap-4">
                                        <div className="rounded-md border border-indigo-200 bg-indigo-50/80 px-2 py-1.5 text-[11px] text-indigo-950 dark:border-indigo-950 dark:bg-indigo-950/40 dark:text-indigo-100">
                                            <span className="font-semibold">A</span>{' '}
                                            {compareLeftMeta?.label && (
                                                <span className="text-indigo-800 dark:text-indigo-200">
                                                    {compareLeftMeta.label} ·{' '}
                                                </span>
                                            )}
                                            <span className="text-indigo-700/90 dark:text-indigo-300/90">
                                                {compareLeftMeta
                                                    ? new Date(compareLeftMeta.created_at).toLocaleString()
                                                    : '—'}
                                            </span>
                                        </div>
                                        <div className="rounded-md border border-violet-200 bg-violet-50/80 px-2 py-1.5 text-[11px] text-violet-950 dark:border-violet-950 dark:bg-violet-950/40 dark:text-violet-100">
                                            <span className="font-semibold">B</span>{' '}
                                            {compareRightMeta?.label && (
                                                <span className="text-violet-800 dark:text-violet-200">
                                                    {compareRightMeta.label} ·{' '}
                                                </span>
                                            )}
                                            <span className="text-violet-700/90 dark:text-violet-300/90">
                                                {compareRightMeta
                                                    ? new Date(compareRightMeta.created_at).toLocaleString()
                                                    : '—'}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="relative overflow-hidden rounded-xl border border-gray-200 bg-neutral-100 shadow-inner dark:border-gray-600 dark:bg-neutral-900">
                                        <img
                                            src={compareUrls[1]}
                                            alt="Version B"
                                            className="relative z-0 block w-full object-contain"
                                        />
                                        <img
                                            src={compareUrls[0]}
                                            alt="Version A"
                                            className="absolute left-0 top-0 z-10 h-full w-full object-contain"
                                            style={{
                                                clipPath: `inset(0 ${100 - compareSlider}% 0 0)`,
                                            }}
                                        />
                                        <div
                                            className="pointer-events-none absolute inset-y-0 z-20 w-0.5 bg-white shadow-[0_0_0_1px_rgba(0,0,0,0.15)]"
                                            style={{ left: `${compareSlider}%`, transform: 'translateX(-50%)' }}
                                        />
                                    </div>
                                    <label className="flex items-center gap-3 text-[11px] text-gray-600 dark:text-gray-400">
                                        <span className="shrink-0 font-medium text-gray-800 dark:text-gray-200">A</span>
                                        <input
                                            type="range"
                                            min={0}
                                            max={100}
                                            value={compareSlider}
                                            onChange={(e) => setCompareSlider(Number(e.target.value))}
                                            className="h-2 w-full cursor-ew-resize accent-indigo-600"
                                        />
                                        <span className="shrink-0 font-medium text-gray-800 dark:text-gray-200">B</span>
                                    </label>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {publishModalOpen && (
                <div
                    className="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Publish to library"
                >
                    <div className="flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900">
                        <div className="flex shrink-0 items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                            <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">Publish</h3>
                            <button
                                type="button"
                                className="rounded p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800"
                                onClick={() => !promoteSaving && setPublishModalOpen(false)}
                                disabled={promoteSaving}
                                aria-label="Close"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>
                        <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4 text-sm">
                            <p className="text-xs text-gray-600 dark:text-gray-400">
                                Publishes a PNG of the canvas through the same upload pipeline as the main asset uploader.
                                Choose a library or deliverable category; category-specific metadata uses the same schema
                                as uploads.
                            </p>
                            {publishCategoriesLoading && (
                                <p
                                    className="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300"
                                    role="status"
                                    aria-busy="true"
                                >
                                    <ArrowPathIcon className="h-4 w-4 shrink-0 animate-spin text-indigo-600 dark:text-indigo-400" />
                                    Loading categories…
                                </p>
                            )}
                            {publishCategoriesError && (
                                <p className="text-xs text-red-600 dark:text-red-400">{publishCategoriesError}</p>
                            )}
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                                    Title
                                </span>
                                <input
                                    type="text"
                                    value={publishTitle}
                                    onChange={(e) => setPublishTitle(e.target.value)}
                                    className="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    disabled={promoteSaving}
                                />
                            </label>
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                                    Category
                                </span>
                                <select
                                    value={publishCategoryId === '' ? '' : String(publishCategoryId)}
                                    onChange={(e) => {
                                        setPublishCategoryId(e.target.value === '' ? '' : Number(e.target.value))
                                        setPublishMetadataValues({})
                                        setPublishCollectionIds([])
                                        setPublishMetadataShowErrors(false)
                                    }}
                                    className="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    disabled={promoteSaving || publishCategories.length === 0}
                                >
                                    {publishCategories.length === 0 ? (
                                        <option value="">No categories</option>
                                    ) : (
                                        <>
                                            {publishCategories.some((c) => c.asset_type === 'asset') && (
                                                <optgroup label="Asset">
                                                    {publishCategories
                                                        .filter((c) => c.asset_type === 'asset')
                                                        .map((c) => (
                                                            <option key={c.id} value={c.id}>
                                                                {c.name}
                                                            </option>
                                                        ))}
                                                </optgroup>
                                            )}
                                            {publishCategories.some((c) => c.asset_type === 'deliverable') && (
                                                <optgroup label="Executions">
                                                    {publishCategories
                                                        .filter((c) => c.asset_type === 'deliverable')
                                                        .map((c) => (
                                                            <option key={c.id} value={c.id}>
                                                                {c.name}
                                                            </option>
                                                        ))}
                                                </optgroup>
                                            )}
                                        </>
                                    )}
                                </select>
                            </label>
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">
                                    Description <span className="font-normal text-gray-500">(optional)</span>
                                </span>
                                <textarea
                                    value={publishDescription}
                                    onChange={(e) => setPublishDescription(e.target.value)}
                                    rows={3}
                                    className="w-full resize-y rounded border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                    disabled={promoteSaving}
                                    placeholder="Shown in asset metadata as editor publish notes."
                                />
                            </label>
                            <div className="border-t border-gray-200 pt-3 dark:border-gray-700">
                                <p className="mb-2 text-xs font-medium text-gray-700 dark:text-gray-300">
                                    Metadata <span className="font-normal text-gray-500">(from category)</span>
                                </p>
                                {publishMetadataLoading && (
                                    <p
                                        className="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300"
                                        role="status"
                                        aria-busy="true"
                                    >
                                        <ArrowPathIcon className="h-4 w-4 shrink-0 animate-spin text-indigo-600 dark:text-indigo-400" />
                                        Loading metadata fields…
                                    </p>
                                )}
                                {publishMetadataError && (
                                    <p className="text-xs text-red-600 dark:text-red-400">{publishMetadataError}</p>
                                )}
                                {!publishMetadataLoading &&
                                    publishMetadataSchema?.groups &&
                                    publishMetadataSchema.groups.length > 0 && (
                                        <div className="max-h-[min(40vh,320px)] overflow-y-auto rounded border border-gray-100 bg-gray-50/80 p-2 dark:border-gray-700 dark:bg-gray-800/50">
                                            <MetadataGroups
                                                groups={publishMetadataSchema.groups}
                                                values={publishMetadataValues}
                                                onChange={(key: string, value: unknown) => {
                                                    setPublishMetadataValues((prev) => ({ ...prev, [key]: value }))
                                                }}
                                                disabled={promoteSaving}
                                                showErrors={publishMetadataShowErrors}
                                                collectionProps={{
                                                    collections: publishCollectionsList,
                                                    collectionsLoading: publishCollectionsLoading,
                                                    selectedIds: publishCollectionIds,
                                                    onChange: setPublishCollectionIds,
                                                    showCreateButton: false,
                                                }}
                                            />
                                        </div>
                                    )}
                                {!publishMetadataLoading &&
                                    !publishMetadataError &&
                                    publishMetadataSchema?.groups &&
                                    publishMetadataSchema.groups.length === 0 && (
                                        <p className="text-xs text-gray-500">No extra metadata fields for this category.</p>
                                    )}
                            </div>
                        </div>
                        <div className="flex shrink-0 justify-end gap-2 border-t border-gray-200 px-4 py-3 dark:border-gray-700">
                            <button
                                type="button"
                                className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
                                onClick={() => !promoteSaving && setPublishModalOpen(false)}
                                disabled={promoteSaving}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                className="rounded-md border border-emerald-600 bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50"
                                onClick={() => void submitPublishModal()}
                                disabled={
                                    promoteSaving ||
                                    publishCategoriesLoading ||
                                    publishMetadataLoading ||
                                    publishCategories.length === 0 ||
                                    publishCategoryId === ''
                                }
                            >
                                {promoteSaving ? 'Publishing…' : 'Publish'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {pickerOpen && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                    role="dialog"
                    aria-modal="true"
                    aria-label="Choose asset"
                >
                    <div className="flex max-h-[85vh] w-full max-w-3xl min-h-0 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-900">
                        <div className="flex shrink-0 items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                            <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                {pickerMode === 'references'
                                    ? `Reference images (max ${MAX_REFERENCE_ASSETS})`
                                    : pickerMode === 'replace'
                                      ? 'Replace image'
                                      : 'Add image from library'}
                            </h3>
                            <button
                                type="button"
                                className="rounded p-1 text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-800"
                                onClick={closeAssetPicker}
                                aria-label="Close"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>
                        <div className="shrink-0 border-b border-gray-200 px-4 py-2 dark:border-gray-700">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-[10px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                    Source
                                </span>
                                <div className="inline-flex rounded-md border border-gray-200 p-0.5 dark:border-gray-600">
                                    <button
                                        type="button"
                                        className={`rounded px-2.5 py-1 text-[11px] font-medium ${
                                            pickerScope === 'library'
                                                ? 'bg-indigo-600 text-white'
                                                : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800'
                                        }`}
                                        onClick={() => {
                                            setPickerScope('library')
                                            setPickerCategoryFilterId('')
                                        }}
                                    >
                                        Library
                                    </button>
                                    <button
                                        type="button"
                                        className={`rounded px-2.5 py-1 text-[11px] font-medium ${
                                            pickerScope === 'executions'
                                                ? 'bg-indigo-600 text-white'
                                                : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800'
                                        }`}
                                        onClick={() => {
                                            setPickerScope('executions')
                                            setPickerCategoryFilterId('')
                                        }}
                                    >
                                        Executions
                                    </button>
                                </div>
                                <label className="ml-auto flex items-center gap-1.5 text-[10px] text-gray-600 dark:text-gray-400">
                                    <span className="whitespace-nowrap">Category</span>
                                    <select
                                        className="max-w-[200px] rounded border border-gray-300 bg-white py-1 pl-1.5 pr-6 text-[11px] text-gray-800 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                                        value={pickerCategoryFilterId === '' ? '' : String(pickerCategoryFilterId)}
                                        onChange={(e) => {
                                            const v = e.target.value
                                            setPickerCategoryFilterId(v === '' ? '' : Number(v))
                                        }}
                                        disabled={pickerCategoriesLoading || pickerCategoriesForScope.length === 0}
                                    >
                                        <option value="">All categories</option>
                                        {pickerCategoriesForScope.map((c) => (
                                            <option key={c.id} value={c.id}>
                                                {c.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                            <p className="mt-2 text-[10px] text-gray-500 dark:text-gray-400">
                                <a
                                    href={pickerScope === 'executions' ? '/app/executions' : '/app/assets'}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="font-medium text-indigo-600 underline decoration-indigo-400/60 underline-offset-2 hover:text-indigo-500 dark:text-indigo-400"
                                >
                                    Open {pickerScope === 'executions' ? 'Executions' : 'Assets'}
                                </a>{' '}
                                in a new tab to upload or manage files, then return here and refresh the list.
                            </p>
                        </div>
                        {pickerMode === 'references' && referenceSelectionIds.length > 0 && (
                            <div className="shrink-0 border-b border-violet-200 bg-violet-50/70 px-4 py-2.5 dark:border-violet-800 dark:bg-violet-950/25">
                                <p className="mb-1.5 text-[10px] font-medium text-violet-900 dark:text-violet-200">
                                    Selected ({referenceSelectionIds.length} / {MAX_REFERENCE_ASSETS})
                                </p>
                                <div className="flex max-h-24 flex-wrap gap-2 overflow-y-auto">
                                    {referenceSelectionIds.map((rid) => {
                                        const refAsset =
                                            damAssets.find((x) => x.id === rid) ??
                                            extraDamAssets.find((x) => x.id === rid)
                                        return (
                                            <div
                                                key={rid}
                                                className="flex max-w-[200px] items-center gap-1.5 rounded-md border border-violet-200 bg-white pr-1 shadow-sm dark:border-violet-700 dark:bg-gray-900"
                                            >
                                                <div className="h-11 w-11 shrink-0 overflow-hidden rounded-l bg-gray-100 dark:bg-gray-800">
                                                    {refAsset ? (
                                                        <img
                                                            src={refAsset.thumbnail_url || refAsset.file_url}
                                                            alt=""
                                                            className="h-full w-full object-cover"
                                                        />
                                                    ) : (
                                                        <div className="flex h-full items-center justify-center text-[8px] text-gray-400">
                                                            …
                                                        </div>
                                                    )}
                                                </div>
                                                <span className="min-w-0 flex-1 truncate text-[10px] text-gray-700 dark:text-gray-200">
                                                    {refAsset?.name ?? 'Asset'}
                                                </span>
                                                <button
                                                    type="button"
                                                    className="shrink-0 rounded p-0.5 text-gray-500 hover:bg-gray-100 hover:text-gray-800 dark:hover:bg-gray-800 dark:hover:text-gray-100"
                                                    aria-label="Remove from selection"
                                                    onClick={() => toggleReferenceAssetInPicker(rid)}
                                                >
                                                    <XMarkIcon className="h-4 w-4" />
                                                </button>
                                            </div>
                                        )
                                    })}
                                </div>
                            </div>
                        )}
                        <div className="flex min-h-0 flex-1 flex-col overflow-y-auto p-4">
                            {pickerMode === 'references' && (
                                <p className="mb-3 text-[10px] text-gray-500 dark:text-gray-400">
                                    Tap assets to add them to your selection (you can pick more than one). Choose{' '}
                                    <strong className="font-semibold text-gray-700 dark:text-gray-300">
                                        Use selection
                                    </strong>{' '}
                                    at the bottom when finished. {referenceSelectionIds.length} /{' '}
                                    {MAX_REFERENCE_ASSETS} selected.
                                </p>
                            )}
                            {damLoading && (
                                <div
                                    className="flex min-h-[180px] flex-col items-center justify-center gap-3 py-6"
                                    aria-busy="true"
                                    aria-label="Loading assets"
                                >
                                    <ArrowPathIcon className="h-8 w-8 animate-spin text-indigo-600 dark:text-indigo-400" />
                                    <span className="text-sm font-medium text-gray-600 dark:text-gray-300">
                                        Loading library…
                                    </span>
                                    <div className="grid w-full max-w-md grid-cols-4 gap-2 opacity-60">
                                        {Array.from({ length: 4 }).map((_, i) => (
                                            <div
                                                key={i}
                                                className="aspect-square animate-pulse rounded bg-gray-200 dark:bg-gray-700"
                                            />
                                        ))}
                                    </div>
                                </div>
                            )}
                            {!damLoading && damError && (
                                <p className="text-center text-sm text-red-600 dark:text-red-400">{damError}</p>
                            )}
                            {!damLoading && !damError && damAssets.length === 0 && (
                                <p className="text-center text-sm text-gray-500">No assets available.</p>
                            )}
                            {!damLoading && !damError && damAssets.length > 0 && (
                                <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                                    {damAssets.map((a) => (
                                        <button
                                            key={a.id}
                                            type="button"
                                            className={`group flex flex-col overflow-hidden rounded border bg-gray-50 text-left transition hover:border-indigo-400 hover:ring-1 hover:ring-indigo-400 dark:bg-gray-800 ${
                                                pickerMode === 'references' &&
                                                referenceSelectionIds.includes(a.id)
                                                    ? 'border-violet-500 ring-2 ring-violet-400 ring-offset-1 dark:border-violet-400'
                                                    : pickerMode === 'replace' &&
                                                        pickerHighlightAssetId === a.id
                                                      ? 'border-indigo-500 ring-2 ring-indigo-400 ring-offset-1 dark:border-indigo-400'
                                                      : 'border-gray-200 dark:border-gray-600'
                                            }`}
                                            onClick={() => {
                                                if (pickerMode === 'references') {
                                                    toggleReferenceAssetInPicker(a.id)
                                                } else {
                                                    handlePickDamAsset(a)
                                                }
                                            }}
                                        >
                                            <div className="aspect-square w-full overflow-hidden bg-gray-200 dark:bg-gray-700">
                                                <img
                                                    src={a.thumbnail_url || a.file_url}
                                                    alt=""
                                                    className="h-full w-full object-cover"
                                                    loading="lazy"
                                                />
                                            </div>
                                            <span className="truncate px-1 py-1 text-[10px] text-gray-700 dark:text-gray-300">
                                                {a.name || 'Untitled'}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                        {pickerMode === 'references' && (
                            <div className="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
                                <button
                                    type="button"
                                    className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
                                    onClick={closeAssetPicker}
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    className="rounded-md bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-violet-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600"
                                    onClick={applyReferencePicker}
                                >
                                    Use selection
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    )
}
