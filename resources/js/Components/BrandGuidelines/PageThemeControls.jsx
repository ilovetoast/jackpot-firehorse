import { useSidebarEditor } from './SidebarEditorContext'
import { hasPageThemeOverrides } from './brandGuidelinesPresentationModel'
import ColorPickerControl from './controls/ColorPickerControl'
import SliderControl from './controls/SliderControl'
import SelectControl from './controls/SelectControl'

const OVERLAY_PRESETS = [
    { value: 'none', label: 'None', opacity: 0 },
    { value: 'subtle', label: 'Subtle', opacity: 0.12 },
    { value: 'medium', label: 'Medium', opacity: 0.28 },
    { value: 'strong', label: 'Strong', opacity: 0.5 },
]

const PAGE_BG_MODES = [
    { value: 'default', label: 'Default (no custom page background)' },
    { value: 'color', label: 'Solid color' },
    { value: 'image', label: 'Image (URL from library field)' },
    { value: 'custom_url', label: 'Custom image URL' },
    { value: 'brand_asset', label: 'Brand library image' },
]

/**
 * Page-level theme (`global.page_theme`) — guidelines only; does not change Brand Identity.
 * Save: presentation is updated only when the user clicks Save in the customizer.
 */
export default function PageThemeControls() {
    const ctx = useSidebarEditor()
    if (!ctx) return null

    const t = ctx.draftOverrides?.global?.page_theme || {}
    const pt = (path, v) => ctx.updatePageTheme(path, v)
    const isCustom = hasPageThemeOverrides(ctx.draftOverrides?.global)
    const logoAssets = ctx.logoAssets || []

    const overlayOp = typeof t.overlay_opacity === 'number' ? t.overlay_opacity : 0
    const isNoneOverlay = overlayOp < 0.02

    const bgMode = t.background_mode
        || (t.background_asset_id ? 'brand_asset'
            : (t.background_image_url && String(t.background_image_url).trim()) ? 'image'
                : (t.background_custom_url && String(t.background_custom_url).trim()) ? 'custom_url'
                    : (t.background_color && String(t.background_color).startsWith('#')) ? 'color' : 'default')

    const setBackgroundMode = (nextMode) => {
        pt('background_mode', nextMode)
        if (nextMode === 'default') {
            pt('background_image_url', null)
            pt('background_custom_url', null)
            pt('background_asset_id', null)
            pt('background_color', null)
        }
    }

    const previewUrl = (() => {
        if (bgMode === 'image' && t.background_image_url) return String(t.background_image_url).trim()
        if (bgMode === 'custom_url' && t.background_custom_url) return String(t.background_custom_url).trim()
        if (bgMode === 'brand_asset' && t.background_asset_id) {
            const a = logoAssets.find((x) => String(x.id) === String(t.background_asset_id))
            return a?.url || null
        }
        return null
    })()

    return (
        <div className="space-y-3 border-t border-violet-100/90 pt-3 mt-1">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Page theme</div>
                <span
                    className={`text-[9px] font-semibold px-1.5 py-0.5 rounded ${isCustom ? 'bg-amber-50 text-amber-800 ring-1 ring-amber-200' : 'bg-slate-100 text-slate-500'}`}
                >
                    {isCustom ? 'Customized' : 'AI default'}
                </span>
            </div>
            <p className="text-[10px] text-gray-600 leading-snug border-l-2 border-violet-200 pl-2">
                Edits here customize <strong>this guidelines page</strong> only. They do not change the official brand identity. Click <strong>Save</strong> in the sidebar to publish; until then, use <strong>Discard</strong> to undo.
            </p>

            <div className="space-y-2">
                <div className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Background</div>
                <p className="text-[9px] text-gray-500">Background is separate from the overlay layer below. Overlay tints the page without changing card content.</p>
                <SelectControl
                    label="Mode"
                    value={bgMode}
                    onChange={(v) => setBackgroundMode(v)}
                    options={PAGE_BG_MODES}
                />
                {bgMode === 'color' && (
                    <div>
                        <span className="text-xs text-gray-600">Page background color</span>
                        <div className="mt-1">
                            <ColorPickerControl
                                hideLabel
                                value={t.background_color || '#ffffff'}
                                onChange={(v) => pt('background_color', v)}
                            />
                        </div>
                    </div>
                )}
                {bgMode === 'image' && (
                    <div>
                        <label className="text-xs text-gray-600 font-medium">Image URL</label>
                        <input
                            type="url"
                            value={t.background_image_url || ''}
                            onChange={(e) => pt('background_image_url', e.target.value || null)}
                            className="mt-1 w-full rounded-md border border-gray-200 px-2 py-1.5 text-xs"
                            placeholder="https://…"
                        />
                    </div>
                )}
                {bgMode === 'custom_url' && (
                    <div>
                        <label className="text-xs text-gray-600 font-medium">Custom image URL</label>
                        <input
                            type="url"
                            value={t.background_custom_url || ''}
                            onChange={(e) => pt('background_custom_url', e.target.value || null)}
                            className="mt-1 w-full rounded-md border border-gray-200 px-2 py-1.5 text-xs"
                            placeholder="https://…"
                        />
                    </div>
                )}
                {bgMode === 'brand_asset' && (
                    <div>
                        <span className="text-xs text-gray-600">Library image</span>
                        <select
                            value={t.background_asset_id || ''}
                            onChange={(e) => pt('background_asset_id', e.target.value || null)}
                            className="mt-1 w-full rounded-md border border-gray-200 px-2 py-1.5 text-xs"
                        >
                            <option value="">— Select an asset —</option>
                            {logoAssets.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.title || `Asset #${a.id}`}
                                </option>
                            ))}
                        </select>
                    </div>
                )}
                {previewUrl && (
                    <div className="flex items-center gap-2">
                        <img src={previewUrl} alt="" className="h-12 w-20 object-cover rounded border border-gray-200" />
                        <span className="text-[9px] text-gray-500">Page background preview</span>
                    </div>
                )}
            </div>

            <div className="space-y-2">
                <div className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Overlay</div>
                <p className="text-[9px] text-gray-500 leading-snug">Tint on top of the page background, under section content. Does not change logo cards.</p>
                <div>
                    <span className="text-xs text-gray-600">Overlay color</span>
                    <div className="mt-1">
                        <ColorPickerControl
                            hideLabel
                            value={t.overlay_color || '#000000'}
                            onChange={(v) => pt('overlay_color', v)}
                        />
                    </div>
                </div>
                <div>
                    <span className="text-xs text-gray-600">Overlay strength</span>
                    <div className="mt-1.5 flex flex-wrap gap-1">
                        {OVERLAY_PRESETS.map((p) => {
                            const active = p.value === 'none' ? isNoneOverlay : !isNoneOverlay && Math.abs(p.opacity - overlayOp) < 0.02
                            return (
                                <button
                                    key={p.value}
                                    type="button"
                                    onClick={() => { ctx.updatePageTheme('overlay_opacity', p.opacity) }}
                                    className={`px-2 py-1 text-[9px] font-medium rounded border transition-colors ${
                                        active ? 'bg-violet-100 border-violet-300 text-violet-900' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50'
                                    }`}
                                >
                                    {p.label}
                                </button>
                            )
                        })}
                        {(() => {
                            if (isNoneOverlay) return null
                            const onPreset = OVERLAY_PRESETS.some((p) => p.value !== 'none' && Math.abs(p.opacity - overlayOp) < 0.02)
                            if (onPreset) return null
                            return <span className="text-[9px] text-gray-400 self-center pl-0.5">Custom</span>
                        })()}
                    </div>
                </div>
                <SliderControl
                    label="Overlay opacity (custom)"
                    value={overlayOp}
                    min={0}
                    max={1}
                    step={0.05}
                    onChange={(v) => pt('overlay_opacity', v)}
                />
            </div>

            <div className="space-y-2">
                <div className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Text</div>
                <div className="grid grid-cols-1 gap-2">
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-xs text-gray-600">Body text</span>
                        <ColorPickerControl
                            hideLabel
                            value={t.text_color || '#111827'}
                            onChange={(v) => pt('text_color', v)}
                        />
                    </div>
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-xs text-gray-600">Muted / labels</span>
                        <ColorPickerControl
                            hideLabel
                            value={t.muted_text_color || '#6b7280'}
                            onChange={(v) => pt('muted_text_color', v)}
                        />
                    </div>
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-xs text-gray-600">Accent</span>
                        <ColorPickerControl
                            hideLabel
                            value={t.accent_color || '#7c3aed'}
                            onChange={(v) => pt('accent_color', v)}
                        />
                    </div>
                </div>
            </div>

            <div className="pt-1 border-t border-gray-100">
                <button
                    type="button"
                    onClick={() => ctx.clearPageTheme?.()}
                    className="w-full text-left rounded-md border border-amber-200/80 bg-amber-50/80 px-2.5 py-2 text-xs font-medium text-amber-900 hover:bg-amber-100/80 transition-colors"
                >
                    Reset page background &amp; overlay
                </button>
                <p className="text-[9px] text-gray-500 mt-1.5">
                    Clears only <strong>page theme</strong> (background, overlay, page text colors). Section and logo block edits stay until you change or reset them. Saves when you click Save.
                </p>
            </div>
        </div>
    )
}
