import type { FillLayer, ImageLayer, Layer, StudioSyncRole, TextLayer } from './documentModel'
import { isFillLayer } from './documentModel'
import type {
    CreativeSetApplyCommand,
    SyncTextAlignRole,
    SyncTextContentRole,
    SyncTransformRole,
} from './studioCreativeSetTypes'

/**
 * Resolves the narrow Phase 3 sync role for a layer (mirrors server heuristics + `studioSyncRole`).
 */
export function inferStudioSyncRole(layer: Layer): StudioSyncRole | null {
    if (layer.studioSyncRole) {
        return layer.studioSyncRole
    }
    if (layer.type === 'text') {
        const n = (layer.name ?? '').toLowerCase()
        if (n.includes('headline')) {
            return 'headline'
        }
        if (n.includes('subhead') || n.includes('sub-head')) {
            return 'subheadline'
        }
        if (/(^cta$|\bcta\b)/i.test(layer.name ?? '')) {
            return 'cta'
        }
        if (/disclaimer|legal|fine\s*print/i.test(layer.name ?? '')) {
            return 'disclaimer'
        }
        const t = layer as TextLayer
        const fs = t.style?.fontSize ?? 0
        if (fs > 32) {
            return 'headline'
        }
        if (fs > 0 && fs <= 18) {
            return 'disclaimer'
        }
        return null
    }
    if (layer.type === 'image') {
        if (/\blogo\b/i.test(layer.name ?? '')) {
            return 'logo'
        }
        if (/\bbadge\b/i.test(layer.name ?? '')) {
            return 'badge'
        }
        return null
    }
    if (isFillLayer(layer)) {
        const f = layer as FillLayer
        if (f.fillRole === 'cta_button') {
            return 'cta'
        }
        if (/\bbadge\b/i.test(layer.name ?? '')) {
            return 'badge'
        }
    }
    return null
}

const ROLE_DISPLAY: Record<StudioSyncRole, string> = {
    headline: 'Headline',
    subheadline: 'Subheadline',
    cta: 'CTA',
    disclaimer: 'Disclaimer',
    logo: 'Logo',
    badge: 'Badge',
}

export function displayNameForSyncRole(role: StudioSyncRole): string {
    return ROLE_DISPLAY[role] ?? role
}

/** Short label for the scope bar (which role is driving sync). */
export function describeSyncForLayer(layer: Layer): string | null {
    const role = inferStudioSyncRole(layer)
    if (!role) {
        return null
    }
    return displayNameForSyncRole(role)
}

/**
 * Human summary of what will be pushed when applying the given command bundle
 * (e.g. "Headline — text, alignment, and position").
 */
export function describeApplyCommandBundle(commands: CreativeSetApplyCommand[]): {
    primaryRoleLabel: string
    aspects: string
} {
    if (commands.length === 0) {
        return { primaryRoleLabel: 'Layer', aspects: 'updates' }
    }
    const first = commands[0]
    const role = 'role' in first ? first.role : 'headline'
    const primaryRoleLabel =
        role === 'headline' ||
        role === 'subheadline' ||
        role === 'cta' ||
        role === 'disclaimer' ||
        role === 'logo' ||
        role === 'badge'
            ? displayNameForSyncRole(role)
            : String(role)

    const aspectsSet = new Set<string>()
    for (const c of commands) {
        if (c.type === 'update_text_content') {
            aspectsSet.add('text')
        }
        if (c.type === 'update_text_alignment') {
            aspectsSet.add('alignment')
        }
        if (c.type === 'update_layer_visibility') {
            aspectsSet.add('visibility')
        }
        if (c.type === 'update_role_transform') {
            aspectsSet.add('position & size')
        }
    }
    const order = ['text', 'alignment', 'visibility', 'position & size']
    const aspects = order.filter((k) => aspectsSet.has(k)).join(', ')

    return { primaryRoleLabel, aspects: aspects || 'updates' }
}

export type ApplyPhraseScope = 'all_versions' | 'selected_versions'

export function formatApplyConfirmMessage(p: {
    mode: ApplyPhraseScope
    primaryRoleLabel: string
    aspects: string
    siblingTargetCount: number
    eligibleCount: number
    wouldSkipCount: number
}): string {
    const { mode, primaryRoleLabel, aspects, siblingTargetCount, eligibleCount, wouldSkipCount } = p
    const noun = `${primaryRoleLabel} (${aspects})`
    const dest =
        mode === 'selected_versions'
            ? siblingTargetCount === 1
                ? '1 selected version'
                : `${siblingTargetCount} selected versions`
            : siblingTargetCount === 1
              ? '1 other version'
              : `${siblingTargetCount} other versions`

    if (siblingTargetCount === 0) {
        return mode === 'selected_versions'
            ? `No selected versions to sync.`
            : `No other versions in this set to sync.`
    }
    if (wouldSkipCount === 0 && eligibleCount === siblingTargetCount) {
        return `Push ${noun} to ${dest}?\n\nOnly allowlisted fields are updated across versions.`
    }
    const destShort = mode === 'selected_versions' ? `${siblingTargetCount} selected` : `${siblingTargetCount} other`
    if (eligibleCount === 0) {
        const pool =
            mode === 'selected_versions'
                ? siblingTargetCount === 1
                    ? 'this selected version'
                    : `these ${siblingTargetCount} selected versions`
                : siblingTargetCount === 1
                  ? 'this other version'
                  : `these ${siblingTargetCount} other versions`
        return `Push ${noun} to ${pool}?\n\nBased on a quick check, none may apply automatically — layouts may be too different. You can still try, or fix template roles.`
    }
    return `Push ${noun} to ${eligibleCount} of ${destShort} version${siblingTargetCount === 1 ? '' : 's'} (${wouldSkipCount} may be skipped if layouts differ).\n\nOnly allowlisted fields are updated across versions.`
}

/** @deprecated Use {@link formatApplyConfirmMessage} with `mode: 'all_versions'`. */
export function formatApplyToAllConfirmMessage(p: {
    primaryRoleLabel: string
    aspects: string
    siblingTargetCount: number
    eligibleCount: number
    wouldSkipCount: number
}): string {
    return formatApplyConfirmMessage({ mode: 'all_versions', ...p })
}

export function formatApplyResultNotice(p: {
    mode?: ApplyPhraseScope
    primaryRoleLabel: string
    aspects: string
    updated: number
    skipped: number
}): string {
    const mode = p.mode ?? 'all_versions'
    const bundle = `${p.primaryRoleLabel} (${p.aspects})`
    const dest =
        mode === 'selected_versions'
            ? p.updated === 1
                ? '1 selected version'
                : `${p.updated} selected versions`
            : p.updated === 1
              ? '1 other version'
              : `${p.updated} other versions`
    if (p.skipped === 0) {
        return `Updated ${bundle} on ${dest}.`
    }
    if (p.updated === 0) {
        return mode === 'selected_versions'
            ? `No selected versions could be updated — ${p.skipped} skipped (layouts may not share the same sync roles).`
            : `Could not update other versions — ${p.skipped} skipped (layouts may not share the same sync roles).`
    }
    if (mode === 'selected_versions') {
        const label = p.updated === 1 ? '1 selected version' : `${p.updated} selected versions`
        return `Updated ${bundle} on ${label}; skipped ${p.skipped}.`
    }
    return `Updated ${bundle} on ${p.updated} version${p.updated === 1 ? '' : 's'}; skipped ${p.skipped}.`
}

/**
 * Builds allowlisted apply commands from the current layer state (used when scope = All versions).
 */
export function buildSemanticApplyCommandsFromLayer(layer: Layer): CreativeSetApplyCommand[] {
    const role = inferStudioSyncRole(layer)
    if (!role) {
        return []
    }

    if (layer.type === 'text') {
        const t = layer as TextLayer
        const contentRole = role as SyncTextContentRole
        const align = t.style?.textAlign
        const cmds: CreativeSetApplyCommand[] = [
            {
                type: 'update_text_content',
                role: contentRole,
                text: t.content,
            },
        ]
        if (
            (role === 'headline' || role === 'subheadline' || role === 'cta') &&
            (align === 'left' || align === 'center' || align === 'right')
        ) {
            cmds.push({
                type: 'update_text_alignment',
                role: role as SyncTextAlignRole,
                alignment: align,
            })
        }
        const tr = role as SyncTransformRole
        if (['headline', 'subheadline', 'cta', 'disclaimer'].includes(role)) {
            cmds.push({
                type: 'update_role_transform',
                role: tr,
                x: t.transform.x,
                y: t.transform.y,
                width: t.transform.width,
                height: t.transform.height,
            })
        }
        return cmds
    }

    if (layer.type === 'image') {
        const im = layer as ImageLayer
        if (role === 'logo' || role === 'badge') {
            const tr = role as SyncTransformRole
            return [
                {
                    type: 'update_layer_visibility',
                    role,
                    visible: im.visible,
                },
                {
                    type: 'update_role_transform',
                    role: tr,
                    x: im.transform.x,
                    y: im.transform.y,
                    width: im.transform.width,
                    height: im.transform.height,
                },
            ]
        }
    }

    if (isFillLayer(layer) && role === 'cta') {
        const f = layer as FillLayer
        return [
            {
                type: 'update_layer_visibility',
                role: 'cta',
                visible: f.visible,
            },
        ]
    }

    if (isFillLayer(layer) && role === 'badge') {
        const f = layer as FillLayer
        const tr = role as SyncTransformRole
        return [
            {
                type: 'update_layer_visibility',
                role: 'badge',
                visible: f.visible,
            },
            {
                type: 'update_role_transform',
                role: tr,
                x: f.transform.x,
                y: f.transform.y,
                width: f.transform.width,
                height: f.transform.height,
            },
        ]
    }

    return []
}
