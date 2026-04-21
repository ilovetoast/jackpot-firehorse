/**
 * Lightweight "creative brief" captured in the template wizard (step 3).
 * Stored on {@link DocumentModel.studioBrief} for future AI / ranking; also
 * used immediately to seed headline / subheadline text layers.
 */

import type { LayerBlueprint } from './templateConfig'

export type WizardPostGoalId =
    | 'brand_awareness'
    | 'product_launch'
    | 'promotion'
    | 'event'
    | 'education'
    | 'seasonal'
    | 'community'

export type StudioBrief = {
    /** Stable id for analytics / future photo ranking. */
    postGoal: WizardPostGoalId
    /** Optional one-line or multi-line key message (see parsing rules below). */
    keyMessage?: string
}

export const WIZARD_POST_GOALS: { id: WizardPostGoalId; label: string }[] = [
    { id: 'brand_awareness', label: 'Brand awareness' },
    { id: 'product_launch', label: 'Product launch' },
    { id: 'promotion', label: 'Promotion / sale' },
    { id: 'event', label: 'Event or deadline' },
    { id: 'education', label: 'Education / tips' },
    { id: 'seasonal', label: 'Seasonal / holiday' },
    { id: 'community', label: 'Community / social proof' },
]

const DEFAULT_GOAL: WizardPostGoalId = 'brand_awareness'

export function defaultWizardPostGoal(): WizardPostGoalId {
    return DEFAULT_GOAL
}

/**
 * Parse optional user copy into headline / subheadline seeds:
 * - Two+ lines (newline): first line → first headline; remaining lines → first subheadline (joined).
 * - Single line with `|` (e.g. `MAKE | IT POP`): first → first headline, second → second headline (pairs).
 * - Single line, no `|`: that line → first headline only (subhead stays template default).
 * - No key message: no text injection — `postGoal` is still saved on the document for analytics / future ranking.
 */
export function applyStudioBriefToBlueprints(
    blueprints: LayerBlueprint[],
    brief: StudioBrief | null | undefined,
): LayerBlueprint[] {
    if (!brief) return blueprints

    const raw = (brief.keyMessage ?? '').trim()

    let h1: string | undefined
    let h2: string | undefined
    let sub: string | undefined

    if (raw) {
        const lines = raw.split(/\r?\n/).map((s) => s.trim()).filter(Boolean)
        if (lines.length >= 2) {
            h1 = lines[0]
            sub = lines.slice(1).join(' ')
        } else {
            const one = lines[0] ?? ''
            if (one.includes('|')) {
                const parts = one.split('|').map((s) => s.trim()).filter(Boolean)
                h1 = parts[0]
                h2 = parts[1]
            } else {
                h1 = one
            }
        }
    }

    if (!h1 && !h2 && !sub) return blueprints

    let headlineIdx = 0
    let subIdx = 0

    return blueprints.map((bp) => {
        if (bp.enabled === false || bp.type !== 'text') return bp

        if (bp.role === 'headline') {
            const idx = headlineIdx++
            const content = idx === 0 ? h1 : idx === 1 ? h2 : undefined
            if (content) {
                return {
                    ...bp,
                    defaults: { ...(bp.defaults ?? {}), content },
                }
            }
            return bp
        }

        if (bp.role === 'subheadline') {
            const idx = subIdx++
            if (idx === 0 && sub) {
                return {
                    ...bp,
                    defaults: { ...(bp.defaults ?? {}), content: sub },
                }
            }
        }

        return bp
    })
}
