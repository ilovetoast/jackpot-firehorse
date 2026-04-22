/**
 * Generative asset editor — JSON document model (source of truth).
 */

export type DocumentPreset = 'instagram_post' | 'web_banner' | 'custom'

/**
 * Narrow roles for Studio "Apply to all versions" (Phase 3). Set on layers from template materialization
 * when the blueprint maps cleanly; optional on older documents (server falls back to name heuristics).
 */
export type StudioSyncRole = 'headline' | 'subheadline' | 'cta' | 'logo' | 'badge' | 'disclaimer'

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
    /**
     * Creative intent from the template wizard (`postGoal` id + optional `keyMessage`).
     * See `wizardBrief.ts` for canonical goal ids. Safe to omit on legacy documents.
     */
    studioBrief?: {
        postGoal: string
        keyMessage?: string
    }
    layers: Layer[]
    /**
     * Layer groups. Groups are flat (no nested groups in v1 — a layer can
     * belong to at most one group) so selection/transform math stays simple.
     * Missing on old documents — the editor treats an unset value as `[]` via
     * {@link migrateDocumentIfNeeded}.
     */
    groups?: Group[]
    /**
     * Optional Studio timeline: total composition length for video layers / export.
     * Extended without breaking older clients.
     */
    studio_timeline?: {
        duration_ms: number
    }
    created_at?: string
    updated_at?: string
}

/**
 * A named collection of layers that move/resize/toggle together. The group is
 * the atom of selection in the editor — clicking any member selects the whole
 * group and transform operations apply proportionally to every member.
 *
 * - `memberIds` is ordered; ordering is used only by the layer panel UI.
 * - `z` isn't tracked here; each member layer keeps its own z and the editor
 *   re-normalizes so group members stay contiguous when the group is moved in
 *   the stack.
 * - Masks target groups by id (see {@link MaskLayer.groupId}).
 */
export type Group = {
    id: string
    name: string
    memberIds: string[]
    /** When locked, all member layers behave as locked (drag/resize disabled). */
    locked: boolean
    /** When true, the layer panel collapses members under a single row. */
    collapsed: boolean
}

export type BaseLayer = {
    id: string
    type: 'image' | 'text' | 'generative_image' | 'fill' | 'mask' | 'video'
    name?: string
    visible: boolean
    locked: boolean
    z: number
    /**
     * When set, this layer belongs to a {@link Group} with the matching id.
     * Drag/resize/visibility toggles propagate to every other layer sharing
     * this id. Cleared on ungroup.
     */
    groupId?: string
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
    /** When set, cross-version sync can target this layer by semantic role. */
    studioSyncRole?: StudioSyncRole
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
        /** Last failed edit message (canvas + panel). */
        lastError?: string
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
        /**
         * Outline stroke width in px. Applied via `-webkit-text-stroke` in the
         * renderer. Undefined / 0 = no stroke. Used by the "ghost" word in a
         * ghost+filled headline pair and anywhere else a template author wants
         * an outlined-text effect.
         */
        strokeWidth?: number
        /** Stroke color. Defaults to {@link TextLayer.style.color} when unset. */
        strokeColor?: string
    }
}

/** Server-built list for FontFace + /api/assets/{id}/file (same-origin); asset IDs from Brand DNA only. */
export type EditorFontFaceSource = {
    family: string
    /** Integer (legacy) or UUID string — matches {@link Asset} keys. */
    asset_id: number | string
    weight: string
    style: string
    /** DNA-linked faces omit this; Fonts-category uploads use `library`. */
    source?: 'dna' | 'library'
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

/**
 * Declarative text-boost preset. Applied on top of `FillLayer.fillKind`/`gradientStartColor`
 * etc. at render time so the user sees a brand-derived scrim (or a preset they chose)
 * without having to hand-author gradient stops.
 *
 * - `solid`           → flat color wash at `textBoostOpacity`.
 * - `gradient_bottom` → fully-transparent at top, `textBoostColor` at bottom.
 * - `gradient_top`    → `textBoostColor` at top, transparent at bottom.
 * - `radial`          → vignette, centered, `textBoostColor` at edges.
 *
 * `source='auto'` means the layer will re-infer when the brand changes (e.g. the
 * brand primary color gets updated). `source='manual'` locks the user's choice so
 * later brand edits don't wipe it out.
 */
export type TextBoostStyle = 'solid' | 'gradient_bottom' | 'gradient_top' | 'radial'

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
    /**
     * When set to 'text_boost', this fill layer is a semantic scrim behind
     * headline/body copy. We treat it specially so the properties panel can
     * expose a preset picker (solid / gradient bottom-up / top-down / radial)
     * instead of forcing users to hand-author gradient stops, and so the
     * renderer can honor the `textBoost*` fields below over `fillKind`.
     *
     * Undefined / other values = ordinary fill layer; no special behavior.
     */
    kind?: 'text_boost'
    /**
     * CTA button / pill backgrounds from layout blueprints — used for brand-color
     * defaults and quick swatches in the properties panel.
     */
    fillRole?: 'cta_button'
    /** Preset style for text-boost rendering. */
    textBoostStyle?: TextBoostStyle
    /** Accent color driving the scrim (hex `#rrggbb`). Defaults to brand primary. */
    textBoostColor?: string
    /** Scrim opacity, 0..1. Applied to the color stop(s). */
    textBoostOpacity?: number
    /** Where this preset came from — auto-inferred from brand DNA, or user-locked. */
    textBoostSource?: 'auto' | 'manual'
    /**
     * Outline border width in px. Rendered as a CSS border on the fill div.
     * Undefined / 0 = no border. Combined with {@link borderStrokeColor} and
     * {@link borderRadius} this lets a "fill" layer act as a hollow frame
     * (e.g. the holding-shape primitive in the recipe engine).
     */
    borderStrokeWidth?: number
    /** Border color. Defaults to the fill color when unset. */
    borderStrokeColor?: string
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
    /** Last failed generate / variation message (canvas overlay). */
    lastError?: string
}

/**
 * Mask layer — clips the visibility of some target layer(s) beneath it.
 *
 * Masks are not visible themselves at export time; they only affect the alpha
 * of their target(s). The renderer wraps the target layer(s) in a container
 * whose `mask-image` is built from the mask's `shape` / gradient fields. In
 * the editor canvas we also paint a dashed rectangle so the user can see and
 * manipulate the mask's extent.
 *
 * Target semantics:
 *   - 'below_one'  → clips only one layer beneath this mask: when the mask
 *                    shares a {@link BaseLayer.groupId} with other layers, the
 *                    target is the group member with the greatest z that is
 *                    still strictly below the mask (the compositing "layer
 *                    directly under" the mask). Otherwise the target is the
 *                    first non-mask layer below the mask in global z-order.
 *   - 'below_all'  → clips every layer beneath this mask.
 *   - 'group'      → clips all members of {@link groupId}.
 */
/** Raster/video asset placed on the canvas (Studio: AI video result or uploaded clip). */
export type VideoLayer = BaseLayer & {
    type: 'video'
    assetId?: string
    src: string
    fit?: 'cover' | 'contain' | 'fill'
    naturalWidth?: number
    naturalHeight?: number
    /**
     * V1: when multiple video layers exist, the one used as the base for baked export.
     * If unset, export falls back to the lowest-`z` visible video layer.
     */
    primaryForExport?: boolean
    /** Provenance for Studio-generated or inserted clips (properties display + debugging). */
    studioProvenance?: {
        sourceMode?: string
        provider?: string
        model?: string
        jobId?: string
        outputAssetId?: string
        durationMs?: number
    }
    /** Timeline within the composition (V1: start/end relative to comp timeline, single playhead). */
    timeline?: {
        start_ms: number
        end_ms: number
        trim_in_ms?: number
        trim_out_ms?: number
        muted?: boolean
    }
}

export type MaskLayer = BaseLayer & {
    type: 'mask'
    shape: 'rect' | 'ellipse' | 'rounded_rect' | 'gradient_linear' | 'gradient_radial'
    /** For 'rounded_rect' shapes — corner radius in px. */
    radius?: number
    /** For gradient shapes — alpha stops. `offset` ∈ 0..1, `alpha` ∈ 0..1. */
    gradientStops?: Array<{ offset: number; alpha: number }>
    /** For 'gradient_linear' — angle in degrees, 0 = to top. */
    gradientAngle?: number
    /** Soft edge on non-gradient shapes in px. */
    featherPx?: number
    /** When true, mask the outside instead of the inside. */
    invert?: boolean
    target: 'below_one' | 'below_all' | 'group'
    /** Required when `target === 'group'` — references {@link Group.id}. */
    groupId?: string
}

export type Layer = ImageLayer | TextLayer | GenerativeImageLayer | FillLayer | MaskLayer | VideoLayer

const ALLOWED_LAYER_TYPES = new Set(['image', 'text', 'generative_image', 'fill', 'mask', 'video'])

export function isFillLayer(l: Layer): l is FillLayer {
    return l.type === 'fill'
}

/** True for CTA pill / button fill layers (brand color defaults + quick swatches). */
export function isCtaButtonFillLayer(layer: FillLayer): boolean {
    if (layer.fillRole === 'cta_button') {
        return true
    }
    const n = (layer.name ?? '').trim().toLowerCase()

    return n === 'cta button' || n === 'cta pill'
}

export function isMaskLayer(l: Layer): l is MaskLayer {
    return l.type === 'mask'
}

export function isVideoLayer(l: Layer): l is VideoLayer {
    return l.type === 'video'
}

/** Same primary rules as the composition MP4 export job (for preview / publish UI). */
export function getPrimaryVideoLayerForDocumentExport(layers: Layer[]): VideoLayer | null {
    const candidates = layers.filter(
        (l): l is VideoLayer => isVideoLayer(l) && l.visible !== false && Boolean((l.assetId ?? '').toString().trim()) && Boolean((l.src ?? '').trim())
    )
    if (candidates.length === 0) {
        return null
    }
    const primary = candidates.find((v) => v.primaryForExport)
    if (primary) {
        return primary
    }
    return [...candidates].sort((a, b) => (a.z ?? 0) - (b.z ?? 0))[0] ?? null
}

/**
 * Compute the concrete set of mask layers that apply to a given target layer.
 *
 * Rules:
 *   - 'below_one' masks apply to one layer beneath the mask: if the mask has
 *     a `groupId`, the target is the in-group non-mask with the highest z
 *     that is still `< mask.z` (avoids mis-targeting when other document layers
 *     sit between group members in global z). If the mask has no `groupId`,
 *     the target is the first non-mask layer immediately below the mask in
 *     global z-order.
 *   - 'below_all' masks apply to every non-mask layer with z < mask.z.
 *   - 'group' masks apply to every layer whose `groupId` matches the mask's
 *     `groupId`.
 *
 * A target can be affected by multiple masks — we return them in z-order so
 * the renderer can compose them (bottom mask applied first). Hidden masks
 * are skipped.
 */
export function masksAffectingLayer(layer: Layer, allLayers: Layer[]): MaskLayer[] {
    if (layer.type === 'mask') return []
    const masks = allLayers.filter((l): l is MaskLayer => isMaskLayer(l) && l.visible)
    if (masks.length === 0) return []
    // Sort all layers by z asc so we can scan downward from each mask's z.
    const byZ = [...allLayers].sort((a, b) => (Number(a.z) || 0) - (Number(b.z) || 0))
    const result: MaskLayer[] = []
    for (const m of masks) {
        if (m.target === 'below_one') {
            const gid = m.groupId
            if (gid) {
                const mZ = Number(m.z) || 0
                let best: Layer | null = null
                let bestZ = -Infinity
                for (const cand of allLayers) {
                    if (cand.type === 'mask') {
                        continue
                    }
                    if (cand.groupId !== gid) {
                        continue
                    }
                    const cz = Number(cand.z) || 0
                    if (cz >= mZ) {
                        continue
                    }
                    if (cz > bestZ) {
                        bestZ = cz
                        best = cand
                    }
                }
                if (best !== null && best.id === layer.id) {
                    result.push(m)
                }
            } else {
                // Global z: first non-mask immediately below this mask.
                const idx = byZ.findIndex((l) => l.id === m.id)
                for (let i = idx - 1; i >= 0; i--) {
                    const candidate = byZ[i]
                    if (candidate.type === 'mask') {
                        continue
                    }
                    if (candidate.id === layer.id) {
                        result.push(m)
                    }
                    break
                }
            }
        } else if (m.target === 'below_all') {
            const mZ = Number(m.z) || 0
            const lZ = Number(layer.z) || 0
            if (lZ < mZ) result.push(m)
        } else if (m.target === 'group') {
            if (m.groupId && layer.groupId === m.groupId) {
                result.push(m)
            }
        }
    }
    // Sort by mask z ascending so the topmost mask applies last (deepest
    // blending). In CSS `mask-image` with multiple values, the later values
    // are applied last — we build the stacking in that order.
    result.sort((a, b) => (Number(a.z) || 0) - (Number(b.z) || 0))
    return result
}

/**
 * Build a CSS `mask-image` data URL for a single mask clipping a given
 * target rect. The SVG is sized to the target's bounding box so it can be
 * placed with `maskSize: 100% 100%; maskPosition: 0 0`.
 *
 * Keep this deterministic & side-effect free — html-to-image serializes
 * styles at export time and needs the same CSS to reproduce the masked
 * rendering. SVG data URLs are preserved by html-to-image (unlike CSS
 * `mask: paint(...)` or `backdrop-filter`).
 */
export function buildMaskDataUrlForTarget(
    mask: MaskLayer,
    target: { x: number; y: number; width: number; height: number }
): string {
    const w = Math.max(1, target.width)
    const h = Math.max(1, target.height)
    const mx = mask.transform.x - target.x
    const my = mask.transform.y - target.y
    const mw = Math.max(1, mask.transform.width)
    const mh = Math.max(1, mask.transform.height)
    const feather = Math.max(0, mask.featherPx ?? 0)
    const filterId = 'mf'
    const filterAttr = feather > 0 ? ` filter="url(#${filterId})"` : ''
    const bgFill = mask.invert ? 'white' : 'black'
    const fgFill = mask.invert ? 'black' : 'white'

    let shapeNode = ''
    switch (mask.shape) {
        case 'rect':
            shapeNode = `<rect x="${mx}" y="${my}" width="${mw}" height="${mh}" fill="${fgFill}"${filterAttr} />`
            break
        case 'rounded_rect': {
            const r = Math.max(0, mask.radius ?? 12)
            shapeNode = `<rect x="${mx}" y="${my}" width="${mw}" height="${mh}" rx="${r}" ry="${r}" fill="${fgFill}"${filterAttr} />`
            break
        }
        case 'ellipse': {
            const cx = mx + mw / 2
            const cy = my + mh / 2
            const rx = mw / 2
            const ry = mh / 2
            shapeNode = `<ellipse cx="${cx}" cy="${cy}" rx="${rx}" ry="${ry}" fill="${fgFill}"${filterAttr} />`
            break
        }
        case 'gradient_linear': {
            // Build a linear gradient mapping alpha stops onto the mask rect.
            // The angle is degrees clockwise from up. We approximate by
            // rotating the gradient within the rect's viewbox.
            const stops = (mask.gradientStops ?? [
                { offset: 0, alpha: 1 },
                { offset: 1, alpha: 0 },
            ])
                .map((s) => `<stop offset="${clamp01(s.offset) * 100}%" stop-color="${fgFill}" stop-opacity="${mask.invert ? 1 - clamp01(s.alpha) : clamp01(s.alpha)}" />`)
                .join('')
            const angle = ((mask.gradientAngle ?? 0) % 360 + 360) % 360
            // SVG gradients use x1/y1→x2/y2. Convert angle (0=up) to endpoints.
            const rad = (angle - 90) * (Math.PI / 180)
            const x1 = 0.5 - 0.5 * Math.cos(rad)
            const y1 = 0.5 - 0.5 * Math.sin(rad)
            const x2 = 0.5 + 0.5 * Math.cos(rad)
            const y2 = 0.5 + 0.5 * Math.sin(rad)
            shapeNode = `<defs><linearGradient id="mg" x1="${x1}" y1="${y1}" x2="${x2}" y2="${y2}">${stops}</linearGradient></defs><rect x="${mx}" y="${my}" width="${mw}" height="${mh}" fill="url(#mg)"${filterAttr} />`
            break
        }
        case 'gradient_radial': {
            const stops = (mask.gradientStops ?? [
                { offset: 0, alpha: 1 },
                { offset: 1, alpha: 0 },
            ])
                .map((s) => `<stop offset="${clamp01(s.offset) * 100}%" stop-color="${fgFill}" stop-opacity="${mask.invert ? 1 - clamp01(s.alpha) : clamp01(s.alpha)}" />`)
                .join('')
            shapeNode = `<defs><radialGradient id="mg" cx="0.5" cy="0.5" r="0.5">${stops}</radialGradient></defs><rect x="${mx}" y="${my}" width="${mw}" height="${mh}" fill="url(#mg)"${filterAttr} />`
            break
        }
    }

    const filterDef = feather > 0
        ? `<defs><filter id="${filterId}" x="-20%" y="-20%" width="140%" height="140%"><feGaussianBlur stdDeviation="${feather}" /></filter></defs>`
        : ''

    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none">${filterDef}<rect width="${w}" height="${h}" fill="${bgFill}" />${shapeNode}</svg>`
    return `url("data:image/svg+xml;utf8,${encodeURIComponent(svg)}")`
}

function clamp01(n: number): number {
    if (!isFinite(n)) return 0
    return Math.max(0, Math.min(1, n))
}

/**
 * Build the full style object (including vendor prefixes) to apply a stack
 * of masks to a target layer. Returns an empty object when no masks apply.
 */
export function buildMaskStyleForLayer(
    layer: Layer,
    allLayers: Layer[]
): Partial<{
    maskImage: string
    WebkitMaskImage: string
    maskRepeat: string
    WebkitMaskRepeat: string
    maskSize: string
    WebkitMaskSize: string
    maskPosition: string
    WebkitMaskPosition: string
    maskComposite: string
    WebkitMaskComposite: string
}> {
    const masks = masksAffectingLayer(layer, allLayers)
    if (masks.length === 0) return {}
    const target = {
        x: layer.transform.x,
        y: layer.transform.y,
        width: layer.transform.width,
        height: layer.transform.height,
    }
    const urls = masks.map((m) => buildMaskDataUrlForTarget(m, target))
    const maskImage = urls.join(', ')
    return {
        maskImage,
        WebkitMaskImage: maskImage,
        maskRepeat: masks.map(() => 'no-repeat').join(', '),
        WebkitMaskRepeat: masks.map(() => 'no-repeat').join(', '),
        maskSize: masks.map(() => '100% 100%').join(', '),
        WebkitMaskSize: masks.map(() => '100% 100%').join(', '),
        maskPosition: masks.map(() => '0 0').join(', '),
        WebkitMaskPosition: masks.map(() => '0 0').join(', '),
        // Multiple masks intersect (AND). When a layer has multiple masks we
        // want "opacity where every mask says opaque" → `intersect`.
        maskComposite: masks.length > 1 ? 'intersect' : 'add',
        WebkitMaskComposite: masks.length > 1 ? 'source-in' : 'source-over',
    }
}

/**
 * Normalize a document loaded from the API / disk into the current shape.
 * Handles schema drift so old drafts don't crash when read by newer code:
 *   - `groups` defaults to [].
 *   - `Layer.groupId` is left undefined if absent (no migration needed).
 *   - Text-boost fill layers with no `textBoost*` fields get sane auto defaults
 *     so the properties panel controls have values to render.
 *
 * Non-mutating — returns a new DocumentModel. Call on load only; don't run in
 * hot paths.
 */
export function migrateDocumentIfNeeded(doc: DocumentModel): DocumentModel {
    let mutated = false
    const nextGroups = Array.isArray(doc.groups) ? doc.groups : []
    if (!Array.isArray(doc.groups)) {
        mutated = true
    }

    const nextLayers = doc.layers.map((layer) => {
        if (isFillLayer(layer) && layer.kind === 'text_boost') {
            if (layer.textBoostStyle && typeof layer.textBoostOpacity === 'number' && layer.textBoostSource) {
                return layer
            }
            mutated = true
            return {
                ...layer,
                textBoostStyle: layer.textBoostStyle ?? 'gradient_bottom',
                textBoostColor: layer.textBoostColor ?? '#000000',
                textBoostOpacity: typeof layer.textBoostOpacity === 'number' ? layer.textBoostOpacity : 0.7,
                textBoostSource: layer.textBoostSource ?? 'auto',
            } as FillLayer
        }
        if (isFillLayer(layer) && layer.fillRole !== 'cta_button' && isCtaButtonFillLayer(layer)) {
            mutated = true
            return { ...layer, fillRole: 'cta_button' as const }
        }
        return layer
    })

    if (!mutated) return doc
    return {
        ...doc,
        layers: nextLayers as Layer[],
        groups: nextGroups,
    }
}

/**
 * Group helpers. Kept in documentModel.ts (alongside the type) so every call
 * site imports the same canonical logic — selection handlers, layer panel UI,
 * and persistence all go through these.
 */
export function findGroupForLayer(doc: DocumentModel, layerId: string): Group | null {
    const layer = doc.layers.find((l) => l.id === layerId)
    if (!layer?.groupId) return null
    return doc.groups?.find((g) => g.id === layer.groupId) ?? null
}

export function groupMemberLayers(doc: DocumentModel, groupId: string): Layer[] {
    const g = doc.groups?.find((x) => x.id === groupId)
    if (!g) return []
    const memberSet = new Set(g.memberIds)
    return doc.layers.filter((l) => memberSet.has(l.id))
}

/**
 * Create a new group from the given member layer ids. Returns the updated
 * document and the new group id. Only layers that aren't already in a group
 * are eligible — trying to group across existing groups is a no-op because
 * v1 groups are flat.
 */
export function createGroup(
    doc: DocumentModel,
    memberIds: string[],
    name?: string
): { doc: DocumentModel; groupId: string | null } {
    const existing = new Set(doc.layers.filter((l) => !!l.groupId).map((l) => l.id))
    const eligible = memberIds.filter((id) => !existing.has(id) && doc.layers.some((l) => l.id === id))
    if (eligible.length < 2) {
        return { doc, groupId: null }
    }
    const groupId = generateId()
    const newGroup: Group = {
        id: groupId,
        name: name ?? `Group ${((doc.groups?.length ?? 0) + 1)}`,
        memberIds: eligible,
        locked: false,
        collapsed: false,
    }
    const memberSet = new Set(eligible)
    return {
        doc: {
            ...doc,
            groups: [...(doc.groups ?? []), newGroup],
            layers: doc.layers.map((l) => (memberSet.has(l.id) ? { ...l, groupId } : l)),
            updated_at: new Date().toISOString(),
        },
        groupId,
    }
}

/**
 * Dissolve a group: removes the {@link Group} entry and strips `groupId`
 * from every member. Layers retain all other properties (z, transform, …).
 */
export function ungroup(doc: DocumentModel, groupId: string): DocumentModel {
    if (!doc.groups?.some((g) => g.id === groupId)) {
        return doc
    }
    return {
        ...doc,
        groups: (doc.groups ?? []).filter((g) => g.id !== groupId),
        layers: doc.layers.map((l) => (l.groupId === groupId ? { ...l, groupId: undefined } : l)),
        updated_at: new Date().toISOString(),
    }
}

/** Patch the group's name/collapsed/locked fields. No-op if group missing. */
export function updateGroup(
    doc: DocumentModel,
    groupId: string,
    patch: Partial<Pick<Group, 'name' | 'collapsed' | 'locked'>>
): DocumentModel {
    const groups = doc.groups ?? []
    if (!groups.some((g) => g.id === groupId)) return doc
    return {
        ...doc,
        groups: groups.map((g) => (g.id === groupId ? { ...g, ...patch } : g)),
        updated_at: new Date().toISOString(),
    }
}

/**
 * Add a layer to a group (or move it between groups). If the layer is already
 * in another group, it's removed from that group first.
 */
export function addLayerToGroup(doc: DocumentModel, layerId: string, groupId: string): DocumentModel {
    const groups = doc.groups ?? []
    if (!groups.some((g) => g.id === groupId)) return doc
    return {
        ...doc,
        groups: groups.map((g) => {
            if (g.id === groupId) {
                return g.memberIds.includes(layerId) ? g : { ...g, memberIds: [...g.memberIds, layerId] }
            }
            return { ...g, memberIds: g.memberIds.filter((id) => id !== layerId) }
        }),
        layers: doc.layers.map((l) => (l.id === layerId ? { ...l, groupId } : l)),
        updated_at: new Date().toISOString(),
    }
}

/** Remove a layer from its current group. No-op if ungrouped. */
export function removeLayerFromGroup(doc: DocumentModel, layerId: string): DocumentModel {
    const layer = doc.layers.find((l) => l.id === layerId)
    if (!layer?.groupId) return doc
    const gid = layer.groupId
    const groups = (doc.groups ?? []).map((g) =>
        g.id === gid ? { ...g, memberIds: g.memberIds.filter((id) => id !== layerId) } : g
    )
    // Prune groups that end up with fewer than 2 members — a group of one
    // is meaningless and just pollutes the panel.
    const prunedGroups = groups.filter((g) => g.memberIds.length >= 2)
    const droppedGroupIds = new Set(
        groups.filter((g) => g.memberIds.length < 2).map((g) => g.id)
    )
    return {
        ...doc,
        groups: prunedGroups,
        layers: doc.layers.map((l) => {
            if (l.id === layerId) return { ...l, groupId: undefined }
            if (l.groupId && droppedGroupIds.has(l.groupId)) return { ...l, groupId: undefined }
            return l
        }),
        updated_at: new Date().toISOString(),
    }
}

/**
 * Rect enclosing every member of the given group. Used by snap (subject rect)
 * and by drag/resize math. Returns null if the group has no visible members.
 */
export function unionRectForGroup(doc: DocumentModel, groupId: string): { x: number; y: number; width: number; height: number } | null {
    const members = groupMemberLayers(doc, groupId)
    if (members.length === 0) return null
    let minX = Infinity
    let minY = Infinity
    let maxX = -Infinity
    let maxY = -Infinity
    for (const m of members) {
        const { x, y, width, height } = m.transform
        if (x < minX) minX = x
        if (y < minY) minY = y
        if (x + width > maxX) maxX = x + width
        if (y + height > maxY) maxY = y + height
    }
    if (!isFinite(minX) || !isFinite(minY)) return null
    return { x: minX, y: minY, width: maxX - minX, height: maxY - minY }
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
        groups: [],
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
export function editorBridgeFileUrlForAssetId(assetId: string): string {
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
    // Parse groups[] defensively — old documents won't have this field, and
    // we don't want a stray string/number crashing reducers that iterate the
    // list. Anything shaped wrong gets dropped; the rest is kept verbatim.
    const groupsRaw = Array.isArray(o.groups) ? (o.groups as unknown[]) : []
    const groups: Group[] = groupsRaw
        .filter((g): g is Record<string, unknown> => !!g && typeof g === 'object')
        .map((g) => ({
            id: typeof g.id === 'string' ? g.id : generateId(),
            name: typeof g.name === 'string' ? g.name : 'Group',
            memberIds: Array.isArray(g.memberIds) ? g.memberIds.filter((x): x is string => typeof x === 'string') : [],
            locked: !!g.locked,
            collapsed: !!g.collapsed,
        }))

    let studioBrief: DocumentModel['studioBrief']
    if (o.studioBrief && typeof o.studioBrief === 'object') {
        const sb = o.studioBrief as Record<string, unknown>
        const postGoal = typeof sb.postGoal === 'string' ? sb.postGoal.trim() : ''
        if (postGoal) {
            studioBrief = {
                postGoal,
                keyMessage: typeof sb.keyMessage === 'string' ? sb.keyMessage : undefined,
            }
        }
    }

    let studio_timeline: DocumentModel['studio_timeline']
    if (o.studio_timeline && typeof o.studio_timeline === 'object') {
        const st = o.studio_timeline as Record<string, unknown>
        if (typeof st.duration_ms === 'number' && st.duration_ms > 0) {
            studio_timeline = { duration_ms: st.duration_ms }
        }
    }

    const parsed: DocumentModel = {
        id: typeof o.id === 'string' ? o.id : generateId(),
        width: w,
        height: h,
        preset:
            o.preset === 'instagram_post' || o.preset === 'web_banner' || o.preset === 'custom'
                ? o.preset
                : undefined,
        layers,
        groups,
        studioBrief,
        studio_timeline,
        created_at: typeof o.created_at === 'string' ? o.created_at : undefined,
        updated_at: typeof o.updated_at === 'string' ? o.updated_at : undefined,
    }

    return migrateDocumentIfNeeded(parsed)
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
    // Heuristic: treat obvious logo assets as `contain` so scaling the layer
    // reveals the full mark instead of cropping it. Everything else keeps
    // `cover` for the fill-to-frame behavior heroes and photography want.
    // The asset's initial transform already matches its aspect ratio
    // (via `computePlacedImageRect`) so both values render identically at
    // creation time — this only affects subsequent user resizes.
    const looksLikeLogo = /\blogo|mark|wordmark|brandmark\b/i.test(asset.name || '')
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
        fit: looksLikeLogo ? 'contain' : 'cover',
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
        studioSyncRole: 'headline',
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
 * OpenAI `images/generations` size for gpt-image-1: 1024×1024, 1024×1536, 1536×1024, or auto.
 * Picks the closest aspect bucket to the layer frame (legacy 1792× sizes are rejected by the API).
 */
export function generationSizeFromDimensions(width: number, height: number): string {
    const dw = Math.max(1, Math.round(width))
    const dh = Math.max(1, Math.round(height))
    if (dw > dh) {
        return '1536x1024'
    }
    if (dh > dw) {
        return '1024x1536'
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

/**
 * Factory for a sensible default {@link MaskLayer}. The mask is placed as a
 * full-bleed rectangle covering the whole canvas with the `below_one` target —
 * so dropping it directly above an image layer immediately produces a clean
 * clipped rectangle the user can resize/move.
 *
 * Defaults match the plan's "most common" case:
 *   - shape: ellipse (soft focal crop)
 *   - target: below_one (clips just the layer directly beneath in z-order)
 *   - feather: 16 (looks good at 1080×1080 canvases without being heavy)
 */
export function createDefaultMaskLayer(
    z: number,
    doc: Pick<DocumentModel, 'width' | 'height'>
): MaskLayer {
    const width = Math.round(doc.width * 0.6)
    const height = Math.round(doc.height * 0.6)
    const { x, y } = centerLayerInDocument(doc, width, height)
    return {
        id: generateId(),
        type: 'mask',
        name: 'Mask',
        visible: true,
        locked: false,
        z,
        transform: defaultTransform({ x, y, width, height }),
        shape: 'ellipse',
        radius: 24,
        featherPx: 16,
        invert: false,
        target: 'below_one',
        gradientStops: [
            { offset: 0, alpha: 1 },
            { offset: 1, alpha: 0 },
        ],
        gradientAngle: 0,
    }
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
