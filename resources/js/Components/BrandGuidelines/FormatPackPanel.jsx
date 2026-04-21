/**
 * FormatPackPanel
 *
 * Companion to the AdStyleCard: lets users pick a registered recipe and a
 * Format Pack, then renders the recipe across every size in the pack so
 * brand owners can see "will this ad style hold up as a billboard AND a
 * mobile banner AND a story AND a Pinterest pin?".
 *
 * Reuses the exact same {@link deriveBrandAdStyle} merge path as AdStyleCard
 * so ad-style overrides stored on `brand.settings.ad_style` are reflected
 * here too — flip a tone in the AdStyleCard, the whole format-pack grid
 * repaints because Inertia re-seeds the initial props on every `brand.save`.
 *
 * Batch export:
 *   "Create all N sizes" materializes one editable composition per size in
 *   the pack. Each uses the same recipe + style + content, sized to that
 *   slot's dimensions, so teams can hand-finish per-format copy without
 *   rebuilding the frame fourteen times. Creation goes through
 *   `POST /app/api/compositions/batch` (single transaction) against the
 *   *session's active brand* — admins viewing another brand's guidelines
 *   should switch brand before batch-creating.
 */

import { useMemo, useState } from 'react'
import {
    buildRecipeDocument,
    deriveBrandAdStyle,
    FORMAT_PACKS,
    RECIPE_REGISTRY,
} from '../../Pages/Editor/recipes'
import { postCompositionsBatch } from '../../Pages/Editor/editorCompositionBridge'
import FormatPackPreview from './FormatPackPreview'

/**
 * Shared placeholder content — same as the AdStyleCard so the pack preview
 * and the per-archetype row tell the same visual story. If we ever want
 * pack previews to be *empty* (to stress-test the empty state), swap this
 * for `undefined`.
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

export default function FormatPackPanel({
    brandId = null,
    adStyle = null,
    brandColors = null,
    brandVoice = null,
    logoAsset = null,
    referenceHints = null,
}) {
    // Default to the first registered recipe + social pack (most familiar
    // set of sizes for brand owners).
    const [recipeKey, setRecipeKey] = useState(() => RECIPE_REGISTRY[0]?.key ?? null)
    const [packId, setPackId] = useState('social')
    const [batchName, setBatchName] = useState('')
    const [batchState, setBatchState] = useState({ kind: 'idle' })

    const selectedPack = useMemo(
        () => FORMAT_PACKS.find((p) => p.id === packId) ?? FORMAT_PACKS[0],
        [packId],
    )

    // Derive the live BrandAdStyle the same way AdStyleCard does. This means
    // persisted overrides AND brand colors flow into the pack preview.
    const style = useMemo(() => {
        const cleanedAdStyle = adStyle && typeof adStyle === 'object' ? adStyle : {}
        return deriveBrandAdStyle(
            {
                id: brandId,
                primary_color: brandColors?.primary_color ?? null,
                secondary_color: brandColors?.secondary_color ?? null,
                accent_color: brandColors?.accent_color ?? null,
                voice: brandVoice ?? null,
                settings: { ad_style: cleanedAdStyle },
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
    }, [adStyle, brandId, brandColors, brandVoice, logoAsset, referenceHints])

    if (!recipeKey || !selectedPack) return null

    const selectedRecipe = RECIPE_REGISTRY.find((r) => r.key === recipeKey)

    // Name prefix defaults to "<Recipe> — <Pack>" so the composition list
    // remains sortable/groupable even if the user skips the custom name input.
    const defaultName = `${selectedRecipe?.name ?? 'Recipe'} — ${selectedPack.name}`
    const effectiveNamePrefix = (batchName || defaultName).trim() || defaultName

    const runBatchCreate = async () => {
        if (!recipeKey || !selectedPack) return
        setBatchState({ kind: 'saving' })
        try {
            // One document per size. We build them client-side because the
            // recipe engine lives here — keeping the server ignorant of
            // recipe semantics means adding a recipe doesn't require a
            // backend deploy.
            const items = selectedPack.sizes.map((size) => {
                const doc = buildRecipeDocument({
                    recipeKey,
                    style,
                    format: { width: size.width, height: size.height },
                    content: PREVIEW_CONTENT,
                    brandPrimaryColor: brandColors?.primary_color ?? undefined,
                })
                return {
                    name: `${effectiveNamePrefix} — ${size.label} (${size.width}×${size.height})`,
                    document: doc,
                }
            })

            const created = await postCompositionsBatch(items)
            setBatchState({ kind: 'success', count: created.length })
        } catch (err) {
            setBatchState({
                kind: 'error',
                message: err instanceof Error ? err.message : 'Batch create failed.',
            })
        }
    }

    return (
        <div className="rounded-2xl border border-white/20 bg-white/5 p-6">
            <div className="flex items-start justify-between gap-4 mb-4">
                <div>
                    <h3 className="text-lg font-semibold text-white mb-1">Format Pack preview</h3>
                    <p className="text-sm text-white/60">
                        See how a recipe holds up across every size in a pack. Uses your ad-style
                        overrides + brand colors live — changes above repaint every tile.
                    </p>
                </div>
                <div className="text-xs text-white/50 whitespace-nowrap">
                    {selectedPack.sizes.length} size{selectedPack.sizes.length === 1 ? '' : 's'}
                </div>
            </div>

            {/* Recipe + Pack pickers */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                <div>
                    <label className="block text-sm font-medium text-white/80 mb-1">Recipe</label>
                    <select
                        value={recipeKey}
                        onChange={(e) => setRecipeKey(e.target.value)}
                        className="w-full rounded-xl border border-white/20 bg-white/5 px-3 py-2 text-white focus:ring-2 focus:ring-white/30 text-sm"
                    >
                        {RECIPE_REGISTRY.map((r) => (
                            <option key={r.key} value={r.key}>
                                {r.name}
                            </option>
                        ))}
                    </select>
                    {selectedRecipe && (
                        <p className="mt-1 text-xs text-white/40">{selectedRecipe.description}</p>
                    )}
                </div>
                <div>
                    <label className="block text-sm font-medium text-white/80 mb-1">Format pack</label>
                    <select
                        value={packId}
                        onChange={(e) => setPackId(e.target.value)}
                        className="w-full rounded-xl border border-white/20 bg-white/5 px-3 py-2 text-white focus:ring-2 focus:ring-white/30 text-sm"
                    >
                        {FORMAT_PACKS.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                    </select>
                    <p className="mt-1 text-xs text-white/40">{selectedPack.description}</p>
                </div>
            </div>

            {/* Matrix of thumbnails. Comprehensive pack can hit 15+ tiles — we
                let the grid wrap naturally so the component scales from a
                single-row social pack up to the full comprehensive pack. */}
            <div className="rounded-xl border border-white/10 bg-black/30 p-4 overflow-x-auto">
                <FormatPackPreview
                    recipeKey={recipeKey}
                    style={style}
                    pack={selectedPack}
                    content={PREVIEW_CONTENT}
                    maxTileDim={180}
                />
            </div>

            {/* Batch create — turns the preview into N editable compositions. */}
            <div className="mt-5 rounded-xl border border-white/10 bg-black/20 p-4">
                <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                    <div className="min-w-0 flex-1">
                        <label className="block text-sm font-medium text-white/80 mb-1">
                            Batch name
                        </label>
                        <input
                            type="text"
                            value={batchName}
                            onChange={(e) => setBatchName(e.target.value)}
                            placeholder={defaultName}
                            disabled={batchState.kind === 'saving'}
                            className="w-full rounded-xl border border-white/20 bg-white/5 px-3 py-2 text-sm text-white placeholder-white/30 focus:ring-2 focus:ring-white/30 disabled:opacity-60"
                        />
                        <p className="mt-1 text-xs text-white/40">
                            Each composition is named “{effectiveNamePrefix} — {'<size>'}”. Edit each
                            one separately in Studio after creation.
                        </p>
                    </div>
                    <div className="flex flex-col items-stretch gap-1 md:items-end">
                        <button
                            type="button"
                            onClick={runBatchCreate}
                            disabled={batchState.kind === 'saving'}
                            className="whitespace-nowrap rounded-xl bg-indigo-500 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-400 disabled:cursor-wait disabled:opacity-60"
                        >
                            {batchState.kind === 'saving'
                                ? `Creating ${selectedPack.sizes.length}…`
                                : `Create all ${selectedPack.sizes.length} sizes`}
                        </button>
                    </div>
                </div>

                {batchState.kind === 'success' && (
                    <p className="mt-3 text-xs text-emerald-300">
                        Created {batchState.count} composition{batchState.count === 1 ? '' : 's'}.
                        Find them in Studio &rarr; Open.
                    </p>
                )}
                {batchState.kind === 'error' && (
                    <p className="mt-3 text-xs text-rose-300">{batchState.message}</p>
                )}
            </div>
        </div>
    )
}
