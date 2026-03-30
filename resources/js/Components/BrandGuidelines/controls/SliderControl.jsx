export default function SliderControl({ label, value, onChange, min = 0, max = 1, step = 0.05, displayValue }) {
    return (
        <div className="space-y-1">
            <div className="flex items-center justify-between">
                <span className="text-xs text-gray-500 font-medium">{label}</span>
                <span className="text-xs font-mono text-gray-400">{displayValue ?? Math.round(value * 100) + '%'}</span>
            </div>
            <input
                type="range"
                min={min}
                max={max}
                step={step}
                value={value}
                onChange={(e) => onChange(parseFloat(e.target.value))}
                className="w-full h-1.5 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-indigo-500"
            />
        </div>
    )
}
