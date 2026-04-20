import type { LayerBlueprint } from './templateConfig'
import { editorBridgeFileUrlForAssetId } from './documentModel'

/**
 * Server payload for /app/api/editor/wizard-defaults.
 *
 * Shape mirrors the backend {@see \App\Http\Controllers\Editor\EditorGenerateLayoutController::wizardDefaults}.
 * The asset entries line up with DamPickerAsset (id / name / file_url / thumbnail_url / width / height)
 * so they can be fed straight into the same replace-image path the asset picker uses.
 */
export type WizardDefaultAsset = {
    id: string
    name: string
    file_url: string
    thumbnail_url: string | null
    width: number | null
    height: number | null
    tags?: string[]
}

export type WizardDefaults = {
    logo: WizardDefaultAsset | null
    background_candidates: WizardDefaultAsset[]
}

/**
 * Fetch the per-brand wizard defaults. Returns empty defaults (not an error)
 * on failure so the wizard keeps working — auto-fill is a convenience, not a
 * hard dependency.
 */
export async function fetchWizardDefaults(): Promise<WizardDefaults> {
    const empty: WizardDefaults = { logo: null, background_candidates: [] }
    try {
        const res = await fetch('/app/api/editor/wizard-defaults', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
        if (!res.ok) return empty
        const data = (await res.json()) as WizardDefaults
        return {
            logo: data.logo ?? null,
            background_candidates: Array.isArray(data.background_candidates)
                ? data.background_candidates
                : [],
        }
    } catch {
        return empty
    }
}

/**
 * Tiny deterministic hash → [0, 1) used to seed background candidate selection
 * by format+style combo. The goal isn't cryptographic randomness — just that a
 * given user opening the wizard at "Instagram Feed / Brand Focused" gets a
 * stable pick, and re-opening at "Facebook Feed / Product Focused" gets a
 * different one, instead of the same top photo every time.
 */
function seededIndex(seed: string, mod: number): number {
    if (mod <= 0) return 0
    let h = 2166136261
    for (let i = 0; i < seed.length; i++) {
        h ^= seed.charCodeAt(i)
        h = Math.imul(h, 16777619)
    }
    return Math.abs(h) % mod
}

/**
 * Blueprint defaults we piggyback onto to carry per-role asset selections
 * from the wizard into {@link blueprintToLayers}. Optional so existing
 * blueprints keep working unchanged.
 */
export type WizardAssetDefaultFields = {
    assetId?: string
    assetUrl?: string
    naturalWidth?: number
    naturalHeight?: number
}

/**
 * Given the base blueprint list for a template and the wizard defaults, return
 * a new list where:
 *   - role=logo layers get `defaults.assetId`/`assetUrl` pointing at the brand's
 *     primary logo (when set).
 *   - role=background | hero_image layers get a photo from the candidate pool
 *     (seeded by `seed` for stability within a single draft).
 *
 * When the original type is `generative_image` (e.g. the default BG_LAYER) and
 * the brand has photography available, we switch the type to `image` so the
 * wizard produces a composition with a real photo rather than an empty
 * generative canvas. That matches the "photography first, AI second" behavior
 * the product calls for — users with tagged assets skip a generation step.
 *
 * If the wizard has no defaults (no logo, no tagged photos), blueprints are
 * returned unchanged so the wizard still works on empty brands.
 */
export function applyWizardAssetDefaults(
    blueprints: LayerBlueprint[],
    defaults: WizardDefaults | null,
    seed: string,
): LayerBlueprint[] {
    if (!defaults) return blueprints

    const logo = defaults.logo
    const candidates = defaults.background_candidates
    if (!logo && candidates.length === 0) {
        return blueprints
    }

    let bgPickIndex = candidates.length > 0 ? seededIndex(seed, candidates.length) : -1

    return blueprints.map((bp) => {
        if (bp.role === 'logo' && logo) {
            const extra: WizardAssetDefaultFields = {
                assetId: logo.id,
                // Always use the same-origin /file bridge for canvas rendering.
                // The backend-provided `file_url` is a signed S3 / CloudFront
                // URL that can 404 (e.g. SVG originals that were never warmed)
                // or be blocked by CORS in some environments. The bridge
                // streams bytes through the app, so if the asset exists, the
                // canvas can render it — which is what the wizard's preview
                // promises the user.
                assetUrl: editorBridgeFileUrlForAssetId(logo.id),
                naturalWidth: logo.width ?? undefined,
                naturalHeight: logo.height ?? undefined,
            }
            return {
                ...bp,
                // Force image type — a logo slot that somehow ended up as
                // generative_image should still receive the real logo.
                type: 'image',
                defaults: { ...(bp.defaults ?? {}), ...extra },
            }
        }

        const isBg = bp.role === 'background' || bp.role === 'hero_image'
        if (isBg && bgPickIndex >= 0) {
            const pick = candidates[bgPickIndex]
            // Use the next candidate for subsequent background slots in the
            // same blueprint (e.g. templates with two hero images) so they
            // don't all share the same photo.
            bgPickIndex = (bgPickIndex + 1) % candidates.length
            const extra: WizardAssetDefaultFields = {
                assetId: pick.id,
                assetUrl: editorBridgeFileUrlForAssetId(pick.id),
                naturalWidth: pick.width ?? undefined,
                naturalHeight: pick.height ?? undefined,
            }
            return {
                ...bp,
                type: 'image',
                defaults: { ...(bp.defaults ?? {}), ...extra },
            }
        }

        return bp
    })
}
