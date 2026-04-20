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
    ChevronDownIcon,
    ChevronRightIcon,
    ClockIcon,
    DocumentDuplicateIcon,
    DocumentIcon,
    ExclamationTriangleIcon,
    RectangleGroupIcon,
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
    Group,
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
    generateId,
    generativeLayerToGenerationSize,
    GENERATIVE_PREVIOUS_RESULTS_MAX,
    nextZIndex,
    normalizeZ,
    isFillLayer,
    isMaskLayer,
    buildMaskStyleForLayer,
    findGroupForLayer,
    groupMemberLayers,
    unionRectForGroup,
    createGroup,
    createDefaultMaskLayer,
    ungroup as ungroupInDoc,
    updateGroup as updateGroupInDoc,
    addLayerToGroup as addLayerToGroupInDoc,
    removeLayerFromGroup as removeLayerFromGroupInDoc,
    parseDocumentFromApi,
    PLACEHOLDER_IMAGE_SRC,
    resolvedFillGradientStops,
    editorBridgeFileUrlForAssetId,
} from './documentModel'
import FillGradientStopField from './FillGradientStopField'
import { TEMPLATE_CATEGORIES, allFormats, blueprintToLayers, blueprintToLayersAndGroups, buildLayersForStyle, LAYOUT_STYLES, textBoostToFillFields, inferTextBoostStyle, type LayerBlueprint, type TemplateFormat, type TemplateCategory, type LayoutStyleId } from './templateConfig'
import { applyWizardAssetDefaults, fetchWizardDefaults, type WizardDefaults } from './wizardDefaults'
import GridOverlay from '../../Components/Editor/GridOverlay'
import PlacementPicker from '../../Components/Editor/PlacementPicker'
import {
    placementToXY,
    snapMove as snapEngineMove,
    snapResize as snapEngineResize,
    xyToPlacement,
    type GridDensity,
    type Placement,
    type SnapHit,
    type SnapMode,
} from '../../utils/snapEngine'
import EditorConfirmDialog, { useEditorConfirm } from './EditorConfirmDialog'
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
import { compressImageBlobForLegacyUploadLimit, editorPublishFileByteBudget } from './editorPublishCompress'
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
const ASSET_EDITOR_GRID_ENABLED_KEY = 'asset-editor:grid-enabled'
const ASSET_EDITOR_SNAP_ENABLED_KEY = 'asset-editor:snap-enabled'
const ASSET_EDITOR_GRID_DENSITY_KEY = 'asset-editor:grid-density'
/**
 * Remember whether the user has the Canvas (document-level) section expanded
 * in the properties panel. Defaults to collapsed so it doesn't compete with
 * the layer editing workflow, but stays sticky once the user opens it.
 */
const ASSET_EDITOR_CANVAS_SECTION_KEY = 'asset-editor:canvas-section-open'
/** Target pixels (screen space) for line_align snap threshold — converted to doc space per-drag. */
const SNAP_THRESHOLD_SCREEN_PX = 8

function readStoredFlag(key: string, fallback: boolean): boolean {
    if (typeof window === 'undefined') return fallback
    const v = window.localStorage.getItem(key)
    if (v === '1' || v === 'true') return true
    if (v === '0' || v === 'false') return false
    return fallback
}

function readStoredGridDensity(): GridDensity {
    if (typeof window === 'undefined') return 3
    const n = Number(window.localStorage.getItem(ASSET_EDITOR_GRID_DENSITY_KEY))
    return n === 6 || n === 12 ? n : 3
}

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
/**
 * Rough luminance of a CSS hex color (`#rgb`, `#rrggbb`, or `#rrggbbaa`).
 * Returns a 0–1 value; white ≈ 1, black ≈ 0. Non-hex inputs return 0.5 (treat as mid-tone).
 * Used to decide whether a text-boost gradient needs to be forced dark to keep colored copy readable.
 */
function roughHexLuminance(hex: string | undefined | null): number {
    if (typeof hex !== 'string') return 0.5
    const trimmed = hex.trim()
    if (!trimmed.startsWith('#')) return 0.5
    const digits = trimmed.slice(1)
    let r = 0, g = 0, b = 0
    if (digits.length === 3) {
        r = parseInt(digits[0] + digits[0], 16)
        g = parseInt(digits[1] + digits[1], 16)
        b = parseInt(digits[2] + digits[2], 16)
    } else if (digits.length === 6 || digits.length === 8) {
        r = parseInt(digits.slice(0, 2), 16)
        g = parseInt(digits.slice(2, 4), 16)
        b = parseInt(digits.slice(4, 6), 16)
    } else {
        return 0.5
    }
    if (Number.isNaN(r) || Number.isNaN(g) || Number.isNaN(b)) return 0.5
    // Rec. 601 luma approximation — fine for contrast heuristics; we don't need WCAG precision here.
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255
}

/** Short relative-time label (e.g. "just now", "2m ago"). Used for the save-status indicator. */
function formatSavedAgo(savedAt: number, now: number): string {
    const elapsedSec = Math.max(0, Math.floor((now - savedAt) / 1000))
    if (elapsedSec < 10) return 'just now'
    if (elapsedSec < 60) return `${elapsedSec}s ago`
    const min = Math.floor(elapsedSec / 60)
    if (min < 60) return `${min}m ago`
    const hr = Math.floor(min / 60)
    if (hr < 24) return `${hr}h ago`
    return new Date(savedAt).toLocaleDateString()
}

/** Debounced autosave (document-only write) when a composition id exists. */
const AUTOSAVE_MS = 2500
/**
 * Rolling autosave SNAPSHOT cadence. While the user actively edits, we persist a
 * `kind: 'autosave'` version row at most this often so history has restore points
 * without spamming the version table. Older autosave rows are pruned server-side.
 */
const AUTOSAVE_SNAPSHOT_MS = 90_000

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

/**
 * Snapshot of a single member's transform captured on `beginMove`/`beginResize`
 * so we can re-apply the group's delta/scale proportionally without losing
 * precision to repeated floating-point rounding.
 */
type GroupMemberStart = {
    layerId: string
    x: number
    y: number
    width: number
    height: number
}

type DragState =
    | {
          kind: 'move'
          layerId: string
          startDocX: number
          startDocY: number
          startLayerX: number
          startLayerY: number
          /**
           * When the subject is a group, this is the list of member transforms
           * captured at drag-start. The handler moves *every* member by the
           * same dx/dy so the group travels as a rigid body. Snap still runs
           * against the union rect (which is the clicked layer's rect + all
           * sibling offsets).
           */
          groupMembers?: GroupMemberStart[]
          /** Union rect at drag-start — used as the snap subject for groups. */
          groupStartRect?: { x: number; y: number; width: number; height: number }
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
          /**
           * When resizing a group, every member is scaled proportionally
           * around the union rect. We capture the original union rect and each
           * member's relative position/size once and reapply on every move
           * event.
           */
          groupMembers?: GroupMemberStart[]
          groupStartRect?: { x: number; y: number; width: number; height: number }
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
                        <ArrowPathIcon className="h-7 w-7 animate-spin text-indigo-400" />
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
                    <ArrowPathIcon className="h-7 w-7 animate-spin text-indigo-400" />
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
        auth: {
            activeBrand?: { id?: number; name?: string; primary_color?: string | null }
            permissions?: { ai_enabled?: boolean }
        }
    }
    const aiEnabled = auth?.permissions?.ai_enabled !== false
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
    /**
     * When the selection is a *group* (not a single layer), this holds the
     * group id and `selectedLayerId` points at the clicked member so
     * text/image-specific property controls still have something to target.
     *
     * Clicking a grouped layer without modifiers → sets both.
     * Alt-clicking a grouped layer → sets layer only, clears group.
     * Empty-canvas click or non-grouped layer click → clears group.
     *
     * Never set manually — always go through `selectLayerOrGroup` below so
     * click semantics stay consistent across canvas + layer panel call sites.
     */
    const [selectedGroupId, setSelectedGroupIdState] = useState<string | null>(null)
    /**
     * Ad-hoc multi-select used *only* by the layer panel's "Group Selected"
     * flow. Populated by shift-clicking panel rows. Kept in a Set for O(1)
     * membership and always reset whenever we leave the grouping UI.
     *
     * Not used by the canvas — canvas selection stays single-layer/group-based
     * (decision doc: no marquee select in v1).
     */
    const [groupingSelection, setGroupingSelection] = useState<Set<string>>(() => new Set())
    // Mirror ref so synchronous click handlers (select → beginMove in the same
    // event) can read the selection that was just set without waiting for the
    // next render.
    const selectedGroupIdRef = useRef<string | null>(null)
    const setSelectedGroupId = useCallback((id: string | null) => {
        selectedGroupIdRef.current = id
        setSelectedGroupIdState(id)
    }, [])
    const [editingTextLayerId, setEditingTextLayerId] = useState<string | null>(null)
    const [pickerOpen, setPickerOpen] = useState(false)
    const [pickerMode, setPickerMode] = useState<'add' | 'replace' | 'references' | null>(null)
    /**
     * Guards against double-clicks on a picker tile: `handlePickDamAsset`
     * awaits an image-probe network hop before mutating state and closing
     * the modal, so a fast second click would otherwise land a second
     * layer. We set this to the in-flight asset id on click, gate the
     * handler and every other tile on it, and clear it in `finally`. A
     * ref mirror is also kept so the guard is synchronous — setState
     * alone would race the second click before React flushed.
     */
    const [pickerPickingAssetId, setPickerPickingAssetId] = useState<string | null>(null)
    const pickerPickingRef = useRef<string | null>(null)
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
    /**
     * Name that was last persisted to the server. Paired with `lastSavedSerialized` so renaming the
     * composition (without touching the canvas) still flips `dirty`, triggers autosave, and lights up
     * the "Unsaved" indicator — otherwise rename-only edits would silently never be saved.
     */
    const [lastSavedName, setLastSavedName] = useState<string>(() =>
        defaultCompositionName(createInitialDocument())
    )
    const [saveState, setSaveState] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle')
    const [saveError, setSaveError] = useState<string | null>(null)
    /** Wall-clock of the last successful save of any kind; used for "Saved Xs ago" indicator. */
    const [lastSavedAt, setLastSavedAt] = useState<number | null>(null)
    /** Coarse "now" ticker that refreshes relative-time labels every 30s without jitter. */
    const [savedRelativeTick, setSavedRelativeTick] = useState(0)
    useEffect(() => {
        const id = window.setInterval(() => setSavedRelativeTick((n) => n + 1), 30_000)
        return () => window.clearInterval(id)
    }, [])
    /** Timestamp of the last autosave version-row snapshot (not just document write). */
    const lastAutosaveSnapshotAtRef = useRef<number>(Date.now())
    /** Serialized doc captured at the time of the last autosave snapshot — skip snapshot if unchanged. */
    const lastAutosaveSnapshotSerializedRef = useRef<string>(JSON.stringify(initialDocumentRef.current!))
    const [compositionBootstrapping, setCompositionBootstrapping] = useState(Boolean(compositionIdFromUrl))
    const [propertiesPanelWidth, setPropertiesPanelWidth] = useState(readStoredPropertiesPanelWidth)
    const propertiesPanelWidthRef = useRef(propertiesPanelWidth)
    const [propertiesMode, setPropertiesMode] = useState<'basic' | 'advanced'>('basic')

    // Grid + snap state. `gridEnabled` drives the overlay; `snapEnabled` drives
    // whether drag/resize actually snap (Advanced mode only — Basic always snaps
    // so the user's mental model matches the Placement picker). `gridDensity`
    // is advanced-only; Basic stays on 3x3 to match the 9-slot picker.
    const [gridEnabled, setGridEnabled] = useState<boolean>(() => readStoredFlag(ASSET_EDITOR_GRID_ENABLED_KEY, true))
    const [snapEnabled, setSnapEnabled] = useState<boolean>(() => readStoredFlag(ASSET_EDITOR_SNAP_ENABLED_KEY, true))
    const [gridDensity, setGridDensity] = useState<GridDensity>(readStoredGridDensity)
    // Canvas (document-level) properties section — collapsed by default so
    // the layer editing content stays above the fold, but sticky across
    // page loads via localStorage.
    const [canvasSectionOpen, setCanvasSectionOpen] = useState<boolean>(() => readStoredFlag(ASSET_EDITOR_CANVAS_SECTION_KEY, false))
    useEffect(() => {
        if (typeof window === 'undefined') return
        window.localStorage.setItem(ASSET_EDITOR_CANVAS_SECTION_KEY, canvasSectionOpen ? '1' : '0')
    }, [canvasSectionOpen])
    const [snapHits, setSnapHits] = useState<SnapHit[]>([])
    const snapHitsClearTimerRef = useRef<number | null>(null)
    useEffect(() => {
        if (typeof window === 'undefined') return
        window.localStorage.setItem(ASSET_EDITOR_GRID_ENABLED_KEY, gridEnabled ? '1' : '0')
    }, [gridEnabled])
    useEffect(() => {
        if (typeof window === 'undefined') return
        window.localStorage.setItem(ASSET_EDITOR_SNAP_ENABLED_KEY, snapEnabled ? '1' : '0')
    }, [snapEnabled])
    useEffect(() => {
        if (typeof window === 'undefined') return
        window.localStorage.setItem(ASSET_EDITOR_GRID_DENSITY_KEY, String(gridDensity))
    }, [gridDensity])
    const [spinPhraseIdx, setSpinPhraseIdx] = useState(0)
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
    const [pickerSearch, setPickerSearch] = useState('')
    const [pickerView, setPickerView] = useState<'grid' | 'list'>('grid')
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
    const [userZoom, setUserZoom] = useState<number | null>(null)
    const [panOffset, setPanOffset] = useState({ x: 0, y: 0 })
    const isPanningRef = useRef(false)
    const panStartRef = useRef({ x: 0, y: 0, ox: 0, oy: 0 })
    const spaceHeldRef = useRef(false)
    const effectiveScale = userZoom ?? viewportScale

    // Derived snap mode: Basic mode always quadrant-locks (mirrors the Placement
    // picker). Advanced mode respects the snapEnabled toggle. Basic also forces
    // density to 3 regardless of stored value so the UI matches the picker.
    const effectiveSnapMode: SnapMode = propertiesMode === 'basic'
        ? 'cell_center'
        : (snapEnabled ? 'line_align' : 'off')
    const effectiveGridDensity: GridDensity = propertiesMode === 'basic' ? 3 : gridDensity
    /** Kept in a ref so the long-lived pointermove listener can read latest values
     *  without being torn down and recreated on every state change. */
    const snapConfigRef = useRef({
        mode: effectiveSnapMode,
        density: effectiveGridDensity,
        screenScale: effectiveScale,
    })
    snapConfigRef.current = {
        mode: effectiveSnapMode,
        density: effectiveGridDensity,
        screenScale: effectiveScale,
    }
    const reportSnapHits = useCallback((hits: SnapHit[]) => {
        setSnapHits(hits)
        if (snapHitsClearTimerRef.current !== null) {
            window.clearTimeout(snapHitsClearTimerRef.current)
        }
        if (hits.length > 0) {
            snapHitsClearTimerRef.current = window.setTimeout(() => {
                setSnapHits([])
                snapHitsClearTimerRef.current = null
            }, 180)
        }
    }, [])
    useEffect(() => {
        return () => {
            if (snapHitsClearTimerRef.current !== null) {
                window.clearTimeout(snapHitsClearTimerRef.current)
            }
        }
    }, [])

    const [leftPanel, setLeftPanel] = useState<'layers' | 'assets' | 'templates' | 'menu' | 'history' | null>('layers')
    const [addLayerOpen, setAddLayerOpen] = useState(false)
    const [shortcutsOpen, setShortcutsOpen] = useState(false)
    const [templateCategory, setTemplateCategory] = useState<TemplateCategory | 'all'>('all')
    const { confirmState, confirm: editorConfirm, handleClose: handleConfirmClose } = useEditorConfirm()
    const [wizardStep, setWizardStep] = useState(1)
    const [wizardCategory, setWizardCategory] = useState<TemplateCategory | 'all'>('all')
    const [wizardPlatform, setWizardPlatform] = useState<string | null>(null)
    const [wizardFormat, setWizardFormat] = useState<string | null>(null)
    const [wizardLayoutStyle, setWizardLayoutStyle] = useState<LayoutStyleId | null>(null)
    const [wizardName, setWizardName] = useState('')
    const [wizardSearch, setWizardSearch] = useState('')
    /** Per-blueprint-index override for the Customize sub-step of wizardStep 2.
     * Keyed by blueprint array index (stable once a layout style is chosen) so
     * we don't have to mutate blueprint identities. `enabled` defaults true;
     * `placement` falls back to the blueprint's xRatio/yRatio. */
    const [wizardLayerOverrides, setWizardLayerOverrides] = useState<Record<number, { enabled?: boolean; placement?: Placement }>>({})
    const [wizardSelectedLayerIdx, setWizardSelectedLayerIdx] = useState<number | null>(null)
    const [templateWizardOpen, setTemplateWizardOpenRaw] = useState(false)
    /**
     * When the user explicitly picks "Blank canvas — I'll build it myself"
     * from the welcome screen, suppress the welcome overlay so they can
     * actually start adding layers to an empty canvas. Without this the
     * overlay's `document.layers.length === 0` guard keeps showing because
     * a fresh composition legitimately has zero layers — there was no way
     * out other than picking a template. Resets the moment layers exist,
     * the composition changes, or a new composition is started.
     */
    const [welcomeDismissed, setWelcomeDismissed] = useState(false)
    /**
     * Cached per-brand wizard defaults (primary logo + photography candidates).
     * Fetched once per wizard-open so step 3 can auto-fill logo slots with the
     * brand's primary logo and background/hero_image slots with a real photo
     * instead of leaving them empty. Null while loading / on error; the wizard
     * treats that as "no auto-fill" and still works.
     */
    const [wizardDefaults, setWizardDefaults] = useState<WizardDefaults | null>(null)
    /**
     * Opt-out flag for wizard auto-fill, per session. Users who don't want the
     * brand photo/logo dropped in can toggle this off in step 3 and get the
     * classic empty-slot behavior. Defaults true because the whole point of
     * the feature is zero-config.
     */
    const [wizardAutoFillEnabled, setWizardAutoFillEnabled] = useState(true)
    const setTemplateWizardOpen = useCallback((v: boolean) => {
        setTemplateWizardOpenRaw(v)
        if (!v) {
            setWizardLayerOverrides({})
            setWizardSelectedLayerIdx(null)
        }
    }, [])

    // Load wizard defaults exactly once per open so the fetch doesn't refire
    // on every wizard-step change. We keep any previously-loaded value while
    // re-fetching so step 3 doesn't flash-empty between runs.
    useEffect(() => {
        if (!templateWizardOpen) return
        let cancelled = false
        void fetchWizardDefaults().then((d) => {
            if (!cancelled) setWizardDefaults(d)
        })
        return () => {
            cancelled = true
        }
    }, [templateWizardOpen])

    const [aiLayoutPromptOpen, setAiLayoutPromptOpen] = useState(false)
    const [aiLayoutPrompt, setAiLayoutPrompt] = useState('')
    const [aiLayoutLoading, setAiLayoutLoading] = useState(false)
    const [aiCreditStatus, setAiCreditStatus] = useState<{
        credits_used: number
        credits_cap: number
        credits_remaining: number
        is_unlimited: boolean
        is_exceeded: boolean
        warning_level: string
        generative_editor_used: number
        /**
         * Credits consumed by AI runs tied to the currently loaded composition,
         * this calendar month. Null when no composition is loaded yet (new draft)
         * or when the backend didn't return a value (legacy response shape).
         */
        this_composition_used: number | null
    } | null>(null)
    const [aiCreditPopoverOpen, setAiCreditPopoverOpen] = useState(false)

    // Read composition id from a ref so this callback stays stable — we don't want
    // a new function identity each time compositionId flips, because that would
    // rerun the load-time effect and double-fetch on mount.
    const refreshAiCreditStatus = useCallback(async () => {
        try {
            const cid = compositionIdRef.current
            const url = cid
                ? `/app/api/editor/ai-credit-status?composition_id=${encodeURIComponent(cid)}`
                : '/app/api/editor/ai-credit-status'
            const res = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
            if (!res.ok) return
            const data = await res.json()
            setAiCreditStatus(data)
        } catch {
            // Non-critical; fail quiet.
        }
    }, [])

    useEffect(() => {
        if (aiEnabled) void refreshAiCreditStatus()
    }, [aiEnabled, refreshAiCreditStatus])

    // When the user switches compositions (opens an existing draft, starts a new
    // one, etc.) re-pull the status so `this_composition_used` retargets. Gated
    // on aiEnabled to avoid noise for accounts without AI features.
    useEffect(() => {
        if (aiEnabled) void refreshAiCreditStatus()
    }, [aiEnabled, compositionId, refreshAiCreditStatus])

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
    const toggleGroupingSelection = useCallback((layerId: string) => {
        setGroupingSelection((prev) => {
            const next = new Set(prev)
            if (next.has(layerId)) {
                next.delete(layerId)
            } else {
                next.add(layerId)
            }
            return next
        })
    }, [])

    /**
     * Flat list of panel rows interleaving group headers with their members.
     *
     * Rendering rules:
     *   - Orphan layers (no groupId) appear as a plain layer row.
     *   - A group appears as a single header row immediately followed by its
     *     member rows (when not collapsed), indented.
     *   - A member is only rendered under its group — never as an orphan —
     *     which keeps the panel readable and matches the canvas's rigid-body
     *     selection model.
     *   - Group position in the panel = position of its top-most member (the
     *     one with the highest z). This keeps z-order readable.
     */
    const layerPanelRows = useMemo(() => {
        type Row =
            | { kind: 'layer'; layer: Layer }
            | { kind: 'group'; group: Group; members: Layer[] }
        const groups = document.groups ?? []
        const groupsById = new Map(groups.map((g) => [g.id, g]))
        // For every group, collect its members in panel order.
        const membersByGroup = new Map<string, Layer[]>()
        for (const g of groups) {
            const list: Layer[] = []
            for (const l of layersForPanel) {
                if (l.groupId === g.id) list.push(l)
            }
            membersByGroup.set(g.id, list)
        }
        const rows: Row[] = []
        const emittedGroups = new Set<string>()
        for (const layer of layersForPanel) {
            if (layer.groupId && groupsById.has(layer.groupId)) {
                if (emittedGroups.has(layer.groupId)) continue
                const group = groupsById.get(layer.groupId)!
                rows.push({
                    kind: 'group',
                    group,
                    members: membersByGroup.get(group.id) ?? [],
                })
                emittedGroups.add(group.id)
                continue
            }
            rows.push({ kind: 'layer', layer })
        }
        return rows
    }, [document.groups, layersForPanel])

    const selectedLayer = useMemo(
        () => document.layers.find((l) => l.id === selectedLayerId) ?? null,
        [document.layers, selectedLayerId]
    )

    /**
     * Shared renderer for layer rows (orphan + grouped).
     * `indented` = true when the row lives under a group header; we drop the
     * drag handle column for alignment since grouped members are reordered
     * inside their group via the existing drag-into-group flow rather than
     * free re-ordering.
     *
     * Kept as a plain closure (not `useCallback`) because some of its deps
     * — `onLayerPanelDragOver`, `onLayerPanelDrop`, `onLayerPanelDragStart`,
     * `selectLayerOrGroup` — are declared further down in the component
     * body. A `useCallback(... , [deps])` here would hit the temporal
     * dead zone on first render and throw a ReferenceError. It's only
     * called during render of the layer panel, so the per-render cost of
     * redefining this arrow is negligible.
     */
    const renderLayerPanelRow = (layer: Layer, indented: boolean) => {
        const selected = layer.id === selectedLayerId
        const inGroupingSet = groupingSelection.has(layer.id)
        const layerIcon = layer.type === 'text' ? 'T' : layer.type === 'image' ? '🖼' : layer.type === 'generative_image' ? '✦' : layer.type === 'fill' ? '◼' : layer.type === 'mask' ? '◑' : '▣'
        return (
            <li
                key={layer.id}
                onDragOver={onLayerPanelDragOver}
                onDrop={(e) => onLayerPanelDrop(e, layer.id)}
                className={`group/layer rounded ${layerDragId === layer.id ? 'opacity-60' : ''}`}
            >
                <div className={`flex items-center gap-1.5 rounded px-2 py-1.5 text-xs ${
                    selected
                        ? 'bg-blue-600/30 text-white'
                        : inGroupingSet
                            ? 'bg-indigo-700/25 text-white'
                            : 'text-gray-300 hover:bg-gray-800'
                }`}>
                    {!indented && (
                        <button
                            type="button"
                            draggable
                            onDragStart={(e) => { e.stopPropagation(); onLayerPanelDragStart(e, layer.id) }}
                            onDragEnd={() => setLayerDragId(null)}
                            className="shrink-0 cursor-grab text-gray-500 active:cursor-grabbing"
                            title="Drag to reorder"
                        >
                            <Bars3Icon className="h-3.5 w-3.5" aria-hidden />
                        </button>
                    )}
                    <span className="shrink-0 text-[10px] opacity-60 w-4 text-center">{layerIcon}</span>
                    <button
                        type="button"
                        draggable={false}
                        className="min-w-0 flex-1 truncate text-left font-medium"
                        onClick={(e) => {
                            // Shift-click builds the ad-hoc grouping selection.
                            // Plain click routes through `selectLayerOrGroup`
                            // so grouped layers still select their group.
                            if (e.shiftKey) {
                                e.preventDefault()
                                toggleGroupingSelection(layer.id)
                                return
                            }
                            selectLayerOrGroup(layer.id, { alt: e.altKey })
                            setEditingTextLayerId(null)
                        }}
                    >
                        {layer.name || layer.type}
                    </button>
                    <div className="flex shrink-0 items-center gap-0 opacity-0 transition-opacity group-hover/layer:opacity-100">
                        <button type="button" draggable={false} className="rounded p-0.5 text-gray-500 hover:bg-gray-700 hover:text-gray-200" title="Bring forward" onClick={(e) => { e.stopPropagation(); setDocument((d) => moveLayerZOrder(d, layer.id, 'up')) }}><ArrowUpIcon className="h-3 w-3" /></button>
                        <button type="button" draggable={false} className="rounded p-0.5 text-gray-500 hover:bg-gray-700 hover:text-gray-200" title="Send backward" onClick={(e) => { e.stopPropagation(); setDocument((d) => moveLayerZOrder(d, layer.id, 'down')) }}><ArrowDownIcon className="h-3 w-3" /></button>
                        {layer.groupId && (
                            <button
                                type="button"
                                draggable={false}
                                className="rounded px-1 text-[10px] text-gray-500 hover:bg-gray-700 hover:text-gray-200"
                                title="Remove from group"
                                onClick={(e) => {
                                    e.stopPropagation()
                                    setDocument((d) => removeLayerFromGroupInDoc(d, layer.id))
                                }}
                            >
                                ungroup
                            </button>
                        )}
                    </div>
                    <button type="button" draggable={false} className="shrink-0 text-gray-500 hover:text-gray-200" title={layer.visible ? 'Hide' : 'Show'} onClick={() => updateLayer(layer.id, (l) => ({ ...l, visible: !l.visible }))}>{layer.visible ? <EyeIcon className="h-3.5 w-3.5" /> : <EyeSlashIcon className="h-3.5 w-3.5 opacity-40" />}</button>
                </div>
            </li>
        )
    }
    /**
     * Union rect for the currently-selected group (null when nothing grouped
     * is selected). Rendered as a dashed outline over the canvas so users can
     * see the group's bounds — the individual member's per-layer ring is
     * suppressed when this is non-null to avoid double outlines.
     */
    const selectedGroupRect = useMemo(() => {
        if (!selectedGroupId) return null
        return unionRectForGroup(document, selectedGroupId)
    }, [document, selectedGroupId])

    // Auto-clear a stale `selectedGroupId` if the group got deleted (e.g. the
    // user swapped the whole document, loaded a different draft, or ungrouped
    // from the layer panel). Keeps UI from rendering a phantom outline.
    useEffect(() => {
        if (!selectedGroupId) return
        const exists = document.groups?.some((g) => g.id === selectedGroupId)
        if (!exists) {
            setSelectedGroupId(null)
        }
    }, [document.groups, selectedGroupId, setSelectedGroupId])

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

    /** Block AI actions when: AI disabled by admin, usage exhausted, or usage fetch failed. */
    const imageEditUsageBlocked = useMemo(
        () =>
            !aiEnabled ||
            genUsageError !== null ||
            (genUsage !== null && !canGenerateFromUsage(genUsage)),
        [aiEnabled, genUsage, genUsageError]
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
        () =>
            JSON.stringify(document) !== lastSavedSerialized
            || compositionName.trim() !== lastSavedName.trim(),
        [document, lastSavedSerialized, compositionName, lastSavedName]
    )

    /** True when there is something worth warning about before navigate / reset (not a fresh empty canvas). */
    const discardRequiresConfirmation = useMemo(
        () => dirty && !isBlankUnsavedCanvas(document, compositionId !== null),
        [dirty, document, compositionId]
    )

    useEffect(() => {
        if (!discardRequiresConfirmation) return
        const handler = (e: BeforeUnloadEvent) => {
            e.preventDefault()
            e.returnValue = ''
        }
        window.addEventListener('beforeunload', handler)
        return () => window.removeEventListener('beforeunload', handler)
    }, [discardRequiresConfirmation])

    const navGuardBypassRef = useRef(false)
    const navGuardPendingRef = useRef(false)

    useEffect(() => {
        if (!discardRequiresConfirmation) return
        const removeListener = router.on('before', (event) => {
            if (navGuardBypassRef.current) return
            const target = (event as any).detail?.visit?.url
            const targetPath = typeof target === 'string' ? target : target?.pathname ?? target?.href
            if (targetPath === window.location.pathname) return
            if (navGuardPendingRef.current) {
                ;(event as Event).preventDefault()
                return
            }
            ;(event as Event).preventDefault()
            navGuardPendingRef.current = true
            editorConfirm({
                title: 'Unsaved changes',
                message: 'You have unsaved changes that will be lost. Leave this page?',
                confirmText: 'Leave page',
                cancelText: 'Stay',
                variant: 'warning',
            }).then((ok) => {
                navGuardPendingRef.current = false
                if (ok && targetPath) {
                    navGuardBypassRef.current = true
                    router.visit(targetPath)
                }
            })
        })
        return () => {
            removeListener()
            navGuardBypassRef.current = false
            navGuardPendingRef.current = false
        }
    }, [discardRequiresConfirmation, editorConfirm])

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
        const t = window.setInterval(() => setSpinPhraseIdx((i) => i + 1), 4000)
        return () => window.clearInterval(t)
    }, [])

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
                    setLastSavedName(c.name ?? '')
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

    // Reset the rolling autosave-snapshot clock whenever we switch to a different composition.
    // Otherwise a long-idle editor would instantly create an autosave the moment a new comp is opened.
    // We intentionally only depend on compositionId; the ref reads the latest serialized doc at runtime.
    useEffect(() => {
        lastAutosaveSnapshotAtRef.current = Date.now()
        lastAutosaveSnapshotSerializedRef.current = lastSavedSerialized
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [compositionId])

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

                    // Decide whether this autosave tick should also persist a rolling snapshot.
                    // Snapshots create `composition_versions` rows (kind=autosave, pruned to 10 by server),
                    // giving the user restore points during long editing sessions without manual saves.
                    const serialized = JSON.stringify(doc)
                    const elapsedSinceSnapshot = Date.now() - lastAutosaveSnapshotAtRef.current
                    const changedSinceSnapshot = serialized !== lastAutosaveSnapshotSerializedRef.current
                    const shouldSnapshot = changedSinceSnapshot && elapsedSinceSnapshot >= AUTOSAVE_SNAPSHOT_MS

                    await putComposition(compositionId, doc, {
                        name,
                        versionLabel: null,
                        createVersion: shouldSnapshot,
                        versionKind: shouldSnapshot ? 'autosave' : undefined,
                        thumbnailPngBase64: thumb,
                    })

                    if (shouldSnapshot) {
                        lastAutosaveSnapshotAtRef.current = Date.now()
                        lastAutosaveSnapshotSerializedRef.current = serialized
                        // Refresh history sidebar so the new autosave row appears without a reload.
                        void refreshVersions()
                    }

                    setLastSavedSerialized(serialized)
                    setLastSavedName(name)
                    setLastSavedAt(Date.now())
                    setSaveState('saved')
                } catch (e) {
                    setSaveState('error')
                    setSaveError(handleAIError(e))
                }
            })()
        }, AUTOSAVE_MS)
        return () => window.clearTimeout(t)
    }, [document, compositionId, dirty, compositionName, refreshVersions])

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

    const fitToView = useCallback(() => {
        setUserZoom(null)
        setPanOffset({ x: 0, y: 0 })
    }, [])

    const zoomToActual = useCallback(() => {
        setUserZoom(1)
    }, [])

    const centerCanvas = useCallback(() => {
        setPanOffset({ x: 0, y: 0 })
    }, [])

    const zoomTo = useCallback((newScale: number) => {
        const clamped = Math.max(0.05, Math.min(8, newScale))
        setUserZoom(clamped)
    }, [])

    useEffect(() => {
        const el = canvasContainerRef.current
        if (!el) return undefined
        const onWheel = (e: WheelEvent) => {
            if (isPanningRef.current || spaceHeldRef.current) return
            if (e.ctrlKey || e.metaKey) {
                e.preventDefault()
                const delta = -e.deltaY * 0.002
                const cur = userZoom ?? viewportScale
                zoomTo(cur * (1 + delta))
            }
        }
        el.addEventListener('wheel', onWheel, { passive: false })
        return () => el.removeEventListener('wheel', onWheel)
    }, [userZoom, viewportScale, zoomTo])

    useEffect(() => {
        const isTypingTarget = () => {
            const a = globalThis.document.activeElement
            if (!a) return false
            const tag = a.tagName
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true
            if ((a as HTMLElement).isContentEditable) return true
            return false
        }
        const onKeyDown = (e: KeyboardEvent) => {
            if (e.code === 'Space' && !e.repeat && !editingTextLayerId && !isTypingTarget()) {
                e.preventDefault()
                e.stopPropagation()
                spaceHeldRef.current = true
                const el = canvasContainerRef.current
                if (el) el.style.cursor = 'grab'
            }
            // G toggles grid overlay; Shift+G toggles snap. Disabled while typing
            // so it doesn't hijack the keystroke inside text layers / inputs.
            if ((e.key === 'g' || e.key === 'G') && !e.metaKey && !e.ctrlKey && !e.altKey && !editingTextLayerId && !isTypingTarget()) {
                e.preventDefault()
                if (e.shiftKey) {
                    setSnapEnabled((v) => !v)
                } else {
                    setGridEnabled((v) => !v)
                }
            }
        }
        const onKeyUp = (e: KeyboardEvent) => {
            if (e.code === 'Space') {
                if (!isTypingTarget()) {
                    e.preventDefault()
                    e.stopPropagation()
                }
                spaceHeldRef.current = false
                isPanningRef.current = false
                const el = canvasContainerRef.current
                if (el) el.style.cursor = ''
            }
        }
        window.addEventListener('keydown', onKeyDown)
        window.addEventListener('keyup', onKeyUp)
        return () => {
            window.removeEventListener('keydown', onKeyDown)
            window.removeEventListener('keyup', onKeyUp)
        }
    }, [editingTextLayerId])

    const onCanvasPointerDown = useCallback((e: React.PointerEvent) => {
        if (spaceHeldRef.current) {
            e.preventDefault()
            e.stopPropagation()
            isPanningRef.current = true
            panStartRef.current = { x: e.clientX, y: e.clientY, ox: panOffset.x, oy: panOffset.y }
            const el = canvasContainerRef.current
            if (el) el.style.cursor = 'grabbing'
            ;(e.target as HTMLElement).setPointerCapture?.(e.pointerId)
        }
    }, [panOffset])

    const onCanvasPointerMove = useCallback((e: React.PointerEvent) => {
        if (!isPanningRef.current) return
        const dx = e.clientX - panStartRef.current.x
        const dy = e.clientY - panStartRef.current.y
        setPanOffset({ x: panStartRef.current.ox + dx, y: panStartRef.current.oy + dy })
    }, [])

    const onCanvasPointerUp = useCallback((e: React.PointerEvent) => {
        if (isPanningRef.current) {
            isPanningRef.current = false
            const el = canvasContainerRef.current
            if (el) el.style.cursor = spaceHeldRef.current ? 'grab' : ''
            ;(e.target as HTMLElement).releasePointerCapture?.(e.pointerId)
        }
    }, [])

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
            if (!aiEnabled) {
                setCopyAssistError('AI features are disabled for this workspace.')
                return
            }
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
                const resolvedName = c.name?.trim() ? c.name : UNTITLED_DRAFT_NAME
                setCompositionName(resolvedName)
                setLastSavedName(resolvedName)
                setDocument(doc)
                const serialized = JSON.stringify(doc)
                setLastSavedSerialized(serialized)
                lastAutosaveSnapshotSerializedRef.current = serialized
                replaceUrlCompositionParam(c.id)
            } else {
                await putComposition(compositionId, docSnapshot, {
                    name: nameToSave,
                    versionLabel: null,
                    createVersion: true,
                    versionKind: 'manual',
                    thumbnailPngBase64: thumb,
                })
                const serialized = JSON.stringify(documentRef.current)
                setLastSavedSerialized(serialized)
                setLastSavedName(nameToSave)
                lastAutosaveSnapshotSerializedRef.current = serialized
            }
            // Manual save resets the autosave-snapshot clock: no point creating an
            // autosave row moments after the user explicitly checkpointed.
            lastAutosaveSnapshotAtRef.current = Date.now()
            setLastSavedAt(Date.now())
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
            if (discardRequiresConfirmation) {
                const ok = await editorConfirm({ title: 'Unsaved changes', message: 'Discard unsaved changes and load this version?', confirmText: 'Load version', variant: 'warning' })
                if (!ok) return
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
        [compositionId, discardRequiresConfirmation, editorConfirm]
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
                setLastSavedName(c.name ?? '')
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
            setLastSavedName(c.name ?? '')
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
                flushSync(() => {
                    setDocument(doc)
                    setUiMode('preview')
                })
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
                flushSync(() => {
                    setDocument(prev)
                    setUiMode('edit')
                })
            } else {
                setUiMode('edit')
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
            // Mask gizmos, selection rings, and snap guides are edit-only
            // decorations. Switch to preview mode for the duration of the
            // rasterization so none of them leak into the exported PNG/JPG.
            const priorUiMode = uiMode
            flushSync(() => setUiMode('preview'))
            await new Promise<void>((r) =>
                requestAnimationFrame(() => requestAnimationFrame(() => r()))
            )
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
            try {
                const dataUrl =
                    kind === 'png'
                        ? await toPng(node, opts)
                        : await toJpeg(node, { ...opts, quality: 0.92 })
                const a = window.document.createElement('a')
                a.href = dataUrl
                a.download = `${fileStem}.${kind === 'png' ? 'png' : 'jpg'}`
                a.click()
                setActivityToast(kind === 'json' ? 'Document exported' : 'Image exported')
            } finally {
                flushSync(() => setUiMode(priorUiMode))
            }
        },
        [document, compositionName, uiMode]
    )

    const startNewComposition = useCallback(async (opts?: { skipDiscardConfirm?: boolean }) => {
        if (!opts?.skipDiscardConfirm && discardRequiresConfirmation) {
            const ok = await editorConfirm({ title: 'Unsaved changes', message: 'Discard unsaved changes and start a new composition?', confirmText: 'Start new', variant: 'warning' })
            if (!ok) return
        }
        const fresh = createInitialDocument()
        const freshName = defaultCompositionName(fresh)
        flushSync(() => {
            setCompositionId(null)
            setCompositionName(freshName)
            setLastSavedName(freshName)
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
            setWelcomeDismissed(false)
        })
        replaceUrlCompositionParam(null)
    }, [discardRequiresConfirmation, editorConfirm])

    const deleteCompositionById = useCallback(
        async (targetId: string) => {
            const ok = await editorConfirm({
                title: 'Delete composition',
                message: 'Delete this composition for everyone in this brand? The saved canvas and its version history will be removed. Images in your library are not deleted.',
                confirmText: 'Delete',
                variant: 'danger',
            })
            if (!ok) {
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
        [compositionId, startNewComposition, editorConfirm]
    )

    const openCompositionPickerAndLoad = useCallback(() => {
        setOpenCompositionPicker(true)
        setPickerSearch('')
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
        async (id: string) => {
            if (discardRequiresConfirmation) {
                const ok = await editorConfirm({ title: 'Unsaved changes', message: 'Discard unsaved changes and open this composition?', confirmText: 'Open', variant: 'warning' })
                if (!ok) return
            }
            setOpenCompositionPicker(false)
            router.visit(`/app/generative?composition=${encodeURIComponent(id)}`)
        },
        [discardRequiresConfirmation, editorConfirm]
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
            // Synchronous double-click guard. `pickerPickingRef` is checked
            // first (state updates don't flush fast enough to block a
            // near-simultaneous second click). Once claimed, every other
            // tile is dimmed via the `pickerPickingAssetId` state and
            // returns early here.
            if (pickerPickingRef.current) {
                return
            }
            pickerPickingRef.current = asset.id
            setPickerPickingAssetId(asset.id)
            setActivityToast(pickerMode === 'replace' ? 'Replacing image…' : 'Adding image…')
            try {
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
            } finally {
                pickerPickingRef.current = null
                setPickerPickingAssetId(null)
            }
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

            const exportStageForPublish = async () => {
                const node = stageRef.current
                if (!node) {
                    throw new Error('Stage not ready')
                }
                const doc = documentRef.current
                await waitForImagesToLoad(node)
                // Downscale large documents first — strict proxies often cap the *entire* POST near 1MiB.
                const maxEdge = 1920
                const docMax = Math.max(doc.width, doc.height, 1)
                const layoutScale = docMax > maxEdge ? maxEdge / docMax : 1
                const outW = Math.round(doc.width * layoutScale)
                const outH = Math.round(doc.height * layoutScale)
                const opts = {
                    cacheBust: true,
                    skipFonts: true,
                    pixelRatio: 1,
                    backgroundColor: '#ffffff',
                    width: outW,
                    height: outH,
                    fetchRequestInit: editorHtmlToImageFetchRequestInit,
                    style: {
                        transform: 'none',
                        width: `${outW}px`,
                        height: `${outH}px`,
                    },
                } as const
                return toJpeg(node, { ...opts, quality: 0.88 })
            }

            try {
                let dataUrl: string
                try {
                    dataUrl = await exportStageForPublish()
                } catch (e) {
                    console.warn('Retry export...', e)
                    dataUrl = await exportStageForPublish()
                }
                let blob = await (await fetch(dataUrl)).blob()
                blob = await compressImageBlobForLegacyUploadLimit(blob, editorPublishFileByteBudget())
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
            if (!aiEnabled) {
                setGenActionError('AI features are disabled for this workspace.')
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
                // Pull fresh credit totals so the top-bar pill updates live after
                // each generation. Fire-and-forget — never block the render path.
                void refreshAiCreditStatus()
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
        [genUsage, updateLayer, brandContext, snapshotCheckpoint, compositionId, activeBrandId, refreshAiCreditStatus]
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
                void refreshAiCreditStatus()
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
        [genUsage, updateLayer, brandContext, snapshotCheckpoint, compositionId, activeBrandId, refreshAiCreditStatus]
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
            if (!aiEnabled) {
                setGenActionError('AI features are disabled for this workspace.')
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
                void refreshAiCreditStatus()
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
        [genUsage, updateLayer, brandContext, compositionId, activeBrandId, refreshAiCreditStatus]
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
        if (!aiEnabled) return
        if (documentRef.current.layers.length > 0) {
            const ok = await editorConfirm({ title: 'Replace layers', message: 'Replace all layers with a new starter layout? Current layers will be removed.', confirmText: 'Replace', variant: 'warning' })
            if (!ok) return
        }
        setAiLayoutPrompt('')
        setAiLayoutPromptOpen(true)
    }, [aiEnabled, editorConfirm])

    const [aiSuggestions, setAiSuggestions] = useState<Array<{ type: string; description: string }>>([])

    const executeAiLayoutGeneration = useCallback(async (promptText: string) => {
        setAiLayoutLoading(true)
        setAiSuggestions([])
        try {
            const csrf = globalThis.document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
            const res = await fetch('/app/api/generate-layout', {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf ?? '' },
                credentials: 'same-origin',
                body: JSON.stringify({ prompt: promptText, brand_context: brandContext }),
            })
            if (!res.ok) {
                const err = await res.json().catch(() => ({ message: 'Layout generation failed' }))
                throw new Error(err.message || 'Layout generation failed')
            }
            const ai = await res.json() as {
                format_id?: string; width?: number; height?: number; layout_style?: string
                headline?: string; subheadline?: string; cta_text?: string
                background_prompt?: string; overlay_color?: string
                layer_assignments?: Array<{ role: string; asset_id?: string; source?: string; prompt?: string; reason?: string }>
                color_palette?: { headline_color?: string; subheadline_color?: string; cta_bg?: string; cta_text?: string }
                post_generation_suggestions?: Array<{ type: string; description: string }>
                asset_suggestions?: Array<{ asset_id: string; role: string }>
            }

            const w = ai.width || 1080
            const h = ai.height || 1080
            const styleId = (ai.layout_style || 'brand_focused') as LayoutStyleId
            const layerBlueprints = buildLayersForStyle(styleId, w, h)
            const primaryColor = brandContext?.colors?.[0]
            const layers = blueprintToLayers(layerBlueprints, w, h, primaryColor)

            const assignments = ai.layer_assignments ?? []
            const palette = ai.color_palette ?? {}
            const brandPrimaryFont = brandContext?.typography?.canvas_primary_font_family
                ?? brandContext?.typography?.primary_font
                ?? undefined

            for (const layer of layers) {
                if (layer.type === 'text') {
                    const textLayer = layer as any
                    if (textLayer.name === 'Headline') {
                        if (ai.headline) textLayer.content = ai.headline
                        if (palette.headline_color) textLayer.style = { ...textLayer.style, color: palette.headline_color }
                        if (brandPrimaryFont) textLayer.style = { ...textLayer.style, fontFamily: brandPrimaryFont }
                    } else if (textLayer.name === 'Sub-Headline') {
                        if (ai.subheadline) textLayer.content = ai.subheadline
                        if (palette.subheadline_color) textLayer.style = { ...textLayer.style, color: palette.subheadline_color }
                    } else if (textLayer.name === 'CTA') {
                        if (ai.cta_text) textLayer.content = ai.cta_text
                        if (palette.cta_text) textLayer.style = { ...textLayer.style, color: palette.cta_text }
                    }
                }

                // Background layer handling (Jackpot Spin path).
                //
                // Blueprints now ship with the background as `type: 'image'`
                // (real photography first). The AI response can either assign
                // a specific asset or supply a prompt to synthesize one. The
                // two blueprint start-states are handled symmetrically:
                //
                //   - image → image: set src + assetId from AI's chosen asset
                //   - image → generative_image: flip to generative so the
                //       user (or auto-run) can materialize a synthesized BG
                //   - generative_image → image: same as before (asset wins
                //       over prompt)
                //   - generative_image → generative_image: keep prompt
                //
                // Role + name matching keeps this working for templates that
                // haven't been migrated to the new default layer name/type.
                const looksLikeBg = layer.name?.toLowerCase?.().includes('background')
                    || (layer as any).role === 'background'
                if (layer.type === 'generative_image' || (layer.type === 'image' && looksLikeBg)) {
                    const bgLayer = layer as any
                    const bgAssignment = assignments.find(a => a.role === 'background')
                    const wantsAsset = bgAssignment?.source === 'use_asset' && bgAssignment.asset_id
                    const wantsPrompt = !wantsAsset && (ai.background_prompt || bgAssignment?.prompt)

                    if (wantsAsset) {
                        bgLayer.type = 'image'
                        bgLayer.src = editorBridgeFileUrlForAssetId(bgAssignment!.asset_id!)
                        bgLayer.assetId = bgAssignment!.asset_id
                        bgLayer.fit = 'cover'
                        bgLayer.opacity = 1
                        delete bgLayer.prompt
                        delete bgLayer.status
                        delete bgLayer.history
                        delete bgLayer.feedback
                    } else if (wantsPrompt) {
                        // Flip an empty image slot into a generative layer so
                        // the user can hit Generate in the properties panel
                        // and materialize a real image from the AI's prompt.
                        // This is the only path in the app that still emits
                        // a generative_image by default — regular templates
                        // stick with photography from the library.
                        bgLayer.type = 'generative_image'
                        bgLayer.status = 'idle'
                        bgLayer.history = bgLayer.history ?? []
                        bgLayer.feedback = bgLayer.feedback ?? []
                        bgLayer.prompt = {
                            scene: bgAssignment?.prompt || ai.background_prompt || '',
                            style: brandContext?.visual_style || '',
                            palette: (brandContext?.colors || []).join(', '),
                            mood: '',
                            additionalDirections: '',
                        }
                        delete bgLayer.src
                        delete bgLayer.assetId
                    }
                }

                if (layer.type === 'fill') {
                    const fillLayer = layer as any
                    const fillName = (fillLayer.name || '').toLowerCase()
                    if (fillName.includes('cta') || fillName.includes('button')) {
                        const ctaBg = palette.cta_bg || brandContext?.colors?.[0]
                        if (ctaBg) {
                            fillLayer.color = ctaBg
                            if (fillLayer.fillKind === 'gradient') {
                                fillLayer.gradientStartColor = ctaBg
                                fillLayer.gradientEndColor = ctaBg
                            }
                        }
                    } else if (fillName.includes('boost') || fillName.includes('overlay')) {
                        // Text-boost gradient: keeps headline/subheadline readable over background imagery.
                        // Rules:
                        //  - Always ensure the gradient has a transparent start + a visible end, otherwise
                        //    legacy renderer collapses to invisible.
                        //  - Honor AI's overlay_color suggestion, BUT if the headline or subheadline is colored
                        //    (i.e. brand primary, not white/black), the overlay MUST be dark enough to contrast
                        //    with vivid text. A pale overlay behind brand-orange/red/etc. reads as a wash.
                        const headlineLum = roughHexLuminance(palette.headline_color)
                        const subLum = roughHexLuminance(palette.subheadline_color)
                        const headlineIsColored =
                            typeof palette.headline_color === 'string'
                            && palette.headline_color.length > 0
                            && headlineLum > 0.15
                            && headlineLum < 0.95
                        const subIsColored =
                            typeof palette.subheadline_color === 'string'
                            && palette.subheadline_color.length > 0
                            && subLum > 0.15
                            && subLum < 0.95
                        const textIsColored = headlineIsColored || subIsColored

                        const aiOverlay = typeof ai.overlay_color === 'string' && ai.overlay_color.trim()
                            ? ai.overlay_color.trim()
                            : null
                        const aiOverlayLum = aiOverlay ? roughHexLuminance(aiOverlay) : null

                        let endColor: string
                        if (textIsColored) {
                            // Colored headline/subhead on top: force a dark overlay for contrast,
                            // regardless of what the AI suggested.
                            endColor = '#000000cc'
                        } else if (aiOverlay && aiOverlayLum !== null) {
                            // White/black text: trust AI's overlay suggestion.
                            endColor = aiOverlay
                        } else {
                            // No AI overlay and neutral text: fall back to a dark overlay (safer default).
                            endColor = fillLayer.gradientEndColor || '#000000cc'
                        }

                        fillLayer.fillKind = 'gradient'
                        fillLayer.gradientStartColor = fillLayer.gradientStartColor ?? 'transparent'
                        fillLayer.gradientEndColor = endColor
                        // Keep the solid-toggle fallback color aligned with the gradient end,
                        // so toggling fillKind in the UI doesn't surprise the user.
                        fillLayer.color = endColor
                    }
                }

                if (layer.type === 'image') {
                    const imgLayer = layer as any
                    const roleName = (layer as any).name?.toLowerCase()?.replace(/\s+/g, '_') ?? ''

                    const heroMatch = assignments.find(a => a.role === 'hero_image')
                    const logoMatch = assignments.find(a => a.role === 'logo')

                    // Use /file endpoint (streams original bytes). Browsers render SVG natively in <img>
                    // and the backend rasterizes TIFF/HEIC to a thumbnail. The /thumbnail endpoint 404s
                    // whenever a rasterized WebP isn't in metadata (fresh uploads, SVGs with a non-image/* MIME),
                    // which silently breaks AI-assigned logos and hero images on the canvas.
                    const assetUrl = (id: string) => editorBridgeFileUrlForAssetId(id)

                    if (roleName.includes('product') || roleName.includes('hero')) {
                        if (heroMatch?.asset_id) {
                            imgLayer.assetId = heroMatch.asset_id
                            imgLayer.src = assetUrl(heroMatch.asset_id)
                        }
                    } else if (roleName.includes('logo')) {
                        if (logoMatch?.asset_id) {
                            imgLayer.assetId = logoMatch.asset_id
                            imgLayer.src = assetUrl(logoMatch.asset_id)
                        }
                    } else {
                        const genericMatch = assignments.find(a =>
                            a.asset_id && a.role !== 'background' && a.role !== 'logo' && a.role !== 'hero_image'
                        )
                        if (genericMatch?.asset_id) {
                            imgLayer.assetId = genericMatch.asset_id
                            imgLayer.src = assetUrl(genericMatch.asset_id)
                        }
                    }

                    if (!imgLayer.assetId && ai.asset_suggestions) {
                        const legacyMatch = ai.asset_suggestions.find(s =>
                            s.role === roleName || s.role === 'logo'
                        )
                        if (legacyMatch) {
                            imgLayer.assetId = legacyMatch.asset_id
                            imgLayer.src = assetUrl(legacyMatch.asset_id)
                        }
                    }
                }
            }

            if (ai.post_generation_suggestions?.length) {
                setAiSuggestions(ai.post_generation_suggestions)
            }

            setDocument((prev) => {
                const next: DocumentModel = {
                    ...prev,
                    width: w,
                    height: h,
                    layers: normalizeZ(layers),
                    updated_at: new Date().toISOString(),
                }
                const bgLayer = layers.find((l) => l.type === 'generative_image')
                const heroLayer = layers.find((l) => l.type === 'image' && (l as any).assetId)
                const selectLayer = bgLayer ?? heroLayer ?? layers[0]
                if (selectLayer) {
                    queueMicrotask(() => setSelectedLayerId(selectLayer.id))
                }
                queueMicrotask(() => {
                    void snapshotCheckpoint('AI-generated layout', next)
                })
                return next
            })
            setAiLayoutPromptOpen(false)
            setLeftPanel('layers')
            void refreshAiCreditStatus()

            // Auto-fire generation for any generative_image layer the AI
            // seeded with a prompt. "Feeling Lucky" is a one-click action —
            // the user expects a finished composition with a real image, not
            // a placeholder telling them to press Generate themselves.
            // We walk the *local* `layers` array rather than deferring to
            // `runRegenerateAllUnlockedGenerative` because `documentRef`
            // hasn't caught up yet on the next microtask (it's sync-assigned
            // at render time, and this runs before React's next commit).
            const autoGenIds = layers
                .filter((l) =>
                    l.type === 'generative_image' &&
                    !l.locked &&
                    !!((l as GenerativeImageLayer).prompt?.scene?.trim())
                )
                .map((l) => l.id)
            if (autoGenIds.length > 0) {
                // Defer a tick so `setDocument` has committed and the layers
                // are in the ref by the time `runGenerativeGeneration` reads
                // them. `setTimeout(..., 0)` is sufficient — no need for
                // intermediate microtasks.
                window.setTimeout(() => {
                    void runWithConcurrency(
                        autoGenIds,
                        MAX_CONCURRENT_AI_REQUESTS,
                        (id) => runGenerativeGeneration(id),
                    )
                }, 0)
            }
        } catch (e: any) {
            await editorConfirm({ title: 'Generation failed', message: e.message || 'Could not generate layout. Please try again.', confirmText: 'OK', variant: 'danger' })
        } finally {
            setAiLayoutLoading(false)
        }
    }, [brandContext, snapshotCheckpoint, editorConfirm, refreshAiCreditStatus])

    useEffect(() => {
        if (aiSuggestions.length === 0) return
        const t = window.setTimeout(() => setAiSuggestions([]), 30_000)
        return () => window.clearTimeout(t)
    }, [aiSuggestions])

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

    /**
     * Insert a new mask layer on top of the current stack. By default it's
     * placed directly above the currently-selected non-mask layer so the
     * user's intent ("mask the thing I have selected") maps cleanly onto the
     * `below_one` default target. If nothing is selected the mask lands at
     * the top of the stack.
     */
    const addMaskLayer = useCallback(() => {
        setDocument((prev) => {
            const mask = createDefaultMaskLayer(nextZIndex(prev.layers), prev)
            const selectedId = selectedLayerId
            const selected = selectedId ? prev.layers.find((l) => l.id === selectedId) : null
            let layers: Layer[]
            if (selected && selected.type !== 'mask') {
                const selectedZ = Number(selected.z) || 0
                // Nudge every layer at or above the selected z up by one, then
                // place the mask at `selectedZ + 1`. normalizeZ after ensures
                // a clean 0..n sequence.
                const bumped = prev.layers.map((l) =>
                    (Number(l.z) || 0) > selectedZ
                        ? { ...l, z: (Number(l.z) || 0) + 1 }
                        : l
                )
                layers = normalizeZ([...bumped, { ...mask, z: selectedZ + 1 }])
            } else {
                layers = normalizeZ([...prev.layers, mask])
            }
            setSelectedLayerId(mask.id)
            return {
                ...prev,
                layers,
                updated_at: new Date().toISOString(),
            }
        })
    }, [selectedLayerId])

    /**
     * Resolve a canvas/panel click into the correct selection:
     *
     *   - If the layer belongs to a group and the user didn't hold Alt, select
     *     the group (we still surface `selectedLayerId = clicked id` so the
     *     properties panel remains layer-centric).
     *   - If Alt is held, drill into the single layer even if grouped.
     *
     * This is the ONE way selection should change in response to a user click.
     * Other callers (programmatic: start-new, load-draft) clear both ids
     * directly — that's fine, they're explicit.
     */
    const selectLayerOrGroup = useCallback(
        (layerId: string, opts?: { alt?: boolean }) => {
            const doc = documentRef.current
            const layer = doc.layers.find((l) => l.id === layerId)
            if (!layer) {
                setSelectedLayerId(null)
                setSelectedGroupId(null)
                return
            }
            if (layer.groupId && !opts?.alt) {
                setSelectedLayerId(layerId)
                setSelectedGroupId(layer.groupId)
                return
            }
            setSelectedLayerId(layerId)
            setSelectedGroupId(null)
        },
        [setSelectedGroupId]
    )

    const beginMove = useCallback(
        (layerId: string, e: React.MouseEvent) => {
            const doc = documentRef.current
            const layer = doc.layers.find((l) => l.id === layerId)
            if (!layer || layer.locked || !layer.visible) {
                return
            }
            e.stopPropagation()
            e.preventDefault()
            const { x, y } = clientToDoc(e.clientX, e.clientY)

            // If the clicked layer is in a group AND the group is currently
            // the active selection (Alt wasn't held on the click that brought
            // us here), move the whole group as a rigid body. We bail out if
            // any member is locked — partial group drags produce surprising
            // results, and the lock icon already communicates "won't move".
            const group = layer.groupId ? findGroupForLayer(doc, layerId) : null
            const groupActive = !!group && selectedGroupIdRef.current === group.id
            if (group && groupActive) {
                const members = groupMemberLayers(doc, group.id)
                if (members.some((m) => m.locked)) {
                    return
                }
                const unionRect = unionRectForGroup(doc, group.id)
                if (unionRect) {
                    dragRef.current = {
                        kind: 'move',
                        layerId,
                        startDocX: x,
                        startDocY: y,
                        startLayerX: layer.transform.x,
                        startLayerY: layer.transform.y,
                        groupMembers: members.map((m) => ({
                            layerId: m.id,
                            x: m.transform.x,
                            y: m.transform.y,
                            width: m.transform.width,
                            height: m.transform.height,
                        })),
                        groupStartRect: unionRect,
                    }
                    return
                }
            }

            dragRef.current = {
                kind: 'move',
                layerId,
                startDocX: x,
                startDocY: y,
                startLayerX: layer.transform.x,
                startLayerY: layer.transform.y,
            }
        },
        [clientToDoc]
    )

    const beginResize = useCallback(
        (layerId: string, corner: ResizeCorner, e: React.MouseEvent) => {
            const doc = documentRef.current
            const layer = doc.layers.find((l) => l.id === layerId)
            if (!layer || layer.locked || !layer.visible) {
                return
            }
            e.stopPropagation()
            e.preventDefault()
            setSelectedLayerId(layerId)
            setEditingTextLayerId(null)
            const { x, y } = clientToDoc(e.clientX, e.clientY)

            // Group resize: we treat the union rect as the subject and every
            // member gets scaled proportionally. Aspect is always locked for
            // groups so CTA fill + text can't drift out of alignment.
            const group = layer.groupId ? findGroupForLayer(doc, layerId) : null
            const groupActive = !!group && selectedGroupIdRef.current === group.id
            if (group && groupActive) {
                const members = groupMemberLayers(doc, group.id)
                if (members.some((m) => m.locked)) {
                    return
                }
                const unionRect = unionRectForGroup(doc, group.id)
                if (unionRect) {
                    const groupAr = unionRect.width / Math.max(unionRect.height, 0.001)
                    dragRef.current = {
                        kind: 'resize',
                        layerId,
                        corner,
                        startDocX: x,
                        startDocY: y,
                        start: { ...unionRect },
                        aspectRatio: groupAr,
                        lockAspectResize: true,
                        groupMembers: members.map((m) => ({
                            layerId: m.id,
                            x: m.transform.x,
                            y: m.transform.y,
                            width: m.transform.width,
                            height: m.transform.height,
                        })),
                        groupStartRect: unionRect,
                    }
                    return
                }
            }

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
        [clientToDoc]
    )

    useEffect(() => {
        const onMove = (e: MouseEvent) => {
            const d = dragRef.current
            if (!d) {
                return
            }
            const { x: mx, y: my } = clientToDoc(e.clientX, e.clientY)
            const snapCfg = snapConfigRef.current
            // Hold Alt to temporarily disable snap (pro escape hatch).
            const altDisable = e.altKey
            const doc = documentRef.current
            const thresholdDoc = (SNAP_THRESHOLD_SCREEN_PX / Math.max(0.05, snapCfg.screenScale))
            if (d.kind === 'move') {
                const dx = mx - d.startDocX
                const dy = my - d.startDocY

                // Group move: snap the union rect as the subject, then shift
                // every member by the same snapped delta so their relative
                // positions stay intact.
                if (d.groupMembers && d.groupStartRect) {
                    const startRect = d.groupStartRect
                    const rawX = startRect.x + dx
                    const rawY = startRect.y + dy
                    let finalX = rawX
                    let finalY = rawY
                    let hits: SnapHit[] = []
                    if (!altDisable && snapCfg.mode !== 'off' && startRect.width > 0 && startRect.height > 0) {
                        const res = snapEngineMove({
                            rect: { x: rawX, y: rawY, width: startRect.width, height: startRect.height },
                            docW: doc.width,
                            docH: doc.height,
                            mode: snapCfg.mode,
                            density: snapCfg.density,
                            thresholdDoc,
                        })
                        finalX = res.x
                        finalY = res.y
                        hits = res.hits
                    }
                    reportSnapHits(hits)
                    const deltaX = finalX - startRect.x
                    const deltaY = finalY - startRect.y
                    const memberIds = new Set(d.groupMembers.map((m) => m.layerId))
                    const startById = new Map(d.groupMembers.map((m) => [m.layerId, m] as const))
                    setDocument((prev) => ({
                        ...prev,
                        layers: prev.layers.map((l) => {
                            if (!memberIds.has(l.id)) return l
                            const s = startById.get(l.id)!
                            return {
                                ...l,
                                transform: {
                                    ...l.transform,
                                    x: s.x + deltaX,
                                    y: s.y + deltaY,
                                },
                            }
                        }),
                        updated_at: new Date().toISOString(),
                    }))
                    return
                }

                const layer = doc.layers.find((l) => l.id === d.layerId)
                const w = layer?.transform.width ?? 0
                const h = layer?.transform.height ?? 0
                const rawX = d.startLayerX + dx
                const rawY = d.startLayerY + dy
                let finalX = rawX
                let finalY = rawY
                let hits: SnapHit[] = []
                if (!altDisable && snapCfg.mode !== 'off' && w > 0 && h > 0) {
                    const res = snapEngineMove({
                        rect: { x: rawX, y: rawY, width: w, height: h },
                        docW: doc.width,
                        docH: doc.height,
                        mode: snapCfg.mode,
                        density: snapCfg.density,
                        thresholdDoc,
                    })
                    finalX = res.x
                    finalY = res.y
                    hits = res.hits
                }
                reportSnapHits(hits)
                updateLayer(d.layerId, (l) => ({
                    ...l,
                    transform: {
                        ...l.transform,
                        x: finalX,
                        y: finalY,
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
            let finalRect = { x, y, width: w, height: h }
            let hits: SnapHit[] = []
            if (!altDisable && snapCfg.mode === 'line_align') {
                const res = snapEngineResize({
                    rect: finalRect,
                    corner: d.corner,
                    docW: doc.width,
                    docH: doc.height,
                    mode: snapCfg.mode,
                    density: snapCfg.density,
                    thresholdDoc,
                })
                finalRect = { x: res.x, y: res.y, width: res.width, height: res.height }
                hits = res.hits
            }
            reportSnapHits(hits)

            // Group resize: union rect transforms into finalRect, and each
            // member's (x, y, w, h) gets mapped into the new rect preserving
            // its normalized position/size within the old union rect.
            if (d.groupMembers && d.groupStartRect) {
                const src = d.groupStartRect
                const dst = finalRect
                const sx = dst.width / Math.max(src.width, 0.001)
                const sy = dst.height / Math.max(src.height, 0.001)
                const memberIds = new Set(d.groupMembers.map((m) => m.layerId))
                const startById = new Map(d.groupMembers.map((m) => [m.layerId, m] as const))
                setDocument((prev) => ({
                    ...prev,
                    layers: prev.layers.map((l) => {
                        if (!memberIds.has(l.id)) return l
                        const s = startById.get(l.id)!
                        return {
                            ...l,
                            transform: {
                                ...l.transform,
                                x: dst.x + (s.x - src.x) * sx,
                                y: dst.y + (s.y - src.y) * sy,
                                width: s.width * sx,
                                height: s.height * sy,
                            },
                        }
                    }),
                    updated_at: new Date().toISOString(),
                }))
                return
            }

            updateLayer(d.layerId, (l) => ({
                ...l,
                transform: {
                    ...l.transform,
                    x: finalRect.x,
                    y: finalRect.y,
                    width: finalRect.width,
                    height: finalRect.height,
                },
            }))
        }
        const onUp = () => {
            dragRef.current = null
            reportSnapHits([])
        }
        window.addEventListener('mousemove', onMove)
        window.addEventListener('mouseup', onUp)
        return () => {
            window.removeEventListener('mousemove', onMove)
            window.removeEventListener('mouseup', onUp)
        }
    }, [clientToDoc, updateLayer, reportSnapHits])

    const clearSelection = useCallback((e: React.MouseEvent) => {
        if (e.target === e.currentTarget) {
            setSelectedLayerId(null)
            setSelectedGroupId(null)
            setEditingTextLayerId(null)
        }
    }, [])

    return (
        // `dark` is forced so every `dark:*` rule inside the editor fires regardless of
        // the user's OS preference or the top-level html class. On staging the html root
        // wasn't getting `dark` applied, which made inner chrome fall back to light
        // `border-gray-200` / `bg-white` — the "hard white borders" the user saw.
        // `bg-gray-950` (vs `neutral-950`) is the slightly-blue near-black that reads as
        // "navy" in the editor surround.
        <div className="jp-editor-shell dark flex h-screen flex-col overflow-hidden bg-gray-950">
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
                {/* Standard white nav — matches the rest of the product. Forcing `dark`
                   above does NOT change AppNav because it uses explicit `variant` branching
                   (not `dark:` Tailwind variants), so the white nav chrome stays intact. */}
                <AppNav brand={auth?.activeBrand} tenant={null} />
            </div>

            {/* Top bar */}
            <header
                className={`relative flex h-10 shrink-0 items-center border-b px-3 ${
                    uiMode === 'preview'
                        ? 'border-neutral-800 bg-neutral-950'
                        : 'border-gray-700 bg-gray-900'
                }`}
            >
                {/* Left: file name + save */}
                <div className="flex min-w-0 items-center gap-1.5">
                    {uiMode === 'edit' ? (
                        <>
                            <input
                                type="text"
                                value={compositionName}
                                onChange={(e) => setCompositionName(e.target.value)}
                                className="min-w-0 max-w-[min(100%,200px)] rounded-md border border-gray-700 bg-transparent px-2 py-1 text-sm font-medium text-gray-100 placeholder-gray-500 focus:border-gray-500 focus:bg-gray-800/50 focus:ring-0 transition-colors"
                                placeholder={defaultCompositionName(document)}
                                aria-label="Composition name"
                            />
                            <button
                                type="button"
                                onClick={handleSave}
                                disabled={saveState === 'saving'}
                                className="shrink-0 rounded-md border border-gray-700 bg-gray-800 px-3 py-1 text-xs font-semibold text-gray-200 hover:border-gray-500 hover:bg-gray-700 hover:text-white transition-colors disabled:opacity-50"
                                title="Save (Ctrl+S)"
                            >
                                {saveState === 'saving' ? (
                                    <span className="inline-flex items-center gap-1"><ArrowPathIcon className="h-3 w-3 animate-spin" aria-hidden />Saving</span>
                                ) : 'Save'}
                            </button>
                            <span className="flex items-center gap-1 text-xs">
                                {(promoteOk || promoteError) && (
                                    <span className={promoteOk ? 'text-emerald-400' : 'text-red-400'} role="status">
                                        {promoteOk ? 'Published' : promoteError}
                                    </span>
                                )}
                                {saveError && (
                                    <span className="max-w-[120px] truncate font-medium text-red-400" title={saveError}>{saveError}</span>
                                )}
                                {discardRequiresConfirmation && saveState !== 'saving' && !saveError && (
                                    <span className="font-medium text-amber-400" title="Unsaved changes — Jackpot autosaves every few seconds while you edit.">Unsaved</span>
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
                                            className="font-semibold text-emerald-400"
                                        >
                                            Saved
                                        </motion.span>
                                    )}
                                </AnimatePresence>
                                {saveState === 'idle' && !saveError && !discardRequiresConfirmation && lastSavedAt !== null && (
                                    <span
                                        className="hidden text-[11px] text-gray-500 md:inline"
                                        title={`Last saved ${new Date(lastSavedAt).toLocaleString()}. Autosaves run automatically while editing.`}
                                        key={`saved-ago-${savedRelativeTick}`}
                                    >
                                        Saved {formatSavedAgo(lastSavedAt, Date.now())}
                                    </span>
                                )}
                                {activityToast && (
                                    <span className="max-w-[140px] truncate text-[11px] text-gray-500" role="status" title={activityToast}>
                                        {activityToast}
                                    </span>
                                )}
                            </span>
                        </>
                    ) : (
                        <span className="text-sm font-medium text-neutral-100">Preview</span>
                    )}
                </div>

                {/* Center: zoom controls */}
                {uiMode === 'edit' && (
                    <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                        <div className="pointer-events-auto flex items-center gap-0.5 text-[11px] font-medium text-gray-300">
                            <button type="button" onClick={() => zoomTo((userZoom ?? viewportScale) / 1.25)} className="flex h-6 w-6 items-center justify-center rounded hover:bg-gray-800 hover:text-white" title="Zoom out">−</button>
                            <button type="button" onClick={fitToView} className="min-w-[2.5rem] rounded px-1 py-0.5 text-center tabular-nums hover:bg-gray-800 hover:text-white" title="Fit to view">{Math.round(effectiveScale * 100)}%</button>
                            <button type="button" onClick={() => zoomTo((userZoom ?? viewportScale) * 1.25)} className="flex h-6 w-6 items-center justify-center rounded hover:bg-gray-800 hover:text-white" title="Zoom in">+</button>
                            <div className="mx-1 h-3.5 w-px bg-gray-700" />
                            <button type="button" onClick={zoomToActual} className={`flex h-6 items-center justify-center rounded px-1.5 hover:bg-gray-800 hover:text-white ${Math.round(effectiveScale * 100) === 100 ? 'text-blue-400' : ''}`} title="Actual size (100%)">1:1</button>
                            <button type="button" onClick={centerCanvas} className="flex h-6 w-6 items-center justify-center rounded hover:bg-gray-800 hover:text-white" title="Center canvas">
                                <ViewfinderCircleIcon className="h-3.5 w-3.5" />
                            </button>
                            <div className="mx-1 h-3.5 w-px bg-gray-700" />
                            <button
                                type="button"
                                onClick={() => setGridEnabled((v) => !v)}
                                className={`flex h-6 w-6 items-center justify-center rounded hover:bg-gray-800 hover:text-white ${gridEnabled ? 'text-indigo-400' : ''}`}
                                title={`${gridEnabled ? 'Hide' : 'Show'} grid (G)`}
                                aria-pressed={gridEnabled}
                            >
                                <Squares2X2Icon className="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>
                )}

                {/* Right: credit indicator (edit mode) / preview controls (preview mode) */}
                {uiMode === 'edit' && aiEnabled && aiCreditStatus && (
                    <div className="relative ml-auto flex items-center">
                        {(() => {
                            const s = aiCreditStatus
                            const compUsed = s.this_composition_used
                            const hasCompCount =
                                compositionId != null && compUsed !== null && compUsed !== undefined
                            const pct = s.is_unlimited || s.credits_cap <= 0
                                ? 0
                                : Math.min(100, Math.round((s.credits_used / s.credits_cap) * 100))
                            // Compute tone buckets once; reused between pill and popover accent.
                            const tone = s.is_exceeded
                                ? 'exceeded'
                                : s.warning_level === 'warning' || s.warning_level === 'danger'
                                    ? 'warn'
                                    : 'ok'
                            const toneButtonClasses =
                                tone === 'exceeded'
                                    ? 'border-red-500/50 bg-red-500/10 text-red-300 hover:bg-red-500/20'
                                    : tone === 'warn'
                                        ? 'border-amber-500/40 bg-amber-500/10 text-amber-200 hover:bg-amber-500/20'
                                        : 'border-gray-700 bg-gray-800/60 text-gray-300 hover:border-gray-600 hover:bg-gray-800 hover:text-white'
                            const toneBarClasses =
                                tone === 'exceeded'
                                    ? 'bg-red-400'
                                    : tone === 'warn'
                                        ? 'bg-amber-400'
                                        : 'bg-indigo-400'
                            const titleText = s.is_unlimited
                                ? `AI credits: unlimited${hasCompCount ? ` · this composition used ${compUsed} credit(s)` : ''}.`
                                : `Monthly AI credits: ${s.credits_used.toLocaleString()} / ${s.credits_cap.toLocaleString()} used (${s.credits_remaining.toLocaleString()} remaining)${hasCompCount ? ` · this composition: ${compUsed}` : ''}.`
                            return (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => setAiCreditPopoverOpen((v) => !v)}
                                        aria-expanded={aiCreditPopoverOpen}
                                        aria-haspopup="dialog"
                                        title={titleText}
                                        className={`group flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-[11px] font-medium tabular-nums transition-colors ${toneButtonClasses}`}
                                    >
                                        <SparklesIcon className="h-3.5 w-3.5 shrink-0" aria-hidden />
                                        {s.is_unlimited ? (
                                            <span>AI: unlimited</span>
                                        ) : (
                                            <>
                                                {hasCompCount && (
                                                    // Inline "this composition" chip. Shown even at 0 so users
                                                    // learn the counter exists and see it tick up as they work.
                                                    <span
                                                        className="rounded-sm bg-gray-900/80 px-1 text-[10px] font-semibold text-indigo-300"
                                                        title="Credits used by this composition this month"
                                                    >
                                                        +{(compUsed ?? 0).toLocaleString()}
                                                    </span>
                                                )}
                                                <span>
                                                    {s.credits_used.toLocaleString()} / {s.credits_cap.toLocaleString()}
                                                </span>
                                                <span className="hidden md:inline text-gray-500 group-hover:text-gray-400">credits</span>
                                            </>
                                        )}
                                    </button>
                                    {aiCreditPopoverOpen && (
                                        <>
                                            {/* click-away scrim — covers the whole viewport so any click outside
                                                the popover closes it without bubbling to editor handlers. */}
                                            <button
                                                type="button"
                                                aria-label="Close credits panel"
                                                className="fixed inset-0 z-40 cursor-default"
                                                onClick={() => setAiCreditPopoverOpen(false)}
                                            />
                                            <div
                                                role="dialog"
                                                aria-label="AI credit usage"
                                                className="absolute right-0 top-full z-50 mt-2 w-72 rounded-lg border border-gray-700 bg-gray-900 p-3 shadow-xl"
                                            >
                                                <div className="mb-2 flex items-center justify-between">
                                                    <span className="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                                        AI credits
                                                    </span>
                                                    {s.is_exceeded && (
                                                        <span className="rounded bg-red-500/20 px-1.5 py-0.5 text-[10px] font-semibold text-red-300">
                                                            Limit reached
                                                        </span>
                                                    )}
                                                </div>
                                                <dl className="space-y-1.5 text-xs text-gray-200">
                                                    <div className="flex items-baseline justify-between gap-2">
                                                        <dt className="text-gray-400">This composition</dt>
                                                        <dd className="tabular-nums font-semibold text-indigo-300">
                                                            {hasCompCount
                                                                ? `${(compUsed ?? 0).toLocaleString()} credits`
                                                                : compositionId == null
                                                                    ? 'Save first'
                                                                    : '—'}
                                                        </dd>
                                                    </div>
                                                    <div className="flex items-baseline justify-between gap-2">
                                                        <dt className="text-gray-400">This month</dt>
                                                        <dd className="tabular-nums font-semibold">
                                                            {s.credits_used.toLocaleString()}
                                                            {!s.is_unlimited && (
                                                                <span className="text-gray-500"> / {s.credits_cap.toLocaleString()}</span>
                                                            )}
                                                        </dd>
                                                    </div>
                                                    {!s.is_unlimited && (
                                                        <div className="flex items-baseline justify-between gap-2">
                                                            <dt className="text-gray-400">Remaining</dt>
                                                            <dd className="tabular-nums font-semibold text-gray-100">
                                                                {s.credits_remaining.toLocaleString()}
                                                            </dd>
                                                        </div>
                                                    )}
                                                </dl>
                                                {!s.is_unlimited && s.credits_cap > 0 && (
                                                    <div className="mt-2">
                                                        <div className="h-1.5 w-full overflow-hidden rounded-full bg-gray-800">
                                                            <div
                                                                className={`h-full transition-all ${toneBarClasses}`}
                                                                style={{ width: `${pct}%` }}
                                                            />
                                                        </div>
                                                        <div className="mt-1 flex items-center justify-between text-[10px] text-gray-500">
                                                            <span>{pct}% used</span>
                                                            <span>Resets monthly</span>
                                                        </div>
                                                    </div>
                                                )}
                                                <div className="mt-3 flex items-center justify-between gap-2 border-t border-gray-800 pt-2 text-[11px]">
                                                    <button
                                                        type="button"
                                                        onClick={() => {
                                                            setAiCreditPopoverOpen(false)
                                                            setLeftPanel('history')
                                                            if (compositionId) void refreshVersions()
                                                        }}
                                                        className="text-indigo-300 hover:text-indigo-200"
                                                    >
                                                        View history
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => void refreshAiCreditStatus()}
                                                        className="text-gray-400 hover:text-gray-200"
                                                        title="Refresh"
                                                    >
                                                        <ArrowPathIcon className="h-3.5 w-3.5" aria-hidden />
                                                    </button>
                                                </div>
                                            </div>
                                        </>
                                    )}
                                </>
                            )
                        })()}
                    </div>
                )}
                {uiMode === 'preview' && (
                    <div className="ml-auto flex items-center gap-1">
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
                            className="inline-flex items-center rounded-md bg-white px-2.5 py-1 text-xs font-semibold text-gray-900 hover:bg-gray-100"
                        >
                            Exit preview
                        </button>
                    </div>
                )}
            </header>

            <div className="flex min-h-0 flex-1">
                {/* Collapsed icon toolbar + flyout panel */}
                {uiMode !== 'preview' && (
                    <div className="flex shrink-0">
                        {/* Narrow icon bar */}
                        <div className="flex w-16 shrink-0 flex-col items-center border-r border-gray-700 bg-gray-900">
                            {/* Cherry logo / menu — pinned to top */}
                            <div className="flex w-full flex-col items-center gap-1 pt-3 pb-2">
                                <button type="button" onClick={() => setLeftPanel(leftPanel === 'menu' ? null : 'menu')} className={`flex h-10 w-10 items-center justify-center rounded-lg transition-colors ${leftPanel === 'menu' ? 'bg-gray-700 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white'}`} title="Menu">
                                    <img src="/jp-parts/cherry-slot.svg" alt="Jackpot" className="h-7 w-7" />
                                </button>
                            </div>

                            {/* Main tools — vertically centered */}
                            <div className="flex flex-1 flex-col items-center justify-center gap-2">
                                <button type="button" onClick={() => setLeftPanel(leftPanel === 'layers' ? null : 'layers')} className={`flex h-14 w-14 flex-col items-center justify-center rounded-xl transition-colors ${leftPanel === 'layers' ? 'bg-gray-700 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white'}`} title="Layers">
                                    <Square2StackIcon className="h-7 w-7" aria-hidden />
                                    <span className="mt-1 text-[10px] font-medium leading-none">Layers</span>
                                </button>
                                <button type="button" onClick={() => setLeftPanel(leftPanel === 'assets' ? null : 'assets')} className={`flex h-14 w-14 flex-col items-center justify-center rounded-xl transition-colors ${leftPanel === 'assets' ? 'bg-gray-700 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white'}`} title="Assets">
                                    <PhotoIcon className="h-7 w-7" aria-hidden />
                                    <span className="mt-1 text-[10px] font-medium leading-none">Assets</span>
                                </button>
                                <button type="button" onClick={() => setLeftPanel(leftPanel === 'templates' ? null : 'templates')} className={`flex h-14 w-14 flex-col items-center justify-center rounded-xl transition-colors ${leftPanel === 'templates' ? 'bg-gray-700 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white'}`} title="Templates">
                                    <Squares2X2Icon className="h-7 w-7" aria-hidden />
                                    <span className="mt-1 text-[10px] font-medium leading-none">Templates</span>
                                </button>
                                <button type="button" onClick={() => { setLeftPanel(leftPanel === 'history' ? null : 'history'); if (leftPanel !== 'history' && compositionId) void refreshVersions() }} className={`flex h-14 w-14 flex-col items-center justify-center rounded-xl transition-colors ${leftPanel === 'history' ? 'bg-gray-700 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white'}`} title="History">
                                    <ClockIcon className="h-7 w-7" aria-hidden />
                                    <span className="mt-1 text-[10px] font-medium leading-none">History</span>
                                </button>
                            </div>

                            {/* Bottom utilities — pinned to bottom */}
                            <div className="relative flex w-full flex-col items-center gap-1 pb-3 pt-2">
                                <button type="button" onClick={() => setShortcutsOpen(!shortcutsOpen)} className={`flex h-10 w-10 items-center justify-center rounded-lg transition-colors ${shortcutsOpen ? 'bg-gray-700 text-white' : 'text-gray-400 hover:bg-gray-800 hover:text-white'}`} title="Keyboard shortcuts">
                                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                        <rect x="2" y="6" width="20" height="12" rx="2" />
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 10h1m2 0h1m2 0h1m2 0h1m2 0h1M6 14h12M5 10v0m14 0v0" />
                                    </svg>
                                </button>

                                {shortcutsOpen && (
                                    <div className="absolute bottom-full left-full mb-0 ml-2 w-72 rounded-lg bg-gray-900 p-4 shadow-xl ring-1 ring-gray-700 z-50">
                                        <div className="mb-3 flex items-center justify-between">
                                            <h3 className="text-xs font-semibold uppercase tracking-wider text-gray-400">Keyboard Shortcuts</h3>
                                            <button type="button" onClick={() => setShortcutsOpen(false)} className="text-gray-500 hover:text-gray-300">
                                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>
                                        </div>
                                        <div className="space-y-2.5 text-[12px]">
                                            <div className="flex items-center justify-between">
                                                <span className="text-gray-300">Pan canvas</span>
                                                <span className="flex items-center gap-1"><kbd className="rounded bg-gray-700 px-1.5 py-0.5 text-[10px] font-medium text-gray-300 ring-1 ring-gray-600">Space</kbd><span className="text-gray-500">+ drag</span></span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-gray-300">Zoom in / out</span>
                                                <span className="flex items-center gap-1"><kbd className="rounded bg-gray-700 px-1.5 py-0.5 text-[10px] font-medium text-gray-300 ring-1 ring-gray-600">Ctrl</kbd><span className="text-gray-500">+ scroll</span></span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-gray-300">Zoom to fit</span>
                                                <span className="text-gray-500">Click zoom %</span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-gray-300">Actual size (100%)</span>
                                                <span className="text-gray-500">Click 1:1</span>
                                            </div>
                                            <div className="my-2 h-px bg-gray-700/60" />
                                            <div className="flex items-center justify-between">
                                                <span className="text-gray-300">Select layer</span>
                                                <span className="text-gray-500">Click layer</span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-gray-300">Move layer</span>
                                                <span className="text-gray-500">Drag layer</span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-gray-300">Resize layer</span>
                                                <span className="text-gray-500">Drag corners</span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-gray-300">Edit text</span>
                                                <span className="text-gray-500">Double-click text</span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-gray-300">Deselect</span>
                                                <kbd className="rounded bg-gray-700 px-1.5 py-0.5 text-[10px] font-medium text-gray-300 ring-1 ring-gray-600">Esc</kbd>
                                            </div>
                                        </div>
                                    </div>
                                )}
                                <button type="button" className="flex h-10 w-10 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-800 hover:text-white transition-colors" title="Settings">
                                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28z" /><path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                </button>
                            </div>
                        </div>
                        {/* Flyout panel */}
                        {leftPanel && (
                            <div className="flex w-64 shrink-0 flex-col border-r border-gray-700 bg-gray-900">
                                {leftPanel === 'menu' && (
                                    <div className="flex flex-1 flex-col">
                                        <div className="border-b border-gray-700 px-3 py-3"><h2 className="text-xs font-semibold uppercase tracking-wide text-gray-400">Menu</h2></div>
                                        <div className="flex-1 overflow-y-auto p-2">
                                            {/* File section */}
                                            <p className="px-3 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wider text-gray-500">File</p>
                                            <button type="button" onClick={() => { setLeftPanel(null); startNewComposition() }} className="flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-sm text-gray-200 hover:bg-gray-800">
                                                <svg className="h-4 w-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg> New
                                            </button>
                                            <button type="button" onClick={() => { setLeftPanel(null); openCompositionPickerAndLoad() }} className="flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-sm text-gray-200 hover:bg-gray-800">
                                                <FolderOpenIcon className="h-4 w-4 shrink-0 text-gray-400" /> Open
                                            </button>
                                            <button type="button" onClick={() => { setLeftPanel(null); handleSave() }} className="flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-sm text-gray-200 hover:bg-gray-800">
                                                <svg className="h-4 w-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg> Save
                                            </button>
                                            <button type="button" onClick={() => { setLeftPanel(null); void duplicateWholeComposition() }} disabled={!compositionId} className="flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-sm text-gray-200 hover:bg-gray-800 disabled:opacity-40 disabled:cursor-not-allowed">
                                                <DocumentDuplicateIcon className="h-4 w-4 shrink-0 text-gray-400" /> Duplicate
                                            </button>
                                            <button type="button" onClick={() => { setLeftPanel(null); compositionId && void deleteCompositionById(compositionId) }} disabled={!compositionId || compositionDeleteBusy} className="flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-sm text-red-400 hover:bg-gray-800 disabled:opacity-40 disabled:cursor-not-allowed">
                                                <TrashIcon className="h-4 w-4 shrink-0" /> Delete
                                            </button>

                                            <div className="my-2 h-px bg-gray-700" />

                                            {/* Publish & Export */}
                                            <p className="px-3 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wider text-gray-500">Publish &amp; Export</p>
                                            <button type="button" onClick={() => { setLeftPanel(null); void openPublishModal() }} disabled={promoteSaving} className="flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-sm text-gray-200 hover:bg-gray-800 disabled:opacity-40">
                                                <img src="/jp-parts/diamond-slot.svg" alt="" className="h-4 w-4 shrink-0" /> Publish to Jackpot
                                            </button>
                                            <button type="button" onClick={() => { setLeftPanel(null); void downloadExport('png') }} className="flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-sm text-gray-200 hover:bg-gray-800">
                                                <PhotoIcon className="h-4 w-4 shrink-0 text-gray-400" /> Export PNG
                                            </button>
                                            <button type="button" onClick={() => { setLeftPanel(null); void downloadExport('jpeg') }} className="flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-sm text-gray-200 hover:bg-gray-800">
                                                <PhotoIcon className="h-4 w-4 shrink-0 text-gray-400" /> Export JPG
                                            </button>
                                            <button type="button" onClick={() => { setLeftPanel(null); void downloadExport('json') }} className="flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-sm text-gray-200 hover:bg-gray-800">
                                                <svg className="h-4 w-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5" /></svg> Export JSON
                                            </button>

                                            <div className="my-2 h-px bg-gray-700" />

                                            {/* AI / Generate */}
                                            <p className="px-3 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wider text-gray-500">Generate</p>
                                            <button type="button" disabled={!aiEnabled} onClick={() => { setLeftPanel(null); generateGuidedLayout() }} className={`flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-sm text-gray-200 hover:bg-gray-800 ${!aiEnabled ? 'opacity-40 cursor-not-allowed' : ''}`}>
                                                <SparklesIcon className="h-4 w-4 shrink-0 text-violet-400" /> {aiEnabled ? 'Generate layout' : 'AI Disabled'}
                                            </button>
                                            <button
                                                type="button"
                                                disabled={
                                                    !aiEnabled ||
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
                                                onClick={() => { setLeftPanel(null); void runRegenerateAllUnlockedGenerative() }}
                                                className="flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-sm text-gray-200 hover:bg-gray-800 disabled:opacity-40 disabled:cursor-not-allowed"
                                            >
                                                <ArrowPathIcon className="h-4 w-4 shrink-0 text-gray-400" /> Regenerate all AI
                                            </button>

                                            <div className="my-2 h-px bg-gray-700" />

                                            {/* View */}
                                            <p className="px-3 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wider text-gray-500">View</p>
                                            <button type="button" onClick={() => { setLeftPanel(null); setUiMode('preview') }} className="flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-sm text-gray-200 hover:bg-gray-800">
                                                <EyeIcon className="h-4 w-4 shrink-0 text-gray-400" /> Preview
                                            </button>
                                            <button type="button" onClick={() => { setLeftPanel('history'); if (compositionId) void refreshVersions() }} className="flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-sm text-gray-200 hover:bg-gray-800">
                                                <ClockIcon className="h-4 w-4 shrink-0 text-gray-400" /> History
                                            </button>
                                            <button type="button" onClick={() => { setLeftPanel(null); setCompareOpen(true) }} disabled={!compositionId || versions.length < 2} className="flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-sm text-gray-200 hover:bg-gray-800 disabled:opacity-40 disabled:cursor-not-allowed">
                                                <ArrowsRightLeftIcon className="h-4 w-4 shrink-0 text-gray-400" /> Compare versions
                                            </button>

                                            <div className="my-2 h-px bg-gray-700" />

                                            {/* Browse */}
                                            <button type="button" onClick={() => { setLeftPanel('templates') }} className="flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-sm text-gray-200 hover:bg-gray-800">
                                                <Squares2X2Icon className="h-4 w-4 shrink-0 text-gray-400" /> Browse Templates
                                            </button>
                                            <button type="button" className="flex w-full items-center gap-2.5 rounded-md px-3 py-1.5 text-sm text-gray-200 hover:bg-gray-800">
                                                <svg className="h-4 w-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0Zm-9 5.25h.008v.008H12v-.008Z" /></svg> Help
                                            </button>
                                        </div>
                                    </div>
                                )}
                                {leftPanel === 'layers' && (
                                    <div className="flex flex-1 flex-col">
                                        <div className="flex items-center justify-between border-b border-gray-700 px-3 py-2">
                                            <h2 className="text-xs font-semibold uppercase tracking-wide text-gray-400">Layer Stack</h2>
                                            <button type="button" onClick={() => setLeftPanel(null)} className="text-gray-500 hover:text-gray-300"><XMarkIcon className="h-4 w-4" /></button>
                                        </div>
                                        {/* Grouping toolbar — visible whenever the user has shift-clicked 2+ rows.
                                            Intentionally unobtrusive so the normal flow stays clean. */}
                                        {groupingSelection.size > 0 && (
                                            <div className="flex items-center justify-between gap-2 border-b border-gray-800 bg-gray-900/60 px-3 py-1.5 text-[11px] text-gray-300">
                                                <span className="truncate">{groupingSelection.size} selected</span>
                                                <div className="flex items-center gap-2">
                                                    <button
                                                        type="button"
                                                        className="rounded border border-gray-700 bg-gray-800 px-2 py-0.5 text-[10px] text-gray-200 hover:border-gray-600 disabled:opacity-50"
                                                        disabled={groupingSelection.size < 2}
                                                        title="Group selected layers so they move and resize together"
                                                        onClick={() => {
                                                            const ids = Array.from(groupingSelection)
                                                            setDocument((d) => {
                                                                const res = createGroup(d, ids)
                                                                if (res.groupId) {
                                                                    setSelectedGroupId(res.groupId)
                                                                }
                                                                return res.doc
                                                            })
                                                            setGroupingSelection(new Set())
                                                        }}
                                                    >
                                                        Group selected
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="text-gray-500 hover:text-gray-300"
                                                        onClick={() => setGroupingSelection(new Set())}
                                                        title="Clear grouping selection"
                                                    >
                                                        Clear
                                                    </button>
                                                </div>
                                            </div>
                                        )}
                                        <div className="flex-1 overflow-y-auto p-2">
                                            {layerPanelRows.length === 0 ? (
                                                <p className="px-2 py-4 text-center text-xs text-gray-500">No layers yet</p>
                                            ) : (
                                                <ul className="space-y-0.5">
                                                    {layerPanelRows.map((row) => {
                                                        if (row.kind === 'group') {
                                                            const g = row.group
                                                            const isGroupSelected = selectedGroupId === g.id
                                                            const anyMemberVisible = row.members.some((m) => m.visible)
                                                            const allMembersLocked = row.members.length > 0 && row.members.every((m) => m.locked)
                                                            return (
                                                                <li key={`g:${g.id}`} className="rounded">
                                                                    <div className={`flex items-center gap-1.5 rounded px-2 py-1.5 text-xs ${isGroupSelected ? 'bg-indigo-600/20 text-white ring-1 ring-indigo-500/60' : 'text-gray-200 hover:bg-gray-800'}`}>
                                                                        <button
                                                                            type="button"
                                                                            className="shrink-0 text-gray-400 hover:text-gray-100"
                                                                            onClick={() => setDocument((d) => updateGroupInDoc(d, g.id, { collapsed: !g.collapsed }))}
                                                                            title={g.collapsed ? 'Expand group' : 'Collapse group'}
                                                                            aria-label={g.collapsed ? 'Expand group' : 'Collapse group'}
                                                                        >
                                                                            {g.collapsed ? '▸' : '▾'}
                                                                        </button>
                                                                        <span className="shrink-0 text-[10px] opacity-70 w-4 text-center">⧉</span>
                                                                        <button
                                                                            type="button"
                                                                            className="min-w-0 flex-1 truncate text-left font-medium"
                                                                            onClick={() => {
                                                                                // Selecting a group picks the top-most
                                                                                // member as the "anchor" (for the
                                                                                // properties panel) and marks the group
                                                                                // active.
                                                                                const anchor = row.members[0]
                                                                                if (anchor) {
                                                                                    selectLayerOrGroup(anchor.id)
                                                                                }
                                                                            }}
                                                                            onDoubleClick={() => {
                                                                                const next = window.prompt('Group name', g.name)
                                                                                if (next && next.trim() !== g.name) {
                                                                                    setDocument((d) => updateGroupInDoc(d, g.id, { name: next.trim() }))
                                                                                }
                                                                            }}
                                                                            title="Click to select group. Double-click to rename."
                                                                        >
                                                                            {g.name}
                                                                        </button>
                                                                        <span className="text-[10px] text-gray-500">{row.members.length}</span>
                                                                        <button
                                                                            type="button"
                                                                            className="shrink-0 text-gray-500 hover:text-gray-200"
                                                                            title={anyMemberVisible ? 'Hide all' : 'Show all'}
                                                                            onClick={() => {
                                                                                setDocument((d) => ({
                                                                                    ...d,
                                                                                    layers: d.layers.map((l) =>
                                                                                        l.groupId === g.id ? { ...l, visible: !anyMemberVisible } : l
                                                                                    ),
                                                                                }))
                                                                            }}
                                                                        >
                                                                            {anyMemberVisible ? <EyeIcon className="h-3.5 w-3.5" /> : <EyeSlashIcon className="h-3.5 w-3.5 opacity-40" />}
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            className="shrink-0 text-gray-500 hover:text-gray-200"
                                                                            title={allMembersLocked ? 'Unlock all' : 'Lock all'}
                                                                            onClick={() => {
                                                                                setDocument((d) => ({
                                                                                    ...d,
                                                                                    layers: d.layers.map((l) =>
                                                                                        l.groupId === g.id ? { ...l, locked: !allMembersLocked } : l
                                                                                    ),
                                                                                }))
                                                                            }}
                                                                        >
                                                                            {allMembersLocked ? <LockClosedIcon className="h-3.5 w-3.5" /> : <LockOpenIcon className="h-3.5 w-3.5 opacity-60" />}
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            className="shrink-0 rounded px-1 text-[10px] text-gray-400 hover:bg-gray-700 hover:text-gray-100"
                                                                            title="Ungroup"
                                                                            onClick={() => {
                                                                                setDocument((d) => ungroupInDoc(d, g.id))
                                                                                if (selectedGroupId === g.id) {
                                                                                    setSelectedGroupId(null)
                                                                                }
                                                                            }}
                                                                        >
                                                                            Ungroup
                                                                        </button>
                                                                    </div>
                                                                    {!g.collapsed && (
                                                                        <ul className="ml-4 mt-0.5 space-y-0.5 border-l border-gray-800 pl-2">
                                                                            {row.members.map((layer) => renderLayerPanelRow(layer, true))}
                                                                        </ul>
                                                                    )}
                                                                </li>
                                                            )
                                                        }
                                                        return renderLayerPanelRow(row.layer, false)
                                                    })}
                                                </ul>
                                            )}
                                        </div>
                                        <div className="relative flex items-center justify-between border-t border-gray-700 px-2 py-2">
                                            <button type="button" onClick={() => setAddLayerOpen((v) => !v)} className="rounded p-1.5 text-gray-400 hover:bg-gray-800 hover:text-white" title="Add layer"><svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg></button>
                                            <button type="button" onClick={() => selectedLayerId && selectedLayer && setDocument((d) => ({ ...d, layers: [...d.layers, cloneLayer(selectedLayer)] }))} className="rounded p-1.5 text-gray-400 hover:bg-gray-800 hover:text-white" title="Duplicate layer"><DocumentDuplicateIcon className="h-4 w-4" /></button>
                                            <button type="button" onClick={() => selectedLayer && updateLayer(selectedLayer.id, (l) => ({ ...l, visible: !l.visible }))} className="rounded p-1.5 text-gray-400 hover:bg-gray-800 hover:text-white" title="Toggle visibility"><EyeIcon className="h-4 w-4" /></button>
                                            <button type="button" onClick={() => selectedLayerId && setDocument((d) => deleteLayerFromDoc(d, selectedLayerId))} className="rounded p-1.5 text-gray-400 hover:bg-gray-800 hover:text-red-400" title="Delete layer"><TrashIcon className="h-4 w-4" /></button>

                                            {/* Add Layer popover */}
                                            {addLayerOpen && (
                                                <div className="absolute bottom-full left-0 mb-2 w-64 rounded-lg bg-gray-900 p-4 shadow-xl ring-1 ring-gray-700">
                                                    <p className="mb-3 text-[10px] font-semibold uppercase tracking-wider text-gray-400">Add layer</p>
                                                    <div className="grid grid-cols-2 gap-3">
                                                        <button type="button" onClick={() => { setAddLayerOpen(false); addTextLayer() }} className="flex flex-col items-center gap-1.5 rounded-md border border-gray-700 bg-gray-800 px-2 py-2.5 text-gray-300 hover:border-gray-500 hover:bg-gray-700 hover:text-white transition-colors">
                                                            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 011.037-.443 48.282 48.282 0 005.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" /></svg>
                                                            <span className="text-[11px] font-medium">Text</span>
                                                        </button>
                                                        <button type="button" onClick={() => { setAddLayerOpen(false); openPickerForAddImage() }} className="flex flex-col items-center gap-1.5 rounded-md border border-gray-700 bg-gray-800 px-2 py-2.5 text-gray-300 hover:border-gray-500 hover:bg-gray-700 hover:text-white transition-colors">
                                                            <PhotoIcon className="h-5 w-5" />
                                                            <span className="text-[11px] font-medium">Image</span>
                                                        </button>
                                                        <button type="button" onClick={() => { setAddLayerOpen(false); addGenerativeImageLayer() }} className="flex flex-col items-center gap-1.5 rounded-md border border-gray-700 bg-gray-800 px-2 py-2.5 text-gray-300 hover:border-gray-500 hover:bg-gray-700 hover:text-white transition-colors">
                                                            <SparklesIcon className="h-5 w-5" />
                                                            <span className="text-[11px] font-medium">AI Image</span>
                                                        </button>
                                                        <button type="button" onClick={() => { setAddLayerOpen(false); addFillLayer() }} className="flex flex-col items-center gap-1.5 rounded-md border border-gray-700 bg-gray-800 px-2 py-2.5 text-gray-300 hover:border-gray-500 hover:bg-gray-700 hover:text-white transition-colors">
                                                            <SwatchIcon className="h-5 w-5" />
                                                            <span className="text-[11px] font-medium">Fill</span>
                                                        </button>
                                                        <button type="button" onClick={() => { setAddLayerOpen(false); addMaskLayer() }} className="flex flex-col items-center gap-1.5 rounded-md border border-gray-700 bg-gray-800 px-2 py-2.5 text-gray-300 hover:border-gray-500 hover:bg-gray-700 hover:text-white transition-colors" title="Clip the layer directly beneath with a shape or gradient">
                                                            <span className="flex h-5 w-5 items-center justify-center text-lg leading-none">◑</span>
                                                            <span className="text-[11px] font-medium">Mask</span>
                                                        </button>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )}
                                {leftPanel === 'assets' && (
                                    <div className="flex flex-1 flex-col">
                                        <div className="flex items-center justify-between border-b border-gray-700 px-3 py-2"><h2 className="text-xs font-semibold uppercase tracking-wide text-gray-400">Assets</h2><button type="button" onClick={() => setLeftPanel(null)} className="text-gray-500 hover:text-gray-300"><XMarkIcon className="h-4 w-4" /></button></div>
                                        <div className="flex flex-1 items-center justify-center p-6 text-center"><div><PhotoIcon className="mx-auto h-10 w-10 text-gray-600" /><p className="mt-2 text-sm text-gray-400">Asset browser</p><p className="mt-1 text-xs text-gray-500">Coming soon — browse and drag assets from your DAM library.</p><button type="button" onClick={() => { setLeftPanel(null); openPickerForAddImage() }} className="mt-4 rounded-md bg-gray-700 px-3 py-1.5 text-xs font-medium text-gray-200 hover:bg-gray-600">Add image from library</button></div></div>
                                    </div>
                                )}
                                {leftPanel === 'templates' && (
                                    <div className="flex flex-1 flex-col">
                                        <div className="flex items-center justify-between border-b border-gray-700 px-3 py-2">
                                            <h2 className="text-xs font-semibold uppercase tracking-wide text-gray-400">Templates</h2>
                                            <button type="button" onClick={() => setLeftPanel(null)} className="text-gray-500 hover:text-gray-300"><XMarkIcon className="h-4 w-4" /></button>
                                        </div>
                                        {/* Guided wizard CTA */}
                                        <div className="border-b border-gray-700 px-2 py-2">
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    setWizardStep(1)
                                                    setWizardCategory('all')
                                                    setWizardPlatform(null)
                                                    setWizardFormat(null)
                                                    setWizardLayoutStyle(null)
                                                    setWizardName('')
                                                    setTemplateWizardOpen(true)
                                                }}
                                                className="flex w-full items-center justify-center gap-2 rounded-md bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-500 transition-colors"
                                            >
                                                <Squares2X2Icon className="h-4 w-4" />
                                                Browse Templates
                                            </button>
                                        </div>
                                        {/* Category tabs */}
                                        <div className="flex gap-1 overflow-x-auto border-b border-gray-700 px-2 py-1.5" style={{ scrollbarWidth: 'none', msOverflowStyle: 'none', WebkitOverflowScrolling: 'touch' }}>
                                            {[{ id: 'all' as const, label: 'All' }, ...TEMPLATE_CATEGORIES.filter(c => c.platforms.length > 0)].map((cat) => (
                                                <button
                                                    key={cat.id}
                                                    type="button"
                                                    onClick={() => setTemplateCategory(cat.id)}
                                                    className={`shrink-0 rounded-md px-2 py-1 text-[11px] font-medium transition-colors ${templateCategory === cat.id ? 'bg-gray-700 text-white' : 'text-gray-400 hover:text-gray-200'}`}
                                                >
                                                    {cat.label}
                                                </button>
                                            ))}
                                        </div>
                                        {/* Template list */}
                                        <div className="flex-1 overflow-y-auto px-2 py-2">
                                            {TEMPLATE_CATEGORIES
                                                .filter((cat) => cat.platforms.length > 0 && (templateCategory === 'all' || templateCategory === cat.id))
                                                .map((cat) =>
                                                    cat.platforms.map((platform) => (
                                                        <div key={platform.id} className="mb-4">
                                                            <h3 className="mb-1.5 px-1 text-[11px] font-semibold text-gray-300">{platform.name}</h3>
                                                            <div className="space-y-1">
                                                                {platform.formats.map((fmt) => (
                                                                    <button
                                                                        key={fmt.id}
                                                                        type="button"
                                                                        onClick={async () => {
                                                                            if (discardRequiresConfirmation) {
                                                                                const ok = await editorConfirm({ title: 'Unsaved changes', message: 'Discard unsaved changes and load this template?', confirmText: 'Load template', variant: 'warning' })
                                                                                if (!ok) return
                                                                            }
                                                                            const brandColor = typeof auth?.activeBrand?.primary_color === 'string' ? auth.activeBrand.primary_color : undefined
                                                                            const layers = blueprintToLayers(fmt.layers, fmt.width, fmt.height, brandColor)
                                                                            const fresh = {
                                                                                id: generateId(),
                                                                                width: fmt.width,
                                                                                height: fmt.height,
                                                                                preset: 'custom' as const,
                                                                                layers,
                                                                                created_at: new Date().toISOString(),
                                                                                updated_at: new Date().toISOString(),
                                                                            }
                                                                            flushSync(() => {
                                                                                setCompositionId(null)
                                                                                setCompositionName(fmt.name)
                                                                                setLastSavedName(fmt.name)
                                                                                setDocument(fresh)
                                                                                setLastSavedSerialized(JSON.stringify(fresh))
                                                                                setSelectedLayerId(null)
                                                                                setEditingTextLayerId(null)
                                                                                setVersions([])
                                                                                setSaveState('idle')
                                                                                setSaveError(null)
                                                                                setUserZoom(null)
                                                                                setPanOffset({ x: 0, y: 0 })
                                                                            })
                                                                            replaceUrlCompositionParam(null)
                                                                            setLeftPanel('layers')
                                                                        }}
                                                                        className="group flex w-full items-center gap-2.5 rounded-md px-2 py-2 text-left hover:bg-gray-800 transition-colors"
                                                                    >
                                                                        <div
                                                                            className="shrink-0 rounded border border-gray-700 bg-gray-800 group-hover:border-gray-600"
                                                                            style={{
                                                                                width: 36,
                                                                                height: Math.round(36 * (fmt.height / fmt.width)),
                                                                                minHeight: 14,
                                                                                maxHeight: 54,
                                                                            }}
                                                                        />
                                                                        <div className="min-w-0 flex-1">
                                                                            <div className="truncate text-[12px] font-medium text-gray-200 group-hover:text-white">{fmt.name}</div>
                                                                            <div className="text-[10px] text-gray-500">{fmt.width}&times;{fmt.height}</div>
                                                                        </div>
                                                                    </button>
                                                                ))}
                                                            </div>
                                                        </div>
                                                    ))
                                                )}
                                            <div className="mt-2 border-t border-gray-700 pt-3">
                                                <button type="button" onClick={() => { setLeftPanel(null); openCompositionPickerAndLoad() }} className="flex w-full items-center gap-2 rounded-md px-2 py-2 text-[12px] font-medium text-gray-400 hover:bg-gray-800 hover:text-gray-200">
                                                    <FolderOpenIcon className="h-4 w-4 shrink-0" />
                                                    Browse saved compositions
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                )}
                                {leftPanel === 'history' && (
                                    <div className="flex flex-1 flex-col">
                                        <div className="flex items-center justify-between border-b border-gray-700 px-3 py-2">
                                            <h2 className="text-xs font-semibold uppercase tracking-wide text-gray-400">History</h2>
                                            <button type="button" onClick={() => setLeftPanel(null)} className="text-gray-500 hover:text-gray-300"><XMarkIcon className="h-4 w-4" /></button>
                                        </div>
                                        <div className="flex-1 overflow-y-auto p-2 text-xs">
                                            {versionsLoading && (
                                                <div className="flex items-center gap-2 py-6 text-gray-400" role="status" aria-busy="true">
                                                    <ArrowPathIcon className="h-4 w-4 shrink-0 animate-spin text-indigo-400" />
                                                    Loading versions…
                                                </div>
                                            )}
                                            {!compositionId && (
                                                <p className="px-2 py-4 text-gray-500">Save the composition first to track versions.</p>
                                            )}
                                            {compositionId && !versionsLoading && versions.length === 0 && !compositionLoadError && (
                                                <p className="px-2 py-4 text-gray-500">No versions yet.</p>
                                            )}
                                            {compositionLoadError && (
                                                <p className="px-2 py-4 text-red-400">{compositionLoadError}</p>
                                            )}
                                            {compositionId && versions.map((v) => {
                                                const isAutosave = v.kind === 'autosave'
                                                return (
                                                    <div
                                                        key={v.id}
                                                        className={`flex items-center gap-2 rounded-md px-2 py-2 hover:bg-gray-800 ${isAutosave ? 'opacity-80' : ''}`}
                                                    >
                                                        {v.thumbnail_url ? (
                                                            <img
                                                                src={v.thumbnail_url}
                                                                alt=""
                                                                className={`h-10 w-10 shrink-0 rounded object-cover ring-1 ${isAutosave ? 'ring-gray-800' : 'ring-gray-700'}`}
                                                            />
                                                        ) : (
                                                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded bg-gray-800 text-[8px] text-gray-500 ring-1 ring-gray-700">No preview</div>
                                                        )}
                                                        <div className="min-w-0 flex-1">
                                                            <div className="flex items-center gap-1.5">
                                                                <div className={`truncate text-[12px] ${isAutosave ? 'text-gray-400' : 'font-medium text-gray-200'}`}>
                                                                    {new Date(v.created_at).toLocaleString()}
                                                                </div>
                                                                {isAutosave && (
                                                                    <span
                                                                        title="Auto-saved while you were editing. Jackpot keeps the 10 most recent autosaves."
                                                                        className="shrink-0 rounded-sm border border-gray-700 bg-gray-800 px-1 py-[1px] text-[9px] font-medium uppercase tracking-wide text-gray-500"
                                                                    >
                                                                        Auto
                                                                    </span>
                                                                )}
                                                            </div>
                                                            {v.label && <div className="truncate text-[10px] text-gray-500">{v.label}</div>}
                                                        </div>
                                                        <button
                                                            type="button"
                                                            onClick={() => { if (v.document) { setDocument(typeof v.document === 'string' ? JSON.parse(v.document) : v.document); setLastSavedSerialized(JSON.stringify(v.document)); setEditingTextLayerId(null) } }}
                                                            className="shrink-0 rounded bg-gray-700 px-2 py-1 text-[10px] font-medium text-gray-300 hover:bg-gray-600 hover:text-white"
                                                        >
                                                            Restore
                                                        </button>
                                                    </div>
                                                )
                                            })}
                                            {compositionId && !versionsLoading && versions.length >= 2 && (
                                                <button
                                                    type="button"
                                                    onClick={() => setCompareOpen(true)}
                                                    className="mt-3 flex w-full items-center justify-center gap-1.5 rounded-md border border-gray-700 bg-gray-800 px-3 py-2 text-xs font-medium text-gray-300 hover:bg-gray-700 hover:text-white"
                                                >
                                                    <ArrowsRightLeftIcon className="h-3.5 w-3.5" />
                                                    Compare versions
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                )}

                {/* Canvas */}
                <main
                    ref={canvasContainerRef}
                    onPointerDown={onCanvasPointerDown}
                    onPointerMove={onCanvasPointerMove}
                    onPointerUp={onCanvasPointerUp}
                    className={`relative box-border flex min-w-0 flex-1 items-center justify-center overflow-hidden ${
                        uiMode === 'preview'
                            ? previewFrame === 'social'
                                ? 'bg-gradient-to-b from-neutral-950 via-neutral-900 to-black p-8 sm:p-8 md:p-12 before:pointer-events-none before:absolute before:inset-0 before:z-[1] before:bg-black/25 before:content-[\'\']'
                                : 'bg-gradient-to-br from-slate-900 via-slate-800 to-slate-950 p-8 sm:p-10 md:p-14 before:pointer-events-none before:absolute before:inset-0 before:z-[1] before:bg-black/25 before:content-[\'\']'
                            : 'bg-neutral-200 dark:bg-neutral-900'
                    }`}
                    style={uiMode === 'edit' ? {
                        backgroundImage: 'radial-gradient(circle, rgba(0,0,0,0.10) 1px, transparent 1px)',
                        backgroundSize: '24px 24px',
                        backgroundPosition: `${panOffset.x % 24}px ${panOffset.y % 24}px`,
                    } : undefined}
                >
                    {/* Zoom controls moved to top bar */}

                    {document.layers.length === 0 && !welcomeDismissed && (
                        <div className="pointer-events-none absolute inset-0 z-10 flex items-center justify-center p-6">
                            <style>{`
                                @keyframes jp-slot-spin { 0% { transform: translateY(0); } 25% { transform: translateY(-6px); } 50% { transform: translateY(0); } 75% { transform: translateY(4px); } 100% { transform: translateY(0); } }
                                @keyframes jp-slot-spin-delay { 0% { transform: translateY(0); } 30% { transform: translateY(5px); } 55% { transform: translateY(-4px); } 80% { transform: translateY(2px); } 100% { transform: translateY(0); } }
                                .jp-slot-reel { transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
                                .group:hover .jp-slot-reel-1 { animation: jp-slot-spin 0.5s ease-in-out; }
                                .group:hover .jp-slot-reel-2 { animation: jp-slot-spin-delay 0.6s ease-in-out 0.08s; }
                                .group:hover .jp-slot-reel-3 { animation: jp-slot-spin 0.55s ease-in-out 0.15s; }
                            `}</style>
                            {/*
                              * White card behind the welcome copy — lifts it off the
                              * canvas dot-grid and keeps the action buttons legible
                              * against busy brand backgrounds without having to fight
                              * every individual text color.
                              */}
                            <div className="pointer-events-auto w-full max-w-lg rounded-2xl bg-white p-8 shadow-2xl ring-1 ring-black/5 sm:p-10">
                                {/* Hero with slot icon trio */}
                                <div className="mb-9 text-center">
                                    <div className="mb-4 flex items-center justify-center gap-3">
                                        <img src="/jp-parts/cherry-slot.svg" alt="" className="jp-slot-reel jp-slot-reel-1 h-8 w-8 opacity-60" />
                                        <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 shadow-lg shadow-indigo-500/25">
                                            <img src="/jp-parts/cherry-slot.svg" alt="" className="h-8 w-8 brightness-0 invert" />
                                        </div>
                                        <img src="/jp-parts/diamond-slot.svg" alt="" className="jp-slot-reel jp-slot-reel-2 h-8 w-8 opacity-60" />
                                    </div>
                                    <h2 className="text-xl font-bold text-gray-900 dark:text-white" style={{ color: '#1f2937' }}>
                                        What would you like to create?
                                    </h2>
                                    <p className="mt-1.5 text-sm text-gray-500">
                                        Pick a starting point — you can always change direction later.
                                    </p>
                                </div>

                                {/* Action cards */}
                                <div className="grid gap-4" style={{ marginTop: '8px' }}>
                                    {/* Start from Template */}
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setWizardStep(1)
                                            setWizardCategory('all')
                                            setWizardPlatform(null)
                                            setWizardFormat(null)
                                            setWizardLayoutStyle(null)
                                            setWizardName('')
                                            setTemplateWizardOpen(true)
                                        }}
                                        className="group relative flex w-full items-start gap-5 rounded-xl border border-indigo-200 bg-gradient-to-r from-indigo-50 to-white px-5 py-5 text-left shadow-sm ring-1 ring-indigo-100 transition-all duration-150 hover:-translate-y-px hover:border-indigo-300 hover:shadow-lg hover:shadow-indigo-100/40 active:scale-[0.99]"
                                    >
                                        <span className="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-indigo-100 transition-colors group-hover:bg-indigo-200">
                                            <Squares2X2Icon className="h-5 w-5 text-indigo-600" />
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <span className="block text-[15px] font-semibold text-gray-900">Start from a template</span>
                                            <span className="mt-1.5 block text-[13px] leading-relaxed text-gray-500">
                                                Choose a platform, pick an ad type, and get a ready-made layer stack — social posts, banners, presentations and more.
                                            </span>
                                        </div>
                                        <span className="ml-auto mt-1 shrink-0 rounded-full bg-indigo-100 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide text-indigo-600 transition-colors group-hover:bg-indigo-600 group-hover:text-white">Recommended</span>
                                        <img src="/jp-parts/seven-slot.svg" alt="" className="jp-slot-reel jp-slot-reel-3 absolute -right-2 -top-2 h-6 w-6 opacity-0 transition-opacity duration-200 group-hover:opacity-40" />
                                    </button>

                                    {/* Open existing composition */}
                                    <button
                                        type="button"
                                        onClick={openCompositionPickerAndLoad}
                                        className="group flex w-full items-start gap-5 rounded-xl border border-gray-200 bg-white px-5 py-5 text-left shadow-sm transition-all duration-150 hover:-translate-y-px hover:border-gray-300 hover:bg-gray-50/80 hover:shadow-md active:scale-[0.99]"
                                    >
                                        <span className="mt-0.5 flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 transition-colors group-hover:bg-gray-200 group-hover:text-gray-700">
                                            <FolderOpenIcon className="h-5 w-5" />
                                        </span>
                                        <div className="min-w-0">
                                            <span className="block text-[15px] font-semibold text-gray-900">Open an existing composition</span>
                                            <span className="mt-1.5 block text-[13px] leading-relaxed text-gray-500">
                                                Continue where you left off — load a saved composition for this brand.
                                            </span>
                                        </div>
                                    </button>

                                    {/* Generate with AI — Jackpot Spin Card (hidden when AI disabled) */}
                                    {!aiEnabled && (
                                        <div className="w-full rounded-xl border border-gray-300 bg-gray-100 px-5 py-5 text-center opacity-50">
                                            <p className="text-sm font-medium text-gray-400">AI features are disabled</p>
                                            <p className="mt-1 text-xs text-gray-400">Contact your workspace admin to enable AI-powered layout generation.</p>
                                        </div>
                                    )}
                                    {aiEnabled && (() => {
                                        const JP_SPIN_PHRASES = [
                                            'Take a lucky spin',
                                            'Feeling lucky?',
                                            'Give the reels a pull',
                                            'Spin up something new',
                                            'Try your luck',
                                        ]
                                        const JP_SPIN_SUBS = [
                                            'Let Jackpot AI build a layout tailored to your brand.',
                                            'Describe what you need and we\'ll roll out the layers.',
                                            'One spin and your composition is ready to go.',
                                        ]
                                        const REEL_SYMS = [
                                            '/jp-parts/cherry-slot.svg',
                                            '/jp-parts/seven-slot.svg',
                                            '/jp-parts/diamond-slot.svg',
                                        ]
                                        return (
                                        <div
                                            className="group/spin relative w-full cursor-pointer select-none overflow-hidden rounded-xl shadow-lg transition-all duration-200 hover:-translate-y-px hover:shadow-xl active:scale-[0.99]"
                                            style={{ background: 'linear-gradient(135deg, #6d28d9 0%, #4338ca 50%, #581c87 100%)', border: '1px solid rgba(139,92,246,0.35)', boxShadow: '0 4px 14px rgba(124,58,237,0.25)' }}
                                            onClick={() => void generateGuidedLayout()}
                                            role="button"
                                            tabIndex={0}
                                            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); void generateGuidedLayout() } }}
                                        >
                                            <style>{`
                                                @keyframes jp-reel-float-1 { 0%,100% { transform: translateY(0) rotate(0deg); } 50% { transform: translateY(-6px) rotate(4deg); } }
                                                @keyframes jp-reel-float-2 { 0%,100% { transform: translateY(0) rotate(0deg); } 50% { transform: translateY(-8px) rotate(-5deg); } }
                                                @keyframes jp-reel-float-3 { 0%,100% { transform: translateY(0) rotate(0deg); } 50% { transform: translateY(-5px) rotate(3deg); } }
                                                @keyframes jp-spin-pull { 0% { transform: translateY(0); } 30% { transform: translateY(8px) scale(0.97); } 60% { transform: translateY(-60px); } 100% { transform: translateY(0); } }
                                                @keyframes jp-phrase-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
                                                .jp-mini-reel-1 { animation: jp-reel-float-1 2.4s ease-in-out infinite; }
                                                .jp-mini-reel-2 { animation: jp-reel-float-2 2.8s ease-in-out 0.3s infinite; }
                                                .jp-mini-reel-3 { animation: jp-reel-float-3 2.2s ease-in-out 0.6s infinite; }
                                                .group\\/spin:hover .jp-mini-reel-1 { animation: jp-spin-pull 0.5s cubic-bezier(0.34, 1.56, 0.64, 1); }
                                                .group\\/spin:hover .jp-mini-reel-2 { animation: jp-spin-pull 0.55s cubic-bezier(0.34, 1.56, 0.64, 1) 0.07s; }
                                                .group\\/spin:hover .jp-mini-reel-3 { animation: jp-spin-pull 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.14s; }
                                                .group\\/spin:active .jp-mini-reel-1,
                                                .group\\/spin:active .jp-mini-reel-2,
                                                .group\\/spin:active .jp-mini-reel-3 { transform: translateY(4px) scale(0.95); transition: transform 80ms ease-out; }
                                            `}</style>

                                            {/* Ambient glow */}
                                            <div className="pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full blur-3xl" style={{ background: 'rgba(255,255,255,0.1)' }} />
                                            <div className="pointer-events-none absolute -bottom-8 -left-8 h-32 w-32 rounded-full blur-2xl" style={{ background: 'rgba(167,139,250,0.2)' }} />

                                            <div className="relative flex items-center gap-5 px-5 py-5">
                                                {/* Mini slot reels */}
                                                <div className="flex shrink-0 items-end gap-1.5">
                                                    {REEL_SYMS.map((sym, i) => (
                                                        <div key={i} className="flex h-12 w-10 items-center justify-center rounded-lg shadow-inner backdrop-blur-sm" style={{ background: 'rgba(255,255,255,0.15)' }}>
                                                            <img
                                                                src={sym}
                                                                alt=""
                                                                className={`jp-mini-reel-${i + 1} h-6 w-6 brightness-0 invert drop-shadow-sm`}
                                                                draggable={false}
                                                            />
                                                        </div>
                                                    ))}
                                                </div>

                                                {/* Copy */}
                                                <div className="min-w-0 flex-1">
                                                    <span key={spinPhraseIdx} className="block text-[15px] font-bold" style={{ animation: 'jp-phrase-in 0.35s ease-out', color: '#ffffff', textShadow: '0 1px 2px rgba(0,0,0,0.3)' }}>
                                                        {JP_SPIN_PHRASES[spinPhraseIdx % JP_SPIN_PHRASES.length]}
                                                    </span>
                                                    <span className="mt-1 block text-[12px] leading-relaxed" style={{ color: 'rgba(237,233,254,0.9)' }}>
                                                        {JP_SPIN_SUBS[spinPhraseIdx % JP_SPIN_SUBS.length]}
                                                    </span>
                                                </div>

                                                {/* CTA arrow */}
                                                <div className="flex shrink-0 items-center">
                                                    <span className="flex h-9 w-9 items-center justify-center rounded-full shadow-sm backdrop-blur-sm transition-all duration-150 group-hover/spin:scale-110" style={{ background: 'rgba(255,255,255,0.2)', color: '#ffffff' }}>
                                                        <SparklesIcon className="h-4 w-4" />
                                                    </span>
                                                </div>
                                            </div>

                                            {/* Bottom accent line */}
                                            <div className="h-[2px] w-full" style={{ background: 'linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent)' }} />
                                        </div>
                                        )
                                    })()}

                                    {/* Blank canvas */}
                                    <button
                                        type="button"
                                        onClick={async () => {
                                            // If there's an existing composition loaded, wipe it back
                                            // to a fresh draft first so "blank canvas" really means
                                            // blank. startNewComposition resets welcomeDismissed to
                                            // false, so we set it *after* awaiting. Otherwise just
                                            // hide the welcome overlay so the user can start adding
                                            // layers on the current empty doc.
                                            if (compositionId || document.layers.length > 0) {
                                                await startNewComposition()
                                            }
                                            setWelcomeDismissed(true)
                                            setActivityToast('Blank canvas ready — add a layer to get started')
                                        }}
                                        className="group flex w-full items-center justify-center gap-2 rounded-lg border border-dashed border-gray-300 py-3 text-xs font-medium text-gray-400 transition-all hover:border-gray-400 hover:text-gray-600"
                                    >
                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                        Blank canvas — I'll build it myself
                                    </button>
                                </div>

                                {/* Slot machine motif footer */}
                                <div className="group mt-5 flex items-center justify-center gap-2 opacity-30 transition-opacity hover:opacity-60">
                                    <img src="/jp-parts/cherry-slot.svg" alt="" className="jp-slot-reel jp-slot-reel-1 h-4 w-4" />
                                    <img src="/jp-parts/seven-slot.svg" alt="" className="jp-slot-reel jp-slot-reel-2 h-4 w-4" />
                                    <img src="/jp-parts/diamond-slot.svg" alt="" className="jp-slot-reel jp-slot-reel-3 h-4 w-4" />
                                </div>
                            </div>
                        </div>
                    )}
                    {uiMode === 'preview' && previewFrame === 'banner' && (
                        <div className="absolute z-[3] mb-4 flex h-9 items-center gap-2 rounded-lg border border-neutral-400/40 bg-gradient-to-r from-neutral-200 to-neutral-300 px-3 shadow-inner" style={{ top: 16, left: '50%', transform: 'translateX(-50%)' }}>
                            <span className="inline-block h-2.5 w-2.5 rounded-full bg-red-400 shadow-sm" />
                            <span className="inline-block h-2.5 w-2.5 rounded-full bg-amber-400 shadow-sm" />
                            <span className="inline-block h-2.5 w-2.5 rounded-full bg-emerald-500 shadow-sm" />
                            <span className="ml-1 text-[11px] font-medium text-neutral-700">Web banner preview</span>
                        </div>
                    )}
                    {/* Artboard positioning wrapper */}
                    <div
                        className={uiMode === 'preview'
                            ? (previewFrame === 'social'
                                ? 'relative z-[2] overflow-hidden rounded-[2.25rem] border-[14px] border-neutral-900 bg-gradient-to-b from-neutral-900 to-black p-3 shadow-[0_25px_80px_-12px_rgba(0,0,0,0.65)] ring-1 ring-white/10'
                                : 'relative z-[2] overflow-hidden w-full max-w-5xl mx-auto rounded-xl border border-neutral-500/80 bg-neutral-100 p-4 shadow-[0_18px_50px_-12px_rgba(0,0,0,0.45)] ring-1 ring-black/10 sm:p-5')
                            : 'absolute'
                        }
                        style={uiMode !== 'preview' ? {
                            left: '50%',
                            top: '50%',
                            width: document.width * effectiveScale,
                            height: document.height * effectiveScale,
                            transform: `translate(calc(-50% + ${panOffset.x}px), calc(-50% + ${panOffset.y}px))`,
                        } : {
                            width: document.width * effectiveScale,
                            height: document.height * effectiveScale,
                        }}
                        onMouseDown={uiMode === 'edit' ? clearSelection : undefined}
                    >
                        {/* Artboard white background shadow — edit mode shows this as artboard boundary */}
                        {uiMode === 'edit' && (
                            <div
                                className="absolute inset-0 shadow-2xl ring-1 ring-black/10 bg-white dark:bg-neutral-800"
                            />
                        )}
                        <div
                            ref={stageRef}
                            role="presentation"
                            className={`isolate origin-top-left ${
                                uiMode === 'preview'
                                    ? 'absolute left-0 top-0 overflow-hidden bg-white dark:bg-neutral-800'
                                    : 'relative'
                            }`}
                            style={{
                                width: document.width,
                                height: document.height,
                                transform: `scale(${effectiveScale})`,
                                transformOrigin: 'top left',
                                ...(uiMode === 'edit' ? { overflow: 'visible' } : {}),
                            }}
                        >
                            {uiMode === 'edit' && gridEnabled && (
                                <GridOverlay
                                    docW={document.width}
                                    docH={document.height}
                                    density={effectiveGridDensity}
                                    hits={snapHits}
                                />
                            )}
                            {layersForCanvas.map((layer) => {
                                if (!layer.visible) {
                                    return null
                                }
                                // Mask layers are rendered as a non-exported
                                // gizmo in edit mode (dashed outline so users
                                // can move/resize/select them) and completely
                                // omitted in preview/export mode — their job
                                // is to alter other layers, not to be visible
                                // themselves. The actual clipping is applied
                                // to target layers via `buildMaskStyleForLayer`
                                // below.
                                if (isMaskLayer(layer) && uiMode === 'preview') {
                                    return null
                                }
                                const isSelected = layer.id === selectedLayerId
                                // When the active selection is a group, we
                                // draw the ring once around the union rect
                                // (see `selectedGroupRect` overlay below) and
                                // suppress per-member rings so members don't
                                // look like they were individually selected.
                                const showMemberRing = isSelected && !selectedGroupId
                                // Highlight non-primary group members with a
                                // subtle teal outline so the user can see the
                                // link without losing the primary selection.
                                const isGroupMate =
                                    !!selectedGroupId &&
                                    !isSelected &&
                                    layer.groupId === selectedGroupId
                                const t = layer.transform
                                const rot = t.rotation ?? 0
                                return (
                                    <div
                                        key={layer.id}
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
                                                      mixBlendMode:
                                                          layer.blendMode as CSSProperties['mixBlendMode'],
                                                  }
                                                : {}),
                                            // Apply any masks that target this layer. Spread AFTER
                                            // the other style props so mask-related props can't be
                                            // accidentally overwritten. Mask layers themselves never
                                            // get masked (guarded by buildMaskStyleForLayer returning
                                            // {} for type==='mask').
                                            ...(buildMaskStyleForLayer(layer, document.layers) as CSSProperties),
                                        }}
                                        onMouseDown={(e) => {
                                            e.stopPropagation()
                                            if (selectedLayerId !== layer.id) {
                                                setEditingTextLayerId(null)
                                            }
                                            // Alt/Option → drill into a single grouped member; plain
                                            // click selects the whole group if this layer belongs to
                                            // one. Must run BEFORE beginMove so the latter can read
                                            // `selectedGroupId` and decide rigid-body vs single.
                                            selectLayerOrGroup(layer.id, { alt: e.altKey })
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
                                        {isMaskLayer(layer) && uiMode === 'edit' && (
                                            // Non-exported gizmo: dashed outline + small label so the
                                            // user can see and manipulate the mask's extent. This div
                                            // gets clipped out of the PNG/JPG export because the export
                                            // path switches uiMode to 'preview' before rasterizing (and
                                            // the mask layer is omitted from the render entirely in
                                            // preview — see the guard up above).
                                            <div
                                                className="pointer-events-none absolute inset-0 rounded border-2 border-dashed border-amber-400/80 bg-amber-400/5"
                                                aria-hidden
                                            >
                                                <span className="absolute left-1 top-1 rounded bg-amber-400 px-1 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-black">
                                                    Mask · {layer.shape}
                                                </span>
                                            </div>
                                        )}
                                        {isGenerativeImageLayer(layer) && (
                                            <div className="pointer-events-none absolute bottom-full left-1/2 z-30 mb-1 flex -translate-x-1/2 opacity-0 transition-opacity duration-200 group-hover:pointer-events-auto group-hover:opacity-100">
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
                                                <div className="pointer-events-auto flex gap-0.5 rounded-md border border-gray-200 bg-white/95 px-1 py-0.5 text-[10px] font-medium text-gray-800 shadow-md dark:border-gray-700 dark:bg-gray-900/95 dark:text-gray-100">
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
                                                <div className="pointer-events-auto flex gap-0.5 rounded-md border border-gray-200 bg-white/95 px-1 py-0.5 text-[10px] font-medium text-gray-800 shadow-md dark:border-gray-700 dark:bg-gray-900/95 dark:text-gray-100">
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
                                                            className="h-8 w-8 shrink-0 animate-spin text-indigo-400"
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
                                                                <span className="text-xs font-medium text-gray-200">
                                                                    Creating variations…
                                                                </span>
                                                            </>
                                                        ) : (
                                                            <span className="text-xs font-medium text-gray-200">
                                                                Generating…
                                                            </span>
                                                        )}
                                                    </div>
                                                )}
                                                {layer.status === 'error' && (
                                                    <div className="pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-red-950/25 px-2 text-center dark:bg-red-950/50">
                                                        <ExclamationTriangleIcon
                                                            className="h-6 w-6 text-red-400"
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
                                                {layer.src ? (
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
                                                ) : (
                                                /*
                                                 * Empty image slot — now that templates default to real-photo
                                                 * backgrounds (BG_LAYER type: 'image'), this placeholder is the
                                                 * brand's first signal when their library has no tag-matched
                                                 * candidates. We show a click-to-pick CTA instead of a bare
                                                 * gray box so they can resolve the empty slot without hunting
                                                 * through the right-panel buttons.
                                                 */
                                                <button
                                                    type="button"
                                                    disabled={layer.locked}
                                                    onClick={(e) => {
                                                        e.stopPropagation()
                                                        openPickerForReplaceImage(layer.id)
                                                    }}
                                                    // Hard-coded dark palette + translucency so an empty
                                                    // slot never renders as an opaque white square when the
                                                    // editor root drops its dark-mode class (happens briefly
                                                    // during template applies). Translucent + muted keeps
                                                    // the slot legible without dominating the canvas.
                                                    className="flex h-full w-full cursor-pointer flex-col items-center justify-center gap-1 border-2 border-dashed border-gray-600/70 bg-gray-900/40 px-2 text-center text-[10px] text-gray-300 backdrop-blur-sm transition-colors hover:border-indigo-500 hover:bg-indigo-950/40 hover:text-indigo-200 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    <svg className="h-6 w-6 opacity-70" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" /></svg>
                                                    <span className="font-medium">Click to pick a photo</span>
                                                </button>
                                                )}
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
                                                            className="h-8 w-8 shrink-0 animate-spin text-indigo-400"
                                                            aria-hidden
                                                        />
                                                        <span className="text-xs font-medium text-gray-200">
                                                            Editing…
                                                        </span>
                                                    </div>
                                                )}
                                                {layer.aiEdit?.status === 'error' && (
                                                    <div className="pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-red-950/25 px-2 text-center dark:bg-red-950/50">
                                                        <ExclamationTriangleIcon
                                                            className="h-6 w-6 text-red-400"
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
                                                    borderRadius: layer.borderRadius != null ? `${layer.borderRadius}px` : undefined,
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
                            {uiMode === 'edit' && selectedGroupRect && (
                                // Single dashed outline enclosing every group
                                // member. Sits above the per-layer rings (which
                                // are suppressed when a group is active) so
                                // it's visually unambiguous that "this is the
                                // thing that will move/resize". pointer-events
                                // off — we still want the underlying layers
                                // handling the click.
                                <div
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
                    </div>

                    {/* Bottom status bar */}
                    {uiMode === 'edit' && (
                        <div className="pointer-events-none absolute inset-x-0 bottom-0 z-20 flex items-center justify-between px-4 py-2 text-[11px] text-gray-500">
                            <span className="pointer-events-auto tabular-nums">{document.width} &times; {document.height}</span>
                            <div className="pointer-events-auto flex items-center gap-3">
                                <button type="button" onClick={fitToView} className="hover:text-gray-300" title="Reset zoom & pan">
                                    <ViewfinderCircleIcon className="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    )}

                    {/* AI Post-Generation Suggestion Bar */}
                    {aiSuggestions.length > 0 && uiMode === 'edit' && (
                        <div className="pointer-events-none absolute inset-x-0 bottom-8 z-30 flex justify-center px-6">
                            <div className="pointer-events-auto flex items-center gap-2 rounded-xl border border-gray-700/80 bg-gray-900/95 px-4 py-2.5 shadow-xl backdrop-blur-md ring-1 ring-white/5">
                                <SparklesIcon className="h-4 w-4 shrink-0 text-indigo-400" />
                                <span className="text-[11px] font-medium text-gray-400 mr-1">Try next:</span>
                                {aiSuggestions.slice(0, 3).map((s, i) => (
                                    <button
                                        key={i}
                                        type="button"
                                        className="rounded-lg border border-gray-700 bg-gray-800/80 px-3 py-1.5 text-[11px] font-medium text-gray-300 transition-colors hover:border-indigo-500/40 hover:bg-indigo-900/30 hover:text-white"
                                        onClick={() => {
                                            setAiLayoutPrompt(s.description)
                                            setAiLayoutPromptOpen(true)
                                            setAiSuggestions([])
                                        }}
                                        title={s.description}
                                    >
                                        {s.description.length > 50 ? s.description.slice(0, 47) + '...' : s.description}
                                    </button>
                                ))}
                                <button
                                    type="button"
                                    onClick={() => setAiSuggestions([])}
                                    className="ml-1 rounded-md p-1 text-gray-500 transition-colors hover:bg-gray-800 hover:text-gray-300"
                                    title="Dismiss suggestions"
                                >
                                    <XMarkIcon className="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>
                    )}
                </main>

                {/* Properties — width is user-resizable (stored in localStorage) */}
                <div
                    className={`dark relative flex shrink-0 flex-col ${uiMode === 'preview' ? 'hidden' : ''}`}
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
                    <aside className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden border-l border-gray-700 bg-gray-900 text-gray-200">
                    <div className="flex items-center justify-between border-b border-gray-700 px-3 py-2">
                        <h2 className="text-xs font-semibold uppercase tracking-wide text-gray-400">Properties</h2>
                        <div className="flex items-center rounded-md bg-gray-800 p-0.5">
                            <button
                                type="button"
                                onClick={() => setPropertiesMode('basic')}
                                className={`rounded px-2 py-0.5 text-[10px] font-semibold transition-colors ${propertiesMode === 'basic' ? 'bg-gray-600 text-white shadow-sm' : 'text-gray-400 hover:text-gray-200'}`}
                            >
                                Basic
                            </button>
                            <button
                                type="button"
                                onClick={() => setPropertiesMode('advanced')}
                                className={`rounded px-2 py-0.5 text-[10px] font-semibold transition-colors ${propertiesMode === 'advanced' ? 'bg-gray-600 text-white shadow-sm' : 'text-gray-400 hover:text-gray-200'}`}
                            >
                                Advanced
                            </button>
                        </div>
                        {brandFontsLoading && (
                            <p
                                className="mt-1.5 flex items-center gap-1.5 text-[10px] text-violet-400"
                                aria-live="polite"
                            >
                                <ArrowPathIcon className="h-3.5 w-3.5 shrink-0 animate-spin" aria-hidden />
                                Loading brand fonts…
                            </p>
                        )}
                    </div>
                    <div className="flex-1 overflow-y-auto p-3 text-xs">
                        {/*
                          * ── CANVAS (document-level) section ──────────────────────────
                          * Global settings that apply to the whole composition, not the
                          * currently selected layer. Collapsible accordion so it
                          * doesn't compete with the layer editing workflow, but sticky
                          * across page loads. Includes: canvas size + presets, snap
                          * on/off, and grid density. Previously these were spread
                          * between an advanced-only Document Size block at the top
                          * and a nested card inside "Placement & Snap" (which read
                          * as layer-specific). Grouping them under one clearly-labeled
                          * CANVAS section makes the layer/global split unambiguous.
                          */}
                        <div className="mb-3">
                            <button
                                type="button"
                                onClick={() => setCanvasSectionOpen((v) => !v)}
                                className="flex w-full items-center justify-between gap-2 rounded-md bg-gray-800/60 px-2.5 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-gray-300 hover:bg-gray-800"
                                aria-expanded={canvasSectionOpen}
                                aria-controls="jp-canvas-section"
                            >
                                <span className="flex items-center gap-1.5">
                                    <DocumentIcon className="h-3.5 w-3.5" aria-hidden />
                                    Canvas
                                    <span className="ml-1 rounded bg-gray-900/70 px-1.5 py-px text-[9px] font-normal normal-case tracking-normal text-gray-500">
                                        {document.width}×{document.height}
                                    </span>
                                </span>
                                {canvasSectionOpen
                                    ? <ChevronDownIcon className="h-3.5 w-3.5 text-gray-500" aria-hidden />
                                    : <ChevronRightIcon className="h-3.5 w-3.5 text-gray-500" aria-hidden />}
                            </button>
                            {canvasSectionOpen && (
                                <div id="jp-canvas-section" className="mt-2 space-y-3 rounded-md bg-gray-900/40 p-2.5">
                                    {/* Canvas size */}
                                    <div className="space-y-2">
                                        <p className="text-[10px] font-semibold uppercase tracking-wide text-gray-500">Size</p>
                                        <div className="grid grid-cols-2 gap-2">
                                            <div>
                                                <label className="mb-1 block text-gray-400">Width (px)</label>
                                                <input
                                                    type="number"
                                                    min={DOCUMENT_DIMENSION_MIN}
                                                    max={DOCUMENT_DIMENSION_MAX}
                                                    value={document.width}
                                                    onChange={(e) => {
                                                        const v = Number(e.target.value)
                                                        if (Number.isNaN(v)) return
                                                        setDocumentDimensions(v, document.height)
                                                    }}
                                                    className="w-full rounded border border-gray-800 bg-gray-900 px-2 py-1 text-gray-200"
                                                />
                                            </div>
                                            <div>
                                                <label className="mb-1 block text-gray-400">Height (px)</label>
                                                <input
                                                    type="number"
                                                    min={DOCUMENT_DIMENSION_MIN}
                                                    max={DOCUMENT_DIMENSION_MAX}
                                                    value={document.height}
                                                    onChange={(e) => {
                                                        const v = Number(e.target.value)
                                                        if (Number.isNaN(v)) return
                                                        setDocumentDimensions(document.width, v)
                                                    }}
                                                    className="w-full rounded border border-gray-800 bg-gray-900 px-2 py-1 text-gray-200"
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
                                                    className="rounded border border-gray-800 bg-gray-900 px-1.5 py-0.5 text-[10px] font-medium text-gray-300 hover:bg-gray-800"
                                                >
                                                    {p.label}
                                                </button>
                                            ))}
                                        </div>
                                    </div>

                                    {/* Snap + grid — advanced only (Basic locks to 3×3 + snap-on) */}
                                    {propertiesMode === 'advanced' && (
                                        <div className="space-y-2 border-t border-gray-800 pt-2.5">
                                            <p className="text-[10px] font-semibold uppercase tracking-wide text-gray-500">Snap &amp; grid</p>
                                            <label className="flex items-center gap-2 text-[11px] text-gray-300">
                                                <input
                                                    type="checkbox"
                                                    checked={snapEnabled}
                                                    onChange={(e) => setSnapEnabled(e.target.checked)}
                                                    className="rounded border-gray-600 bg-gray-900 text-indigo-500 focus:ring-indigo-500"
                                                />
                                                Snap to grid
                                                <span className="ml-auto text-[10px] text-gray-500">Shift+G</span>
                                            </label>
                                            <div>
                                                <p className="mb-1 text-[10px] text-gray-500">Grid density</p>
                                                <div className="flex gap-1">
                                                    {([3, 6, 12] as const).map((d) => (
                                                        <button
                                                            key={d}
                                                            type="button"
                                                            disabled={!snapEnabled}
                                                            onClick={() => setGridDensity(d)}
                                                            className={`flex-1 rounded border px-2 py-1 text-[11px] font-medium transition-colors ${
                                                                gridDensity === d
                                                                    ? 'border-indigo-500 bg-indigo-900/40 text-white'
                                                                    : 'border-gray-800 bg-gray-900 text-gray-300 hover:border-gray-700'
                                                            } ${!snapEnabled ? 'opacity-50 cursor-not-allowed' : ''}`}
                                                        >
                                                            {d}×{d}
                                                        </button>
                                                    ))}
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                    {propertiesMode === 'basic' && (
                                        <p className="border-t border-gray-800 pt-2.5 text-[10px] leading-snug text-gray-500">
                                            Basic mode snaps layers to a 3×3 grid. Switch to Advanced for finer control.
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>

                        {!selectedLayer && (
                            <p className="text-gray-400">Select a layer to edit properties.</p>
                        )}
                        {selectedLayer && (
                            <div className="space-y-4">
                                {/*
                                  * ── LAYER section header ────────────────────────────
                                  * Visually disambiguates layer-specific controls below
                                  * from the Canvas (global) controls above. Echoes the
                                  * Canvas header's visual language (small pill card,
                                  * icon + uppercase label) so the two sections read
                                  * as peers.
                                  */}
                                <div className="flex items-center justify-between gap-2 rounded-md bg-indigo-950/40 px-2.5 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-indigo-200 ring-1 ring-inset ring-indigo-900/50">
                                    <span className="flex min-w-0 items-center gap-1.5">
                                        <RectangleGroupIcon className="h-3.5 w-3.5 shrink-0" aria-hidden />
                                        Layer
                                        <span className="ml-1 min-w-0 truncate rounded bg-gray-900/70 px-1.5 py-px text-[9px] font-normal normal-case tracking-normal text-gray-400">
                                            {selectedLayer.name ?? selectedLayer.type}
                                        </span>
                                    </span>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={duplicateSelectedLayer}
                                        className="inline-flex items-center gap-1 rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200 hover:bg-gray-700"
                                        title="Duplicate layer"
                                    >
                                        <Square2StackIcon className="h-3.5 w-3.5" aria-hidden />
                                        Duplicate
                                    </button>
                                    <button
                                        type="button"
                                        onClick={deleteSelectedLayer}
                                        className="inline-flex items-center gap-1 rounded border border-red-900 bg-gray-800 px-2 py-1 text-red-400 hover:bg-red-950/40"
                                        title="Delete layer"
                                    >
                                        <TrashIcon className="h-3.5 w-3.5" aria-hidden />
                                        Delete
                                    </button>
                                    <button
                                        type="button"
                                        onClick={bringSelectedToFront}
                                        className="inline-flex items-center gap-1 rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200 hover:bg-gray-700"
                                        title="Bring to front"
                                    >
                                        <ChevronDoubleUpIcon className="h-3.5 w-3.5" aria-hidden />
                                        Front
                                    </button>
                                    <button
                                        type="button"
                                        onClick={sendSelectedToBack}
                                        className="inline-flex items-center gap-1 rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200 hover:bg-gray-700"
                                        title="Send to back"
                                    >
                                        <ChevronDoubleDownIcon className="h-3.5 w-3.5" aria-hidden />
                                        Back
                                    </button>
                                </div>
                                <div>
                                    <label className="mb-1 block font-medium text-gray-300">Name</label>
                                    <input
                                        type="text"
                                        value={selectedLayer.name ?? ''}
                                        onChange={(e) =>
                                            updateLayer(selectedLayer.id, (l) => ({
                                                ...l,
                                                name: e.target.value || undefined,
                                            }))
                                        }
                                        className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
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

                                {/* Visibility / Lock — always visible */}
                                <div className="flex gap-3">
                                    <button
                                        type="button"
                                        className="inline-flex items-center gap-1 text-gray-300"
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
                                        className="inline-flex items-center gap-1 text-gray-300"
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

                                {/*
                                  * Placement (layer-specific). Snap on/off + grid
                                  * density moved up into the Canvas section — they're
                                  * composition-wide, not per-layer. This block now
                                  * only exposes the 3×3 quadrant picker + a status
                                  * hint reflecting the canvas-level snap setting.
                                  * Hidden for full-bleed backgrounds since "which
                                  * quadrant" is meaningless there.
                                  */}
                                {(() => {
                                    const t = selectedLayer.transform
                                    const isFullBleed = t.width >= document.width * 0.999 && t.height >= document.height * 0.999
                                    if (isFullBleed) return null
                                    const currentPlacement = xyToPlacement(t.x, t.y, t.width, t.height, document.width, document.height)
                                    const applyPlacement = (p: Placement) => {
                                        const { x, y } = placementToXY(p, t.width, t.height, document.width, document.height)
                                        updateLayer(selectedLayer.id, (l) => ({
                                            ...l,
                                            transform: { ...l.transform, x, y },
                                        }))
                                    }
                                    return (
                                        <div className="space-y-3">
                                            <h3 className="text-[11px] font-semibold uppercase tracking-wide text-gray-400">Placement</h3>
                                            <div className="flex items-start gap-3">
                                                <PlacementPicker
                                                    value={currentPlacement}
                                                    onChange={applyPlacement}
                                                    size="sm"
                                                    label="Quadrant"
                                                />
                                                <div className="flex-1 pt-1 text-[11px] leading-snug text-gray-500">
                                                    {propertiesMode === 'basic'
                                                        ? 'Snaps to the 3×3 grid. Canvas settings control density.'
                                                        : (snapEnabled
                                                            ? `Snapping to a ${gridDensity}×${gridDensity} grid. Hold Alt to disable while dragging.`
                                                            : 'Free positioning — grid is visual only. Re-enable snap in Canvas to align.')}
                                                </div>
                                            </div>
                                        </div>
                                    )
                                })()}

                                {/* Advanced: Transform, Rotation, Blend */}
                                {propertiesMode === 'advanced' && (
                                <>
                                <div className="grid grid-cols-2 gap-2 border-t border-gray-700 pt-3">
                                    <div>
                                        <label className="mb-1 block text-gray-400">X</label>
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
                                            className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
                                        />
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-gray-400">Y</label>
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
                                            className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
                                        />
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-gray-400">Width</label>
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
                                            className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
                                        />
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-gray-400">Height</label>
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
                                            className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
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
                                        className="inline-flex w-full items-center justify-center gap-1.5 rounded border border-gray-700 bg-gray-800 px-2 py-1.5 font-medium text-gray-100 hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <ViewfinderCircleIcon className="h-3.5 w-3.5 shrink-0" aria-hidden />
                                        Center on canvas
                                    </button>
                                </div>
                                <div>
                                    <label className="mb-1 block text-gray-400">Rotation (°)</label>
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
                                        className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
                                    />
                                </div>
                                <div>
                                    <label className="mb-1 block text-gray-400">
                                        Blend mode
                                    </label>
                                    <p className="mb-1.5 text-[9px] leading-snug text-gray-400">
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
                                        className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-xs text-gray-200"
                                    >
                                        {LAYER_BLEND_MODE_OPTIONS.map((opt) => (
                                            <option key={opt.value} value={opt.value}>
                                                {opt.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                </>
                                )}

                                {isFillLayer(selectedLayer) && selectedLayer.kind === 'text_boost' && (() => {
                                    // Local working values — default to inferred if a field
                                    // somehow wasn't set (older drafts loaded pre-model change).
                                    const tbStyle = selectedLayer.textBoostStyle ?? 'gradient_bottom'
                                    const tbColor = selectedLayer.textBoostColor
                                        ?? (typeof auth?.activeBrand?.primary_color === 'string' ? auth.activeBrand.primary_color : '#000000')
                                    const tbOpacity = typeof selectedLayer.textBoostOpacity === 'number'
                                        ? selectedLayer.textBoostOpacity
                                        : 0.7
                                    const tbSource = selectedLayer.textBoostSource ?? 'auto'
                                    const palette = labeledBrandPalette(brandContext)

                                    // Applies a new text-boost triple and mirrors it into the underlying
                                    // fill fields so the renderer doesn't need a second code path. Any
                                    // call into this flips `source` to 'manual' — brand edits after a
                                    // user deliberately tweaks the scrim shouldn't silently overwrite.
                                    const applyTextBoost = (
                                        next: { style?: typeof tbStyle; color?: string; opacity?: number },
                                    ) => {
                                        updateLayer(selectedLayer.id, (l) => {
                                            if (!isFillLayer(l) || l.kind !== 'text_boost') return l
                                            const style = next.style ?? l.textBoostStyle ?? 'gradient_bottom'
                                            const color = next.color ?? l.textBoostColor ?? tbColor
                                            const opacity = typeof next.opacity === 'number'
                                                ? next.opacity
                                                : (l.textBoostOpacity ?? 0.7)
                                            const derived = textBoostToFillFields(style, color, opacity)
                                            return {
                                                ...l,
                                                textBoostStyle: style,
                                                textBoostColor: color,
                                                textBoostOpacity: opacity,
                                                textBoostSource: 'manual' as const,
                                                fillKind: derived.fillKind,
                                                color: derived.color,
                                                gradientStartColor: derived.gradientStartColor,
                                                gradientEndColor: derived.gradientEndColor,
                                                gradientAngleDeg: derived.gradientAngleDeg,
                                            }
                                        })
                                    }

                                    return (
                                        <div className="space-y-3 border-t border-gray-700 pt-3">
                                            <div className="flex items-center justify-between">
                                                <h3 className="text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                                    Text Boost
                                                </h3>
                                                {tbSource === 'manual' && (
                                                    <button
                                                        type="button"
                                                        className="rounded border border-gray-700 bg-gray-800 px-2 py-0.5 text-[10px] text-gray-300 hover:border-gray-600"
                                                        disabled={selectedLayer.locked}
                                                        onClick={() => {
                                                            const inferred = inferTextBoostStyle(
                                                                { primary_color: typeof auth?.activeBrand?.primary_color === 'string' ? auth.activeBrand.primary_color : undefined },
                                                                { background_is_photo: true },
                                                            )
                                                            updateLayer(selectedLayer.id, (l) => {
                                                                if (!isFillLayer(l) || l.kind !== 'text_boost') return l
                                                                const derived = textBoostToFillFields(
                                                                    inferred.style,
                                                                    inferred.color,
                                                                    inferred.opacity,
                                                                )
                                                                return {
                                                                    ...l,
                                                                    textBoostStyle: inferred.style,
                                                                    textBoostColor: inferred.color,
                                                                    textBoostOpacity: inferred.opacity,
                                                                    textBoostSource: 'auto' as const,
                                                                    fillKind: derived.fillKind,
                                                                    color: derived.color,
                                                                    gradientStartColor: derived.gradientStartColor,
                                                                    gradientEndColor: derived.gradientEndColor,
                                                                    gradientAngleDeg: derived.gradientAngleDeg,
                                                                }
                                                            })
                                                        }}
                                                        title="Re-infer style from brand DNA. Clears your manual override."
                                                    >
                                                        Reset to auto
                                                    </button>
                                                )}
                                            </div>
                                            <div>
                                                <label className="mb-1 block text-gray-400">Preset</label>
                                                <select
                                                    value={tbStyle}
                                                    disabled={selectedLayer.locked}
                                                    onChange={(e) => applyTextBoost({ style: e.target.value as typeof tbStyle })}
                                                    className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
                                                >
                                                    <option value="solid">Solid wash</option>
                                                    <option value="gradient_bottom">Gradient — bottom up</option>
                                                    <option value="gradient_top">Gradient — top down</option>
                                                    <option value="radial">Radial vignette</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label className="mb-1 block text-gray-400">Color</label>
                                                {palette.length > 0 && (
                                                    <div className="mb-2 flex flex-wrap gap-1.5">
                                                        {palette.map((p) => (
                                                            <button
                                                                type="button"
                                                                key={`${p.label}-${p.color}`}
                                                                title={p.label}
                                                                disabled={selectedLayer.locked}
                                                                onClick={() => applyTextBoost({ color: p.color })}
                                                                className={`h-6 w-6 rounded border ${tbColor.toLowerCase() === p.color.toLowerCase() ? 'border-white ring-1 ring-indigo-400' : 'border-gray-600'}`}
                                                                style={{ background: p.color }}
                                                            />
                                                        ))}
                                                    </div>
                                                )}
                                                <div className="flex gap-2">
                                                    <input
                                                        type="color"
                                                        value={/^#[0-9a-fA-F]{6}$/.test(tbColor) ? tbColor : '#000000'}
                                                        disabled={selectedLayer.locked}
                                                        onChange={(e) => applyTextBoost({ color: e.target.value })}
                                                        className="h-9 w-12 cursor-pointer rounded border border-gray-700 bg-gray-800"
                                                    />
                                                    <input
                                                        type="text"
                                                        value={tbColor}
                                                        disabled={selectedLayer.locked}
                                                        onChange={(e) => applyTextBoost({ color: e.target.value })}
                                                        className="min-w-0 flex-1 rounded border border-gray-700 bg-gray-800 px-2 py-1 font-mono text-[11px] text-gray-200"
                                                        placeholder="#RRGGBB"
                                                    />
                                                </div>
                                            </div>
                                            <div>
                                                <label className="mb-1 block text-gray-400">
                                                    Opacity ({Math.round(tbOpacity * 100)}%)
                                                </label>
                                                <input
                                                    type="range"
                                                    min={0}
                                                    max={100}
                                                    value={Math.round(tbOpacity * 100)}
                                                    disabled={selectedLayer.locked}
                                                    onChange={(e) => applyTextBoost({ opacity: Number(e.target.value) / 100 })}
                                                    className="w-full accent-indigo-600"
                                                />
                                            </div>
                                            <p className="text-[9px] text-gray-500">
                                                {tbSource === 'auto'
                                                    ? 'Auto — recomputes if the brand primary changes.'
                                                    : 'Manual — locked to your choice. Use Reset to re-infer.'}
                                            </p>
                                        </div>
                                    )
                                })()}

                                {isMaskLayer(selectedLayer) && (
                                    <div className="space-y-3 border-t border-gray-700 pt-3">
                                        <div className="flex items-center justify-between">
                                            <h3 className="text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                                Mask
                                            </h3>
                                            <span className="rounded bg-amber-500/10 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-amber-300">Clips layers below</span>
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-gray-400">Shape</label>
                                            <select
                                                value={selectedLayer.shape}
                                                disabled={selectedLayer.locked}
                                                onChange={(e) => {
                                                    const v = e.target.value as 'rect' | 'ellipse' | 'rounded_rect' | 'gradient_linear' | 'gradient_radial'
                                                    updateLayer(selectedLayer.id, (l) => isMaskLayer(l) ? { ...l, shape: v } : l)
                                                }}
                                                className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
                                            >
                                                <option value="rect">Rectangle</option>
                                                <option value="rounded_rect">Rounded rectangle</option>
                                                <option value="ellipse">Ellipse</option>
                                                <option value="gradient_linear">Gradient (linear)</option>
                                                <option value="gradient_radial">Gradient (radial)</option>
                                            </select>
                                        </div>
                                        {selectedLayer.shape === 'rounded_rect' && (
                                            <div>
                                                <label className="mb-1 block text-gray-400">
                                                    Corner radius ({Math.round(selectedLayer.radius ?? 12)}px)
                                                </label>
                                                <input
                                                    type="range"
                                                    min={0}
                                                    max={128}
                                                    value={Math.round(selectedLayer.radius ?? 12)}
                                                    disabled={selectedLayer.locked}
                                                    onChange={(e) => updateLayer(selectedLayer.id, (l) => isMaskLayer(l) ? { ...l, radius: Number(e.target.value) } : l)}
                                                    className="w-full accent-indigo-600"
                                                />
                                            </div>
                                        )}
                                        <div>
                                            <label className="mb-1 block text-gray-400">
                                                Feather ({Math.round(selectedLayer.featherPx ?? 0)}px)
                                            </label>
                                            <input
                                                type="range"
                                                min={0}
                                                max={128}
                                                value={Math.round(selectedLayer.featherPx ?? 0)}
                                                disabled={selectedLayer.locked}
                                                onChange={(e) => updateLayer(selectedLayer.id, (l) => isMaskLayer(l) ? { ...l, featherPx: Number(e.target.value) } : l)}
                                                className="w-full accent-indigo-600"
                                            />
                                        </div>
                                        <div className="flex items-center justify-between">
                                            <label className="text-gray-400">Invert</label>
                                            <input
                                                type="checkbox"
                                                checked={!!selectedLayer.invert}
                                                disabled={selectedLayer.locked}
                                                onChange={(e) => updateLayer(selectedLayer.id, (l) => isMaskLayer(l) ? { ...l, invert: e.target.checked } : l)}
                                            />
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-gray-400">Target</label>
                                            <select
                                                value={selectedLayer.target}
                                                disabled={selectedLayer.locked}
                                                onChange={(e) => {
                                                    const v = e.target.value as 'below_one' | 'below_all' | 'group'
                                                    updateLayer(selectedLayer.id, (l) => isMaskLayer(l) ? { ...l, target: v } : l)
                                                }}
                                                className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
                                            >
                                                <option value="below_one">Layer directly beneath</option>
                                                <option value="below_all">All layers beneath</option>
                                                <option value="group" disabled={!selectedLayer.groupId}>Group members{selectedLayer.groupId ? '' : ' (set on a group first)'}</option>
                                            </select>
                                        </div>
                                        {(selectedLayer.shape === 'gradient_linear' || selectedLayer.shape === 'gradient_radial') && (
                                            <>
                                                {selectedLayer.shape === 'gradient_linear' && (
                                                    <div>
                                                        <label className="mb-1 block text-gray-400">
                                                            Angle ({Math.round(selectedLayer.gradientAngle ?? 0)}°)
                                                        </label>
                                                        <input
                                                            type="range"
                                                            min={0}
                                                            max={360}
                                                            value={Math.round(selectedLayer.gradientAngle ?? 0)}
                                                            disabled={selectedLayer.locked}
                                                            onChange={(e) => updateLayer(selectedLayer.id, (l) => isMaskLayer(l) ? { ...l, gradientAngle: Number(e.target.value) } : l)}
                                                            className="w-full accent-indigo-600"
                                                        />
                                                    </div>
                                                )}
                                                <div className="rounded border border-gray-800 bg-gray-900/60 p-2">
                                                    <p className="mb-1 text-[10px] text-gray-400">Alpha stops</p>
                                                    {(selectedLayer.gradientStops ?? [
                                                        { offset: 0, alpha: 1 },
                                                        { offset: 1, alpha: 0 },
                                                    ]).map((stop, idx) => (
                                                        <div key={idx} className="mb-1 flex items-center gap-2">
                                                            <span className="w-8 shrink-0 text-[10px] text-gray-500">{Math.round(stop.offset * 100)}%</span>
                                                            <input
                                                                type="range"
                                                                min={0}
                                                                max={100}
                                                                value={Math.round(stop.alpha * 100)}
                                                                disabled={selectedLayer.locked}
                                                                onChange={(e) => {
                                                                    const next = Number(e.target.value) / 100
                                                                    updateLayer(selectedLayer.id, (l) => {
                                                                        if (!isMaskLayer(l)) return l
                                                                        const stops = (l.gradientStops ?? [
                                                                            { offset: 0, alpha: 1 },
                                                                            { offset: 1, alpha: 0 },
                                                                        ]).slice()
                                                                        stops[idx] = { ...stops[idx], alpha: next }
                                                                        return { ...l, gradientStops: stops }
                                                                    })
                                                                }}
                                                                className="w-full accent-indigo-600"
                                                            />
                                                            <span className="w-10 shrink-0 text-right text-[10px] text-gray-400">{Math.round(stop.alpha * 100)}%</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </>
                                        )}
                                        <p className="text-[9px] text-gray-500">
                                            Masks aren&apos;t rendered themselves — they clip whatever they target. Move / resize the dashed rectangle on the canvas to reshape the mask.
                                        </p>
                                    </div>
                                )}

                                {isFillLayer(selectedLayer) && (
                                    <div className="space-y-3 border-t border-gray-700 pt-3">
                                        <h3 className="text-[11px] font-semibold uppercase tracking-wide text-gray-500">
                                            Fill
                                        </h3>
                                        <div>
                                            <label className="mb-1 block text-gray-400">
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
                                                className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
                                            >
                                                <option value="solid">Solid color</option>
                                                <option value="gradient">Gradient (two colors)</option>
                                            </select>
                                        </div>
                                        {selectedLayer.fillKind === 'solid' && (
                                            <div>
                                                <label className="mb-1 block text-gray-400">
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
                                                        className="h-9 w-12 cursor-pointer rounded border border-gray-700 bg-gray-800"
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
                                                        className="min-w-0 flex-1 rounded border border-gray-700 bg-gray-800 px-2 py-1 font-mono text-[11px] text-gray-200"
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
                                                <p className="text-[9px] text-gray-400">
                                                    New gradients default to transparent → brand color. Use angle to
                                                    place each stop along the line.
                                                </p>
                                                <div>
                                                    <label className="mb-1 block text-gray-400">
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
                                                    <p className="text-[10px] text-gray-400">
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
                                    <div className="space-y-3 border-t border-gray-700 pt-3">
                                        {!aiEnabled && (
                                            <div className="rounded-md border border-amber-800/50 bg-amber-950/30 px-2.5 py-2">
                                                <p className="text-[10px] font-medium text-amber-200">AI features are disabled for this workspace.</p>
                                                <p className="mt-0.5 text-[10px] text-amber-300/70">An administrator has turned off AI. Contact your workspace admin to re-enable.</p>
                                            </div>
                                        )}
                                        {genUsageError && (
                                            <p className="text-[10px] text-amber-300">{genUsageError}</p>
                                        )}
                                        {aiEnabled && genUsage && (
                                            <p className="text-[10px] text-gray-400">
                                                {genUsage.limit < 0
                                                    ? `Plan: ${genUsage.plan_name ?? genUsage.plan} — unlimited`
                                                    : `${genUsage.remaining} / ${genUsage.limit} generations this month (${genUsage.plan_name ?? genUsage.plan})`}
                                            </p>
                                        )}
                                        {genActionError && (
                                            <div className="flex flex-wrap items-start gap-2">
                                                <p className="min-w-0 flex-1 text-[10px] text-red-400">
                                                    {genActionError}
                                                </p>
                                                <button
                                                    type="button"
                                                    className="shrink-0 rounded border border-red-900 bg-gray-900 px-2 py-0.5 text-[10px] font-semibold text-red-300 transition-colors hover:bg-red-950/40"
                                                    onClick={() => void runGenerativeGeneration(selectedLayer.id)}
                                                >
                                                    Retry
                                                </button>
                                            </div>
                                        )}
                                        {suggestionToast && (
                                            <p
                                                className="rounded border border-emerald-800 bg-emerald-950/40 px-2 py-1.5 text-[10px] text-emerald-100"
                                                role="status"
                                            >
                                                {suggestionToast}
                                            </p>
                                        )}
                                        {genUsage && genUsage.limit >= 0 && genUsage.remaining <= 0 && (
                                            <p className="text-[10px] font-medium text-amber-200">
                                                You&apos;ve reached your monthly limit.
                                            </p>
                                        )}
                                        {selectedLayer.status === 'generating' && (
                                            <div
                                                className="flex items-center gap-2 rounded-md border border-violet-600 bg-violet-950/40 px-2.5 py-2 text-[10px] font-medium text-violet-100"
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
                                            <label className="mb-1 block font-medium text-gray-300">
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
                                                className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1.5 text-[11px] leading-snug text-gray-200"
                                            />
                                            <p className="mb-1 mt-1.5 text-[9px] text-gray-400">
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
                                                        className="rounded-full border border-gray-700 bg-gray-800 px-2 py-0.5 text-[9px] text-gray-200 hover:bg-gray-700 disabled:opacity-40"
                                                    >
                                                        {chip}
                                                    </button>
                                                ))}
                                            </div>
                                            {generativePromptPreview && (
                                                <p className="mt-2 rounded border border-gray-700 bg-gray-800/50 px-2 py-1.5 text-[9px] leading-snug text-gray-300">
                                                    <span className="font-medium text-gray-400">
                                                        Current style:{' '}
                                                    </span>
                                                    {generativePromptPreview}
                                                </p>
                                            )}
                                        </div>
                                        <div className="rounded-md border border-gray-700 bg-gray-800/50 p-3">
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="flex flex-wrap items-center gap-1.5 text-[11px] font-medium text-gray-200">
                                                    Brand influence
                                                    {brandContext && selectedLayer.applyBrandDna !== false && (
                                                        <span className="rounded-full bg-emerald-900/50 px-1.5 py-0.5 text-[8px] font-bold uppercase tracking-wide text-emerald-100">
                                                            Brand alignment active
                                                        </span>
                                                    )}
                                                </span>
                                                <label className="flex cursor-pointer items-center gap-2 text-[10px] text-gray-400">
                                                    <span>Apply Brand DNA</span>
                                                    <input
                                                        type="checkbox"
                                                        className="h-3.5 w-3.5 rounded border-gray-600 bg-gray-800 text-violet-500 focus:ring-violet-500"
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
                                                <p className="mt-2 rounded border border-amber-800 bg-amber-950/30 px-2 py-1 text-[9px] leading-snug text-amber-100">
                                                    You are generating without brand alignment.
                                                </p>
                                            )}
                                            {brandContext && (
                                                <p className="mt-1 text-[9px] leading-snug text-gray-400">
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
                                                <p className="mt-1 text-[9px] text-amber-300">
                                                    No brand context — generation uses your prompt only.
                                                </p>
                                            )}
                                        </div>
                                        <div>
                                            <div className="mb-1 flex items-center justify-between">
                                                <label className="block font-medium text-gray-300">
                                                    References
                                                </label>
                                                <span className="text-[9px] text-gray-400">
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
                                                className="mb-2 w-full rounded-md border border-dashed border-violet-500/50 bg-violet-950/30 px-2 py-1.5 text-[11px] font-medium text-violet-100 hover:bg-violet-950/50 disabled:cursor-not-allowed disabled:opacity-50"
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
                                                                className="relative h-12 w-12 overflow-hidden rounded border border-gray-700 bg-gray-700"
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
                                            <div className="rounded-md border border-gray-700 bg-gray-900 p-2">
                                                <p className="text-[11px] font-semibold text-gray-100">
                                                    Estimated brand alignment: {generativeBrandScore.score}%
                                                </p>
                                                <p className="mt-0.5 text-[9px] text-gray-400">
                                                    Heuristic preview — not a measured guarantee.
                                                </p>
                                                {generativeBrandScore.feedback.length > 0 && (
                                                    <ul className="mt-1 list-inside list-disc text-[10px] text-gray-400">
                                                        {generativeBrandScore.feedback.map((f, i) => (
                                                            <li key={i}>{f}</li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </div>
                                        )}
                                        <div>
                                            <p className="mb-1 font-medium text-gray-300">
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
                                                        className="rounded border border-gray-700 bg-gray-800 px-2 py-0.5 text-[10px] text-gray-200 hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-40"
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
                                                        className="rounded border border-gray-700 bg-gray-800 px-2 py-0.5 text-[10px] text-gray-200 hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-40"
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
                                                        className="rounded border border-gray-700 bg-gray-800 px-2 py-0.5 text-[10px] text-gray-200 hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-40"
                                                    >
                                                        Use brand colors
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                        {selectedLayer.variationResults &&
                                            selectedLayer.variationResults.length > 0 && (
                                                <div className="rounded-md border border-violet-800 bg-violet-950/30 p-2">
                                                    <p className="mb-1.5 text-[10px] font-medium text-violet-100">
                                                        Pick a variation ({selectedLayer.variationResults.length}{' '}
                                                        {selectedLayer.variationResults.length === 1 ? 'option' : 'options'})
                                                    </p>
                                                    <div className="grid grid-cols-2 gap-1.5">
                                                        {selectedLayer.variationResults.map((url, idx) => (
                                                            <button
                                                                key={`${url}-${idx}`}
                                                                type="button"
                                                                className={`group/v relative overflow-hidden rounded bg-gray-900 transition-shadow focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-600 ${
                                                                    variationPressedIdx === idx
                                                                        ? 'ring-2 ring-violet-400'
                                                                        : variationHoverIdx === idx
                                                                          ? 'ring-2 ring-violet-500'
                                                                          : 'ring-1 ring-violet-700'
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
                                                        className="mt-2 w-full text-[10px] text-gray-400 underline hover:text-gray-100"
                                                        onClick={() => discardVariationResults(selectedLayer.id)}
                                                    >
                                                        Discard variations
                                                    </button>
                                                </div>
                                            )}
                                        <div>
                                            <label className="mb-1 block font-medium text-gray-300">
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
                                                className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
                                            >
                                                {GENERATIVE_MODEL_OPTIONS.map((opt) => (
                                                    <option key={opt.value} value={opt.value}>
                                                        {opt.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="space-y-1">
                                            <label className="flex cursor-pointer items-center gap-2 text-xs text-gray-400">
                                                <input
                                                    type="checkbox"
                                                    className="rounded border-gray-600 bg-gray-800 text-indigo-500"
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
                                                    className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-xs text-gray-200"
                                                >
                                                    {GENERATIVE_ADVANCED_MODEL_OPTIONS.map((opt) => (
                                                        <option key={opt.value} value={opt.value}>
                                                            {opt.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            )}
                                            {selectedLayer.lastResolvedModelDisplay && (
                                                <p className="text-[10px] text-gray-400">
                                                    Model: {selectedLayer.lastResolvedModelDisplay}
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex flex-col gap-2">
                                            <button
                                                type="button"
                                                disabled={
                                                    !aiEnabled ||
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
                                                {aiEnabled ? 'Generate' : 'AI Disabled'}
                                            </button>
                                            <button
                                                type="button"
                                                disabled={
                                                    !aiEnabled ||
                                                    selectedLayer.locked ||
                                                    selectedLayer.status === 'generating' ||
                                                    selectedLayer.variationPending ||
                                                    genUsage === null ||
                                                    !canGenerateFromUsage(genUsage) ||
                                                    !(selectedLayer.prompt.scene?.trim()) ||
                                                    variationRequestCount(genUsage) < 1
                                                }
                                                onClick={() => void runGenerativeVariations(selectedLayer.id)}
                                                className="inline-flex items-center justify-center gap-1.5 rounded-md border border-violet-500 bg-violet-950/50 px-3 py-2 text-xs font-semibold text-violet-100 shadow-sm transition-colors duration-150 hover:bg-violet-900/40 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                <Squares2X2Icon className="h-4 w-4" aria-hidden />
                                                Generate variations
                                            </button>
                                            {selectedLayer.resultSrc && (
                                                <>
                                                    <button
                                                        type="button"
                                                        disabled={
                                                            !aiEnabled ||
                                                            selectedLayer.locked ||
                                                            selectedLayer.status === 'generating' ||
                                                            selectedLayer.variationPending ||
                                                            genUsage === null ||
                                                            !canGenerateFromUsage(genUsage) ||
                                                            !(selectedLayer.prompt.scene?.trim())
                                                        }
                                                        onClick={() => void runGenerativeGeneration(selectedLayer.id)}
                                                        className="rounded-md border border-gray-700 bg-gray-800 px-3 py-2 text-xs font-medium text-gray-100 hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        Regenerate
                                                    </button>
                                                    <button
                                                        type="button"
                                                        disabled={selectedLayer.locked}
                                                        onClick={convertGenerativeToImageLayer}
                                                        className="rounded-md border border-emerald-500 bg-emerald-950/40 px-3 py-2 text-xs font-semibold text-emerald-100 hover:bg-emerald-900/60 disabled:opacity-50"
                                                    >
                                                        Convert to image layer
                                                    </button>
                                                </>
                                            )}
                                        </div>
                                        {selectedLayer.resultSrc && (
                                            <div>
                                                <label className="mb-1 block font-medium text-gray-300">
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
                                                    className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
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
                                            className="w-full rounded-md border border-gray-700 bg-gray-800 px-3 py-2 text-left text-xs font-medium text-gray-100 shadow-sm hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            Replace image
                                        </button>
                                        <div>
                                        <label className="mb-1 block font-medium text-gray-300">
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
                                            className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
                                        >
                                            <option value="cover">cover</option>
                                            <option value="contain">contain</option>
                                            <option value="fill">fill</option>
                                        </select>
                                        <p className="mt-1 text-[10px] leading-snug text-gray-500">
                                            <span className="font-semibold text-gray-400">contain</span>: reveals the full image when the layer is scaled (best for logos).{' '}
                                            <span className="font-semibold text-gray-400">cover</span>: fills the layer, crops overflow (best for hero photos).
                                        </p>
                                        </div>
                                        {/*
                                          * Snap the layer shape back to the asset's aspect ratio.
                                          * This is the one-click fix for "my logo is cropped in a
                                          * square" — it resizes the layer so no part of the image
                                          * is cut off, keeping it centered in its current slot.
                                          */}
                                        {selectedLayer.naturalWidth &&
                                            selectedLayer.naturalHeight &&
                                            selectedLayer.naturalWidth > 0 &&
                                            selectedLayer.naturalHeight > 0 &&
                                            (() => {
                                                const nw = selectedLayer.naturalWidth
                                                const nh = selectedLayer.naturalHeight
                                                const lw = selectedLayer.transform.width
                                                const lh = selectedLayer.transform.height
                                                const layerRatio = lw / Math.max(1, lh)
                                                const imageRatio = nw / Math.max(1, nh)
                                                // Only offer the action when the layer shape is meaningfully
                                                // off the image's aspect — otherwise the button is a no-op.
                                                const misaligned =
                                                    Math.abs(layerRatio - imageRatio) / imageRatio > 0.02
                                                if (!misaligned) return null
                                                return (
                                                    <button
                                                        type="button"
                                                        disabled={selectedLayer.locked}
                                                        onClick={() =>
                                                            updateLayer(selectedLayer.id, (l) => {
                                                                if (!isImageLayer(l)) return l
                                                                const inw = l.naturalWidth ?? 0
                                                                const inh = l.naturalHeight ?? 0
                                                                if (inw <= 0 || inh <= 0) return l
                                                                const ilw = l.transform.width
                                                                const ilh = l.transform.height
                                                                // Preserve the layer's current pixel area —
                                                                // users expect "fix the shape" not "shrink".
                                                                const area = Math.max(1, ilw * ilh)
                                                                const ir = inw / Math.max(1, inh)
                                                                const newW = Math.round(Math.sqrt(area * ir))
                                                                const newH = Math.max(1, Math.round(newW / ir))
                                                                const cx = l.transform.x + ilw / 2
                                                                const cy = l.transform.y + ilh / 2
                                                                return {
                                                                    ...l,
                                                                    transform: {
                                                                        ...l.transform,
                                                                        x: Math.round(cx - newW / 2),
                                                                        y: Math.round(cy - newH / 2),
                                                                        width: newW,
                                                                        height: newH,
                                                                    },
                                                                }
                                                            })
                                                        }
                                                        className="w-full rounded-md border border-indigo-800 bg-indigo-950/40 px-3 py-2 text-left text-xs font-medium text-indigo-200 shadow-sm hover:bg-indigo-900/40 disabled:cursor-not-allowed disabled:opacity-50"
                                                        title="Resize the layer so it matches the image's natural aspect ratio"
                                                    >
                                                        Fit layer to image shape
                                                    </button>
                                                )
                                            })()}

                                        {selectedLayer.assetId && (
                                            <div className="mt-4">
                                                <div className="text-xs font-semibold text-gray-400 mb-2">
                                                    Versions
                                                </div>
                                                <p className="mb-2 text-[10px] leading-snug text-gray-400">
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
                                                                        ? 'ring-2 ring-indigo-500 ring-offset-2 ring-offset-gray-900'
                                                                        : 'hover:ring-2 hover:ring-indigo-400/60 hover:ring-offset-1 hover:ring-offset-gray-900'
                                                                }`}
                                                            >
                                                                <div className="h-14 w-14 overflow-hidden rounded-md border border-gray-700 bg-gray-800">
                                                                    <img
                                                                        src={v.url}
                                                                        alt=""
                                                                        className="h-full w-full object-cover"
                                                                    />
                                                                </div>
                                                                <span className="max-w-[4.5rem] truncate text-center text-[10px] font-semibold tabular-nums text-gray-300">
                                                                    {label}
                                                                </span>
                                                            </button>
                                                        )
                                                    })}
                                                </div>
                                            </div>
                                        )}

                                        <div className="rounded-lg border border-violet-800 bg-violet-950/30 p-2 text-gray-100">
                                            <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-violet-200">
                                                Modify image (AI)
                                            </p>
                                            <p className="mb-2 text-[10px] leading-snug text-gray-400">
                                                Uses the same monthly AI image budget as Generate. Apply runs on the
                                                current layer. After a successful edit,{' '}
                                                <span className="font-medium text-gray-300">
                                                    Regenerate
                                                </span>{' '}
                                                re-runs your prompt on the latest result. Choose{' '}
                                                <span className="font-medium text-gray-300">
                                                    Nano Banana (2.5)
                                                </span>{' '}
                                                or{' '}
                                                <span className="font-medium text-gray-300">
                                                    Nano Banana Pro (3)
                                                </span>{' '}
                                                if OpenAI cannot decode your file (e.g. AVIF/HEIC); Gemini models
                                                require{' '}
                                                <span className="font-mono text-[10px]">GEMINI_API_KEY</span>.
                                            </p>
                                            <div className="mb-2">
                                                <label className="mb-0.5 block text-[10px] font-medium text-gray-300">
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
                                                    className="w-full rounded border border-violet-700 bg-gray-900 px-2 py-1.5 text-[11px] text-gray-100"
                                                >
                                                    {GENERATIVE_EDIT_MODEL_OPTIONS.map((opt) => (
                                                        <option key={opt.value} value={opt.value}>
                                                            {opt.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            {genUsageError && (
                                                <p className="mb-2 text-[10px] text-amber-200">
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
                                                className="w-full rounded border border-violet-700 bg-gray-900 px-2 py-1.5 text-xs text-gray-100 placeholder:text-gray-400"
                                            />
                                            {imageEditActionError && (
                                                <p className="mt-1 text-[10px] text-red-400">{imageEditActionError}</p>
                                            )}
                                            {selectedLayer.aiEdit?.status === 'error' && !imageEditActionError && (
                                                <p className="mt-1 text-[10px] text-red-400">
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
                                                        className="group inline-flex min-h-[2.25rem] min-w-[8.5rem] flex-1 items-center justify-center rounded-md border-2 border-violet-400 bg-violet-700 px-3 py-1.5 text-xs font-semibold shadow-sm hover:bg-violet-600 disabled:cursor-not-allowed disabled:border-violet-800 disabled:bg-violet-900"
                                                    >
                                                        <span className="text-white group-disabled:text-violet-100">
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
                                                            className="inline-flex min-h-[2.25rem] min-w-[8rem] flex-1 items-center justify-center rounded-md border-2 border-gray-400 bg-neutral-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-neutral-500 disabled:cursor-not-allowed disabled:border-neutral-700 disabled:bg-neutral-800 disabled:text-neutral-300"
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
                                                        className="inline-flex w-full min-h-[2.25rem] items-center justify-center rounded-md border-2 border-gray-400 bg-neutral-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-neutral-500 disabled:cursor-not-allowed disabled:border-neutral-700 disabled:bg-neutral-800 disabled:text-neutral-300 sm:w-auto"
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
                                                    className="w-full rounded border border-violet-700 bg-violet-950/50 px-2 py-1.5 text-left text-[11px] font-medium text-violet-100 hover:bg-violet-900/60"
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
                                            <label className="mb-1 block font-medium text-gray-300">
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
                                                className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
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
                                                <label className="mb-1 block font-medium text-gray-300">
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
                                                    className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
                                                />
                                            </div>
                                            <div>
                                                <label className="mb-1 block font-medium text-gray-300">
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
                                                    className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
                                                >
                                                    {[100, 200, 300, 400, 500, 600, 700, 800, 900].map((w) => (
                                                        <option key={w} value={w}>
                                                            {w}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                        </div>

                                        {propertiesMode === 'advanced' && (
                                        <div className="grid grid-cols-2 gap-2">
                                            <div>
                                                <label className="mb-1 block font-medium text-gray-300">
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
                                                    className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
                                                />
                                            </div>
                                            <div>
                                                <label className="mb-1 block font-medium text-gray-300">
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
                                                    className="w-full rounded border border-gray-700 bg-gray-800 px-2 py-1 text-gray-200"
                                                />
                                            </div>
                                        </div>
                                        )}

                                        <div>
                                            <label className="mb-1 block font-medium text-gray-300">
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
                                                        className="rounded border border-gray-700 px-2 py-1 text-[10px] font-medium text-gray-200 hover:bg-gray-800"
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

                                        <div className="rounded-md border border-violet-900/60 bg-violet-950/20 p-2">
                                            <p className="mb-1.5 text-[10px] font-semibold uppercase tracking-wide text-violet-200">
                                                Copy Assist
                                            </p>
                                            {copyAssistLoadingId === selectedLayer.id && (
                                                <div
                                                    className="mb-2 flex items-center gap-2 rounded border border-violet-900/60 bg-violet-950/50 px-2 py-1.5 text-[10px] font-medium text-violet-100"
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
                                                            !aiEnabled ||
                                                            selectedLayer.locked ||
                                                            copyAssistLoadingId === selectedLayer.id
                                                        }
                                                        onClick={() => void runCopyAssist(op)}
                                                        className="rounded border border-violet-900/60 bg-violet-950/40 px-2 py-1 text-[10px] font-medium text-violet-100 hover:bg-violet-900/50 disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        {label}
                                                    </button>
                                                ))}
                                            </div>
                                            {copyAssistError && (
                                                <div className="mt-2 flex flex-wrap items-start gap-2">
                                                    <p
                                                        className="min-w-0 flex-1 text-[10px] font-medium text-red-400"
                                                        role="alert"
                                                    >
                                                        {copyAssistError}
                                                    </p>
                                                    <button
                                                        type="button"
                                                        className="shrink-0 rounded border border-red-900 bg-gray-900 px-2 py-0.5 text-[10px] font-semibold text-red-300 transition-colors hover:bg-red-950/40"
                                                        onClick={() => void runCopyAssist(copyAssistLastOpRef.current)}
                                                    >
                                                        Retry
                                                    </button>
                                                </div>
                                            )}
                                            {copyAssistScore && (
                                                <div className="mt-2 rounded border border-violet-900/60 bg-violet-950/40 p-1.5">
                                                    <p className="text-[10px] font-semibold text-gray-100">
                                                        Estimated brand voice alignment: {copyAssistScore.score}%
                                                    </p>
                                                    <p className="mt-0.5 text-[9px] text-gray-400">
                                                        Heuristic preview — not a measured guarantee.
                                                    </p>
                                                    {copyAssistScore.feedback.length > 0 && (
                                                        <ul className="mt-0.5 list-inside list-disc text-[9px] text-gray-400">
                                                            {copyAssistScore.feedback.map((f, i) => (
                                                                <li key={i}>{f}</li>
                                                            ))}
                                                        </ul>
                                                    )}
                                                </div>
                                            )}
                                            {copyAssistSuggestions.length > 0 && (
                                                <div className="mt-2">
                                                    <p className="mb-0.5 text-[9px] font-medium text-gray-400">
                                                        Alternates (hover for full text)
                                                    </p>
                                                    <div className="flex flex-col gap-1.5">
                                                        {copyAssistSuggestions.map((sug, idx) => (
                                                            <div
                                                                key={`${idx}-${sug.label}`}
                                                                className={`rounded border border-gray-700 bg-gray-900 p-1.5 ${
                                                                    copyAssistHoverIdx === idx
                                                                        ? 'ring-1 ring-indigo-500'
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
                                                                <p className="text-[9px] font-semibold text-indigo-200">
                                                                    {sug.label}
                                                                </p>
                                                                <p className="mt-0.5 line-clamp-2 text-[10px] leading-snug text-gray-100">
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
                                                                        className="rounded border border-gray-500 bg-gray-800 px-2 py-0.5 text-[9px] font-medium text-gray-100 hover:bg-gray-700 disabled:opacity-50"
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
                                                    className="mt-2 text-[10px] font-medium text-violet-300 underline hover:text-violet-100 disabled:opacity-50"
                                                >
                                                    Revert last change
                                                </button>
                                            )}
                                            <div className="mt-2 border-t border-violet-800 pt-2">
                                                <p className="mb-1 text-[9px] font-medium text-gray-400">
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
                                                            className="rounded border border-gray-700 bg-gray-800 px-2 py-0.5 text-[10px] text-gray-200 hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-40"
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
                                                            className="rounded border border-gray-700 bg-gray-800 px-2 py-0.5 text-[10px] text-gray-200 hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-40"
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
                                                            className="rounded border border-gray-700 bg-gray-800 px-2 py-0.5 text-[10px] text-gray-200 hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-40"
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
                                                    className="rounded border-gray-600 bg-gray-800 text-indigo-500"
                                                />
                                                <label
                                                    htmlFor={`autofit-${selectedLayer.id}`}
                                                    className="font-medium text-gray-300"
                                                    title="Auto-fit adjusts font size to fit the box"
                                                >
                                                    Auto-fit text
                                                </label>
                                            </div>
                                            <p className="mt-1 text-[10px] leading-snug text-gray-400">
                                                Auto-fit adjusts font size to fit the box. Resize the layer to change
                                                the box; font size follows when this is on.
                                            </p>
                                        </div>

                                        <div>
                                            <p className="mb-1 font-medium text-gray-300">Color</p>
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
                                                                                    ? 'border-indigo-400 ring-2 ring-indigo-700'
                                                                                    : 'border-gray-600'
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
                                                                        <span className="max-w-[4.5rem] truncate text-center text-[9px] font-medium text-gray-400">
                                                                            {label}
                                                                        </span>
                                                                    </div>
                                                                )
                                                            })}
                                                        </div>
                                                        {labeled.some(({ color: c }) =>
                                                            colorsMatch(c, selectedLayer.style.color)
                                                        ) && (
                                                            <p className="mt-1 text-[10px] text-indigo-400">
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
                                                                    ? 'border-indigo-400 ring-2 ring-indigo-700'
                                                                    : 'border-gray-600'
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

                                            <div className="my-2 border-t border-gray-700" />

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
                                                className="h-9 w-full cursor-pointer rounded border border-gray-700"
                                            />
                                        </div>

                                        <div>
                                            <label className="mb-1 block font-medium text-gray-300">
                                                Horizontal align
                                            </label>
                                            <div className="flex gap-1">
                                                {(['left', 'center', 'right'] as const).map((al) => (
                                                    <button
                                                        key={al}
                                                        type="button"
                                                        className={`flex-1 rounded border px-1 py-1 text-[10px] font-medium capitalize ${
                                                            (selectedLayer.style.textAlign ?? 'left') === al
                                                                ? 'border-indigo-400 bg-indigo-950/50 text-indigo-100'
                                                                : 'border-gray-600'
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
                                            <label className="mb-1 block font-medium text-gray-300">
                                                Vertical align
                                            </label>
                                            <div className="flex gap-1">
                                                {(['top', 'middle', 'bottom'] as const).map((al) => (
                                                    <button
                                                        key={al}
                                                        type="button"
                                                        className={`flex-1 rounded border px-1 py-1 text-[10px] font-medium capitalize ${
                                                            (selectedLayer.style.verticalAlign ?? 'top') === al
                                                                ? 'border-indigo-400 bg-indigo-950/50 text-indigo-100'
                                                                : 'border-gray-600'
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

            {openCompositionPicker && (() => {
                const filtered = compositionSummaries.filter((c) =>
                    !pickerSearch || (c.name || '').toLowerCase().includes(pickerSearch.toLowerCase())
                )

                const now = Date.now()
                const DAY = 86400000
                const groups: { label: string; items: typeof filtered }[] = []
                const today: typeof filtered = []
                const thisWeek: typeof filtered = []
                const thisMonth: typeof filtered = []
                const older: typeof filtered = []
                for (const c of filtered) {
                    const age = now - new Date(c.updated_at).getTime()
                    if (age < DAY) today.push(c)
                    else if (age < DAY * 7) thisWeek.push(c)
                    else if (age < DAY * 30) thisMonth.push(c)
                    else older.push(c)
                }
                if (today.length) groups.push({ label: 'Today', items: today })
                if (thisWeek.length) groups.push({ label: 'This week', items: thisWeek })
                if (thisMonth.length) groups.push({ label: 'This month', items: thisMonth })
                if (older.length) groups.push({ label: 'Older', items: older })

                const renderCard = (c: (typeof compositionSummaries)[0]) => (
                    <div
                        key={c.id}
                        className="group/card flex flex-col overflow-hidden rounded-xl border border-gray-700 bg-gray-800/60 transition-all hover:border-gray-600 hover:bg-gray-800 hover:shadow-lg"
                    >
                        {/* Thumbnail */}
                        <button type="button" onClick={() => navigateToComposition(c.id)} className="relative block w-full">
                            {c.thumbnail_url ? (
                                <img
                                    key={`open-comp-${c.id}-${c.updated_at}`}
                                    src={`${c.thumbnail_url}${c.thumbnail_url.includes('?') ? '&' : '?'}rid=${encodeURIComponent(c.id)}`}
                                    alt={c.name || 'Composition'}
                                    className="aspect-[4/3] w-full object-cover"
                                />
                            ) : (
                                <div className="flex aspect-[4/3] w-full items-center justify-center bg-gray-800">
                                    <svg className="h-10 w-10 text-gray-600" fill="none" viewBox="0 0 24 24" strokeWidth="1" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" /></svg>
                                </div>
                            )}
                            <div className="absolute inset-0 flex items-center justify-center bg-black/0 opacity-0 transition-all group-hover/card:bg-black/30 group-hover/card:opacity-100">
                                <span className="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-lg">Open</span>
                            </div>
                        </button>
                        {/* Info */}
                        <div className="flex min-w-0 flex-1 items-center gap-2 px-3 py-2.5">
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-xs font-semibold text-gray-100">{c.name || 'Untitled'}</p>
                                <p className="mt-0.5 text-[10px] text-gray-500">
                                    {c.updated_at ? new Date(c.updated_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }) : ''}
                                </p>
                            </div>
                            <button
                                type="button"
                                disabled={compositionDeleteBusy}
                                className="shrink-0 rounded-md p-1 text-gray-600 opacity-0 transition-all hover:bg-red-950/40 hover:text-red-400 group-hover/card:opacity-100 disabled:cursor-not-allowed disabled:opacity-30"
                                title="Delete"
                                aria-label={`Delete ${c.name || 'composition'}`}
                                onClick={(e) => { e.stopPropagation(); void deleteCompositionById(c.id) }}
                            >
                                <TrashIcon className="h-3.5 w-3.5" aria-hidden />
                            </button>
                        </div>
                    </div>
                )

                const renderListRow = (c: (typeof compositionSummaries)[0]) => (
                    <button
                        key={c.id}
                        type="button"
                        onClick={() => navigateToComposition(c.id)}
                        className="group/row flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left transition-colors hover:bg-gray-800"
                    >
                        {c.thumbnail_url ? (
                            <img
                                src={`${c.thumbnail_url}${c.thumbnail_url.includes('?') ? '&' : '?'}rid=${encodeURIComponent(c.id)}`}
                                alt=""
                                className="h-10 w-10 shrink-0 rounded-lg object-cover ring-1 ring-gray-700"
                            />
                        ) : (
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-800 ring-1 ring-gray-700">
                                <svg className="h-4 w-4 text-gray-600" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0 0 22.5 18.75V5.25A2.25 2.25 0 0 0 20.25 3H3.75A2.25 2.25 0 0 0 1.5 5.25v13.5A2.25 2.25 0 0 0 3.75 21Z" /></svg>
                            </div>
                        )}
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-xs font-semibold text-gray-100">{c.name || 'Untitled'}</p>
                            <p className="mt-0.5 text-[10px] text-gray-500">{c.updated_at ? new Date(c.updated_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : ''}</p>
                        </div>
                        <span className="shrink-0 rounded-md bg-gray-800 px-2 py-1 text-[10px] font-medium text-gray-400 opacity-0 transition-opacity group-hover/row:opacity-100">Open</span>
                        <button
                            type="button"
                            disabled={compositionDeleteBusy}
                            className="shrink-0 rounded-md p-1 text-gray-600 opacity-0 transition-all hover:bg-red-950/40 hover:text-red-400 group-hover/row:opacity-100 disabled:opacity-30"
                            title="Delete"
                            onClick={(e) => { e.stopPropagation(); e.preventDefault(); void deleteCompositionById(c.id) }}
                        >
                            <TrashIcon className="h-3.5 w-3.5" aria-hidden />
                        </button>
                    </button>
                )

                return (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="open-composition-dialog-title"
                >
                    <div className="flex w-full flex-col overflow-hidden rounded-xl border border-gray-700 bg-gray-900 shadow-2xl ring-1 ring-white/5" style={{ maxWidth: 820, maxHeight: 'min(88vh, 720px)' }}>
                        {/* Header */}
                        <div className="flex shrink-0 items-center justify-between border-b border-gray-700 px-5 py-4">
                            <div className="flex items-center gap-3">
                                <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-gray-800">
                                    <FolderOpenIcon className="h-4.5 w-4.5 text-indigo-400" />
                                </div>
                                <div>
                                    <h3 id="open-composition-dialog-title" className="text-sm font-semibold text-white">Your Compositions</h3>
                                    <p className="text-[11px] text-gray-500">{compositionSummaries.length} saved for this brand</p>
                                </div>
                            </div>
                            <button type="button" className="rounded-md p-1.5 text-gray-400 hover:bg-gray-800 hover:text-white transition-colors" onClick={() => setOpenCompositionPicker(false)} aria-label="Close">
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>

                        {/* Toolbar: search + view toggle */}
                        {compositionSummaries.length > 0 && (
                            <div className="flex shrink-0 items-center gap-3 border-b border-gray-800 px-5 py-3">
                                <div className="relative flex-1">
                                    <svg className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                                    <input
                                        type="text"
                                        value={pickerSearch}
                                        onChange={(e) => setPickerSearch(e.target.value)}
                                        placeholder="Search compositions…"
                                        className="w-full rounded-lg border border-gray-700 bg-gray-800 py-1.5 pl-9 pr-3 text-xs text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                    />
                                </div>
                                <div className="flex shrink-0 items-center rounded-lg border border-gray-700 bg-gray-800 p-0.5">
                                    <button type="button" onClick={() => setPickerView('grid')} className={`rounded-md p-1.5 transition-colors ${pickerView === 'grid' ? 'bg-gray-700 text-white' : 'text-gray-500 hover:text-gray-300'}`} title="Grid view">
                                        <Squares2X2Icon className="h-3.5 w-3.5" />
                                    </button>
                                    <button type="button" onClick={() => setPickerView('list')} className={`rounded-md p-1.5 transition-colors ${pickerView === 'list' ? 'bg-gray-700 text-white' : 'text-gray-500 hover:text-gray-300'}`} title="List view">
                                        <Bars3Icon className="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </div>
                        )}

                        {/* Body */}
                        <div className="min-h-0 flex-1 overflow-y-auto" style={{ scrollbarWidth: 'thin', scrollbarColor: '#374151 transparent' }}>
                            {/* Loading */}
                            {compositionListLoading && (
                                <div className="flex flex-col items-center justify-center py-16 text-center">
                                    <ArrowPathIcon className="h-8 w-8 animate-spin text-indigo-500" />
                                    <p className="mt-3 text-sm text-gray-400">Loading your compositions…</p>
                                </div>
                            )}

                            {/* Error */}
                            {compositionListError && (
                                <div className="flex flex-col items-center justify-center py-16 text-center px-6">
                                    <ExclamationTriangleIcon className="h-8 w-8 text-red-400" />
                                    <p className="mt-3 text-sm text-red-400">{compositionListError}</p>
                                </div>
                            )}

                            {/* Empty state */}
                            {!compositionListLoading && !compositionListError && compositionSummaries.length === 0 && (
                                <div className="flex flex-col items-center justify-center px-6 py-20 text-center">
                                    <div className="mb-5 flex items-center gap-3">
                                        <img src="/jp-parts/cherry-slot.svg" alt="" className="h-7 w-7 opacity-40" />
                                        <div className="flex h-16 w-16 items-center justify-center rounded-2xl bg-gray-800 ring-1 ring-gray-700">
                                            <FolderOpenIcon className="h-8 w-8 text-gray-600" />
                                        </div>
                                        <img src="/jp-parts/diamond-slot.svg" alt="" className="h-7 w-7 opacity-40" />
                                    </div>
                                    <h4 className="text-base font-semibold text-white">No compositions yet</h4>
                                    <p className="mt-2 max-w-xs text-sm leading-relaxed text-gray-500">
                                        Start from a template or build one from scratch. Once you save a composition it'll appear here — ready to pick up right where you left off.
                                    </p>
                                    <div className="mt-6 flex items-center gap-3">
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setOpenCompositionPicker(false)
                                                setWizardStep(1)
                                                setWizardCategory('all')
                                                setWizardPlatform(null)
                                                setWizardFormat(null)
                                                setWizardLayoutStyle(null)
                                                setWizardName('')
                                                setTemplateWizardOpen(true)
                                            }}
                                            className="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-indigo-500"
                                        >
                                            Browse Templates
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => { setOpenCompositionPicker(false); startNewComposition() }}
                                            className="rounded-lg border border-gray-700 bg-gray-800 px-4 py-2 text-xs font-semibold text-gray-300 transition-colors hover:bg-gray-700 hover:text-white"
                                        >
                                            Blank Canvas
                                        </button>
                                    </div>
                                </div>
                            )}

                            {/* No search results */}
                            {!compositionListLoading && !compositionListError && compositionSummaries.length > 0 && filtered.length === 0 && (
                                <div className="flex flex-col items-center justify-center py-16 text-center">
                                    <svg className="h-8 w-8 text-gray-600" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                                    <p className="mt-3 text-sm text-gray-400">No compositions match "<span className="text-gray-300">{pickerSearch}</span>"</p>
                                </div>
                            )}

                            {/* Composition grid/list grouped by time */}
                            {!compositionListLoading && !compositionListError && groups.map((group) => (
                                <div key={group.label} className="px-5 pb-4 pt-3 first:pt-4">
                                    <h4 className="mb-3 text-[10px] font-bold uppercase tracking-wider text-gray-500">{group.label}</h4>
                                    {pickerView === 'grid' ? (
                                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                            {group.items.map(renderCard)}
                                        </div>
                                    ) : (
                                        <div className="space-y-0.5">
                                            {group.items.map(renderListRow)}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
                )
            })()}

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
                                className="text-sm font-semibold text-gray-100"
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
                                    className="flex items-center gap-2 py-6 text-gray-300"
                                    role="status"
                                    aria-busy="true"
                                >
                                    <ArrowPathIcon className="h-5 w-5 shrink-0 animate-spin text-indigo-400" />
                                    Loading versions…
                                </div>
                            )}
                            {!compositionId && (
                                <p className="text-gray-400">
                                    Save the composition first to track versions.
                                </p>
                            )}
                            {compositionId &&
                                !versionsLoading &&
                                versions.length === 0 &&
                                !compositionLoadError && (
                                    <p className="text-gray-400">No versions yet.</p>
                                )}
                            {compositionLoadError && (
                                <p className="text-red-400">{compositionLoadError}</p>
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
                                                <div className="font-medium text-gray-100">
                                                    {new Date(v.created_at).toLocaleString()}
                                                </div>
                                                {v.label && (
                                                    <div className="text-gray-400">{v.label}</div>
                                                )}
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 gap-1">
                                            <button
                                                type="button"
                                                className="rounded border border-gray-300 bg-white px-2 py-1 text-gray-800 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                                                onClick={() => void loadVersionIntoEditor(v.id)}
                                            >
                                                Load
                                            </button>
                                            <button
                                                type="button"
                                                className="rounded border border-gray-300 bg-white px-2 py-1 text-gray-800 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
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
                                className="text-sm font-semibold text-gray-100"
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
                            <p className="text-gray-400">
                                Image preview only (no layer diff). Drag the slider to compare A vs B.
                            </p>
                            <div className="flex flex-wrap items-end gap-3">
                                <div>
                                    <label className="mb-1 block text-[10px] font-semibold uppercase tracking-wide text-gray-500">Version A (left)</label>
                                    <select
                                        value={compareLeftId ?? ''}
                                        onChange={(e) => setCompareLeftId(e.target.value || null)}
                                        className="max-w-[220px] rounded border border-gray-300 bg-white px-2 py-1.5 text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
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
                                        className="max-w-[220px] rounded border border-gray-300 bg-white px-2 py-1.5 text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
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
                                                <span className="text-indigo-200">
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
                                    <div className="relative overflow-hidden rounded-xl border border-gray-200 bg-neutral-100 shadow-inner dark:border-gray-700 dark:bg-neutral-900">
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
                                    <label className="flex items-center gap-3 text-[11px] text-gray-400">
                                        <span className="shrink-0 font-medium text-gray-200">A</span>
                                        <input
                                            type="range"
                                            min={0}
                                            max={100}
                                            value={compareSlider}
                                            onChange={(e) => setCompareSlider(Number(e.target.value))}
                                            className="h-2 w-full cursor-ew-resize accent-indigo-600"
                                        />
                                        <span className="shrink-0 font-medium text-gray-200">B</span>
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
                            <h3 className="text-sm font-semibold text-gray-100">Publish</h3>
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
                            <p className="text-xs text-gray-400">
                                Publishes a JPEG export of the canvas. The file is compressed so publish works on servers
                                with a strict upload size cap (about 1MB for the whole request). Choose a library or
                                deliverable category; category-specific metadata uses the same schema as uploads.
                            </p>
                            {publishCategoriesLoading && (
                                <p
                                    className="flex items-center gap-2 text-xs text-gray-300"
                                    role="status"
                                    aria-busy="true"
                                >
                                    <ArrowPathIcon className="h-4 w-4 shrink-0 animate-spin text-indigo-400" />
                                    Loading categories…
                                </p>
                            )}
                            {publishCategoriesError && (
                                <p className="text-xs text-red-400">{publishCategoriesError}</p>
                            )}
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-gray-300">
                                    Title
                                </span>
                                <input
                                    type="text"
                                    value={publishTitle}
                                    onChange={(e) => setPublishTitle(e.target.value)}
                                    className="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                                    disabled={promoteSaving}
                                />
                            </label>
                            <label className="block">
                                <span className="mb-1 block text-xs font-medium text-gray-300">
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
                                    className="w-full rounded border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
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
                                <span className="mb-1 block text-xs font-medium text-gray-300">
                                    Description <span className="font-normal text-gray-500">(optional)</span>
                                </span>
                                <textarea
                                    value={publishDescription}
                                    onChange={(e) => setPublishDescription(e.target.value)}
                                    rows={3}
                                    className="w-full resize-y rounded border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-900 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100"
                                    disabled={promoteSaving}
                                    placeholder="Shown in asset metadata as editor publish notes."
                                />
                            </label>
                            <div className="border-t border-gray-700 pt-3">
                                <p className="mb-2 text-xs font-medium text-gray-300">
                                    Metadata <span className="font-normal text-gray-500">(from category)</span>
                                </p>
                                {publishMetadataLoading && (
                                    <p
                                        className="flex items-center gap-2 text-xs text-gray-300"
                                        role="status"
                                        aria-busy="true"
                                    >
                                        <ArrowPathIcon className="h-4 w-4 shrink-0 animate-spin text-indigo-400" />
                                        Loading metadata fields…
                                    </p>
                                )}
                                {publishMetadataError && (
                                    <p className="text-xs text-red-400">{publishMetadataError}</p>
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
                                className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200"
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
                    {/*
                      * The picker only ever opens inside the Studio editor, which is
                      * permanently dark-themed. Using explicit dark palette classes
                      * (rather than `dark:` variants over a `bg-white` base) makes the
                      * modal render correctly even if some unusual wrapper or portal
                      * breaks the `.dark` ancestor chain — previously the title came
                      * through as near-white text on a near-white backdrop.
                      */}
                    <div className="flex max-h-[85vh] w-full max-w-3xl min-h-0 flex-col overflow-hidden rounded-lg border border-gray-700 bg-gray-900 shadow-xl">
                        <div className="flex shrink-0 items-center justify-between border-b border-gray-700 px-4 py-3">
                            <h3 className="text-sm font-semibold text-gray-100">
                                {pickerMode === 'references'
                                    ? `Reference images (max ${MAX_REFERENCE_ASSETS})`
                                    : pickerMode === 'replace'
                                      ? 'Replace image'
                                      : 'Add image from library'}
                            </h3>
                            <button
                                type="button"
                                className="rounded p-1 text-gray-400 hover:bg-gray-800 hover:text-gray-200 disabled:cursor-not-allowed disabled:opacity-40"
                                onClick={closeAssetPicker}
                                disabled={pickerPickingAssetId !== null}
                                aria-label="Close"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>
                        <div className="shrink-0 border-b border-gray-700 px-4 py-2">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-[10px] font-medium uppercase tracking-wide text-gray-400">
                                    Source
                                </span>
                                <div className="inline-flex rounded-md border border-gray-700 p-0.5">
                                    <button
                                        type="button"
                                        className={`rounded px-2.5 py-1 text-[11px] font-medium ${
                                            pickerScope === 'library'
                                                ? 'bg-indigo-600 text-white'
                                                : 'text-gray-300 hover:bg-gray-800'
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
                                                : 'text-gray-300 hover:bg-gray-800'
                                        }`}
                                        onClick={() => {
                                            setPickerScope('executions')
                                            setPickerCategoryFilterId('')
                                        }}
                                    >
                                        Executions
                                    </button>
                                </div>
                                <label className="ml-auto flex items-center gap-1.5 text-[10px] text-gray-400">
                                    <span className="whitespace-nowrap">Category</span>
                                    <select
                                        className="max-w-[200px] rounded border border-gray-700 bg-gray-800 py-1 pl-1.5 pr-6 text-[11px] text-gray-100"
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
                            <p className="mt-2 text-[10px] text-gray-400">
                                <a
                                    href={pickerScope === 'executions' ? '/app/executions' : '/app/assets'}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="font-medium text-indigo-300 underline decoration-indigo-400/60 underline-offset-2 hover:text-indigo-200"
                                >
                                    Open {pickerScope === 'executions' ? 'Executions' : 'Assets'}
                                </a>{' '}
                                in a new tab to upload or manage files, then return here and refresh the list.
                            </p>
                        </div>
                        {pickerMode === 'references' && referenceSelectionIds.length > 0 && (
                            <div className="shrink-0 border-b border-violet-800 bg-violet-950/25 px-4 py-2.5">
                                <p className="mb-1.5 text-[10px] font-medium text-violet-200">
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
                                                className="flex max-w-[200px] items-center gap-1.5 rounded-md border border-violet-700 bg-gray-900 pr-1 shadow-sm"
                                            >
                                                <div className="h-11 w-11 shrink-0 overflow-hidden rounded-l bg-gray-800">
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
                                                <span className="min-w-0 flex-1 truncate text-[10px] text-gray-200">
                                                    {refAsset?.name ?? 'Asset'}
                                                </span>
                                                <button
                                                    type="button"
                                                    className="shrink-0 rounded p-0.5 text-gray-400 hover:bg-gray-800 hover:text-gray-100"
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
                                <p className="mb-3 text-[10px] text-gray-400">
                                    Tap assets to add them to your selection (you can pick more than one). Choose{' '}
                                    <strong className="font-semibold text-gray-300">
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
                                    <ArrowPathIcon className="h-8 w-8 animate-spin text-indigo-400" />
                                    <span className="text-sm font-medium text-gray-300">
                                        Loading library…
                                    </span>
                                    <div className="grid w-full max-w-md grid-cols-4 gap-2 opacity-60">
                                        {Array.from({ length: 4 }).map((_, i) => (
                                            <div
                                                key={i}
                                                className="aspect-square animate-pulse rounded bg-gray-700"
                                            />
                                        ))}
                                    </div>
                                </div>
                            )}
                            {!damLoading && damError && (
                                <p className="text-center text-sm text-red-400">{damError}</p>
                            )}
                            {!damLoading && !damError && damAssets.length === 0 && (
                                <p className="text-center text-sm text-gray-500">No assets available.</p>
                            )}
                            {!damLoading && !damError && damAssets.length > 0 && (
                                <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                                    {damAssets.map((a) => {
                                        const isPicking = pickerPickingAssetId === a.id
                                        const otherPicking = pickerPickingAssetId !== null && !isPicking
                                        return (
                                        <button
                                            key={a.id}
                                            type="button"
                                            disabled={pickerPickingAssetId !== null && pickerMode !== 'references'}
                                            aria-busy={isPicking || undefined}
                                            className={`group relative flex flex-col overflow-hidden rounded border bg-gray-800 text-left transition hover:border-indigo-400 hover:ring-1 hover:ring-indigo-400 ${
                                                isPicking
                                                    ? 'border-indigo-400 ring-2 ring-indigo-400 ring-offset-1'
                                                    : pickerMode === 'references' &&
                                                      referenceSelectionIds.includes(a.id)
                                                      ? 'border-violet-400 ring-2 ring-violet-400 ring-offset-1'
                                                      : pickerMode === 'replace' &&
                                                          pickerHighlightAssetId === a.id
                                                        ? 'border-indigo-400 ring-2 ring-indigo-400 ring-offset-1'
                                                        : 'border-gray-700'
                                            } ${otherPicking ? 'pointer-events-none cursor-not-allowed opacity-40' : ''} ${isPicking ? 'cursor-wait' : ''}`}
                                            onClick={() => {
                                                if (pickerMode === 'references') {
                                                    toggleReferenceAssetInPicker(a.id)
                                                } else {
                                                    void handlePickDamAsset(a)
                                                }
                                            }}
                                        >
                                            <div className="aspect-square w-full overflow-hidden bg-gray-700">
                                                <img
                                                    src={a.thumbnail_url || a.file_url}
                                                    alt=""
                                                    className="h-full w-full object-cover"
                                                    loading="lazy"
                                                    onError={(e) => {
                                                        // SVG logos and freshly uploaded assets often 404 on /thumbnail before
                                                        // the rasterized WebP lands; swap to the /file endpoint which streams
                                                        // original bytes (SVG renders natively in <img>).
                                                        const img = e.currentTarget
                                                        if (a.file_url && img.src !== a.file_url) {
                                                            img.src = a.file_url
                                                        }
                                                    }}
                                                />
                                            </div>
                                            <span className="truncate px-1 py-1 text-[10px] text-gray-300">
                                                {a.name || 'Untitled'}
                                            </span>
                                            {isPicking && (
                                                <div
                                                    className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-2 bg-gray-900/70 backdrop-blur-[1px]"
                                                    aria-live="polite"
                                                >
                                                    <ArrowPathIcon className="h-6 w-6 animate-spin text-indigo-300" />
                                                    <span className="text-[10px] font-medium text-white">
                                                        {pickerMode === 'replace' ? 'Replacing…' : 'Adding…'}
                                                    </span>
                                                </div>
                                            )}
                                        </button>
                                        )
                                    })}
                                </div>
                            )}
                        </div>
                        {pickerMode === 'references' && (
                            <div className="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-gray-700 bg-gray-900 px-4 py-3">
                                <button
                                    type="button"
                                    className="rounded-md border border-gray-700 bg-gray-800 px-3 py-1.5 text-xs font-medium text-gray-200 hover:bg-gray-700"
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

            {/* Template Wizard Modal */}
            {templateWizardOpen && (() => {
                const cats = TEMPLATE_CATEGORIES.filter(c => c.platforms.length > 0)
                const allPlatforms = cats.flatMap(c => (wizardCategory === 'all' || wizardCategory === c.id) ? c.platforms : [])
                const query = wizardSearch.trim().toLowerCase()
                const filteredPlatforms = query
                    ? allPlatforms.map(p => ({ ...p, formats: p.formats.filter(f => f.name.toLowerCase().includes(query) || p.name.toLowerCase().includes(query) || `${f.width}x${f.height}`.includes(query)) })).filter(p => p.formats.length > 0)
                    : allPlatforms
                const selectedPlatformObj = allPlatforms.find(p => p.id === wizardPlatform)
                const selectedFormatObj = selectedPlatformObj?.formats.find(f => f.id === wizardFormat)

                /** Apply Customize overrides (enable/disable + placement) onto the base
                 * blueprint list. Keyed by index, so the wizard's checkbox/placement UI
                 * lines up 1:1 with whatever `buildLayersForStyle` or `fmt.layers` returns. */
                const applyWizardOverrides = (bps: LayerBlueprint[]): LayerBlueprint[] => bps.map((bp, i) => {
                    const o = wizardLayerOverrides[i]
                    if (!o) return bp
                    return {
                        ...bp,
                        enabled: o.enabled === false ? false : true,
                        placement: o.placement ?? bp.placement,
                    }
                })

                const applyTemplate = (fmt: TemplateFormat, name: string, styleId: LayoutStyleId | null) => {
                    const brandColor = typeof auth?.activeBrand?.primary_color === 'string' ? auth.activeBrand.primary_color : undefined
                    const baseBps = styleId ? buildLayersForStyle(styleId, fmt.width, fmt.height) : fmt.layers
                    // Seed background randomization by format+style+brand so the wizard
                    // gives varied output across templates but is stable for a given
                    // (brand, format, style) combo. Date.now() would make every re-open
                    // reshuffle — we want users re-opening the same template to see the
                    // same photo they just walked past.
                    const autoFillSeed = `${auth?.activeBrand?.id ?? 'no-brand'}|${fmt.id}|${styleId ?? 'default'}`
                    const enriched = wizardAutoFillEnabled
                        ? applyWizardAssetDefaults(baseBps, wizardDefaults, autoFillSeed)
                        : baseBps
                    const bps = applyWizardOverrides(enriched)
                    const { layers, groups } = blueprintToLayersAndGroups(bps, fmt.width, fmt.height, brandColor)
                    const fresh = {
                        id: generateId(),
                        width: fmt.width,
                        height: fmt.height,
                        preset: 'custom' as const,
                        layers,
                        // Pre-groupings from the template (e.g. CTA fill + text
                        // shipping as one group) land here. Users can ungroup
                        // from the layer panel — the fresh composition will
                        // still save correctly either way.
                        groups,
                        created_at: new Date().toISOString(),
                        updated_at: new Date().toISOString(),
                    }
                    const resolvedName = name || fmt.name
                    flushSync(() => {
                        setCompositionId(null)
                        setCompositionName(resolvedName)
                        setLastSavedName(resolvedName)
                        setDocument(fresh)
                        setLastSavedSerialized(JSON.stringify(fresh))
                        setSelectedLayerId(null)
                        setEditingTextLayerId(null)
                        setVersions([])
                        setSaveState('idle')
                        setSaveError(null)
                        setUserZoom(null)
                        setPanOffset({ x: 0, y: 0 })
                    })
                    replaceUrlCompositionParam(null)
                    setTemplateWizardOpen(false)
                    setLeftPanel('layers')
                }

                return (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60" role="dialog" aria-modal="true">
                        <div className="flex flex-col overflow-hidden rounded-xl bg-gray-900 shadow-2xl ring-1 ring-gray-700" style={{ width: 'min(95vw, 1100px)', height: 'min(92vh, 800px)' }}>
                            {/* Header */}
                            <div className="flex shrink-0 items-center justify-between border-b border-gray-700 px-6 py-4">
                                <div>
                                    <h2 className="text-lg font-semibold text-white">Create from Template</h2>
                                    <p className="mt-0.5 text-sm text-gray-400">
                                        {wizardStep === 1 && 'Choose a platform and format'}
                                        {wizardStep === 2 && 'What type of content are you creating?'}
                                        {wizardStep === 3 && 'Review layers and name your composition'}
                                    </p>
                                </div>
                                <div className="flex items-center gap-3">
                                    <div className="flex items-center gap-1 text-xs text-gray-500">
                                        <span className={`flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-bold ${wizardStep >= 1 ? 'bg-indigo-600 text-white' : 'bg-gray-700 text-gray-400'}`}>1</span>
                                        <div className="h-px w-4 bg-gray-600" />
                                        <span className={`flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-bold ${wizardStep >= 2 ? 'bg-indigo-600 text-white' : 'bg-gray-700 text-gray-400'}`}>2</span>
                                        <div className="h-px w-4 bg-gray-600" />
                                        <span className={`flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-bold ${wizardStep >= 3 ? 'bg-indigo-600 text-white' : 'bg-gray-700 text-gray-400'}`}>3</span>
                                    </div>
                                    <button type="button" onClick={() => setTemplateWizardOpen(false)} className="rounded-md p-1 text-gray-400 hover:bg-gray-800 hover:text-white">
                                        <XMarkIcon className="h-5 w-5" />
                                    </button>
                                </div>
                            </div>

                            {/* Body */}
                            <div className="min-h-0 flex-1 flex flex-col">
                                {wizardStep === 1 && (
                                    <div className="flex min-h-0 flex-1">
                                        {/* Left: category nav */}
                                        <div className="w-44 shrink-0 border-r border-gray-700 overflow-y-auto py-3" style={{ scrollbarWidth: 'none', msOverflowStyle: 'none' }}>
                                            <div className="px-3">
                                                <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-gray-500">Category</p>
                                                {[{ id: 'all' as const, label: 'All Templates' }, ...cats].map((cat) => (
                                                    <button
                                                        key={cat.id}
                                                        type="button"
                                                        onClick={() => { setWizardCategory(cat.id); setWizardPlatform(null); setWizardFormat(null); setWizardSearch('') }}
                                                        className={`flex w-full items-center rounded-md px-2.5 py-1.5 text-[13px] transition-colors ${wizardCategory === cat.id ? 'bg-gray-800 text-white font-medium' : 'text-gray-400 hover:text-gray-200 hover:bg-gray-800/50'}`}
                                                    >
                                                        {cat.label}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                        {/* Right: search + formats grid */}
                                        <div className="flex min-h-0 flex-1 flex-col">
                                            {/* Search */}
                                            <div className="shrink-0 border-b border-gray-700 px-5 py-3">
                                                <div className="relative">
                                                    <svg className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-500" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                                                    <input
                                                        type="text"
                                                        value={wizardSearch}
                                                        onChange={(e) => setWizardSearch(e.target.value)}
                                                        placeholder="Search templates…"
                                                        className="w-full rounded-md border border-gray-700 bg-gray-800 py-2 pl-9 pr-3 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                                    />
                                                </div>
                                            </div>
                                            {/* Scrollable grid */}
                                            <div className="flex-1 overflow-y-auto p-5">
                                                {filteredPlatforms.length === 0 && (
                                                    <div className="flex flex-col items-center justify-center py-12 text-center">
                                                        <svg className="h-10 w-10 text-gray-600" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
                                                        <p className="mt-3 text-sm text-gray-400">No templates match "{wizardSearch}"</p>
                                                    </div>
                                                )}
                                                {filteredPlatforms.map((platform) => (
                                                    <div key={platform.id} className="mb-6">
                                                        <h3 className="mb-3 text-sm font-semibold text-gray-200">{platform.name}</h3>
                                                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                                            {platform.formats.map((fmt) => {
                                                                const isSelected = wizardPlatform === platform.id && wizardFormat === fmt.id
                                                                const aspect = fmt.height / fmt.width
                                                                const thumbH = Math.min(70, Math.max(24, Math.round(80 * aspect)))
                                                                return (
                                                                    <button
                                                                        key={fmt.id}
                                                                        type="button"
                                                                        onClick={() => { setWizardPlatform(platform.id); setWizardFormat(fmt.id) }}
                                                                        className={`flex flex-col items-center rounded-lg border p-3 text-center transition-colors ${isSelected ? 'border-indigo-500 bg-indigo-950/30 ring-1 ring-indigo-500' : 'border-gray-700 bg-gray-800/50 hover:border-gray-600 hover:bg-gray-800'}`}
                                                                    >
                                                                        <div
                                                                            className={`mb-2 w-full max-w-[90px] rounded border ${isSelected ? 'border-indigo-500/50 bg-indigo-900/20' : 'border-gray-600 bg-gray-700/50'}`}
                                                                            style={{ height: thumbH }}
                                                                        />
                                                                        <span className="text-xs font-medium text-gray-200">{fmt.name}</span>
                                                                        <span className="mt-0.5 text-[10px] text-gray-500">{fmt.width}&times;{fmt.height}</span>
                                                                    </button>
                                                                )
                                                            })}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {wizardStep === 2 && selectedFormatObj && (
                                    <div className="flex min-h-0 flex-1 flex-col">
                                        <div className="flex-1 overflow-y-auto p-6">
                                            <h3 className="mb-1 text-sm font-semibold text-gray-200">Ad Type</h3>
                                            <p className="mb-5 text-xs text-gray-500">This determines which layers are set up for your composition.</p>
                                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                                                {LAYOUT_STYLES.map((style) => {
                                                    const isSelected = wizardLayoutStyle === style.id
                                                    return (
                                                        <button
                                                            key={style.id}
                                                            type="button"
                                                            onClick={() => {
                                                                setWizardLayoutStyle(style.id)
                                                                // Picking a different style invalidates any per-index overrides
                                                                // because the blueprint list identity changes.
                                                                setWizardLayerOverrides({})
                                                                setWizardSelectedLayerIdx(null)
                                                            }}
                                                            className={`group flex flex-col items-center rounded-xl border-2 p-5 text-center transition-all ${isSelected ? 'border-indigo-500 bg-indigo-950/30 ring-1 ring-indigo-500/50' : 'border-gray-700 bg-gray-800/50 hover:border-gray-500 hover:bg-gray-800'}`}
                                                        >
                                                            <div className={`mb-3 flex h-16 w-16 items-center justify-center rounded-lg ${isSelected ? 'bg-indigo-900/40' : 'bg-gray-700/60 group-hover:bg-gray-700'}`}>
                                                                <svg className={`h-7 w-7 ${isSelected ? 'text-indigo-400' : 'text-gray-400 group-hover:text-gray-300'}`} fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                                                    {style.icon === 'product' && (
                                                                        <>
                                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                                                                        </>
                                                                    )}
                                                                    {style.icon === 'brand' && (
                                                                        <>
                                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 0 0-2.455 2.456ZM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 0 0-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 0 0 1.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 0 0 1.423 1.423l1.183.394-1.183.394a2.25 2.25 0 0 0-1.423 1.423Z" />
                                                                        </>
                                                                    )}
                                                                    {style.icon === 'lifestyle' && (
                                                                        <>
                                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                                                                        </>
                                                                    )}
                                                                    {style.icon === 'custom' && (
                                                                        <>
                                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                                                                        </>
                                                                    )}
                                                                </svg>
                                                            </div>
                                                            <span className={`text-sm font-semibold ${isSelected ? 'text-white' : 'text-gray-200'}`}>{style.name}</span>
                                                            <span className="mt-1.5 text-[11px] leading-snug text-gray-500">{style.description}</span>
                                                        </button>
                                                    )
                                                })}
                                            </div>

                                            {wizardLayoutStyle && selectedFormatObj && (() => {
                                                // Build blueprint list for the chosen style and merge in the wizard's
                                                // per-index overrides (enabled + placement). `activeBps` is what both
                                                // the layer checkbox list and the mini-preview render from.
                                                const baseBps = buildLayersForStyle(wizardLayoutStyle, selectedFormatObj.width, selectedFormatObj.height)
                                                const activeBps = applyWizardOverrides(baseBps)
                                                const brandLogoUrl = (auth?.activeBrand?.logo_dark_path
                                                    || auth?.activeBrand?.logo_path
                                                    || null) as string | null
                                                const brandPrimary = (auth?.activeBrand?.primary_color as string | undefined) || '#6366f1'
                                                const toggleEnabled = (idx: number) => setWizardLayerOverrides((prev) => ({
                                                    ...prev,
                                                    [idx]: { ...(prev[idx] ?? {}), enabled: prev[idx]?.enabled === false ? true : false },
                                                }))
                                                const setPlacement = (idx: number, p: Placement) => setWizardLayerOverrides((prev) => ({
                                                    ...prev,
                                                    [idx]: { ...(prev[idx] ?? {}), placement: p },
                                                }))
                                                const selectedBp = wizardSelectedLayerIdx !== null ? activeBps[wizardSelectedLayerIdx] : null
                                                const selectedCurrentPlacement: Placement | null = (() => {
                                                    if (selectedBp === null || selectedBp === undefined) return null
                                                    if (selectedBp.placement) return selectedBp.placement
                                                    return xyToPlacement(
                                                        selectedBp.xRatio * selectedFormatObj.width,
                                                        selectedBp.yRatio * selectedFormatObj.height,
                                                        selectedBp.widthRatio * selectedFormatObj.width,
                                                        selectedBp.heightRatio * selectedFormatObj.height,
                                                        selectedFormatObj.width,
                                                        selectedFormatObj.height,
                                                    )
                                                })()
                                                // Scale the preview to fit a ~260 px-wide, 320 px-tall viewport.
                                                const previewMaxW = 260
                                                const previewMaxH = 320
                                                const ar = selectedFormatObj.width / selectedFormatObj.height
                                                const previewH = ar > previewMaxW / previewMaxH ? Math.round(previewMaxW / ar) : previewMaxH
                                                const previewW = ar > previewMaxW / previewMaxH ? previewMaxW : Math.round(previewMaxH * ar)
                                                const roleTypeBadge = (bp: LayerBlueprint) => ({
                                                    bg: bp.type === 'text' ? 'bg-blue-900/50 text-blue-300'
                                                        : bp.type === 'fill' ? 'bg-purple-900/50 text-purple-300'
                                                        : bp.type === 'generative_image' ? 'bg-violet-900/50 text-violet-300'
                                                        : bp.type === 'image' ? 'bg-amber-900/50 text-amber-300'
                                                        : 'bg-gray-700 text-gray-400',
                                                    glyph: bp.type === 'text' ? 'T' : bp.type === 'fill' ? '◐' : bp.type === 'generative_image' ? '✦' : bp.type === 'image' ? '□' : '·',
                                                })
                                                return (
                                                    <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-[220px_minmax(0,1fr)_auto]">
                                                        {/* Left: per-layer placement + (for logo) brand source */}
                                                        <div className="rounded-lg border border-gray-700 bg-gray-800/40 p-4">
                                                            <h4 className="text-xs font-semibold text-gray-300">
                                                                {selectedBp ? selectedBp.name : 'Customize'}
                                                            </h4>
                                                            {!selectedBp && (
                                                                <p className="mt-2 text-[11px] leading-snug text-gray-500">
                                                                    Select a layer from the stack to pick its placement on the 3×3 grid.
                                                                </p>
                                                            )}
                                                            {selectedBp && (
                                                                <div className="mt-3 space-y-3">
                                                                    {selectedBp.role === 'logo' && brandLogoUrl && (
                                                                        <div className="flex items-center gap-2 rounded-md border border-gray-700 bg-gray-900/60 p-2">
                                                                            <div className="flex h-10 w-10 items-center justify-center overflow-hidden rounded bg-neutral-900">
                                                                                <img src={brandLogoUrl} alt="Brand logo" className="max-h-8 max-w-8 object-contain" />
                                                                            </div>
                                                                            <div className="min-w-0 flex-1 text-[11px] text-gray-400">
                                                                                <p className="font-medium text-gray-300">Brand logo</p>
                                                                                <p className="truncate">Will auto-fill on create.</p>
                                                                            </div>
                                                                        </div>
                                                                    )}
                                                                    <div>
                                                                        <PlacementPicker
                                                                            value={selectedCurrentPlacement ?? undefined}
                                                                            onChange={(p) => setPlacement(wizardSelectedLayerIdx!, p)}
                                                                            label="Placement"
                                                                            size="sm"
                                                                            disabled={selectedBp.widthRatio >= 0.999 && selectedBp.heightRatio >= 0.999}
                                                                        />
                                                                        {(selectedBp.widthRatio >= 0.999 && selectedBp.heightRatio >= 0.999) && (
                                                                            <p className="mt-1 text-[10px] text-gray-500">Full-bleed layers ignore placement.</p>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            )}
                                                        </div>

                                                        {/* Middle: layer stack with checkboxes */}
                                                        <div className="rounded-lg border border-gray-700 bg-gray-800/40 p-4">
                                                            <h4 className="mb-2 flex items-center gap-2 text-xs font-semibold text-gray-300">
                                                                <span>Layer Stack</span>
                                                                <span className="text-[10px] font-normal text-gray-500">Toggle layers on/off before you create.</span>
                                                            </h4>
                                                            <div className="space-y-1">
                                                                {activeBps.map((bp, revIdx) => {
                                                                    // Stack is shown visually top-to-bottom as top-of-z first (reverse blueprint array).
                                                                    const idx = activeBps.length - 1 - revIdx
                                                                    const forward = activeBps[idx]
                                                                    const badge = roleTypeBadge(forward)
                                                                    const enabled = forward.enabled !== false
                                                                    const selected = wizardSelectedLayerIdx === idx
                                                                    return (
                                                                        <button
                                                                            type="button"
                                                                            key={idx}
                                                                            onClick={() => setWizardSelectedLayerIdx(idx)}
                                                                            className={`flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm text-left transition-colors ${
                                                                                selected ? 'bg-indigo-900/40 ring-1 ring-indigo-500/60 text-white' : 'text-gray-300 hover:bg-gray-800'
                                                                            }`}
                                                                        >
                                                                            <input
                                                                                type="checkbox"
                                                                                checked={enabled}
                                                                                onChange={(e) => { e.stopPropagation(); toggleEnabled(idx) }}
                                                                                onClick={(e) => e.stopPropagation()}
                                                                                className="rounded border-gray-600 bg-gray-900 text-indigo-500 focus:ring-indigo-500"
                                                                                aria-label={`Include ${forward.name}`}
                                                                            />
                                                                            <span className={`flex h-5 w-5 items-center justify-center rounded text-[10px] ${badge.bg}`}>{badge.glyph}</span>
                                                                            <span className={`flex-1 truncate ${enabled ? '' : 'line-through opacity-50'}`}>{forward.name}</span>
                                                                            <span className="text-[10px] text-gray-500 capitalize">{forward.role.replace('_', ' ')}</span>
                                                                        </button>
                                                                    )
                                                                })}
                                                            </div>
                                                        </div>

                                                        {/* Right: live snapped preview */}
                                                        <div className="rounded-lg border border-gray-700 bg-gray-800/40 p-4">
                                                            <h4 className="mb-2 text-xs font-semibold text-gray-300">Preview</h4>
                                                            <div className="flex flex-col items-center">
                                                                <div
                                                                    className="relative overflow-hidden rounded-md bg-neutral-900 shadow-lg ring-1 ring-black/40"
                                                                    style={{ width: previewW, height: previewH }}
                                                                >
                                                                    {/* Layers */}
                                                                    {activeBps.filter((bp) => bp.enabled !== false).map((bp, i) => {
                                                                        const isFullBleed = bp.widthRatio >= 0.999 && bp.heightRatio >= 0.999
                                                                        const w = bp.widthRatio * previewW
                                                                        const h = bp.heightRatio * previewH
                                                                        const pos = bp.placement && !isFullBleed
                                                                            ? placementToXY(bp.placement, w, h, previewW, previewH)
                                                                            : { x: bp.xRatio * previewW, y: bp.yRatio * previewH }
                                                                        const isLogo = bp.role === 'logo'
                                                                        const style: CSSProperties = {
                                                                            position: 'absolute',
                                                                            left: pos.x,
                                                                            top: pos.y,
                                                                            width: w,
                                                                            height: h,
                                                                            display: 'flex',
                                                                            alignItems: 'center',
                                                                            justifyContent: 'center',
                                                                        }
                                                                        if (isLogo && brandLogoUrl) {
                                                                            return (
                                                                                <div key={i} style={style}>
                                                                                    <img src={brandLogoUrl} alt="" className="max-h-full max-w-full object-contain" />
                                                                                </div>
                                                                            )
                                                                        }
                                                                        let bg = 'rgba(255,255,255,0.12)'
                                                                        let label = bp.name
                                                                        if (bp.type === 'fill') bg = bp.role === 'cta_button' ? brandPrimary : 'rgba(0,0,0,0.35)'
                                                                        if (bp.type === 'text') bg = 'rgba(255,255,255,0.08)'
                                                                        if (bp.type === 'generative_image') bg = 'rgba(124,58,237,0.25)'
                                                                        if (bp.type === 'image') bg = 'rgba(245,158,11,0.2)'
                                                                        if (isFullBleed) label = ''
                                                                        return (
                                                                            <div
                                                                                key={i}
                                                                                style={{ ...style, background: bg, border: isFullBleed ? 'none' : '1px dashed rgba(255,255,255,0.25)' }}
                                                                                className="text-[9px] font-medium text-white/80"
                                                                            >
                                                                                <span className="truncate px-1">{label}</span>
                                                                            </div>
                                                                        )
                                                                    })}
                                                                    {/* 3x3 grid overlay */}
                                                                    <GridOverlay docW={previewW} docH={previewH} density={3} />
                                                                </div>
                                                                <p className="mt-3 text-[11px] text-center text-gray-400">
                                                                    <span className="font-medium text-gray-200">{selectedPlatformObj?.name} — {selectedFormatObj.name}</span>
                                                                </p>
                                                                <p className="text-[10px] text-gray-500">{selectedFormatObj.width} × {selectedFormatObj.height}px</p>
                                                                <p className="mt-1 text-[10px] text-indigo-400">{LAYOUT_STYLES.find(s => s.id === wizardLayoutStyle)?.name}</p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                )
                                            })()}
                                        </div>

                                        <div className="shrink-0 border-t border-gray-700 px-6 py-3">
                                            <div className="flex items-center gap-3 text-xs text-gray-500">
                                                <span className="font-medium text-gray-300">{selectedPlatformObj?.name} — {selectedFormatObj.name}</span>
                                                <span>{selectedFormatObj.width}&times;{selectedFormatObj.height}px</span>
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {wizardStep === 3 && selectedFormatObj && (() => {
                                    const baseLayers = wizardLayoutStyle
                                        ? buildLayersForStyle(wizardLayoutStyle, selectedFormatObj.width, selectedFormatObj.height)
                                        : selectedFormatObj.layers
                                    // Mirror the same enrichment applyTemplate uses so the step-3
                                    // preview list accurately reflects what the user will get
                                    // (image type for background/logo slots, asset thumbnails shown
                                    // inline below).
                                    const autoFillSeed = `${auth?.activeBrand?.id ?? 'no-brand'}|${selectedFormatObj.id}|${wizardLayoutStyle ?? 'default'}`
                                    const previewLayers = wizardAutoFillEnabled
                                        ? applyWizardAssetDefaults(baseLayers, wizardDefaults, autoFillSeed)
                                        : baseLayers
                                    const hasLogoSlot = previewLayers.some((bp) => bp.role === 'logo')
                                    const hasBgSlot = previewLayers.some((bp) => bp.role === 'background' || bp.role === 'hero_image')
                                    const autoLogo = wizardDefaults?.logo ?? null
                                    const autoBgPick = (() => {
                                        const cands = wizardDefaults?.background_candidates ?? []
                                        if (cands.length === 0) return null
                                        // Same seeded pick as applyWizardAssetDefaults — keep UI in sync.
                                        const slotCount = cands.length
                                        let h = 2166136261
                                        for (let i = 0; i < autoFillSeed.length; i++) {
                                            h ^= autoFillSeed.charCodeAt(i)
                                            h = Math.imul(h, 16777619)
                                        }
                                        return cands[Math.abs(h) % slotCount]
                                    })()
                                    return (
                                    <div className="flex min-h-0 flex-1">
                                        <div className="flex-1 overflow-y-auto p-6">
                                            <div className="mb-6">
                                                <label className="mb-1.5 block text-xs font-semibold text-gray-300">Composition Name</label>
                                                <input
                                                    type="text"
                                                    value={wizardName}
                                                    onChange={(e) => setWizardName(e.target.value)}
                                                    placeholder={selectedFormatObj.name}
                                                    className="w-full rounded-md border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-100 placeholder-gray-500 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                                                />
                                            </div>
                                            {/* Auto-fill summary — shows the brand logo + hero photo
                                                that will be dropped into matching layers, plus a toggle
                                                for users who want the classic empty-slot behavior. */}
                                            {wizardDefaults && (hasLogoSlot || hasBgSlot) && (
                                                <div className="mb-6 rounded-lg border border-gray-700 bg-gray-800/30 p-3">
                                                    <div className="mb-2 flex items-center justify-between">
                                                        <h4 className="text-xs font-semibold text-gray-300">Auto-filled assets</h4>
                                                        <label className="flex cursor-pointer items-center gap-1.5 text-[11px] text-gray-400">
                                                            <input
                                                                type="checkbox"
                                                                checked={wizardAutoFillEnabled}
                                                                onChange={(e) => setWizardAutoFillEnabled(e.target.checked)}
                                                                className="h-3.5 w-3.5 rounded border-gray-600 bg-gray-800 text-indigo-500 focus:ring-indigo-500"
                                                            />
                                                            Enabled
                                                        </label>
                                                    </div>
                                                    <div className="grid gap-2 sm:grid-cols-2">
                                                        {hasLogoSlot && (
                                                            <div className="flex items-center gap-2 rounded-md border border-gray-700 bg-gray-800/60 p-2">
                                                                <div className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded bg-gray-900">
                                                                    {wizardAutoFillEnabled && autoLogo?.thumbnail_url ? (
                                                                        <img src={autoLogo.thumbnail_url} alt="" className="max-h-full max-w-full object-contain" />
                                                                    ) : (
                                                                        <span className="text-[10px] text-gray-500">Logo</span>
                                                                    )}
                                                                </div>
                                                                <div className="min-w-0 flex-1">
                                                                    <p className="truncate text-[11px] font-medium text-gray-200">Logo slot</p>
                                                                    <p className="truncate text-[10px] text-gray-500">
                                                                        {wizardAutoFillEnabled && autoLogo ? autoLogo.name : 'Empty — add in editor'}
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        )}
                                                        {hasBgSlot && (
                                                            <div className="flex items-center gap-2 rounded-md border border-gray-700 bg-gray-800/60 p-2">
                                                                <div className="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded bg-gray-900">
                                                                    {wizardAutoFillEnabled && autoBgPick?.thumbnail_url ? (
                                                                        <img src={autoBgPick.thumbnail_url} alt="" className="h-full w-full object-cover" />
                                                                    ) : (
                                                                        <span className="text-[10px] text-gray-500">Photo</span>
                                                                    )}
                                                                </div>
                                                                <div className="min-w-0 flex-1">
                                                                    <p className="truncate text-[11px] font-medium text-gray-200">Background photo</p>
                                                                    <p className="truncate text-[10px] text-gray-500">
                                                                        {wizardAutoFillEnabled && autoBgPick
                                                                            ? autoBgPick.name
                                                                            : (wizardDefaults.background_candidates.length === 0
                                                                                ? 'No tagged background photos — add one with tag "background" or "hero"'
                                                                                : 'Disabled — generative background')}
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            )}
                                            <div className="mb-6">
                                                <h4 className="mb-2 text-xs font-semibold text-gray-300">
                                                    {wizardLayoutStyle ? `${LAYOUT_STYLES.find(s => s.id === wizardLayoutStyle)?.name} ` : ''}Layer Stack
                                                </h4>
                                                <p className="mb-3 text-[11px] text-gray-500">These layers will be pre-populated. You can add, remove, or reorder them later.</p>
                                                <div className="space-y-1 rounded-lg border border-gray-700 bg-gray-800/50 p-2">
                                                    {[...previewLayers].reverse().map((bp, i) => (
                                                        <div key={i} className="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-sm text-gray-300">
                                                            <span className={`flex h-5 w-5 items-center justify-center rounded text-[10px] ${
                                                                bp.type === 'text' ? 'bg-blue-900/50 text-blue-300' :
                                                                bp.type === 'fill' ? 'bg-purple-900/50 text-purple-300' :
                                                                bp.type === 'generative_image' ? 'bg-violet-900/50 text-violet-300' :
                                                                bp.type === 'image' ? 'bg-amber-900/50 text-amber-300' :
                                                                'bg-gray-700 text-gray-400'
                                                            }`}>
                                                                {bp.type === 'text' ? 'T' : bp.type === 'fill' ? '◐' : bp.type === 'generative_image' ? '✦' : bp.type === 'image' ? '□' : '·'}
                                                            </span>
                                                            <span className="flex-1 truncate">{bp.name}</span>
                                                            <span className="text-[10px] text-gray-500 capitalize">{bp.role.replace('_', ' ')}</span>
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        </div>
                                        <div className="flex w-72 shrink-0 flex-col items-center justify-center border-l border-gray-700 bg-gray-800/30 p-6">
                                            {/* Mini composition preview — shows the auto-filled
                                                background photo with the brand logo stamped at
                                                the template's LOGO slot, so the user can see
                                                what "Let's Go" will produce before clicking.
                                                Falls back to a blank card when no auto-fill is
                                                available or the user disabled it, matching the
                                                empty-canvas behavior the editor will start at. */}
                                            {(() => {
                                                const previewW = 200
                                                const previewH = Math.min(280, Math.round(200 * (selectedFormatObj.height / selectedFormatObj.width)))
                                                const showBg = wizardAutoFillEnabled && autoBgPick && autoBgPick.file_url
                                                const showLogo = wizardAutoFillEnabled && autoLogo && autoLogo.file_url
                                                return (
                                                    <div
                                                        className="relative overflow-hidden rounded-lg border border-gray-700 bg-white shadow-lg"
                                                        style={{ width: previewW, height: previewH }}
                                                    >
                                                        {showBg ? (
                                                            <img
                                                                src={autoBgPick!.thumbnail_url || autoBgPick!.file_url}
                                                                alt=""
                                                                className="absolute inset-0 h-full w-full object-cover"
                                                                onError={(e) => {
                                                                    // Thumbnail may 404 for non-photo mimes; fall back to the original.
                                                                    const img = e.currentTarget
                                                                    if (img.src !== autoBgPick!.file_url) img.src = autoBgPick!.file_url
                                                                }}
                                                            />
                                                        ) : null}
                                                        {showLogo ? (
                                                            <img
                                                                src={autoLogo!.thumbnail_url || autoLogo!.file_url}
                                                                alt=""
                                                                className="absolute object-contain"
                                                                style={{
                                                                    left: '5%',
                                                                    top: '5%',
                                                                    width: '20%',
                                                                    height: '15%',
                                                                }}
                                                                onError={(e) => {
                                                                    const img = e.currentTarget
                                                                    if (img.src !== autoLogo!.file_url) img.src = autoLogo!.file_url
                                                                }}
                                                            />
                                                        ) : null}
                                                    </div>
                                                )
                                            })()}
                                            <p className="mt-3 text-sm font-medium text-gray-200">{selectedPlatformObj?.name} — {selectedFormatObj.name}</p>
                                            <p className="mt-1 text-xs text-gray-500">{selectedFormatObj.width} &times; {selectedFormatObj.height}px</p>
                                            {wizardLayoutStyle && (
                                                <p className="mt-1 text-xs text-indigo-400">{LAYOUT_STYLES.find(s => s.id === wizardLayoutStyle)?.name}</p>
                                            )}
                                        </div>
                                    </div>
                                    )
                                })()}
                            </div>

                            {/* Footer */}
                            <div className="flex shrink-0 items-center justify-between border-t border-gray-700 px-6 py-3">
                                <button
                                    type="button"
                                    onClick={() => { if (wizardStep > 1) setWizardStep(wizardStep - 1); else setTemplateWizardOpen(false) }}
                                    className="rounded-md px-4 py-2 text-sm font-medium text-gray-400 hover:text-gray-200 transition-colors"
                                >
                                    {wizardStep === 1 ? 'Cancel' : 'Back'}
                                </button>
                                <button
                                    type="button"
                                    disabled={
                                        (wizardStep === 1 && !wizardFormat) ||
                                        (wizardStep === 2 && !wizardLayoutStyle)
                                    }
                                    onClick={() => {
                                        if (wizardStep === 1 && wizardFormat) {
                                            setWizardLayoutStyle(null)
                                            setWizardStep(2)
                                        } else if (wizardStep === 2 && wizardLayoutStyle) {
                                            setWizardName('')
                                            setWizardStep(3)
                                        } else if (wizardStep === 3 && selectedFormatObj) {
                                            applyTemplate(selectedFormatObj, wizardName, wizardLayoutStyle)
                                        }
                                    }}
                                    className="rounded-md bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-500 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                >
                                    {wizardStep === 3 ? "Let's Go" : 'Next'}
                                </button>
                            </div>
                        </div>
                    </div>
                )
            })()}

            {/* AI Layout Prompt Dialog */}
            {aiLayoutPromptOpen && (
                <div className="fixed inset-0 z-[60] flex items-center justify-center">
                    <div className="absolute inset-0 bg-black/60 backdrop-blur-sm" onClick={() => { if (!aiLayoutLoading) setAiLayoutPromptOpen(false) }} />
                    <div className="relative mx-4 w-full max-w-lg animate-[jp-dialog-enter_0.15s_ease-out] overflow-hidden rounded-xl border border-gray-700 bg-gray-900 shadow-2xl ring-1 ring-white/5">
                        <style>{`@keyframes jp-dialog-enter { from { opacity: 0; transform: scale(0.96) translateY(8px); } to { opacity: 1; transform: scale(1) translateY(0); } }`}</style>
                        <div className="px-5 pt-5 pb-0">
                            <div className="flex items-center gap-3 mb-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg" style={{ background: 'linear-gradient(135deg, #6d28d9, #4338ca)' }}>
                                    <SparklesIcon className="h-5 w-5 text-white" />
                                </div>
                                <div>
                                    <h3 className="text-sm font-semibold text-white">Generate a layout with AI</h3>
                                    <p className="text-[12px] text-gray-400">Describe what you want to create and Jackpot will build it.</p>
                                </div>
                                {!aiLayoutLoading && (
                                    <button type="button" onClick={() => setAiLayoutPromptOpen(false)} className="ml-auto shrink-0 rounded-md p-1 text-gray-500 transition-colors hover:bg-gray-800 hover:text-gray-300">
                                        <XMarkIcon className="h-4 w-4" />
                                    </button>
                                )}
                            </div>
                            <textarea
                                className="w-full resize-none rounded-lg border border-gray-700 bg-gray-800 px-3 py-2.5 text-sm text-gray-200 placeholder-gray-500 transition-colors focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                rows={4}
                                placeholder="e.g. &quot;Instagram post for a summer sale on sneakers — bold, energetic, product-focused&quot;"
                                value={aiLayoutPrompt}
                                onChange={(e) => setAiLayoutPrompt(e.target.value)}
                                disabled={aiLayoutLoading}
                                autoFocus
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey) && aiLayoutPrompt.trim() && !aiLayoutLoading) {
                                        void executeAiLayoutGeneration(aiLayoutPrompt.trim())
                                    }
                                }}
                            />
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {['Instagram product post', 'Facebook brand awareness', 'Web banner for sale', 'LinkedIn thought leadership', 'YouTube thumbnail'].map((s) => (
                                    <button
                                        key={s}
                                        type="button"
                                        className="rounded-full border border-gray-700 bg-gray-800/50 px-2.5 py-1 text-[10px] text-gray-400 transition-colors hover:border-gray-600 hover:text-gray-200 disabled:opacity-40"
                                        disabled={aiLayoutLoading}
                                        onClick={() => setAiLayoutPrompt(s)}
                                    >
                                        {s}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <div className="flex items-center justify-between px-5 pb-4 pt-4">
                            <p className="text-[10px] text-gray-500">
                                {aiLayoutLoading ? 'Generating your layout...' : 'Ctrl+Enter to generate'}
                            </p>
                            <div className="flex gap-2">
                                {!aiLayoutLoading && (
                                    <button type="button" onClick={() => setAiLayoutPromptOpen(false)} className="rounded-lg border border-gray-700 bg-gray-800 px-3.5 py-2 text-xs font-semibold text-gray-300 transition-colors hover:bg-gray-700 hover:text-white">
                                        Cancel
                                    </button>
                                )}
                                <button
                                    type="button"
                                    disabled={!aiLayoutPrompt.trim() || aiLayoutLoading}
                                    onClick={() => void executeAiLayoutGeneration(aiLayoutPrompt.trim())}
                                    className="flex items-center gap-1.5 rounded-lg px-4 py-2 text-xs font-semibold text-white shadow-sm transition-colors disabled:opacity-50"
                                    style={{ background: aiLayoutLoading ? '#4338ca' : 'linear-gradient(135deg, #7c3aed, #4f46e5)' }}
                                >
                                    {aiLayoutLoading ? (
                                        <>
                                            <svg className="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="3" strokeLinecap="round" className="opacity-25" /><path d="M4 12a8 8 0 018-8" stroke="currentColor" strokeWidth="3" strokeLinecap="round" /></svg>
                                            Generating...
                                        </>
                                    ) : (
                                        <>
                                            <SparklesIcon className="h-3.5 w-3.5" />
                                            Generate Layout
                                        </>
                                    )}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Styled confirm dialog (replaces window.confirm) */}
            <EditorConfirmDialog state={confirmState} onClose={handleConfirmClose} />
        </div>
    )
}
