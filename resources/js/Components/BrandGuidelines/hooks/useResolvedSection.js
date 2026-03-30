import { useMemo } from 'react'
import { useSidebarEditor } from '../SidebarEditorContext'

const DEFAULT_BACKGROUND = { type: null, color: null, gradient: null, image_url: null, image_opacity: 0.2, blend_mode: 'overlay' }

export default function useResolvedSection(sectionId) {
    const ctx = useSidebarEditor()
    const overrides = ctx?.draftOverrides
    const content = ctx?.draftContent

    return useMemo(() => {
        const sectionOverrides = overrides?.sections?.[sectionId] ?? {}
        const globalOverrides = overrides?.global ?? {}

        const visible = sectionOverrides.visible !== false

        const background = {
            ...DEFAULT_BACKGROUND,
            ...(sectionOverrides.background || {}),
        }
        const hasBackgroundOverride = sectionOverrides.background?.type != null

        const textStyles = {
            title_size: sectionOverrides.text?.title_size ?? null,
            title_style: sectionOverrides.text?.title_style ?? null,
        }

        const contentToggles = sectionOverrides.content ?? {}

        const sectionContent = content?.[sectionId] ?? {}
        const contentOverrides = {}
        for (const [key, val] of Object.entries(sectionContent)) {
            if (val != null && val !== '') {
                contentOverrides[key] = val
            }
        }

        return {
            visible,
            background,
            hasBackgroundOverride,
            textStyles,
            contentToggles,
            contentOverrides,
            globalOverrides,
            raw: sectionOverrides,
        }
    }, [sectionId, overrides, content])
}
