export default function ToggleControl({ label, value, onChange, description }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <div className="min-w-0">
                <span className="text-xs text-gray-500 font-medium">{label}</span>
                {description && <p className="text-[10px] text-gray-400 mt-0.5">{description}</p>}
            </div>
            <button
                type="button"
                role="switch"
                aria-checked={!!value}
                onClick={() => onChange(!value)}
                className={`relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none ${value ? 'bg-indigo-500' : 'bg-gray-200'}`}
            >
                <span
                    className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow-sm ring-0 transition duration-200 ease-in-out ${value ? 'translate-x-4' : 'translate-x-0'}`}
                />
            </button>
        </div>
    )
}
