/**
 * AdStyleCard
 *
 * Lives in the Brand Guidelines Builder → Expression step. Lets users tune
 * how Studio's ad recipes compose their brand — voice, headline treatment,
 * watermark behavior, footer style, CTA style. Every field is *optional*;
 * leaving a field on "Infer from voice" hands the decision back to the
 * runtime inference in `deriveBrandAdStyle()`.
 *
 * State is persisted via `POST /app/brands/{brand}/ad-style` (see
 * `BrandController::updateAdStyle`). The endpoint merges into
 * `brand.settings.ad_style` so `auth.activeBrand.settings` — which already
 * rides along with every Inertia request — picks it up immediately in the
 * Studio editor. No migration, no builder-draft changes, no extra plumbing.
 *
 * The card is intentionally thin: it does NOT preview recipe output here
 * (that's a big dependency graph). A future follow-up can drop in a recipe
 * thumbnail beside this card. For now the value is:
 *   1. Users can see which ad-composition knobs exist.
 *   2. Users can lock in choices per brand without asking an engineer.
 *   3. Studio recipes immediately honor those choices.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import axios from 'axios'
import { deriveBrandAdStyle, RECIPE_REGISTRY } from '../../Pages/Editor/recipes'
import RecipePreview from './RecipePreview'

const INFER = '__infer__'

/**
 * Canonical preview size for the recipe thumbnails. Square keeps the row
 * tidy across every format category — recipes reflow on their own, so a
 * square snapshot is enough to read "brand feel" at a glance without
 * dedicating screen real estate to a 9:16 column.
 */
const PREVIEW_SIZE = 200

/**
 * Fallback preview content seeded into every preview so empty content slots
 * don't produce an empty-looking card. Each recipe has its own internal
 * placeholder text, but having shared content keeps the whole preview row
 * visually consistent — same "headline/subline/product" feel across every
 * thumbnail, so users compare composition, not copy variance.
 */
const PREVIEW_CONTENT = {
    ghostWord: 'MAKE',
    filledWord: 'IT POP',
    subline: 'Limited edition drop',
    tagline: 'Collection No. 07',
    productName: 'Signature piece',
    productVariant: 'Obsidian',
    cta: 'Shop now',
    url: 'yourbrand.com',
    featureList: ['Feature one', 'Feature two', 'Feature three'],
    dates: [{ label: 'SAT', numeral: '12', detail: 'OCT' }],
    body: 'Headline body copy goes here so spec-heavy recipes have a sample to render.',
}

// Fields rendered in the card. Keep this in sync with the validator in
// BrandController::updateAdStyle + the AdStyleOverrides type in the Studio
// recipe module (brandAdStyle.ts). Order matters — grouped logically.
const FIELDS = [
    {
        key: 'voiceTone',
        label: 'Voice tone',
        hint: 'Seeds defaults for headline, CTA, and watermark when those fields are left on "Infer".',
        options: [
            { value: 'playful', label: 'Playful' },
            { value: 'bold', label: 'Bold' },
            { value: 'heritage', label: 'Heritage / craft' },
            { value: 'technical', label: 'Technical / tech' },
            { value: 'minimal', label: 'Minimal / clean' },
            { value: 'celebratory', label: 'Celebratory / event' },
        ],
    },
    {
        key: 'headlineStyle',
        label: 'Headline style',
        hint: 'Lead headline treatment. "Ghost + filled" is the Shefit signature — two words stacked with an outlined accent.',
        options: [
            { value: 'ghost_filled_pair', label: 'Ghost + filled (stacked outline)' },
            { value: 'filled_single', label: 'Single filled line' },
            { value: 'script_plus_caps', label: 'Script + all-caps (heritage)' },
            { value: 'grunge_stacked', label: 'Grunge stacked display' },
            { value: 'bold_display_stack', label: 'Bold display stack (tech)' },
        ],
    },
    {
        key: 'holdingShapeStyle',
        label: 'Holding shape',
        hint: 'Thin rectangle / pill / ornament that frames product name + variant lines.',
        options: [
            { value: 'hairline_rect', label: 'Hairline rectangle' },
            { value: 'rounded_pill', label: 'Rounded pill' },
            { value: 'double_frame', label: 'Double frame' },
            { value: 'ornamented', label: 'Ornamented (heritage)' },
            { value: 'none', label: 'None' },
        ],
    },
    {
        key: 'watermarkMode',
        label: 'Watermark',
        hint: 'How the brand mark appears behind the composition.',
        options: [
            { value: 'faded_bg', label: 'Faded in background' },
            { value: 'corner_only', label: 'Small corner mark' },
            { value: 'both', label: 'Both (faded + corner)' },
            { value: 'none', label: 'None' },
        ],
    },
    {
        key: 'footerStyle',
        label: 'Footer',
        hint: 'Optional brand lockup bar across the bottom of the ad.',
        options: [
            { value: 'white_bar', label: 'White bar' },
            { value: 'dark_bar', label: 'Dark bar' },
            { value: 'logo_centered', label: 'Centered logo (no bar)' },
            { value: 'none', label: 'None' },
        ],
    },
    {
        key: 'ctaStyle',
        label: 'CTA',
        hint: 'Shape of the call-to-action on ads that include one.',
        options: [
            { value: 'pill_filled', label: 'Filled pill' },
            { value: 'pill_outlined', label: 'Outlined pill' },
            { value: 'underline', label: 'Underlined text' },
            { value: 'none', label: 'None' },
        ],
    },
    {
        key: 'photoTreatment',
        label: 'Photo treatment',
        hint: 'Post-process applied to lifestyle / hero photography in ads.',
        options: [
            { value: 'natural', label: 'Natural (no treatment)' },
            { value: 'duotone_primary', label: 'Duotone (brand primary)' },
            { value: 'tone_mapped', label: 'Tone-mapped' },
            { value: 'grayscale', label: 'Grayscale' },
            { value: 'glow', label: 'Glow (tech reveal)' },
        ],
    },
    {
        key: 'backgroundPreference',
        label: 'Default background',
        hint: 'Fallback background when a recipe doesn\'t have a hero photo available.',
        options: [
            { value: 'solid', label: 'Solid brand hue' },
            { value: 'gradient_linear', label: 'Linear gradient' },
            { value: 'gradient_radial', label: 'Radial gradient' },
            { value: 'photo', label: 'Prefer photography' },
            { value: 'texture', label: 'Textured' },
            { value: 'paper', label: 'Paper (heritage)' },
            { value: 'black', label: 'Black' },
        ],
    },
]

export default function AdStyleCard({
    brandId,
    initialAdStyle,
    // Brand hint fields used purely to drive the live preview. All optional —
    // the card still persists overrides fine without them, users just won't
    // see their brand colors / logo mark in the preview thumbnails.
    brandColors = null,
    brandVoice = null,
    logoAsset = null,
    // Aggregated signals from the brand's reference-ad gallery. Optional —
    // when present, the preview reflects the nudges the reference gallery
    // would apply in Studio, so users see "what their references do" while
    // tuning overrides. User overrides still win over hints.
    referenceHints = null,
}) {
    // Normalize initial value so we never hit a null sub-object.
    const [values, setValues] = useState(() => ({ ...(initialAdStyle || {}) }))
    const [status, setStatus] = useState({ kind: 'idle' })

    // Build the BrandAdStyle *live* from the in-memory `values`, not the
    // persisted settings. This is the whole point of the preview — a
    // dropdown flip should repaint every preview thumbnail instantly, with
    // no round-trip to the server. The debounced persist still fires in the
    // background, but preview + persist are decoupled so the UI never feels
    // laggy.
    //
    // We fake the `settings.ad_style` shape here so `deriveBrandAdStyle`'s
    // existing override-merge path picks up our in-memory values without
    // needing a second code path.
    const previewStyle = useMemo(() => {
        // Strip INFER sentinels so derivation treats them as "not set" and
        // falls back to inferred defaults — matching server-side behavior.
        const cleanValues = {}
        Object.keys(values).forEach((k) => {
            if (values[k] && values[k] !== INFER) cleanValues[k] = values[k]
        })
        return deriveBrandAdStyle(
            {
                id: brandId,
                primary_color: brandColors?.primary_color ?? null,
                secondary_color: brandColors?.secondary_color ?? null,
                accent_color: brandColors?.accent_color ?? null,
                voice: brandVoice ?? null,
                settings: { ad_style: cleanValues },
            },
            logoAsset?.id
                ? {
                      logo: {
                          id: logoAsset.id,
                          name: logoAsset.name ?? '',
                          file_url: logoAsset.file_url ?? logoAsset.preview_url ?? logoAsset.thumbnail_url ?? '',
                          thumbnail_url: logoAsset.thumbnail_url ?? null,
                          width: logoAsset.width ?? null,
                          height: logoAsset.height ?? null,
                      },
                      background_candidates: [],
                  }
                : null,
            referenceHints,
        )
    }, [values, brandId, brandColors, brandVoice, logoAsset, referenceHints])

    // Debounce persist: coalesce rapid dropdown flips into a single PATCH.
    // We intentionally send the full current `values` object (minus INFER
    // sentinels) rather than a diff — server-side merges anyway, and this
    // way a flaky connection can never leave the record half-applied.
    const pendingRef = useRef(null)
    const saveTimerRef = useRef(null)

    const persist = useCallback((nextValues) => {
        if (saveTimerRef.current) clearTimeout(saveTimerRef.current)
        pendingRef.current = nextValues
        setStatus({ kind: 'pending' })
        saveTimerRef.current = setTimeout(async () => {
            const payload = { ...pendingRef.current }
            // Strip the INFER sentinel — server expects either omitted keys
            // or concrete enum values, never the sentinel itself.
            Object.keys(payload).forEach((k) => {
                if (payload[k] === INFER) delete payload[k]
            })
            try {
                await axios.post(`/app/brands/${brandId}/ad-style`, { ad_style: payload })
                setStatus({ kind: 'saved', at: Date.now() })
            } catch (err) {
                setStatus({
                    kind: 'error',
                    message: err?.response?.data?.message || err?.message || 'Save failed',
                })
            }
        }, 500)
    }, [brandId])

    // Clear pending timer on unmount so we don't fire a stale save.
    useEffect(() => {
        return () => {
            if (saveTimerRef.current) clearTimeout(saveTimerRef.current)
        }
    }, [])

    const handleChange = useCallback((key, rawValue) => {
        setValues((prev) => {
            const next = { ...prev }
            if (rawValue === INFER) delete next[key]
            else next[key] = rawValue
            persist(next)
            return next
        })
    }, [persist])

    const handleReset = useCallback(() => {
        setValues({})
        setStatus({ kind: 'pending' })
        if (saveTimerRef.current) clearTimeout(saveTimerRef.current)
        axios
            .post(`/app/brands/${brandId}/ad-style`, { ad_style: null })
            .then(() => setStatus({ kind: 'saved', at: Date.now() }))
            .catch((err) => setStatus({
                kind: 'error',
                message: err?.response?.data?.message || err?.message || 'Reset failed',
            }))
    }, [brandId])

    const overrideCount = useMemo(() => {
        return Object.values(values).filter((v) => v && v !== INFER).length
    }, [values])

    const statusLabel = (() => {
        if (status.kind === 'pending') return 'Saving…'
        if (status.kind === 'saved') return 'Saved'
        if (status.kind === 'error') return status.message
        return null
    })()

    return (
        <div className="rounded-2xl border border-white/20 bg-white/5 p-6">
            <div className="flex items-start justify-between gap-4 mb-3">
                <div>
                    <h3 className="text-lg font-semibold text-white mb-1">Ad Style</h3>
                    <p className="text-sm text-white/60">
                        Fine-tune how Studio composes this brand's ads. Any field left on{' '}
                        <span className="font-medium text-white/80">Infer from voice</span>{' '}
                        uses the runtime default based on your brand voice + colors.
                    </p>
                </div>
                <div className="text-xs text-white/50 whitespace-nowrap">
                    {overrideCount > 0 ? `${overrideCount} override${overrideCount === 1 ? '' : 's'}` : 'All inferred'}
                </div>
            </div>

            {/* Live preview row — one thumbnail per registered recipe. Re-renders
                on every dropdown flip via the in-memory `previewStyle`. No API
                round-trip, no debouncing — derivation is pure math against
                already-loaded brand data. */}
            {RECIPE_REGISTRY.length > 0 && (
                <div className="mb-5 -mx-2 overflow-x-auto">
                    <div className="flex gap-3 px-2 pb-2">
                        {RECIPE_REGISTRY.map((descriptor) => (
                            <div
                                key={descriptor.key}
                                className="flex-shrink-0 flex flex-col items-center gap-1.5"
                                style={{ width: PREVIEW_SIZE }}
                            >
                                <RecipePreview
                                    recipeKey={descriptor.key}
                                    style={previewStyle}
                                    width={PREVIEW_SIZE}
                                    height={PREVIEW_SIZE}
                                    content={PREVIEW_CONTENT}
                                    label={`Preview of ${descriptor.name}`}
                                    className="shadow-lg ring-1 ring-white/10"
                                />
                                <div className="text-[11px] font-medium text-white/70 text-center leading-tight">
                                    {descriptor.name}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {FIELDS.map((f) => {
                    const current = values[f.key] ?? INFER
                    return (
                        <div key={f.key}>
                            <label className="block text-sm font-medium text-white/80 mb-1">{f.label}</label>
                            <select
                                value={current}
                                onChange={(e) => handleChange(f.key, e.target.value)}
                                className="w-full rounded-xl border border-white/20 bg-white/5 px-3 py-2 text-white focus:ring-2 focus:ring-white/30 text-sm"
                            >
                                <option value={INFER}>Infer from voice</option>
                                {f.options.map((opt) => (
                                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                                ))}
                            </select>
                            <p className="mt-1 text-xs text-white/40">{f.hint}</p>
                        </div>
                    )
                })}
            </div>

            <div className="mt-5 flex items-center justify-between gap-4">
                <div className="text-xs text-white/50 min-h-[1em]">
                    {statusLabel}
                </div>
                <button
                    type="button"
                    onClick={handleReset}
                    disabled={overrideCount === 0}
                    className="text-xs text-white/60 hover:text-white disabled:opacity-40 disabled:cursor-not-allowed underline"
                >
                    Reset all to inferred
                </button>
            </div>
        </div>
    )
}
