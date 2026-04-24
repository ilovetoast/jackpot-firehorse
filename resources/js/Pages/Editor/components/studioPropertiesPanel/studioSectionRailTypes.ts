/** Major Studio Properties column sections — ids match `data-studio-section` / DOM `jp-studio-section-*`. */
export const STUDIO_PROPERTIES_SECTION_IDS = [
    'canvas',
    'layer',
    'element',
    'actions',
    'history',
    'advanced',
] as const

export type StudioPropertiesSectionId = (typeof STUDIO_PROPERTIES_SECTION_IDS)[number]

/**
 * Defaults: layer + element open for editing; actions/history/advanced collapsed so AI cards
 * do not dominate. Canvas starts open for document-only context; AssetEditor collapses canvas
 * when a layer becomes selected.
 */
export const INITIAL_STUDIO_PROPERTIES_SECTION_OPEN: Record<StudioPropertiesSectionId, boolean> = {
    canvas: true,
    layer: true,
    element: true,
    actions: false,
    history: false,
    advanced: false,
}
