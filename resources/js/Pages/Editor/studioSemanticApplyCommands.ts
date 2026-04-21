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

export function describeSyncForLayer(layer: Layer): string | null {
    const role = inferStudioSyncRole(layer)
    if (!role) {
        return null
    }
    const labels: Record<StudioSyncRole, string> = {
        headline: 'headline',
        subheadline: 'subheadline',
        cta: 'CTA',
        disclaimer: 'disclaimer',
        logo: 'logo',
        badge: 'badge',
    }
    return labels[role] ?? role
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
