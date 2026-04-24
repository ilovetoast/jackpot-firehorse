export type StudioFontOption = {
    key: string
    label: string
    family: string
    source: string
    weight: number
    style: string
    export_supported: boolean
    css_stack?: string
    asset_id?: string
}

export type StudioFontGroup = {
    id: string
    label: string
    fonts: StudioFontOption[]
}

export type StudioFontsCatalog = {
    groups: StudioFontGroup[]
    default_font_key: string
}

export async function fetchEditorStudioFonts(): Promise<StudioFontsCatalog | null> {
    const res = await fetch('/app/api/editor/studio-fonts', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    })
    if (!res.ok) {
        return null
    }
    return (await res.json()) as StudioFontsCatalog
}
