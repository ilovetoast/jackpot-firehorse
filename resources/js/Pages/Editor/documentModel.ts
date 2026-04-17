/**
 * Generative asset editor — JSON document model (source of truth).
 */

export type DocumentPreset = 'instagram_post' | 'web_banner' | 'custom'

/** CSS `mix-blend-mode` values supported in the editor (layer vs content below). */
export type LayerBlendMode =
    | 'normal'
    | 'multiply'
    | 'screen'
    | 'overlay'
    | 'darken'
    | 'lighten'
    | 'color-dodge'
    | 'color-burn'
    | 'hard-light'
    | 'soft-light'
    | 'difference'
    | 'exclusion'
    | 'hue'
    | 'saturation'
    | 'color'
    | 'luminosity'

export const LAYER_BLEND_MODE_OPTIONS: { value: LayerBlendMode; label: string }[] = [
    { value: 'normal', label: 'Normal' },
    { value: 'multiply', label: 'Multiply' },
    { value: 'screen', label: 'Screen' },
    { value: 'overlay', label: 'Overlay' },
    { value: 'darken', label: 'Darken' },
    { value: 'lighten', label: 'Lighten' },
    { value: 'color-dodge', label: 'Color dodge' },
    { value: 'color-burn', label: 'Color burn' },
    { value: 'hard-light', label: 'Hard light' },
    { value: 'soft-light', label: 'Soft light' },
    { value: 'difference', label: 'Difference' },
    { value: 'exclusion', label: 'Exclusion' },
    { value: 'hue', label: 'Hue' },
    { value: 'saturation', label: 'Saturation' },
    { value: 'color', label: 'Color' },
    { value: 'luminosity', label: 'Luminosity' },
]

export type DocumentModel = {
    id: string
    width: number
    height: number
    preset?: DocumentPreset
    layers: Layer[]
    created_at?: string
    updated_at?: string
}

export type BaseLayer = {
    id: string
    type: 'image' | 'text' | 'generative_image' | 'fill'
    name?: string
    visible: boolean
    locked: boolean
    z: number
    /** How this layer composites over layers below (CSS mix-blend-mode). Default: normal. */
    blendMode?: LayerBlendMode
    transform: {
        x: number
        y: number
        width: number
        height: number
        rotation?: number
        /** Reserved for Phase 7+ (default 1). */
        scaleX?: number
        /** Reserved for Phase 7+ (default 1). */
        scaleY?: number
    }
}

export type ImageLayer = BaseLayer & {
    type: 'image'
    /** DAM asset id when sourced from the library */
    assetId?: string
    /** Selected {@link AssetVersion} id when user picks a historical version (UUID). */
    assetVersionId?: string
    src: string
    naturalWidth?: number
    naturalHeight?: number
    fit?: 'cover' | 'contain' | 'fill'
    /** AI image edit (instructional edits — does not mutate DAM asset; new URLs only). */
    aiEdit?: {
        prompt?: string
        /** Registry key for POST /app/api/edit-image (e.g. gpt-image-1, gemini-2.5-flash-image). */
        editModelKey?: string
        status?: 'idle' | 'editing' | 'error' | 'done'
        resultSrc?: string
        previousResults?: string[]
    }
}

/** API row from GET /app/api/assets */
export type DamPickerAsset = {
    id: string
    name: string
    thumbnail_url: string
    file_url: string
    width?: number
    height?: number
}

/** Lightweight brand-voice heuristic for copy assist (editor UI). */
export type CopyScore = {
    score: number
    feedback: string[]
}

export type TextLayer = BaseLayer & {
    type: 'text'
    content: string
    /** Undo stack for copy assist (most recent last); capped client-side. */
    previousText?: string[]
    style: {
        fontFamily: string
        fontSize: number
        fontWeight?: number
        /** Unitless line-height multiplier (e.g. 1.25). */
        lineHeight?: number
        /** Letter spacing in px. */
        letterSpacing?: number
        color: string
        textAlign?: 'left' | 'center' | 'right'
        verticalAlign?: 'top' | 'middle' | 'bottom'
        /** When true, font size is reduced to fit the layer box. */
        autoFit?: boolean
    }
}

/** Server-built list for FontFace + /api/assets/{id}/file (same-origin); asset IDs from Brand DNA only. */
export type EditorFontFaceSource = {
    family: string
    /** Integer (legacy) or UUID string — matches {@link Asset} keys. */
    asset_id: number | string
    weight: string
    style: string
}

export type BrandContext = {
    tone?: string[]
    colors?: string[]
    typography?: {
        primary_font?: string
        secondary_font?: string
        /** Google Fonts / self-hosted CSS (HTTPS). Binary font URLs are stripped — use font_face_sources. */
        font_urls?: string[]
        /** Same as font_urls; explicit list for the editor loader. */
        stylesheet_urls?: string[]
        /** Licensed/uploaded font binaries resolved via authenticated asset file endpoint. */
        font_face_sources?: EditorFontFaceSource[]
        /**
         * Canonical family name for the primary licensed upload (matches FontFace `family` in font_face_sources).
         * Use for defaults / canvas when `primary_font` is a looser label.
         */
        canvas_primary_font_family?: string | null
        /**
         * Optional brand-defined type scale (Phase 7+). Partial keys allowed.
         */
        presets?: {
            heading?: { fontSize?: number; fontWeight?: number; lineHeight?: number; letterSpacing?: number }
            subheading?: { fontSize?: number; fontWeight?: number; lineHeight?: number; letterSpacing?: number }
            body?: { fontSize?: number; fontWeight?: number; lineHeight?: number; letterSpacing?: number }
            caption?: { fontSize?: number; fontWeight?: number; lineHeight?: number; letterSpacing?: number }
        }
    }
    /**
     * Canonical brand ink colors (for labeled swatches). May overlap with `colors`.
     */
    brand_color_slots?: {
        primary?: string | null
        secondary?: string | null
        accent?: string | null
    }
    visual_style?: string
    archetype?: string
}

/** Labeled ink for editor color swatches (brain guidelines slots, else {@link BrandContext.colors}). */
export type LabeledBrandColor = { label: string; color: string }

export function labeledBrandPalette(brand: BrandContext | null | undefined): LabeledBrandColor[] {
    const slots = brand?.brand_color_slots
    const labeled: LabeledBrandColor[] = []
    if (slots) {
        if (slots.primary) {
            labeled.push({ label: 'Primary', color: slots.primary })
        }
        if (slots.secondary) {
            labeled.push({ label: 'Secondary', color: slots.secondary })
        }
        if (slots.accent) {
            labeled.push({ label: 'Accent', color: slots.accent })
        }
    }
    if (labeled.length === 0 && brand?.colors?.length) {
        const fallback = ['Primary', 'Secondary', 'Accent']
        brand.colors.forEach((c, idx) => {
            labeled.push({ label: fallback[idx] ?? `Color ${idx + 1}`, color: c })
        })
    }
    return labeled
}

/** Default stack when no brand primary font is set. */
export const DEFAULT_TEXT_FONT_FAMILY = 'Inter, system-ui, sans-serif'

export function defaultTextFontFamilyFromBrand(brand: BrandContext | null | undefined): string {
    const canvasPrimary = brand?.typography?.canvas_primary_font_family?.trim()
    if (canvasPrimary) {
        return canvasPrimary
    }
    const primary = brand?.typography?.primary_font?.trim()
    if (primary) {
        return primary
    }
    return DEFAULT_TEXT_FONT_FAMILY
}

/** Primary font label for UI / layer value when a licensed face name is known. */
export function effectivePrimaryFontFamily(brand: BrandContext | null | undefined): string | undefined {
    const canvas = brand?.typography?.canvas_primary_font_family?.trim()
    if (canvas) {
        return canvas
    }
    const p = brand?.typography?.primary_font?.trim()
    return p || undefined
}

export type BrandScore = {
    score: number
    feedback: string[]
}

export type GenerativePrompt = {
    scene?: string
    /** Structured hints from smart actions (kept out of free-text scene). */
    brand_hints?: {
        tone?: string
        palette?: string
    }
    subject?: {
        type?: string
        description?: string
        expression?: string
        hair?: string
        details?: string[]
    }
    camera?: {
        brand?: string
        model?: string
        lens?: string
        aperture?: string
        look?: string
        focus?: string
    }
    composition?: {
        style?: string
        framing?: string
        pose?: string
        background?: string
    }
    lighting?: {
        setup?: unknown[]
        look?: string
    }
    style?: {
        photorealism?: string
        editorial?: string
        color?: string
        fabric_detail?: string
        depth_of_field?: string
    }
}

/** Solid color or linear gradient (e.g. brand wash) — no bitmap. */
export type FillLayer = BaseLayer & {
    type: 'fill'
    fillKind: 'solid' | 'gradient'
    /**
     * Solid fill color. For gradient, kept aligned with the second stop when using the two-color UI
     * (used when toggling to solid).
     */
    color: string
    /**
     * Two-stop gradient: first CSS color (`transparent`, `#rrggbb`, …). When both this and
     * {@link gradientEndColor} are undefined, legacy rendering uses {@link color} → transparent.
     */
    gradientStartColor?: string
    /** Two-stop gradient: second CSS color. */
    gradientEndColor?: string
    /** CSS linear-gradient angle in degrees (0 = to top, 90 = to right, 180 = to bottom). */
    gradientAngleDeg?: number
    /** Optional border-radius in px. Used for pill/rounded-button CTA backgrounds. */
    borderRadius?: number
}

export type GenerativeImageLayer = BaseLayer & {
    type: 'generative_image'
    prompt: GenerativePrompt
    negativePrompt?: string[]
    /** UI preset key — maps to provider/model via MODEL_MAP on the client. */
    model?: string
    /** Advanced: registry key from config/ai.php (server allowlisted). */
    modelOverride?: string
    /** When true, use {@link modelOverride} instead of the preset mapping. */
    advancedModel?: boolean
    /** Last resolved label from API (`model_display_name`). */
    lastResolvedModelDisplay?: string
    /** When true (default), prompt_string is augmented with Brand DNA; when false, only buildPromptString. */
    applyBrandDna?: boolean
    /** DAM asset ids used as visual references (max 5 enforced in UI). */
    referenceAssetIds?: string[]
    status?: 'idle' | 'generating' | 'error' | 'done'
    resultSrc?: string
    /** Prior outputs (oldest → newest tail) for undo / compare — updated when result is replaced. */
    previousResults?: string[]
    resultAssetId?: string
    fit?: 'cover' | 'contain' | 'fill'
    /** True while a variation batch is in flight (parallel generates). */
    variationPending?: boolean
    /** How many images this batch requested (for loading UI). */
    variationBatchSize?: number
    /** Populated when variation batch completes; user picks one to apply to resultSrc. */
    variationResults?: string[]
}

export type Layer = ImageLayer | TextLayer | GenerativeImageLayer | FillLayer

const ALLOWED_LAYER_TYPES = new Set(['image', 'text', 'generative_image', 'fill'])

export function isFillLayer(l: Layer): l is FillLayer {
    return l.type === 'fill'
}

/** Inline SVG placeholder — no network request. */
export const PLACEHOLDER_IMAGE_SRC =
    'data:image/svg+xml,' +
    encodeURIComponent(
        `<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300">
  <rect fill="#e5e7eb" width="100%" height="100%"/>
  <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#9ca3af" font-family="system-ui,sans-serif" font-size="18">Image</text>
</svg>`
    )

export function generateId(): string {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID()
    }
    return `layer_${Date.now()}_${Math.random().toString(16).slice(2)}_${Math.random().toString(16).slice(2)}`
}

/** Default name when promoting a composition to the DAM (uses first text layer if non-empty). */
export function buildPromotionAssetName(doc: DocumentModel): string {
    const firstText = doc.layers.find((l): l is TextLayer => l.type === 'text')
    const raw = firstText?.content?.trim().replace(/\s+/g, ' ') ?? ''
    if (raw.length > 0) {
        const max = 72
        return raw.length > max ? `${raw.slice(0, max - 1)}…` : raw
    }
    const d = new Date()
    const dateStr = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
    return `Untitled Composition — ${dateStr}`
}

export function createInitialDocument(): DocumentModel {
    const now = new Date().toISOString()
    return {
        id: generateId(),
        width: 1080,
        height: 1080,
        preset: 'instagram_post',
        layers: [],
        created_at: now,
        updated_at: now,
    }
}

/**
 * True when the document has never been saved (`compositionId` absent), has no layers,
 * and still matches default canvas size/preset — nothing meaningful to discard.
 */
export function isBlankUnsavedCanvas(doc: DocumentModel, hasCompositionId: boolean): boolean {
    if (hasCompositionId) {
        return false
    }
    if (doc.layers.length > 0) {
        return false
    }
    const defaults = createInitialDocument()
    return (
        doc.width === defaults.width &&
        doc.height === defaults.height &&
        (doc.preset ?? 'instagram_post') === (defaults.preset ?? 'instagram_post')
    )
}

/** Local-only initial load (no server). Prefer loading via composition API in the editor. */
export function loadDocument(): DocumentModel {
    return createInitialDocument()
}

/** @deprecated Use composition API from editorCompositionBridge */
export function saveDocument(doc: DocumentModel): void {
    console.warn('saveDocument() is a stub; use POST/PUT /app/api/compositions', doc)
}

/** Default composition title: first text layer content, or "Untitled — {date}". */
export function defaultCompositionName(doc: DocumentModel): string {
    const firstText = doc.layers.find((l): l is TextLayer => l.type === 'text')
    const raw = firstText?.content?.trim().replace(/\s+/g, ' ') ?? ''
    if (raw.length > 0) {
        const max = 72
        return raw.length > max ? `${raw.slice(0, max - 1)}…` : raw
    }
    const d = new Date()
    const dateStr = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
    return `Untitled — ${dateStr}`
}

/** Same-origin URL for streaming original bytes (works in <img> after SW bypass for this path). */
function editorBridgeFileUrlForAssetId(assetId: string): string {
    const path = `/app/api/assets/${encodeURIComponent(assetId)}/file`
    if (typeof window !== 'undefined' && window.location?.origin) {
        return `${window.location.origin}${path}`
    }
    return path
}

/**
 * Older saves sometimes stored thumbnail URLs in `src`; canvas must use /file or decode fails / looks wrong.
 */
function normalizeImageLayerSrcAfterApiLoad(layer: Layer): Layer {
    if (layer.type !== 'image') {
        return layer
    }
    const assetId = layer.assetId
    if (!assetId || typeof layer.src !== 'string') {
        return layer
    }
    const s = layer.src
    if (s.includes('/thumbnail') || s.includes('thumbnail?style')) {
        return { ...layer, src: editorBridgeFileUrlForAssetId(assetId) }
    }
    return layer
}

/** Normalize JSON from the server into a {@link DocumentModel}. */
export function parseDocumentFromApi(raw: unknown): DocumentModel {
    if (!raw || typeof raw !== 'object') {
        return createInitialDocument()
    }
    const o = raw as Record<string, unknown>
    const w = typeof o.width === 'number' && o.width > 0 ? o.width : 1080
    const h = typeof o.height === 'number' && o.height > 0 ? o.height : 1080
    const layersRaw = Array.isArray(o.layers) ? (o.layers as unknown[]) : []
    const layers = normalizeZ(
        layersRaw.filter((raw): raw is Layer => {
            if (!raw || typeof raw !== 'object') {
                return false
            }
            const t = (raw as { type?: string }).type
            return typeof t === 'string' && ALLOWED_LAYER_TYPES.has(t)
        }) as Layer[]
    ).map(normalizeImageLayerSrcAfterApiLoad)
    return {
        id: typeof o.id === 'string' ? o.id : generateId(),
        width: w,
        height: h,
        preset:
            o.preset === 'instagram_post' || o.preset === 'web_banner' || o.preset === 'custom'
                ? o.preset
                : undefined,
        layers,
        created_at: typeof o.created_at === 'string' ? o.created_at : undefined,
        updated_at: typeof o.updated_at === 'string' ? o.updated_at : undefined,
    }
}

/** Stable 0…n-1 z-order after any add/remove/reorder (back → front = ascending z). */
export function normalizeZ(layers: Layer[]): Layer[] {
    return [...layers]
        .sort((a, b) => {
            const za = Number((a as Layer).z)
            const zb = Number((b as Layer).z)
            const diff = (Number.isFinite(za) ? za : 0) - (Number.isFinite(zb) ? zb : 0)
            return diff !== 0 ? diff : a.id.localeCompare(b.id)
        })
        .map((layer, index) => ({
            ...layer,
            z: index,
        }))
}

/** Next z after normalize (append to top / front). */
export function nextZIndex(layers: Layer[]): number {
    return layers.length
}

/** Visual hints for copy generation (ties text to on-canvas imagery + brand). */
export type EditorVisualContext = {
    has_product: boolean
    style?: string
    color?: string
    /** Rough layout role for copy tone (hero strip, product focus, full-bleed bg). */
    composition_type?: 'hero' | 'product' | 'background'
    /** Overall canvas / palette contrast. */
    contrast_level?: 'light' | 'dark' | 'mid'
}

function isNearFullBleed(
    t: { x: number; y: number; width: number; height: number },
    doc: Pick<DocumentModel, 'width' | 'height'>
): boolean {
    return t.x <= 4 && t.y <= 4 && t.width >= doc.width * 0.92 && t.height >= doc.height * 0.92
}

export function detectTextIntent(layer: TextLayer): 'headline' | 'body' | 'caption' {
    if (layer.style.fontSize > 32) {
        return 'headline'
    }
    if (layer.style.fontSize <= 18) {
        return 'caption'
    }
    return 'body'
}

export function buildEditorVisualContext(
    doc: DocumentModel,
    brandContext: BrandContext | null | undefined,
    /** Selected text layer — refines composition hints vs this frame. */
    textLayer?: TextLayer | null
): EditorVisualContext {
    const hasProduct = doc.layers.some(
        (l) => l.type === 'image' || (l.type === 'generative_image' && Boolean(l.resultSrc))
    )
    const sorted = [...doc.layers].sort((a, b) => a.z - b.z)
    const fullBleedGen = sorted.find(
        (l): l is GenerativeImageLayer =>
            l.type === 'generative_image' && isNearFullBleed(l.transform, doc)
    )
    const gen = doc.layers.find((l) => l.type === 'generative_image')
    const scene = gen?.prompt?.scene?.trim() ?? ''
    const editorial = gen?.prompt?.style?.editorial?.trim() ?? ''
    const style =
        [scene.slice(0, 140), editorial].filter(Boolean).join(' — ') || undefined
    const palette = brandContext?.colors?.filter(Boolean).join(', ')
    let colorMood: string | undefined
    let contrastLevel: EditorVisualContext['contrast_level'] = 'mid'
    const firstHex = brandContext?.colors?.[0]?.trim()
    if (firstHex?.startsWith('#') && firstHex.length >= 7) {
        const hex = firstHex.slice(1, 7)
        if (/^[0-9a-fA-F]{6}$/.test(hex)) {
            const r = parseInt(hex.slice(0, 2), 16)
            const g = parseInt(hex.slice(2, 4), 16)
            const b = parseInt(hex.slice(4, 6), 16)
            const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255
            colorMood = lum < 0.35 ? 'dark minimal' : lum > 0.75 ? 'light airy' : 'balanced mid-tone'
            contrastLevel = lum < 0.35 ? 'dark' : lum > 0.75 ? 'light' : 'mid'
        }
    }

    let composition: EditorVisualContext['composition_type'] = 'hero'
    if (fullBleedGen) {
        composition = 'background'
    } else if (hasProduct) {
        composition = 'product'
    }
    if (textLayer && textLayer.transform.y > doc.height * 0.45) {
        composition = 'product'
    }

    return {
        has_product: hasProduct,
        style,
        color: palette || colorMood,
        composition_type: composition,
        contrast_level: contrastLevel,
    }
}

function defaultTransform(
    partial: Pick<BaseLayer['transform'], 'x' | 'y' | 'width' | 'height'>
): BaseLayer['transform'] {
    return {
        ...partial,
        rotation: 0,
        scaleX: 1,
        scaleY: 1,
    }
}

/** Centers a layer box of known size within the document frame. */
export function centerLayerInDocument(
    doc: Pick<DocumentModel, 'width' | 'height'>,
    width: number,
    height: number
): { x: number; y: number } {
    const dw = Math.max(1, doc.width)
    const dh = Math.max(1, doc.height)
    const w = Math.max(1, width)
    const h = Math.max(1, height)
    return {
        x: (dw - w) / 2,
        y: (dh - h) / 2,
    }
}

export function createDefaultImageLayer(z: number, doc: Pick<DocumentModel, 'width' | 'height'>): ImageLayer {
    const defaultWidth = 300
    const defaultHeight = 200
    const { x, y } = centerLayerInDocument(doc, defaultWidth, defaultHeight)
    return {
        id: generateId(),
        type: 'image',
        name: 'Image layer',
        visible: true,
        locked: false,
        z,
        transform: defaultTransform({
            x,
            y,
            width: defaultWidth,
            height: defaultHeight,
        }),
        src: PLACEHOLDER_IMAGE_SRC,
        fit: 'cover',
    }
}

/** Scale new image into 80% box, centered (natural dims from DAM or fallback). */
export function computePlacedImageRect(
    docWidth: number,
    docHeight: number,
    naturalWidth: number,
    naturalHeight: number
): { x: number; y: number; width: number; height: number } {
    const maxW = docWidth * 0.8
    const maxH = docHeight * 0.8
    const nw = naturalWidth > 0 ? naturalWidth : 800
    const nh = naturalHeight > 0 ? naturalHeight : 600
    const scale = Math.min(maxW / nw, maxH / nh, 1)
    const width = nw * scale
    const height = nh * scale
    const { x, y } = centerLayerInDocument({ width: docWidth, height: docHeight }, width, height)
    return { x, y, width, height }
}

export function createImageLayerFromDamAsset(z: number, doc: Pick<DocumentModel, 'width' | 'height'>, asset: DamPickerAsset): ImageLayer {
    const nw = asset.width && asset.width > 0 ? asset.width : 800
    const nh = asset.height && asset.height > 0 ? asset.height : 600
    const rect = computePlacedImageRect(doc.width, doc.height, nw, nh)
    return {
        id: generateId(),
        type: 'image',
        name: asset.name || 'Image layer',
        visible: true,
        locked: false,
        z,
        transform: defaultTransform({
            x: rect.x,
            y: rect.y,
            width: rect.width,
            height: rect.height,
        }),
        assetId: asset.id,
        src: asset.file_url,
        naturalWidth: asset.width,
        naturalHeight: asset.height,
        fit: 'cover',
    }
}

export function createDefaultGenerativeImageLayer(
    z: number,
    doc: Pick<DocumentModel, 'width' | 'height'>
): GenerativeImageLayer {
    const rect = computePlacedImageRect(doc.width, doc.height, 1024, 1024)
    return {
        id: generateId(),
        type: 'generative_image',
        name: 'AI image',
        visible: true,
        locked: false,
        z,
        transform: defaultTransform({
            x: rect.x,
            y: rect.y,
            width: rect.width,
            height: rect.height,
        }),
        prompt: {},
        applyBrandDna: true,
        status: 'idle',
        fit: 'cover',
    }
}

/**
 * Generative “background” for guided layouts — same centered frame as {@link createDefaultGenerativeImageLayer}
 * so headline, optional product, and AI layer share one visual center (not top / bottom strips).
 */
export function createBackgroundGenerativeLayer(
    z: number,
    doc: Pick<DocumentModel, 'width' | 'height'>,
    scene: string,
    applyBrandDna: boolean = true
): GenerativeImageLayer {
    const rect = computePlacedImageRect(doc.width, doc.height, 1024, 1024)
    return {
        id: generateId(),
        type: 'generative_image',
        name: 'Background',
        visible: true,
        locked: false,
        z,
        transform: defaultTransform({
            x: rect.x,
            y: rect.y,
            width: rect.width,
            height: rect.height,
        }),
        prompt: { scene },
        applyBrandDna,
        status: 'idle',
        fit: 'cover',
    }
}

/** Headline for guided layouts — centered on the canvas (same anchor as other starter layers). */
export function createHeadlineTextLayer(
    z: number,
    doc: Pick<DocumentModel, 'width' | 'height'>,
    content: string,
    brand?: BrandContext | null
): TextLayer {
    const padX = 48
    const w = Math.max(120, doc.width - padX * 2)
    const h = 120
    const { x, y } = centerLayerInDocument(doc, w, h)
    return {
        id: generateId(),
        type: 'text',
        name: 'Headline',
        visible: true,
        locked: false,
        z,
        transform: defaultTransform({
            x,
            y,
            width: w,
            height: h,
        }),
        content,
        style: {
            fontFamily: defaultTextFontFamilyFromBrand(brand),
            fontSize: 40,
            fontWeight: 700,
            lineHeight: 1.15,
            letterSpacing: -0.5,
            color: '#111827',
            textAlign: 'center',
            verticalAlign: 'top',
        },
    }
}

/**
 * Product / hero image for guided layouts — centered on the canvas (overlaps headline + gen layer).
 */
export function createLayoutProductImageLayer(
    z: number,
    doc: Pick<DocumentModel, 'width' | 'height'>,
    asset: DamPickerAsset
): ImageLayer {
    const nw = asset.width && asset.width > 0 ? asset.width : 800
    const nh = asset.height && asset.height > 0 ? asset.height : 600
    const maxW = doc.width * 0.5
    const maxH = doc.height * 0.42
    const scale = Math.min(maxW / nw, maxH / nh, 1)
    const width = nw * scale
    const height = nh * scale
    const x = (doc.width - width) / 2
    const y = (doc.height - height) / 2
    return {
        id: generateId(),
        type: 'image',
        name: asset.name || 'Product',
        visible: true,
        locked: false,
        z,
        transform: defaultTransform({ x, y, width, height }),
        assetId: asset.id,
        src: asset.file_url,
        naturalWidth: asset.width,
        naturalHeight: asset.height,
        fit: 'contain',
    }
}

const DEFAULT_GUIDED_BACKGROUND_SCENE =
    'Soft atmospheric brand background, subtle depth and texture, clean editorial space for headline and product, professional lighting'

/**
 * Starter stack: centered generative layer, optional centered product image, centered headline (z stacked upward).
 * `startZ` is the z-index for the background (use `0` when replacing the whole stack). Caller should assign
 * `layers: normalizeZ(createGuidedLayoutLayers(...))` so old layers are not left behind.
 */
export function createGuidedLayoutLayers(
    doc: Pick<DocumentModel, 'width' | 'height'>,
    startZ: number,
    options?: {
        backgroundScene?: string
        headlineText?: string
        productAsset?: DamPickerAsset | null
        /** When set, background `prompt.scene` is pre-augmented and DNA toggle is off to avoid double-counting. */
        brandContext?: BrandContext | null
    }
): Layer[] {
    const rawScene = options?.backgroundScene?.trim() || DEFAULT_GUIDED_BACKGROUND_SCENE
    const brand = options?.brandContext
    const scene = brand
        ? buildBrandAugmentedPrompt({ scene: rawScene }, brand)
        : rawScene
    const bg = createBackgroundGenerativeLayer(startZ, doc, scene, !brand)
    const out: Layer[] = [bg]
    let z = startZ + 1
    const product = options?.productAsset ?? null
    const headline = options?.headlineText?.trim() || 'Your headline'
    if (product) {
        out.push(createLayoutProductImageLayer(z, doc, product))
        z += 1
    }
    out.push(createHeadlineTextLayer(z, doc, headline, brand))
    return out
}

/** Map a simple textarea line into structured prompt (v1). */
export function scenePromptFromTextInput(text: string): GenerativePrompt {
    const trimmed = text.trim()
    return trimmed ? { scene: trimmed } : {}
}

export const GENERATIVE_PREVIOUS_RESULTS_MAX = 20

/**
 * Flatten structured prompt → provider prompt string (core engine for later expansion).
 */
export function buildPromptString(prompt: GenerativePrompt): string {
    return [
        prompt.scene,
        prompt.subject?.description,
        prompt.style?.editorial,
        prompt.lighting?.look,
        prompt.composition?.style,
        prompt.brand_hints?.tone ? `Brand tone alignment: ${prompt.brand_hints.tone}` : undefined,
        prompt.brand_hints?.palette ? `Palette: ${prompt.brand_hints.palette}` : undefined,
    ]
        .filter(Boolean)
        .join(', ')
}

/**
 * Short human-readable summary for the properties panel (structured fields first, then scene).
 */
export function buildPromptPreviewSummary(prompt: GenerativePrompt): string {
    const parts: string[] = []
    const pushTrim = (s: string, max: number) => {
        const t = s.trim()
        if (!t) {
            return
        }
        parts.push(t.length > max ? `${t.slice(0, max - 1)}…` : t)
    }
    if (prompt.style?.editorial) {
        pushTrim(prompt.style.editorial, 42)
    }
    if (prompt.lighting?.look) {
        pushTrim(prompt.lighting.look, 36)
    }
    if (prompt.composition?.style) {
        pushTrim(prompt.composition.style, 36)
    }
    if (prompt.brand_hints?.tone) {
        pushTrim(`Tone: ${prompt.brand_hints.tone}`, 32)
    }
    if (prompt.brand_hints?.palette) {
        pushTrim(`Palette: ${prompt.brand_hints.palette}`, 32)
    }
    if (parts.length > 0) {
        return parts.slice(0, 5).join(' · ')
    }
    const scene = (prompt.scene ?? '').trim()
    if (!scene) {
        return '—'
    }
    return scene.length > 120 ? `${scene.slice(0, 117)}…` : scene
}

const MAX_ESTIMATED_BRAND_ALIGNMENT_SCORE = 85

export type BuildBrandAugmentedPromptOptions = {
    /** Number of DAM reference images attached (adds lightweight prompt influence). */
    referenceCount?: number
}

/**
 * Hierarchical brand weighting: labeled clauses + scene last, then optional reference line.
 */
export function buildBrandAugmentedPrompt(
    prompt: GenerativePrompt,
    brand?: BrandContext | null,
    options?: BuildBrandAugmentedPromptOptions
): string {
    const base = buildPromptString(prompt)
    const refCount = options?.referenceCount ?? 0
    const refSuffix =
        refCount > 0
            ? `Style inspired by ${refCount} reference image${refCount > 1 ? 's' : ''}`
            : ''

    if (!brand) {
        return [base, refSuffix].filter(Boolean).join('. ')
    }

    const parts = [
        brand.visual_style ? `Brand style: ${brand.visual_style}` : '',
        brand.archetype ? `Brand archetype: ${brand.archetype}` : '',
        brand.tone?.length ? `Tone: ${brand.tone.join(', ')}` : '',
        brand.colors?.length ? `Color palette: ${brand.colors.join(', ')}` : '',
        base ? `Scene: ${base}` : '',
    ].filter(Boolean)

    if (refSuffix) {
        parts.push(refSuffix)
    }

    return parts.join('. ')
}

/** Lightweight v1 alignment hint (replace with real scoring later). */
export function estimateBrandScore(
    prompt: GenerativePrompt,
    brand: BrandContext | null | undefined
): BrandScore {
    let score = 50
    const scene = (prompt.scene ?? '').toLowerCase()
    const hintsTone = (prompt.brand_hints?.tone ?? '').toLowerCase()
    const hintsPalette = (prompt.brand_hints?.palette ?? '').toLowerCase()
    if (brand?.tone?.[0]) {
        const t = String(brand.tone[0]).toLowerCase()
        if (scene.includes(t) || hintsTone.includes(t)) {
            score += 20
        }
    }
    if (brand?.visual_style) {
        const vs = String(brand.visual_style).toLowerCase()
        if (scene.includes(vs)) {
            score += 20
        }
    }
    if (brand?.colors?.length && hintsPalette) {
        // Light signal that palette hints were applied.
        score += 10
    }
    score = Math.min(score, MAX_ESTIMATED_BRAND_ALIGNMENT_SCORE)
    return {
        score,
        feedback: score < 60 ? ['Try aligning more with brand tone'] : [],
    }
}

/**
 * API size bucket matching aspect ratio (landscape / portrait / square).
 * Uses the layer frame when generating so output matches the on-canvas box.
 */
export function generationSizeFromDimensions(width: number, height: number): string {
    const dw = Math.max(1, Math.round(width))
    const dh = Math.max(1, Math.round(height))
    if (dw > dh) {
        return '1792x1024'
    }
    if (dh > dw) {
        return '1024x1792'
    }
    return '1024x1024'
}

/** Size bucket from the layer’s on-canvas frame (matches aspect of the generative layer box). */
export function generativeLayerToGenerationSize(layer: Pick<GenerativeImageLayer, 'transform'>): string {
    return generationSizeFromDimensions(layer.transform.width, layer.transform.height)
}

/** @deprecated Prefer generativeLayerToGenerationSize for generation; kept for callers that only have doc. */
export function documentToGenerationSize(doc: Pick<DocumentModel, 'width' | 'height'>): string {
    return generationSizeFromDimensions(doc.width, doc.height)
}

export function convertGenerativeLayerToImage(
    doc: DocumentModel,
    layerId: string
): { doc: DocumentModel; newLayerId: string } | null {
    const layer = doc.layers.find((l) => l.id === layerId)
    if (!layer || layer.type !== 'generative_image' || !layer.resultSrc) {
        return null
    }
    const imageLayer: ImageLayer = {
        id: generateId(),
        type: 'image',
        name: layer.name?.replace(/^AI\s+/i, '') || 'Image layer',
        visible: layer.visible,
        locked: layer.locked,
        z: layer.z,
        blendMode: layer.blendMode,
        /* Preserve frame + rotation + scale from the generative layer verbatim. */
        transform: { ...layer.transform },
        src: layer.resultSrc,
        fit: layer.fit ?? 'cover',
    }
    return {
        doc: {
            ...doc,
            layers: normalizeZ(doc.layers.filter((l) => l.id !== layerId).concat(imageLayer)),
            updated_at: new Date().toISOString(),
        },
        newLayerId: imageLayer.id,
    }
}

const AUTO_FIT_MIN = 8

/**
 * Shrinks font size until text fits a box (simple decrement loop; off-DOM measurement).
 */
export function computeAutoFitTextFontSize(
    text: string,
    boxWidth: number,
    boxHeight: number,
    startFontSize: number,
    style: Pick<TextLayer['style'], 'fontFamily' | 'fontWeight' | 'lineHeight' | 'letterSpacing' | 'textAlign'>
): number {
    if (typeof document === 'undefined') {
        return Math.max(AUTO_FIT_MIN, startFontSize)
    }
    const w = Math.max(1, Math.round(boxWidth))
    const h = Math.max(1, Math.round(boxHeight))
    const lh = style.lineHeight ?? 1.25
    const ls = style.letterSpacing ?? 0
    const fw = style.fontWeight ?? 400
    const ta = style.textAlign ?? 'left'

    const div = document.createElement('div')
    div.style.position = 'absolute'
    div.style.visibility = 'hidden'
    div.style.left = '0'
    div.style.top = '0'
    div.style.width = `${w}px`
    div.style.boxSizing = 'border-box'
    div.style.fontFamily = style.fontFamily
    div.style.fontWeight = String(fw)
    div.style.lineHeight = String(lh)
    div.style.letterSpacing = `${ls}px`
    div.style.textAlign = ta
    div.style.whiteSpace = 'pre-wrap'
    div.style.wordBreak = 'break-word'
    div.textContent = text
    document.body.appendChild(div)

    let size = Math.max(AUTO_FIT_MIN, Math.round(startFontSize))
    div.style.fontSize = `${size}px`
    let guard = 0
    while (guard < 500 && size > AUTO_FIT_MIN) {
        if (div.scrollHeight <= h + 1 && div.scrollWidth <= w + 1) {
            break
        }
        size -= 1
        div.style.fontSize = `${size}px`
        guard++
    }
    document.body.removeChild(div)
    return size
}

/** CSS `background` for a fill layer (solid or linear gradient, including legacy one-color gradients). */
export function fillLayerBackgroundCss(layer: FillLayer): string {
    if (layer.fillKind === 'solid') {
        return layer.color
    }
    const angle = layer.gradientAngleDeg ?? 180
    const explicit =
        layer.gradientStartColor !== undefined || layer.gradientEndColor !== undefined
    if (explicit) {
        const start = layer.gradientStartColor ?? 'transparent'
        const end = layer.gradientEndColor ?? layer.color ?? '#6366f1'
        return `linear-gradient(${angle}deg, ${start}, ${end})`
    }
    return `linear-gradient(${angle}deg, ${layer.color}, transparent)`
}

/** Resolved gradient stops for UI (legacy layers only had {@link FillLayer.color} → transparent). */
export function resolvedFillGradientStops(layer: FillLayer): { start: string; end: string } {
    if (layer.fillKind !== 'gradient') {
        return { start: layer.color, end: layer.color }
    }
    if (layer.gradientStartColor === undefined && layer.gradientEndColor === undefined) {
        return { start: layer.color, end: 'transparent' }
    }
    return {
        start: layer.gradientStartColor ?? 'transparent',
        end: layer.gradientEndColor ?? layer.color ?? '#6366f1',
    }
}

export function createFillLayer(
    z: number,
    doc: Pick<DocumentModel, 'width' | 'height'>,
    opts: {
        color: string
        fillKind?: 'solid' | 'gradient'
        gradientStartColor?: string
        gradientEndColor?: string
    }
): FillLayer {
    const fillKind = opts.fillKind ?? 'gradient'
    const base: FillLayer = {
        id: generateId(),
        type: 'fill',
        name: fillKind === 'solid' ? 'Solid fill' : 'Gradient fill',
        visible: true,
        locked: false,
        z,
        transform: defaultTransform({
            x: 0,
            y: 0,
            width: doc.width,
            height: doc.height,
        }),
        fillKind,
        color: opts.color,
        gradientAngleDeg: 180,
    }
    if (fillKind === 'gradient') {
        base.gradientStartColor = opts.gradientStartColor ?? 'transparent'
        base.gradientEndColor = opts.gradientEndColor ?? opts.color
    }
    return base
}

export function createDefaultTextLayer(
    z: number,
    doc: Pick<DocumentModel, 'width' | 'height'>,
    brand?: BrandContext | null
): TextLayer {
    const defaultWidth = 300
    const defaultHeight = 120
    const { x, y } = centerLayerInDocument(doc, defaultWidth, defaultHeight)
    return {
        id: generateId(),
        type: 'text',
        name: 'Text layer',
        visible: true,
        locked: false,
        z,
        transform: defaultTransform({
            x,
            y,
            width: defaultWidth,
            height: defaultHeight,
        }),
        content: 'Double-click to edit',
        style: {
            fontFamily: defaultTextFontFamilyFromBrand(brand),
            fontSize: 28,
            fontWeight: 600,
            lineHeight: 1.25,
            letterSpacing: 0,
            color: '#111827',
            textAlign: 'center',
            verticalAlign: 'middle',
        },
    }
}

export function cloneLayer(layer: Layer): Layer {
    const id = generateId()
    if (layer.type === 'text') {
        return {
            ...layer,
            id,
            name: layer.name ? `${layer.name} copy` : 'Text layer copy',
            transform: { ...layer.transform },
            style: { ...layer.style },
            previousText: undefined,
        }
    }
    if (layer.type === 'generative_image') {
        return {
            ...layer,
            id,
            name: layer.name ? `${layer.name} copy` : 'AI image copy',
            transform: { ...layer.transform },
            prompt: { ...layer.prompt },
            negativePrompt: layer.negativePrompt ? [...layer.negativePrompt] : undefined,
            referenceAssetIds: layer.referenceAssetIds ? [...layer.referenceAssetIds] : undefined,
            applyBrandDna: layer.applyBrandDna,
            previousResults: layer.previousResults ? [...layer.previousResults] : undefined,
            status: layer.resultSrc ? 'done' : 'idle',
            variationPending: undefined,
            variationBatchSize: undefined,
            variationResults: undefined,
        }
    }
    if (layer.type === 'fill') {
        return {
            ...layer,
            id,
            name: layer.name ? `${layer.name} copy` : 'Fill copy',
            transform: { ...layer.transform },
        }
    }
    return {
        ...layer,
        id,
        name: layer.name ? `${layer.name} copy` : 'Image layer copy',
        transform: { ...layer.transform },
    }
}
