import { useSidebarEditor } from './SidebarEditorContext'
import {
    IDENTITY_LOGO_BLOCK_LABELS,
    logoSourceToSelectValue,
    parseLogoSourceValue,
    getLogoSourceDisplayLabel,
    isLogoBlockOverridden,
    isSectionOverridden,
    countSectionOverrideUnits,
} from './brandGuidelinesPresentationModel'
import LogoSourceSelect from './LogoSourceSelect'
import SelectControl from './controls/SelectControl'
import ToggleControl from './controls/ToggleControl'
import ColorPickerControl from './controls/ColorPickerControl'
import SliderControl from './controls/SliderControl'

const BG_PRESETS = [
    { value: 'inherit', label: 'Card default' },
    { value: 'transparent', label: 'Transparent' },
    { value: 'white', label: 'White' },
    { value: 'black', label: 'Black' },
    { value: 'primary', label: 'Brand primary' },
    { value: 'secondary', label: 'Brand secondary' },
    { value: 'accent', label: 'Accent' },
    { value: 'custom', label: 'Custom hex' },
    { value: 'image', label: 'Image (URL)' },
    { value: 'brand_asset', label: 'Library image' },
]

const SIZE_PRESETS = [
    { value: 'sm', label: 'Small' },
    { value: 'md', label: 'Medium' },
    { value: 'lg', label: 'Large' },
    { value: 'xl', label: 'Extra large' },
]

const ALIGN_PRESETS = [
    { value: 'center', label: 'Center' },
    { value: 'top-left', label: 'Top left' },
    { value: 'top-right', label: 'Top right' },
    { value: 'bottom-left', label: 'Bottom left' },
    { value: 'bottom-right', label: 'Bottom right' },
]

const VARIANT_OPTIONS = [
    { value: 'auto', label: 'Auto' },
    { value: 'original', label: 'Original' },
    { value: 'white', label: 'White / reversed' },
    { value: 'dark', label: 'Dark' },
]

/**
 * @param {{ sectionId: string, blockId: string, slot: 'hero' | 'sm' | 'mini' }} props
 */
export default function GuidelinesLogoBlockPanel({ sectionId, blockId, slot = 'sm' }) {
    const ctx = useSidebarEditor()
    const block = ctx?.draftOverrides?.sections?.[sectionId]?.content?.logo_blocks?.[blockId] || {}

    const title = IDENTITY_LOGO_BLOCK_LABELS[blockId] || blockId
    const selectVal = logoSourceToSelectValue(block.source)
    const hasOverride = isLogoBlockOverridden(ctx?.draftOverrides?.sections, sectionId, blockId)
    const sourceDisplay = getLogoSourceDisplayLabel(block.source, ctx.brand, ctx?.logoAssets || [])

    const sectionHasOverrides = isSectionOverridden(ctx?.draftOverrides?.sections, sectionId)
    const sectionUnits = countSectionOverrideUnits(ctx?.draftOverrides?.sections, sectionId)

    const patch = (partial) => ctx.mergeLogoBlock(sectionId, blockId, partial)

    const onSourceChange = (value) => {
        const src = parseLogoSourceValue(value)
        patch({ source: src })
    }

    const onResetSection = () => {
        if (!sectionHasOverrides) return
        const msg =
            sectionUnits > 1
                ? `Reset this section to AI? This removes ${sectionUnits} customization(s) in Brand Identity (page theme is kept).`
                : 'Reset this section to AI? This removes customization for this section. Page theme is kept.'
        if (typeof window !== 'undefined' && window.confirm(msg)) {
            ctx.resetSection(sectionId)
        }
    }

    return (
        <div className="space-y-3">
            <div className="rounded-lg border border-gray-200 bg-slate-50/80 px-3 py-2.5 space-y-2">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <h3 className="text-sm font-semibold text-gray-900">{title}</h3>
                        <div className="mt-0.5 flex flex-wrap items-center gap-1.5">
                            <span className="text-[9px] font-semibold uppercase tracking-wide text-violet-700/90 bg-violet-50 ring-1 ring-violet-100 rounded px-1.5 py-0.5">Logo block</span>
                            <span
                                className={`text-[9px] font-semibold rounded px-1.5 py-0.5 ${
                                    hasOverride ? 'bg-amber-50 text-amber-800 ring-1 ring-amber-200' : 'bg-slate-200/80 text-slate-600'
                                }`}
                            >
                                {hasOverride ? 'Customized' : 'AI default'}
                            </span>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={() => ctx.resetLogoBlock(sectionId, blockId)}
                        className="shrink-0 text-[10px] font-medium text-violet-700 hover:text-violet-900"
                    >
                        Reset block
                    </button>
                </div>
                <p className="text-[10px] text-gray-600 leading-snug">
                    {hasOverride ? 'This block has custom overrides.' : 'Using AI/default layout.'}
                </p>
                <div className="pt-0.5 border-t border-gray-200/80">
                    <p className="text-[9px] font-medium text-gray-500 uppercase tracking-wide mb-0.5">Current source</p>
                    <p className="text-xs text-gray-900 font-medium">{sourceDisplay.line}</p>
                    {sourceDisplay.sub && <p className="text-[10px] text-gray-500 break-all">{sourceDisplay.sub}</p>}
                </div>
            </div>

            <p className="text-[10px] text-gray-600 leading-snug border-l-2 border-violet-200 pl-2">
                Edits here customize <strong>this guidelines page</strong> only. They do not change the official brand identity. Use <strong>Save</strong> in the sidebar to publish.
            </p>

            <ToggleControl
                label="Visible"
                value={block.visible !== false}
                onChange={(v) => patch({ visible: v })}
            />

            <div className="space-y-2 border-t border-gray-100 pt-2">
                <div className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Content</div>
                <ToggleControl
                    label="Show label"
                    value={block.label?.visible !== false}
                    onChange={(v) => patch({ label: { ...block.label, visible: v, text: block.label?.text } })}
                />
                <div>
                    <label className="text-xs text-gray-600">Label text</label>
                    <input
                        type="text"
                        value={block.label?.text ?? ''}
                        onChange={(e) => patch({ label: { ...block.label, text: e.target.value, visible: block.label?.visible !== false } })}
                        className="mt-1 w-full rounded-md border border-gray-200 px-2 py-1.5 text-xs"
                        placeholder={title}
                    />
                </div>
            </div>

            <div className="space-y-2 border-t border-gray-100 pt-2">
                <div className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Source</div>
                <LogoSourceSelect
                    value={selectVal}
                    onChange={onSourceChange}
                    logoAssets={ctx?.logoAssets || []}
                />
                {block.source?.type === 'custom_url' && (
                    <div>
                        <label className="text-xs text-gray-600">Image URL (edit)</label>
                        <input
                            type="url"
                            value={block.source?.url || ''}
                            onChange={(e) => patch({ source: { type: 'custom_url', url: e.target.value } })}
                            className="mt-1 w-full rounded-md border border-gray-200 px-2 py-1.5 text-xs"
                            placeholder="https://…"
                        />
                    </div>
                )}
            </div>

            <div className="space-y-2 border-t border-gray-100 pt-2">
                <div className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Appearance</div>
                <SelectControl
                    label="Card background"
                    value={block.background?.mode || 'inherit'}
                    onChange={(v) => {
                        if (v === 'custom') {
                            patch({ background: { mode: 'custom', custom_color: block.background?.custom_color || '#ffffff' } })
                        } else if (v === 'image') {
                            patch({ background: { mode: 'image', image_url: block.background?.image_url || '' } })
                        } else if (v === 'brand_asset') {
                            patch({ background: { mode: 'brand_asset', asset_id: block.background?.asset_id || '' } })
                        } else {
                            patch({ background: { mode: v, custom_color: null, image_url: null, asset_id: null } })
                        }
                    }}
                    options={BG_PRESETS}
                />
                {block.background?.mode === 'image' && (
                    <div>
                        <label className="text-xs text-gray-600">Background image URL</label>
                        <input
                            type="url"
                            value={block.background?.image_url || ''}
                            onChange={(e) => patch({ background: { ...block.background, mode: 'image', image_url: e.target.value } })}
                            className="mt-1 w-full rounded-md border border-gray-200 px-2 py-1.5 text-xs"
                            placeholder="https://…"
                        />
                        {block.background?.image_url && (
                            <img src={block.background.image_url} alt="" className="mt-1 h-10 w-full max-w-[8rem] object-cover rounded border border-gray-200" />
                        )}
                    </div>
                )}
                {block.background?.mode === 'brand_asset' && (
                    <div>
                        <span className="text-xs text-gray-600">Library image</span>
                        <select
                            value={block.background?.asset_id || ''}
                            onChange={(e) => patch({ background: { ...block.background, mode: 'brand_asset', asset_id: e.target.value } })}
                            className="mt-1 w-full rounded-md border border-gray-200 px-2 py-1.5 text-xs"
                        >
                            <option value="">— Select —</option>
                            {(ctx?.logoAssets || []).map((a) => (
                                <option key={a.id} value={a.id}>{a.title || `Asset #${a.id}`}</option>
                            ))}
                        </select>
                        {(() => {
                            const a = (ctx?.logoAssets || []).find((x) => String(x.id) === String(block.background?.asset_id))
                            return a?.url ? <img src={a.url} alt="" className="mt-1 h-10 w-20 object-cover rounded border" /> : null
                        })()}
                    </div>
                )}
                {block.background?.mode === 'custom' && (
                    <ColorPickerControl
                        label="Custom BG"
                        value={block.background?.custom_color || '#f8fafc'}
                        onChange={(v) => patch({ background: { ...block.background, mode: 'custom', custom_color: v } })}
                    />
                )}
                <SelectControl
                    label="Logo treatment"
                    value={block.appearance?.logo_variant || 'auto'}
                    onChange={(v) => patch({ appearance: { ...block.appearance, logo_variant: v } })}
                    options={VARIANT_OPTIONS}
                />
                <div>
                    <span className="text-xs text-gray-600">Label color</span>
                    <div className="mt-1 max-w-[8rem]">
                        <ColorPickerControl
                            hideLabel
                            value={block.appearance?.label_color || (slot === 'hero' ? 'rgba(255,255,255,0.4)' : '#9ca3af')}
                            onChange={(v) => patch({ appearance: { ...block.appearance, label_color: v } })}
                        />
                    </div>
                </div>
            </div>

            <div className="space-y-2 border-t border-gray-100 pt-2">
                <div className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Layout</div>
                <SelectControl
                    label="Size"
                    value={block.layout?.size_preset || (slot === 'hero' ? 'lg' : 'md')}
                    onChange={(v) => patch({ layout: { ...block.layout, size_preset: v } })}
                    options={SIZE_PRESETS}
                />
                <SelectControl
                    label="Alignment"
                    value={block.layout?.align || 'center'}
                    onChange={(v) => patch({ layout: { ...block.layout, align: v } })}
                    options={ALIGN_PRESETS}
                />
                <SliderControl
                    label="Opacity"
                    value={typeof block.appearance?.opacity === 'number' ? block.appearance.opacity : 1}
                    min={0.2}
                    max={1}
                    step={0.05}
                    onChange={(v) => patch({ appearance: { ...block.appearance, opacity: v } })}
                />
            </div>

            {sectionId === 'sec-logo' && (
                <div className="space-y-1.5 border-t border-gray-100 pt-2">
                    <div className="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Section</div>
                    <button
                        type="button"
                        disabled={!sectionHasOverrides}
                        onClick={onResetSection}
                        className="w-full text-left rounded-md border border-amber-200/90 bg-amber-50/90 px-2.5 py-2 text-xs font-medium text-amber-900 hover:bg-amber-100/90 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                    >
                        Reset section to AI
                    </button>
                    <p className="text-[9px] text-gray-500">Clears custom layout and block edits for Brand Identity only. Page theme is unchanged.</p>
                </div>
            )}
        </div>
    )
}
