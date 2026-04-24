/** Major Studio Properties column sections — ids match `data-studio-section` / DOM `jp-studio-section-*`. */
export const STUDIO_PROPERTIES_SECTION_IDS = [
    'canvas',
    'layer',
    'primaryTool',
    'sourceHistory',
    'layout',
    'style',
    'advanced',
] as const

export type StudioPropertiesSectionId = (typeof STUDIO_PROPERTIES_SECTION_IDS)[number]

export const INITIAL_STUDIO_PROPERTIES_SECTION_OPEN: Record<StudioPropertiesSectionId, boolean> = {
    canvas: true,
    layer: true,
    primaryTool: true,
    sourceHistory: true,
    layout: true,
    style: true,
    advanced: true,
}
