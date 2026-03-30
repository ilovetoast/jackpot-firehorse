import ColorPickerControl from './ColorPickerControl'
import SelectControl from './SelectControl'
import SliderControl from './SliderControl'

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

export default function BackgroundControl({ background = {}, onChange }) {
    const type = background.type || 'default'

    const update = (key, value) => {
        onChange({ ...background, [key]: value })
    }

    return (
        <div className="space-y-2.5">
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
                    <SliderControl
                        label="Opacity"
                        value={background.image_opacity ?? 0.2}
                        onChange={(v) => update('image_opacity', v)}
                    />
                    <SelectControl
                        label="Blend"
                        value={background.blend_mode || 'overlay'}
                        onChange={(v) => update('blend_mode', v)}
                        options={BLEND_MODES}
                    />
                </div>
            )}
        </div>
    )
}
