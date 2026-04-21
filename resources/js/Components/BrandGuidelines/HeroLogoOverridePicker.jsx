import { useState, useCallback } from 'react'
import { useSidebarEditor } from './SidebarEditorContext'
import BuilderAssetSelectorModal from './BuilderAssetSelectorModal'

/**
 * Guidelines Customize — hero logo can differ from Brand Settings (presentation_overrides only).
 */
export default function HeroLogoOverridePicker() {
    const ctx = useSidebarEditor()
    const [libraryOpen, setLibraryOpen] = useState(false)
    const brandId = ctx.brandId
    const heroId = ctx.draftOverrides?.sections?.['sec-hero']?.content?.hero_logo_asset_id ?? null

    const setAssetId = useCallback(
        (id) => {
            ctx.updateOverride('sec-hero', 'content.hero_logo_asset_id', id)
        },
        [ctx],
    )

    const onSelect = useCallback(
        (asset) => {
            const id = asset?.id
            if (id) setAssetId(id)
            setLibraryOpen(false)
        },
        [setAssetId],
    )

    if (!brandId) return null

    return (
        <div className="space-y-2 pt-1 border-t border-indigo-100/90 mt-2">
            <div className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Hero logo</div>
            <p className="text-[10px] text-gray-500 leading-snug">
                Optional: use a different mark on this guidelines page only. Brand Settings logo is unchanged.
            </p>
            <div className="flex items-center gap-2 flex-wrap">
                {heroId && (
                    <span className="text-[10px] font-mono text-gray-500 truncate max-w-[200px]" title={heroId}>
                        {heroId.slice(0, 8)}…
                    </span>
                )}
                <button
                    type="button"
                    onClick={() => setLibraryOpen(true)}
                    className="text-[11px] font-medium text-indigo-600 hover:text-indigo-800"
                >
                    {heroId ? 'Change…' : 'Choose from library…'}
                </button>
                {heroId && (
                    <button
                        type="button"
                        onClick={() => setAssetId(null)}
                        className="text-[11px] text-gray-400 hover:text-red-500"
                    >
                        Use brand default
                    </button>
                )}
            </div>
            <BuilderAssetSelectorModal
                open={libraryOpen}
                onClose={() => setLibraryOpen(false)}
                brandId={brandId}
                builderContext="logo_reference"
                onSelect={onSelect}
                title="Hero logo (guidelines only)"
                multiSelect={false}
            />
        </div>
    )
}
