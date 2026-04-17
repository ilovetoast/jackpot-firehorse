import { useState } from 'react'
import ColorPickerControl from './ColorPickerControl'
import SelectControl from './SelectControl'
import SliderControl from './SliderControl'
import BuilderAssetSelectorModal from '../BuilderAssetSelectorModal'

const BG_TYPES = [
    { value: 'default', label: 'Default' },
    { value: 'solid', label: 'Solid Color' },
    { value: 'gradient', label: 'Gradient' },
    { value: 'image', label: 'Image' },
    { value: 'transparent', label: 'Transparent' },
]

const BLEND_MODES = [
    { value: 'overlay', label: 'Overlay' },
    { value: 'multiply', label: 'Multiply' },
    { value: 'screen', label: 'Screen' },
    { value: 'normal', label: 'Normal' },
]

export default function BackgroundControl({ background = {}, onChange, presetImages = [], presentationStyle = 'clean', brandId = null }) {
    const type = background.type || 'default'
    const isTexturedGlobal = presentationStyle === 'textured'
    const [libraryOpen, setLibraryOpen] = useState(false)

    const update = (key, value) => {
        onChange({ ...background, [key]: value })
    }

    const applyPresetUrl = (url) => {
        onChange({
            ...background,
            type: 'image',
            image_url: url,
            image_opacity: typeof background.image_opacity === 'number' ? background.image_opacity : 0.35,
            blend_mode: background.blend_mode || 'multiply',
        })
    }

    const clearPinnedImage = () => {
        onChange({ type: 'default' })
    }

    const handleLibrarySelect = (asset) => {
        const url = asset?.original || asset?.signed_url || asset?.url || asset?.thumbnail_url
        if (!url) return
        applyPresetUrl(url)
        setLibraryOpen(false)
    }

    return (
        <div className="space-y-2.5">
            {(presetImages.length > 0 || brandId) && (
                <div className="rounded-lg border border-indigo-100/80 bg-indigo-50/40 p-2.5 space-y-2">
                    <div>
                        <span className="text-xs text-gray-700 font-medium">Brand reference images</span>
                        <p className="text-[10px] text-gray-500 mt-0.5 leading-snug">
                            Pulled from Brand DNA reference categories and from the AI builder when assets are attached
                            as visual references. Use them in{' '}
                            <strong>any</strong> presentation style — click to pin one to this section.
                        </p>
                        {isTexturedGlobal && (
                            <p className="text-[10px] text-amber-800/90 mt-1 leading-snug">
                                Textured + <strong>Default</strong> background rotates these automatically by section.
                                Pinning an image here overrides rotation for this section only.
                            </p>
                        )}
                    </div>
                    {presetImages.length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                            {presetImages.map((p, i) => (
                                <button
                                    key={`${p.url}-${i}`}
                                    type="button"
                                    title={p.label}
                                    onClick={() => applyPresetUrl(p.url)}
                                    className={`relative h-10 w-10 shrink-0 rounded-md overflow-hidden border-2 transition-all ${
                                        type === 'image' && (background.image_url || '') === p.url
                                            ? 'border-indigo-500 ring-1 ring-indigo-300'
                                            : 'border-gray-200 hover:border-gray-400'
                                    }`}
                                >
                                    <img src={p.url} alt="" className="h-full w-full object-cover" />
                                </button>
                            ))}
                        </div>
                    )}
                    {brandId && (
                        <button
                            type="button"
                            onClick={() => setLibraryOpen(true)}
                            className="w-full inline-flex items-center justify-center gap-1.5 rounded-md border border-dashed border-indigo-300 bg-white/70 px-2 py-1.5 text-[11px] font-medium text-indigo-700 hover:bg-indigo-50 hover:border-indigo-400 transition-colors"
                        >
                            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                            </svg>
                            Browse library…
                        </button>
                    )}
                    {type === 'image' && (background.image_url || '').trim() !== '' && (
                        <button
                            type="button"
                            onClick={clearPinnedImage}
                            className="text-[10px] font-medium text-indigo-600 hover:text-indigo-800"
                        >
                            Clear pinned image — use section default
                            {isTexturedGlobal ? ' (Textured: automatic rotation)' : ''}
                        </button>
                    )}
                </div>
            )}

            <SelectControl
                label="Background"
                value={type}
                onChange={(v) => update('type', v)}
                options={BG_TYPES}
            />

            {type === 'solid' && (
                <ColorPickerControl
                    label="Color"
                    value={background.color || '#000000'}
                    onChange={(v) => update('color', v)}
                />
            )}

            {type === 'gradient' && (
                <div className="space-y-2">
                    <div className="flex items-center gap-2">
                        <span className="text-[10px] text-gray-400 w-10 shrink-0">From</span>
                        <input
                            type="color"
                            value={background.gradient_from || '#000000'}
                            onChange={(e) => update('gradient_from', e.target.value)}
                            className="w-6 h-6 rounded border border-gray-200 cursor-pointer"
                        />
                        <span className="text-[10px] text-gray-400 w-6 shrink-0">To</span>
                        <input
                            type="color"
                            value={background.gradient_to || '#333333'}
                            onChange={(e) => update('gradient_to', e.target.value)}
                            className="w-6 h-6 rounded border border-gray-200 cursor-pointer"
                        />
                    </div>
                </div>
            )}

            {type === 'image' && (
                <div className="space-y-2">
                    <div>
                        <span className="text-xs text-gray-500 font-medium">Image URL</span>
                        <input
                            type="text"
                            value={background.image_url || ''}
                            onChange={(e) => update('image_url', e.target.value)}
                            placeholder="https://..."
                            className="mt-1 w-full px-2 py-1 text-xs border border-gray-200 rounded-md focus:outline-none focus:border-indigo-400"
                        />
                    </div>
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-xs text-gray-500 font-medium shrink-0">Tint</span>
                        <div className="flex items-center gap-1.5">
                            {background.overlay_color && (
                                <button
                                    type="button"
                                    onClick={() => update('overlay_color', null)}
                                    className="text-[10px] text-gray-400 hover:text-red-500"
                                    title="Clear tint (use neutral dark)"
                                >
                                    Clear
                                </button>
                            )}
                            <ColorPickerControl
                                label="Tint"
                                hideLabel
                                value={background.overlay_color || '#000000'}
                                onChange={(v) => update('overlay_color', v)}
                            />
                        </div>
                    </div>
                    <SliderControl
                        label="Opacity"
                        value={background.image_opacity ?? 0.35}
                        onChange={(v) => update('image_opacity', v)}
                    />
                    <SelectControl
                        label="Blend"
                        value={background.blend_mode || 'multiply'}
                        onChange={(v) => update('blend_mode', v)}
                        options={BLEND_MODES}
                    />
                </div>
            )}

            {brandId && (
                <BuilderAssetSelectorModal
                    open={libraryOpen}
                    onClose={() => setLibraryOpen(false)}
                    brandId={brandId}
                    builderContext="visual_reference"
                    onSelect={handleLibrarySelect}
                    title="Choose background image"
                    showUpload={false}
                />
            )}
        </div>
    )
}
