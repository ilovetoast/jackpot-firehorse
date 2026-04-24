import type { Layer } from '../../documentModel'

export function StudioPrecisionTransformControls({
    layer,
    disabled,
    onChangeXYWH,
}: {
    layer: Layer
    disabled?: boolean
    onChangeXYWH: (patch: Partial<{ x: number; y: number; width: number; height: number }>) => void
}) {
    const t = layer.transform
    return (
        <div className="grid grid-cols-2 gap-1.5">
            {(
                [
                    ['X', 'x', Math.round(t.x)],
                    ['Y', 'y', Math.round(t.y)],
                    ['W', 'width', Math.round(t.width)],
                    ['H', 'height', Math.round(t.height)],
                ] as const
            ).map(([lab, key, val]) => (
                <div key={key}>
                    <label className="mb-0.5 block text-[10px] text-gray-400">{lab}</label>
                    <input
                        type="number"
                        disabled={disabled}
                        value={val}
                        onChange={(e) => {
                            const n = Number(e.target.value)
                            if (Number.isNaN(n)) return
                            const v = key === 'width' || key === 'height' ? Math.max(20, n) : n
                            onChangeXYWH({ [key]: v })
                        }}
                        className="w-full rounded-md border border-gray-700/90 bg-gray-900/55 px-2 py-1 text-[11px] text-gray-200"
                    />
                </div>
            ))}
        </div>
    )
}
